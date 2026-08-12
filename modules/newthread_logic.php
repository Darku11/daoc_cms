<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) { exit; }

$board_id    = isset($_GET['bid']) ? (int)$_GET['bid'] : 0;
$userPriv    = (int)($_SESSION['priv_level'] ?? 0);
$myId        = (int)($_SESSION['user_id']    ?? 0);
$is_verified = (int)($_SESSION['is_verified'] ?? 0);

$spike_cfg = [];
try {
    foreach ($db->query("SELECT setting_key, setting_value FROM spike_settings")->fetchAll() as $s) {
        $spike_cfg[$s['setting_key']] = $s['setting_value'];
    }
} catch (\Throwable $e) {}

$attachments_enabled = ($spike_cfg['attachments_enabled'] ?? '1') === '1';
$max_attach_size     = (int)($spike_cfg['max_attachment_size'] ?? 2097152);
$allowed_mimes       = array_map('trim', explode(',', $spike_cfg['allowed_mime_types'] ?? 'image/jpeg,image/png,image/gif'));
$cooldown_limit      = (int)($spike_cfg['spam_cooldown'] ?? 30);
$polls_enabled       = ($spike_cfg['polls_enabled'] ?? '1') === '1';

$smilies = [];
try {
    $smilies = $db->query("SELECT code, image_url, emoji, title FROM spike_smilies WHERE is_active=1 ORDER BY pos ASC")->fetchAll();
} catch (\Throwable $e) {}

$attach_path_raw = $spike_cfg['attachment_path'] ?? 'uploads/forum/';
$attach_path = (strpos($attach_path_raw, '/') === 0)
    ? rtrim($attach_path_raw, '/') . '/'
    : rtrim(__DIR__ . '/../' . $attach_path_raw, '/') . '/';

$available_prefixes = [];
try {
    $available_prefixes = $db->query("SELECT * FROM spike_prefixes WHERE is_active=1 ORDER BY pos ASC")->fetchAll();
} catch (\Throwable $e) {}

$stmt = $db->prepare("
    SELECT b.title, b.min_priv_post, b.require_approval, c.min_priv_post as cat_min_post
    FROM spike_boards b
    JOIN spike_categories c ON b.cat_id = c.id
    WHERE b.id = ?
");
$stmt->execute([$board_id]);
$board = $stmt->fetch();

if (!$board) {
    header("Location: index.php?p=spike&err=not_found"); exit;
}

$required_auth = ((int)$board['min_priv_post'] > 0)
    ? (int)$board['min_priv_post']
    : (int)$board['cat_min_post'];

if ($myId <= 0 || $userPriv < $required_auth || $is_verified === 0) {
    header("Location: index.php?p=viewboard&id=$board_id&err=unauthorized_post"); exit;
}

$forbidden_words = [];
try {
    $s = $db->prepare("SELECT word, action, replacement FROM spike_forbidden_words WHERE scope IN ('forum','both') ORDER BY LENGTH(word) DESC");
    $s->execute();
    $forbidden_words = $s->fetchAll();
} catch (\Throwable $e) {}

function nt_check_forbidden(string $text, array $words): bool {
    foreach ($words as $w) {
        if ($w['action'] === 'block' && mb_stripos($text, $w['word']) !== false) return true;
    }
    return false;
}
function nt_replace_forbidden(string $text, array $words): string {
    foreach ($words as $w) {
        if ($w['action'] === 'replace')
            $text = str_ireplace($w['word'], $w['replacement'] ?? '***', $text);
    }
    return $text;
}

function nt_generate_slug(string $title, int $id, PDO $db): string {
    $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
    if (empty($base)) $base = 'thread';
    $slug = $base . '-' . $id;
    $check = $db->prepare("SELECT id FROM spike_threads WHERE slug = ? AND id != ? LIMIT 1");
    $check->execute([$slug, $id]);
    if ($check->fetch()) {
        $slug = $base . '-' . $id . '-' . bin2hex(random_bytes(3));
    }
    return $slug;
}

function nt_clean_poll_options(array $raw): array {
    $out = [];
    foreach ($raw as $opt) {
        $opt = trim(strip_tags((string)$opt));
        if ($opt === '') continue;
        $opt = mb_substr($opt, 0, 120);
        if (!in_array($opt, $out, true)) $out[] = $opt;
    }
    return array_slice($out, 0, 10);
}

if (!function_exists('spike_process_inline_images')) {
    // Pasted/embedded screenshots are stored and served directly - they are NOT
    // recorded in spike_attachments. Only files uploaded through the dedicated
    // attachments[] field (real attachments like archives) create attachment rows.
    function spike_process_inline_images(string $content, string $attach_path, string $attach_path_raw, int $max_size): string {
        return preg_replace_callback('/<img\s+[^>]*src="data:(image\/[^;]+);base64,([^"]+)"[^>]*>/i', function($m) use ($attach_path, $attach_path_raw, $max_size) {
            $mime = $m[1];
            $data = base64_decode($m[2]);
            if ($data === false || strlen($data) > $max_size) return '';
            $ext = match($mime) { 'image/png'=>'png', 'image/gif'=>'gif', 'image/webp'=>'webp', default=>'jpg' };
            $stored_name = bin2hex(random_bytes(16)) . '.' . $ext;
            if (!is_dir($attach_path)) @mkdir($attach_path, 0755, true);
            if (!file_exists($attach_path . '.htaccess')) {
                @file_put_contents($attach_path . '.htaccess', "php_flag engine off\nAddHandler none .php .php3 .php4 .php5 .phtml\n");
            }
            if (file_put_contents($attach_path . $stored_name, $data)) {
                $web_path = rtrim($attach_path_raw, '/') . '/' . $stored_name;
                return preg_replace('/src="data:[^"]+"/', 'src="' . htmlspecialchars($web_path, ENT_QUOTES) . '"', $m[0]);
            }
            return $m[0];
        }, $content);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'check_similar') {
    header('Content-Type: application/json');
    checkToken($_POST['csrf_token'] ?? '');
    
    $q = trim(strip_tags($_POST['title'] ?? ''));
    if (mb_strlen($q) < 4) { echo json_encode(['ok' => true, 'threads' => []]); exit; }

    $words = array_slice(array_filter(explode(' ', $q), fn($w) => mb_strlen($w) >= 4), 0, 3);
    if (empty($words)) { $words = [$q]; }

    $where = [];
    $params = [];
    foreach ($words as $w) {
        $where[] = "title LIKE ?";
        $params[] = "%" . $w . "%";
    }
    $whereSql = implode(' OR ', $where);

    try {
        $stmt = $db->prepare("SELECT id, title, slug FROM spike_threads WHERE is_approved = 1 AND (" . $whereSql . ") ORDER BY created_at DESC LIMIT 5");
        $stmt->execute($params);
        echo json_encode(['ok' => true, 'threads' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && array_key_exists('thread_content_input', $_POST)) {

    checkToken($_POST['csrf_token'] ?? '');

    $title     = trim($_POST['thread_title']         ?? '');
    $content   = trim($_POST['thread_content_input'] ?? '');
    $prefix_id = (int)($_POST['prefix_id']           ?? 0) ?: null;

    if (empty($title)) {
        header("Location: index.php?p=newthread&bid=$board_id&err=title_required"); exit;
    }

    if (empty($content) || empty(trim(strip_tags($content)))) {
        header("Location: index.php?p=newthread&bid=$board_id&err=content_required"); exit;
    }

    if ($cooldown_limit > 0) {
        $sl = $db->prepare("SELECT created_at FROM spike_posts WHERE author_id = ? ORDER BY created_at DESC LIMIT 1");
        $sl->execute([$myId]);
        $lp = $sl->fetch();
        if ($lp && (time() - strtotime($lp['created_at'])) < $cooldown_limit) {
            $wait = $cooldown_limit - (time() - strtotime($lp['created_at']));
            header("Location: index.php?p=newthread&bid=$board_id&err=spam_cooldown&wait=$wait"); exit;
        }
    }

    if (nt_check_forbidden($title . ' ' . strip_tags($content), $forbidden_words)) {
        header("Location: index.php?p=newthread&bid=$board_id&err=forbidden_word"); exit;
    }
    $content = nt_replace_forbidden($content, $forbidden_words);

    $poll_question      = $polls_enabled ? trim(strip_tags($_POST['poll_question'] ?? '')) : '';
    $wants_poll         = $polls_enabled && $poll_question !== '';
    $poll_options_clean = $wants_poll ? nt_clean_poll_options((array)($_POST['poll_options'] ?? [])) : [];
    $poll_multi         = ($wants_poll && isset($_POST['poll_multi'])) ? 1 : 0;
    $poll_ends_at       = null;

    if ($wants_poll) {
        $poll_question = mb_substr($poll_question, 0, 255);

        if (count($poll_options_clean) < 2) {
            header("Location: index.php?p=newthread&bid=$board_id&err=poll_min_options"); exit;
        }
        if (nt_check_forbidden($poll_question . ' ' . implode(' ', $poll_options_clean), $forbidden_words)) {
            header("Location: index.php?p=newthread&bid=$board_id&err=forbidden_word"); exit;
        }
        if (!empty($_POST['poll_ends_at'])) {
            $ts = strtotime(str_replace('T', ' ', trim($_POST['poll_ends_at'])));
            if ($ts && $ts > time()) $poll_ends_at = date('Y-m-d H:i:s', $ts);
        }
    }

    $is_approved = ((int)$board['require_approval'] === 1 && $userPriv < 2) ? 0 : 1;

    try {
        $db->beginTransaction();

        $db->prepare("INSERT INTO spike_threads (board_id, author_id, title, prefix_id, is_approved) VALUES (?, ?, ?, ?, ?)")
           ->execute([$board_id, $myId, $title, $prefix_id, $is_approved]);
        $new_thread_id = (int)$db->lastInsertId();

        $slug = nt_generate_slug($title, $new_thread_id, $db);
        $db->prepare("UPDATE spike_threads SET slug = ? WHERE id = ?")->execute([$slug, $new_thread_id]);

        $db->prepare("INSERT INTO spike_posts (thread_id, author_id, content) VALUES (?, ?, ?)")
           ->execute([$new_thread_id, $myId, $content]);
        $new_post_id = (int)$db->lastInsertId();

        if (strpos($content, 'data:image') !== false) {
            $content = spike_process_inline_images($content, $attach_path, $attach_path_raw, $max_attach_size);
            $db->prepare("UPDATE spike_posts SET content = ? WHERE id = ?")->execute([$content, $new_post_id]);
        }

        if ($is_approved === 1) {
            $db->prepare("UPDATE users SET forum_posts = forum_posts + 1 WHERE id = ?")->execute([$myId]);
        }

        if ($wants_poll) {
            $db->prepare("INSERT INTO spike_polls (thread_id, question, multi, ends_at, created_by) VALUES (?, ?, ?, ?, ?)")
               ->execute([$new_thread_id, $poll_question, $poll_multi, $poll_ends_at, $myId]);
            $new_poll_id = (int)$db->lastInsertId();

            $ins_opt = $db->prepare("INSERT INTO spike_poll_options (poll_id, label, pos) VALUES (?, ?, ?)");
            foreach ($poll_options_clean as $i => $label) {
                $ins_opt->execute([$new_poll_id, $label, $i + 1]);
            }
            aldhran_log("POLL_CREATED", "Poll added to new thread '$title'", $myId, $new_thread_id);
        }

        aldhran_log("THREAD_CREATED", "New thread '$title' in board #$board_id", $myId, $new_thread_id);

        $db->commit();

    } catch (\Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("Spike NewThread Error: " . $e->getMessage());
        header("Location: index.php?p=newthread&bid=$board_id&err=db_error"); exit;
    }

    if ($attachments_enabled && !empty($_FILES['attachments']['name'][0])) {
        if (!is_dir($attach_path)) @mkdir($attach_path, 0755, true);
        if (is_writable($attach_path)) {
            $image_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $inline_html = '';
            $stmt_att = $db->prepare("INSERT INTO spike_attachments (post_id,user_id,filename,stored_name,filesize,mime_type) VALUES (?,?,?,?,?,?)");
            foreach ($_FILES['attachments']['name'] as $idx => $orig_name) {
                if ($_FILES['attachments']['error'][$idx] !== UPLOAD_ERR_OK) continue;
                $tmp  = $_FILES['attachments']['tmp_name'][$idx];
                $size = (int)$_FILES['attachments']['size'][$idx];
                $mime = function_exists('finfo_file')
                    ? (finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmp) ?: 'application/octet-stream')
                    : (function_exists('mime_content_type') ? mime_content_type($tmp) : 'application/octet-stream');
                if ($size > $max_attach_size || !in_array($mime, $allowed_mimes)) continue;
                $ext  = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($orig_name, PATHINFO_EXTENSION)));
                $name = bin2hex(random_bytes(16)) . ($ext ? '.' . $ext : '');
                if (move_uploaded_file($tmp, $attach_path . $name)) {
                    // Images are embedded inline in the post - only non-image files
                    // (archives, documents, ...) become a spike_attachments entry.
                    if (in_array($mime, $image_mimes, true)) {
                        $web_path = rtrim($attach_path_raw, '/') . '/' . $name;
                        $inline_html .= '<img src="' . htmlspecialchars($web_path, ENT_QUOTES) . '" alt="' . htmlspecialchars($orig_name, ENT_QUOTES) . '">';
                    } else {
                        $stmt_att->execute([$new_post_id, $myId, $orig_name, $name, $size, $mime]);
                    }
                }
            }
            if ($inline_html !== '') {
                $db->prepare("UPDATE spike_posts SET content = CONCAT(content, ?) WHERE id = ?")
                   ->execute([$inline_html, $new_post_id]);
            }
        }
    }

    if ($is_approved === 0) {
        header("Location: index.php?p=viewboard&id=$board_id&msg=pending_approval"); exit;
    } else {
        header("Location: index.php?p=viewthread&slug=" . urlencode($slug) . "&msg=thread_created&undo_tid=$new_thread_id"); exit;
    }
}