<?php
// SPDX-License-Identifier: GPL-3.0-only
require_once(__DIR__ . '/../includes/spike_bb_helper.php');
if (!defined('IN_CMS')) { exit; }

$userPriv    = (int)($_SESSION['priv_level'] ?? 0);
$myId        = (int)($_SESSION['user_id']    ?? 0);
$myStanding  = (int)($_SESSION['standing']   ?? 0);
$is_verified = (int)($_SESSION['is_verified'] ?? 0);

global $botSettings;
$ai_active = isset($botSettings) && $botSettings->isActive() && $botSettings->hasAiConfigured() && class_exists('AiManager');

$block_filter_p   = ($myId > 0 && $userPriv < 2) ? " AND p.author_id NOT IN (SELECT blocker_id FROM user_blocks WHERE blocked_id = " . $myId . ")" : "";
$block_filter_raw = ($myId > 0 && $userPriv < 2) ? " AND author_id NOT IN (SELECT blocker_id FROM user_blocks WHERE blocked_id = " . $myId . ")" : "";

define('SPIKE_POSTS_PER_PAGE', 4);
$current_page = max(1, (int)($_GET['page'] ?? 1));

$thread_id = 0;

if (!empty($_GET['slug'])) {
    $slug_raw   = trim($_GET['slug']);
    $slug_clean = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug_raw));
    
    try {
        $s = $db->prepare("SELECT id FROM spike_threads WHERE slug = ? LIMIT 1");
        $s->execute([$slug_clean]);
        $r = $s->fetch();
        if ($r) {
            $thread_id = (int)$r['id'];
        }
    } catch (\Throwable $e) {}

    if ($thread_id <= 0 && preg_match('/-(\d+)$/', $slug_raw, $matches)) {
        $thread_id = (int)$matches[1];
    }

    if ($thread_id <= 0) {
        header("Location: index.php?p=spike&err=not_found"); exit;
    }
} elseif (!empty($_GET['id'])) {
    $thread_id = (int)$_GET['id'];
    try {
        $s = $db->prepare("SELECT slug FROM spike_threads WHERE id = ? LIMIT 1");
        $s->execute([$thread_id]);
        $r = $s->fetch();
        if ($r && !empty($r['slug'])) {
            $redir = "index.php?p=viewthread&slug=" . urlencode($r['slug']);
            if ($current_page > 1) $redir .= "&page=$current_page";
            header("Location: $redir", true, 301); exit;
        }
    } catch (\Throwable $e) {}
} else {
    header("Location: index.php?p=spike&err=not_found"); exit;
}

$spike_cfg = [];
try {
    foreach ($db->query("SELECT setting_key, setting_value FROM spike_settings")->fetchAll() as $row) {
        $spike_cfg[$row['setting_key']] = $row['setting_value'];
    }
} catch (\Throwable $e) {}

$cooldown_limit      = (int)($spike_cfg['spam_cooldown']        ?? 30);
$min_auth_links      = (int)($spike_cfg['spam_min_auth_links']  ?? 1);
$reactions_enabled   = ($spike_cfg['reactions_enabled']         ?? '1') === '1';
$polls_enabled       = ($spike_cfg['polls_enabled']             ?? '1') === '1';
$attachments_enabled = ($spike_cfg['attachments_enabled']       ?? '1') === '1';
$max_attach_size     = (int)($spike_cfg['max_attachment_size'] ?? 2097152);
$allowed_mimes       = array_map('trim', explode(',', $spike_cfg['allowed_mime_types'] ?? 'image/jpeg,image/png,image/gif,application/pdf'));
$smilies_enabled     = ($spike_cfg['smilies_enabled']          ?? '1') === '1';

$attach_path_raw = $spike_cfg['attachment_path'] ?? 'uploads/forum/';
$attach_path = (strpos($attach_path_raw, '/') === 0)
    ? rtrim($attach_path_raw, '/') . '/'
    : rtrim(__DIR__ . '/../' . $attach_path_raw, '/') . '/';

// --- IMPORTANT: function must be defined BEFORE its first call ---
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

function spike_get_forbidden_words(PDO $db): array {
    try {
        $s = $db->prepare("SELECT word,action,replacement FROM spike_forbidden_words WHERE scope IN ('forum','both') ORDER BY LENGTH(word) DESC");
        $s->execute(); return $s->fetchAll();
    } catch (\Throwable $e) { return []; }
}
function spike_check_forbidden(string $text, array $words): array {
    foreach ($words as $w) {
        if (mb_stripos($text, $w['word']) !== false) {
            if ($w['action'] === 'block') return ['blocked'=>true,'word'=>$w['word']];
            if ($w['action'] === 'flag')  return ['flag'=>true,'word'=>$w['word']];
        }
    }
    return ['blocked'=>false];
}
function spike_replace_forbidden(string $text, array $words): string {
    foreach ($words as $w) {
        if ($w['action'] === 'replace')
            $text = str_ireplace($w['word'], $w['replacement'] ?? '***', $text);
    }
    return $text;
}
$forbidden_words = spike_get_forbidden_words($db);

$smilies = [];
if ($smilies_enabled) {
    try { $smilies = $db->query("SELECT code,image_url,title,emoji FROM spike_smilies WHERE is_active=1 ORDER BY pos ASC")->fetchAll(); }
    catch (\Throwable $e) {}
}

function spike_parse_smilies(string $text, array $smilies): string {
    $replacements = [];
    foreach ($smilies as $s) {
        $code_escaped = htmlspecialchars($s['code'], ENT_QUOTES);
        $r = !empty($s['image_url'])
            ? '<img src="' . htmlspecialchars($s['image_url'], ENT_QUOTES) . '" alt="' . $code_escaped . '" title="' . htmlspecialchars($s['title'] ?? '', ENT_QUOTES) . '" class="spike-smiley">'
            : '<span class="spike-smiley-emoji" title="' . htmlspecialchars($s['title'] ?? '', ENT_QUOTES) . '">' . ($s['emoji'] ?? '') . '</span>';
        $replacements[$code_escaped] = $r;
    }
    
    $parts = preg_split('/(<[^>]*>)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $i => &$part) {
        if ($i % 2 === 0) {
            $part = strtr($part, $replacements);
        }
    }
    return implode('', $parts);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    checkToken($_POST['csrf_token'] ?? '');
    $action = $_POST['ajax_action'];

    if ($action === 'translate_post') {
        if ($myId <= 0) { echo json_encode(['error' => 'not_logged_in']); exit; }
        if (!$ai_active) { echo json_encode(['error' => 'ai_disabled']); exit; }
        
        $post_id = (int)($_POST['post_id'] ?? 0);
        $target_lang = preg_replace('/[^a-zA-Z]/', '', $_POST['target_lang'] ?? $_SESSION['lang'] ?? 'en');

        $chk = $db->prepare("SELECT content FROM spike_posts WHERE id = ?");
        $chk->execute([$post_id]);
        $p = $chk->fetch();
        if (!$p) { echo json_encode(['error' => 'not_found']); exit; }

        $ai = new AiManager($db, $botSettings, $myId, $userPriv);
        $result = $ai->request('spike_admin', 'translate_post', [
            'original_text' => $p['content'],
            'target_lang'   => $target_lang,
            'instruction'   => "Translate this forum post to language code: {$target_lang}. Preserve all BBCode tags exactly. Do not add any conversational filler, return ONLY the translated text."
        ], ['save_suggestion' => false]);

        if ($result['status'] === 'ok') {
            $translated = $result['result']['suggestion'] ?? '';
            $parsed = parseBBCode($translated);
            if ($smilies_enabled) $parsed = spike_parse_smilies($parsed, $smilies);
            echo json_encode(['ok' => true, 'translation' => $parsed]);
        } else {
            echo json_encode(['error' => $result['message'] ?? 'Translation failed']);
        }
        exit;
    }

    if ($action === 'update_thread_title') {
        if ($myId <= 0) { echo json_encode(['error' => 'not_logged_in']); exit; }
        
        $chk = $db->prepare("SELECT author_id FROM spike_threads WHERE id = ?");
        $chk->execute([$thread_id]);
        $t_auth = $chk->fetchColumn();
        
        if ($t_auth === false || ((int)$t_auth !== $myId && $userPriv < 2)) {
            echo json_encode(['error' => 'unauthorized']); exit;
        }

        $new_title = trim($_POST['new_title'] ?? '');
        if (empty($new_title)) { echo json_encode(['error' => 'empty_title']); exit; }
        $new_title = mb_substr($new_title, 0, 120);

        $fw = spike_check_forbidden($new_title, $forbidden_words);
        if ($fw['blocked'] ?? false) { echo json_encode(['error' => 'forbidden_word']); exit; }
        $new_title = spike_replace_forbidden($new_title, $forbidden_words);

        $has_prefix = isset($_POST['prefix_id']);
        $new_prefix = $has_prefix ? (int)$_POST['prefix_id'] : null;
        if ($new_prefix === 0) $new_prefix = null;

        if ($has_prefix) {
            $db->prepare("UPDATE spike_threads SET title = ?, prefix_id = ? WHERE id = ?")->execute([$new_title, $new_prefix, $thread_id]);
        } else {
            $db->prepare("UPDATE spike_threads SET title = ? WHERE id = ?")->execute([$new_title, $thread_id]);
        }
        
        $ret_prefix = null;
        if ($has_prefix && $new_prefix) {
            $ps = $db->prepare("SELECT label, color, bg_color FROM spike_prefixes WHERE id = ?");
            $ps->execute([$new_prefix]);
            if ($row = $ps->fetch()) {
                $ret_prefix = ['label' => $row['label'], 'color' => $row['color'], 'bg_color' => $row['bg_color']];
            }
        }
        
        if ((int)$t_auth !== $myId) {
            try { aldhran_log("MOD_EDIT_TITLE", "Edited thread #$thread_id title/prefix", $myId); } catch(\Throwable $e){}
        }

        echo json_encode(['ok' => true, 'title' => h($new_title), 'raw_title' => $new_title, 'prefix' => $ret_prefix]); exit;
    }

    if ($action === 'undo_post') {
        if ($myId <= 0) { echo json_encode(['error' => 'not_logged_in']); exit; }
        $pid = (int)($_POST['post_id'] ?? 0);
        $chk = $db->prepare("SELECT author_id, created_at FROM spike_posts WHERE id = ?");
        $chk->execute([$pid]);
        $p = $chk->fetch();
        if ($p && (int)$p['author_id'] === $myId && (time() - strtotime($p['created_at'])) <= 20) {
            $db->prepare("DELETE FROM spike_posts WHERE id = ?")->execute([$pid]);
            $db->prepare("UPDATE users SET forum_posts = forum_posts - 1 WHERE id = ?")->execute([$myId]);
            $db->prepare("DELETE FROM spike_notifications WHERE post_id = ?")->execute([$pid]);
            echo json_encode(['ok' => true]); exit;
        }
        echo json_encode(['error' => 'timeout_or_unauthorized']); exit;
    }

    if ($action === 'undo_thread') {
        if ($myId <= 0) { echo json_encode(['error' => 'not_logged_in']); exit; }
        $tid = (int)($_POST['thread_id'] ?? 0);
        $chk = $db->prepare("SELECT author_id, created_at, board_id FROM spike_threads WHERE id = ?");
        $chk->execute([$tid]);
        $t = $chk->fetch();
        if ($t && (int)$t['author_id'] === $myId && (time() - strtotime($t['created_at'])) <= 20) {
            $db->prepare("DELETE FROM spike_posts WHERE thread_id = ?")->execute([$tid]);
            $db->prepare("DELETE FROM spike_threads WHERE id = ?")->execute([$tid]);
            $db->prepare("UPDATE users SET forum_posts = forum_posts - 1 WHERE id = ?")->execute([$myId]);
            $db->prepare("DELETE FROM spike_notifications WHERE thread_id = ?")->execute([$tid]);
            if ($polls_enabled) {
                $db->prepare("DELETE FROM spike_poll_votes WHERE poll_id IN (SELECT id FROM spike_polls WHERE thread_id = ?)")->execute([$tid]);
                $db->prepare("DELETE FROM spike_poll_options WHERE poll_id IN (SELECT id FROM spike_polls WHERE thread_id = ?)")->execute([$tid]);
                $db->prepare("DELETE FROM spike_polls WHERE thread_id = ?")->execute([$tid]);
            }
            echo json_encode(['ok' => true, 'board_id' => $t['board_id']]); exit;
        }
        echo json_encode(['error' => 'timeout_or_unauthorized']); exit;
    }

    if ($action === 'report_post') {
        if ($myId <= 0) { echo json_encode(['error' => 'not_logged_in']); exit; }
        $post_id = (int)($_POST['post_id'] ?? 0);
        $reason  = $_POST['reason'] ?? 'other';
        $details = trim($_POST['details'] ?? '');

        $chk = $db->prepare("SELECT id FROM spike_reports WHERE post_id = ? AND reporter_id = ?");
        $chk->execute([$post_id, $myId]);
        if ($chk->fetch()) { echo json_encode(['error' => 'already_reported']); exit; }

        $db->prepare("INSERT INTO spike_reports (post_id, thread_id, reporter_id, reason, details, status) VALUES (?, ?, ?, ?, ?, 'open')")
           ->execute([$post_id, $thread_id, $myId, $reason, $details]);
        echo json_encode(['ok' => true]); exit;
    }

    if ($action === 'toggle_reaction') {
        if ($myId <= 0) { echo json_encode(['error' => 'not_logged_in']); exit; }
        $post_id = (int)($_POST['post_id'] ?? 0);
        $emoji   = $_POST['emoji'] ?? '';
        
        $chk = $db->prepare("SELECT id FROM spike_reactions WHERE post_id = ? AND user_id = ? AND emoji = ?");
        $chk->execute([$post_id, $myId, $emoji]);
        $existing = $chk->fetch();
        
        if ($existing) {
            $db->prepare("DELETE FROM spike_reactions WHERE id = ?")->execute([$existing['id']]);
            $added = false;
        } else {
            $db->prepare("INSERT INTO spike_reactions (post_id, user_id, emoji) VALUES (?, ?, ?)")->execute([$post_id, $myId, $emoji]);
            $added = true;
        }
        $cnt = $db->prepare("SELECT COUNT(*) FROM spike_reactions WHERE post_id = ? AND emoji = ?");
        $cnt->execute([$post_id, $emoji]);
        echo json_encode(['ok' => true, 'added' => $added, 'count' => (int)$cnt->fetchColumn()]); exit;
    }

    if ($action === 'toggle_subscription') {
        if ($myId <= 0) { echo json_encode(['error' => 'not_logged_in']); exit; }
        $chk = $db->prepare("SELECT id FROM spike_subscriptions WHERE user_id = ? AND thread_id = ?");
        $chk->execute([$myId, $thread_id]);
        $existing = $chk->fetch();
        if ($existing) {
            $db->prepare("DELETE FROM spike_subscriptions WHERE id = ?")->execute([$existing['id']]);
            $sub = false;
        } else {
            $db->prepare("INSERT INTO spike_subscriptions (user_id, thread_id) VALUES (?, ?)")->execute([$myId, $thread_id]);
            $sub = true;
        }
        echo json_encode(['ok' => true, 'subscribed' => $sub]); exit;
    }

    if ($action === 'get_edit_history') {
        $post_id = (int)($_POST['post_id'] ?? 0);
        $stmt = $db->prepare("
            SELECT e.old_content, e.edit_reason, e.created_at, u.username as editor
            FROM spike_post_edits e
            LEFT JOIN users u ON e.editor_id = u.id
            WHERE e.post_id = ?
            ORDER BY e.created_at DESC
        ");
        $stmt->execute([$post_id]);
        $hist = [];
        foreach ($stmt->fetchAll() as $r) {
            $hist[] = [
                'date'   => date("d.m.Y H:i", strtotime($r['created_at'])),
                'editor' => $r['editor'] ?? 'Unknown',
                'reason' => h($r['edit_reason'])
            ];
        }
        echo json_encode(['ok' => true, 'history' => $hist]); exit;
    }

    if ($action === 'poll_vote') {
        if ($myId <= 0) { echo json_encode(['error' => 'not_logged_in']); exit; }
        $option_ids = $_POST['option_ids'] ?? [];
        if (empty($option_ids)) { echo json_encode(['error' => 'no_options']); exit; }
        
        $s = $db->prepare("SELECT id, multi FROM spike_polls WHERE thread_id = ? LIMIT 1");
        $s->execute([$thread_id]);
        $poll = $s->fetch();
        if (!$poll) { echo json_encode(['error' => 'no_poll']); exit; }
        
        if (!$poll['multi'] && count($option_ids) > 1) {
            $option_ids = [$option_ids[0]];
        }
        
        $chk = $db->prepare("SELECT id FROM spike_poll_votes WHERE poll_id = ? AND user_id = ?");
        $chk->execute([$poll['id'], $myId]);
        if ($chk->fetch()) { echo json_encode(['error' => 'already_voted']); exit; }
        
        $ins = $db->prepare("INSERT INTO spike_poll_votes (poll_id, option_id, user_id) VALUES (?, ?, ?)");
        foreach ($option_ids as $oid) {
            $ins->execute([$poll['id'], (int)$oid, $myId]);
        }
        echo json_encode(['ok' => true]); exit;
    }

    echo json_encode(['error' => 'unknown_action']); exit;
}

$stmt_t = $db->prepare("
    SELECT t.*, b.title as board_title, b.id as board_id, b.slug as board_slug,
           b.min_priv as board_min_view, b.min_priv_post as board_min_post,
           c.min_priv as cat_min_view,   c.min_priv_post as cat_min_post, c.title as cat_title,
           p.label as prefix_label, p.color as prefix_color, p.bg_color as prefix_bg
    FROM spike_threads t
    JOIN spike_boards b     ON t.board_id = b.id
    JOIN spike_categories c ON b.cat_id   = c.id
    LEFT JOIN spike_prefixes p ON t.prefix_id = p.id AND p.is_active = 1
    WHERE t.id = ?
");
$stmt_t->execute([$thread_id]);
$thread = $stmt_t->fetch();
if (!$thread) { header("Location: index.php?p=spike&err=not_found"); exit; }

$chk_u = $db->prepare("SELECT id FROM users WHERE id=?");
$chk_u->execute([$thread['author_id']]);
if (!$chk_u->fetch() && $userPriv < 2) {
    header("Location: index.php?p=viewboard&id=".$thread['board_id']."&err=not_found"); exit;
}

if (isset($thread['is_approved']) && (int)$thread['is_approved'] === 0 && (int)$thread['author_id'] !== $myId && $userPriv < 2) {
    header("Location: index.php?p=viewboard&id=" . $thread['board_id'] . "&err=pending_approval"); exit;
}

if (empty($thread['slug'])) {
    $gen = trim(preg_replace('/[^a-z0-9]+/','-',strtolower($thread['title'])),'-') . '-' . $thread_id;
    try { $db->prepare("UPDATE spike_threads SET slug=? WHERE id=?")->execute([$gen,$thread_id]); } catch(\Throwable $e){}
    $thread['slug'] = $gen;
}
if (empty($thread['board_slug'])) {
    $gen = trim(preg_replace('/[^a-z0-9]+/','-',strtolower($thread['board_title'])),'-') . '-' . $thread['board_id'];
    try { $db->prepare("UPDATE spike_boards SET slug=? WHERE id=?")->execute([$gen,$thread['board_id']]); } catch(\Throwable $e){}
    $thread['board_slug'] = $gen;
}

$effective_min_view = ($thread['board_min_view']>0) ? (int)$thread['board_min_view'] : (int)$thread['cat_min_view'];
$effective_min_post = ($thread['board_min_post']>0) ? (int)$thread['board_min_post'] : (int)$thread['cat_min_post'];

if ($userPriv < 4 && $userPriv < $effective_min_view) {
    header("Location: index.php?p=viewboard&id=".$thread['board_id']."&err=no_access"); exit;
}

$first_post_s = $db->prepare("SELECT id, is_deleted FROM spike_posts WHERE thread_id=? ORDER BY created_at ASC LIMIT 1");
$first_post_s->execute([$thread_id]);
$first_post   = $first_post_s->fetch();
$first_post_id = $first_post ? (int)$first_post['id'] : 0;

if ($first_post && !empty($first_post['is_deleted']) && $userPriv < 5) {
    header("Location: index.php?p=viewboard&id=".$thread['board_id']."&err=not_found"); exit;
}

$deleted_filter     = ($userPriv < 5) ? " AND (p.is_deleted IS NULL OR p.is_deleted = 0)" : "";
$del_user_filter    = ($userPriv < 2) ? " AND (u.id IS NOT NULL AND p.author_id > 0)" : "";

$total_posts_s = $db->prepare("SELECT COUNT(p.id) FROM spike_posts p LEFT JOIN users u ON p.author_id = u.id WHERE p.thread_id=?" . $deleted_filter . $block_filter_p . $del_user_filter);
$total_posts_s->execute([$thread_id]);
$total_posts  = (int)$total_posts_s->fetchColumn();
$total_pages  = max(1, (int)ceil($total_posts / SPIKE_POSTS_PER_PAGE));
$current_page = min($current_page, $total_pages);
$offset       = ($current_page - 1) * SPIKE_POSTS_PER_PAGE;
$per_page     = SPIKE_POSTS_PER_PAGE;

if (isset($_GET['ajax_posts'])) {
    header('Content-Type: application/json');

    $ajax_page = max(1, (int)($_GET['page'] ?? 1));
    $ajax_page = min($ajax_page, $total_pages);
    $ajax_offset = ($ajax_page - 1) * $per_page;

    $available_reactions = [
        'thanks'=>['emoji'=>'👍','label'=>'Thanks'],
        'haha'  =>['emoji'=>'😄','label'=>'Haha'],
        'love'  =>['emoji'=>'❤️', 'label'=>'Love'],
        'wow'   =>['emoji'=>'😮','label'=>'Wow'],
        'sad'   =>['emoji'=>'😢','label'=>'Sad'],
        'angry' =>['emoji'=>'😡','label'=>'Angry'],
    ];

    try {
        $stmt = $db->prepare("
            SELECT p.*, u.username, u.avatar_url, u.user_title, u.standing,
                   u.forum_posts, u.forum_signature, u.priv_level,
                   ue.username as editor_username
            FROM spike_posts p
            LEFT JOIN users u  ON p.author_id = u.id
            LEFT JOIN users ue ON p.edited_by = ue.id
            WHERE p.thread_id = ?
              " . $deleted_filter . "
              " . $block_filter_p . "
              " . $del_user_filter . "
            ORDER BY p.created_at ASC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$thread_id, $per_page, $ajax_offset]);
        $ajax_posts = $stmt->fetchAll();

        if (empty($ajax_posts)) {
            echo json_encode(['ok' => true, 'html' => '', 'is_last' => true]);
            exit;
        }

        $post_ids = array_column($ajax_posts, 'id');
        $pl = implode(',', array_fill(0, count($post_ids), '?'));
        
        $rs = $db->prepare("
            SELECT emoji, post_id, COUNT(*) AS cnt,
                   MAX(CASE WHEN user_id=? THEN 1 ELSE 0 END) AS mine
            FROM spike_reactions WHERE post_id IN ($pl)
            GROUP BY emoji, post_id
        ");
        $rs->execute(array_merge([$myId], $post_ids));
        $rxp = [];
        foreach ($rs->fetchAll() as $r) {
            $rxp[$r['post_id']][$r['emoji']] = ['cnt'=>(int)$r['cnt'],'mine'=>(bool)$r['mine']];
        }

        $attachments_by_post = [];
        if ($attachments_enabled) {
            $s_att = $db->prepare("SELECT * FROM spike_attachments WHERE post_id IN ($pl) ORDER BY created_at ASC");
            $s_att->execute($post_ids);
            foreach ($s_att->fetchAll() as $att) {
                $attachments_by_post[$att['post_id']][] = $att;
            }
        }

        $csrf_token = generateToken();
        $thread_url = "index.php?p=viewthread&slug=" . urlencode($thread['slug'] ?? '');

        if (!function_exists('renderRankStars')) {
            function renderRankStars($privLevel) {
                $privLevel = (int)$privLevel; $count=0;
                if ($privLevel===1) $count=1; elseif ($privLevel===2) $count=2;
                elseif ($privLevel===3) $count=5; elseif ($privLevel===4) $count=6; elseif ($privLevel>=5) $count=7;
                if ($count===0) return '';
                $o='<div class="vt-rank-stars"><div style="color:var(--glow-gold);font-size:0.5em;letter-spacing:1px;opacity:0.6;">';
                for ($i=0;$i<$count;$i++) $o.='<i class="fas fa-star"></i>';
                return $o.'</div></div>';
            }
        }

        ob_start();
        foreach ($ajax_posts as $p):
            $is_author        = $myId > 0 && $myId == $p['author_id'];
            $can_edit         = ($myId > 0 && $myStanding < 3 && $userPriv >= $effective_min_post && $is_author && $is_verified === 1) || $userPriv >= 3;
            $post_reactions   = $rxp[$p['id']] ?? [];
            $post_attachments = $attachments_by_post[$p['id']] ?? [];
        ?>
        <div class="vt-post" id="post-<?= (int)$p['id'] ?>" style="animation:vt-post-fadein 0.3s ease forwards;">
            <div class="vt-user">
                <?php if (!empty($p['avatar_url'])): ?>
                    <img src="<?= h(ltrim($p['avatar_url'], '/')) ?>" class="vt-avatar" alt="">
                <?php else: ?>
                    <div class="vt-avatar" style="background:#070707;display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-user" style="color:#252525;font-size:1.4em;"></i>
                    </div>
                <?php endif; ?>
                <div class="vt-username" style="color:<?= ((int)($p['priv_level']??0)>=5)?'#ff4444':'var(--glow-blue)' ?>;">
                    <?= h($p['username'] ?? t('viewthread.deleted_user', [], 'Deleted User')) ?>
                </div>
                <div class="vt-usertitle"><?= h($p['user_title']??'') ?></div>
                <?= renderRankStars($p['priv_level']??0) ?>
                <div class="vt-postcount"><?= t('viewthread.label_posts',[],'Posts') ?>: <span><?= (int)($p['forum_posts']??0) ?></span></div>
            </div>

            <div class="vt-body">
                <div class="vt-meta">
                    <span class="vt-meta-date"><?= date("d.m.Y – H:i",strtotime($p['created_at'])) ?></span>

                    <?php if (!empty($p['edited_at'])): ?>
                    <span class="vt-edited-tag" onclick="toggleEditHistory(<?= (int)$p['id'] ?>)" title="<?= t('viewthread.show_edit_history',[],'Show edit history') ?>">
                        <i class="fas fa-pencil-alt"></i>
                        <?= t('viewthread.edited_label',[],'Edited') ?>
                        <?php if ($userPriv>=5): ?><?= (int)$p['edit_count'] ?>× <?= t('viewthread.edited_by',[],'by') ?> <?= h($p['editor_username']??'Staff') ?> · <?= date("d.m.Y H:i",strtotime($p['edited_at'])) ?><?php endif; ?>
                    </span>
                    <?php endif; ?>

                    <?php $sp_url = "index.php?p=viewthread&slug=" . urlencode($thread['slug'] ?? '') . "&pid=" . (int)$p['id'] . "#post-" . (int)$p['id']; ?>
                    <a href="<?= $sp_url ?>" class="vt-meta-btn" title="<?= t('viewthread.copy_link',[],'Copy Link to this Post') ?>" style="opacity:0.4;" onclick="spkCopyLink(event, '<?= $sp_url ?>')">
                        <i class="fas fa-link"></i>
                    </a>

                    <?php if ($myId>0 && !$is_author): ?>
                    <button class="vt-meta-btn vt-meta-report" onclick="openReport(<?= (int)$p['id'] ?>)">
                        <i class="fas fa-flag"></i>
                        <span class="hide-mobile"><?= t('viewthread.report_post',[],'Report') ?></span>
                    </button>
                    <?php endif; ?>

                    <?php if ($userPriv>=2): ?>
                    <form method="POST" action="<?= $thread_url ?>" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="mod_action" value="delete_post">
                        <input type="hidden" name="post_id"    value="<?= (int)$p['id'] ?>">
                        <button type="submit" class="vt-meta-btn vt-meta-del"
                                onclick="return confirm('<?= addslashes(t('viewthread.confirm_delete_post',[],'Delete this post?')) ?>')">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </form>
                    <?php endif; ?>

                    <?php if ($can_edit): ?>
                    <button class="vt-meta-btn vt-meta-edit" onclick="openEditBox(<?= (int)$p['id'] ?>)">
                        <i class="fas fa-edit"></i>
                        <span class="hide-mobile"><?= t('viewthread.btn_edit',[],'Edit') ?></span>
                    </button>
                    <?php endif; ?>
                    
                    <?php if ($ai_active && $myId > 0): ?>
                    <div style="position:relative; display:inline-block;">
                        <button class="vt-meta-btn vt-meta-translate" onclick="toggleTranslatePicker(<?= (int)$p['id'] ?>)">
                            <i class="fas fa-language"></i>
                            <span class="hide-mobile"><?= t('viewthread.btn_translate',[],'Translate') ?></span>
                        </button>
                        <div class="vt-translate-picker" id="trans-picker-<?= (int)$p['id'] ?>" style="display:none; position:absolute; bottom:100%; left:0; margin-bottom:5px; background:#0a0a0a; border:1px solid #1a1a1a; padding:6px; border-radius:4px; z-index:100; gap:6px; white-space:nowrap; box-shadow:0 5px 15px rgba(0,0,0,0.6);">
                            <button type="button" onclick="executeTranslation(<?= (int)$p['id'] ?>, 'en')" style="background:transparent; border:1px solid #333; color:#ccc; cursor:pointer; padding:4px 10px; border-radius:2px; font-family:'Cinzel',serif; transition:0.2s;" onmouseover="this.style.background='rgba(197,160,89,0.1)';this.style.borderColor='#c5a059';this.style.color='#c5a059';" onmouseout="this.style.background='transparent';this.style.borderColor='#333';this.style.color='#ccc';">EN</button>
                            <button type="button" onclick="executeTranslation(<?= (int)$p['id'] ?>, 'de')" style="background:transparent; border:1px solid #333; color:#ccc; cursor:pointer; padding:4px 10px; border-radius:2px; font-family:'Cinzel',serif; transition:0.2s;" onmouseover="this.style.background='rgba(197,160,89,0.1)';this.style.borderColor='#c5a059';this.style.color='#c5a059';" onmouseout="this.style.background='transparent';this.style.borderColor='#333';this.style.color='#ccc';">DE</button>
                            <button type="button" onclick="executeTranslation(<?= (int)$p['id'] ?>, 'fr')" style="background:transparent; border:1px solid #333; color:#ccc; cursor:pointer; padding:4px 10px; border-radius:2px; font-family:'Cinzel',serif; transition:0.2s;" onmouseover="this.style.background='rgba(197,160,89,0.1)';this.style.borderColor='#c5a059';this.style.color='#c5a059';" onmouseout="this.style.background='transparent';this.style.borderColor='#333';this.style.color='#ccc';">FR</button>
                            <button type="button" onclick="executeTranslation(<?= (int)$p['id'] ?>, 'es')" style="background:transparent; border:1px solid #333; color:#ccc; cursor:pointer; padding:4px 10px; border-radius:2px; font-family:'Cinzel',serif; transition:0.2s;" onmouseover="this.style.background='rgba(197,160,89,0.1)';this.style.borderColor='#c5a059';this.style.color='#c5a059';" onmouseout="this.style.background='transparent';this.style.borderColor='#333';this.style.color='#ccc';">ES</button>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($myId>0 && $myStanding<3 && $userPriv>=$effective_min_post && !$thread['is_locked'] && $is_verified === 1): ?>
                    <button class="vt-meta-btn vt-meta-quote" onclick="quotePost('<?= addslashes($p['username'] ?? t('viewthread.deleted_user', [], 'Deleted User')) ?>','post-content-<?= (int)$p['id'] ?>')">
                        <i class="fas fa-quote-left"></i>
                        <span class="hide-mobile"><?= t('viewthread.btn_quote',[],'Quote') ?></span>
                    </button>
                    <?php endif; ?>
                </div>

                <?php if (!empty($p['edited_at'])): ?>
                <div class="vt-history-box" id="hist-<?= (int)$p['id'] ?>">
                    <div style="font-size:0.76em;color:#333;margin-bottom:3px;"><i class="fas fa-history"></i> <?= t('viewthread.edit_history',[],'Edit History') ?></div>
                    <div id="hist-content-<?= (int)$p['id'] ?>"><span style="color:#282828;font-style:italic;"><?= t('viewthread.loading',[],'Loading…') ?></span></div>
                </div>
                <?php endif; ?>

                <div id="edit-box-<?= (int)$p['id'] ?>" class="vt-edit-box">
                    <form method="POST" action="<?= $thread_url ?>"
                          onsubmit="if(editQuillers[<?=(int)$p['id']?>])document.getElementById('edit-content-<?=(int)$p['id']?>').value=editQuillers[<?=(int)$p['id']?>].root.innerHTML;">
                        <input type="hidden" name="csrf_token"   value="<?= $csrf_token ?>">
                        <input type="hidden" name="post_id"      value="<?= (int)$p['id'] ?>">
                        <div class="quill-editor-wrap" style="margin-bottom:6px;">
                            <div id="edit-quill-<?= (int)$p['id'] ?>"></div>
                        </div>
                        <input type="hidden" name="edit_content" id="edit-content-<?= (int)$p['id'] ?>">
                        <input type="text" name="edit_reason"
                               placeholder="<?= t('viewthread.edit_reason_placeholder',[],'Reason for edit (optional)') ?>"
                               style="width:100%;background:#040404;border:1px solid #0c0c0c;color:#666;padding:6px 8px;font-size:0.74em;margin-bottom:6px;outline:none;box-sizing:border-box;">
                        <div style="display:flex;gap:6px;">
                            <button type="submit" name="submit_edit" value="1" class="spike-editor-btn spike-editor-btn--save" style="padding:5px 12px;font-size:0.56em;">
                                <?= t('viewthread.btn_save',[],'Save') ?>
                            </button>
                            <button type="button" onclick="closeEditBox(<?= (int)$p['id'] ?>)" class="spike-editor-btn spike-editor-btn--cancel" style="padding:5px 10px;font-size:0.56em;">
                                <?= t('viewthread.btn_cancel',[],'Cancel') ?>
                            </button>
                        </div>
                    </form>
                </div>

                <div class="vt-content" id="post-content-<?= (int)$p['id'] ?>">
                    <?php $content=parseBBCode($p['content']); if($smilies_enabled) $content=spike_parse_smilies($content,$smilies); echo $content; ?>
                </div>

                <?php if (!empty($post_attachments)): ?>
                <div class="vt-attachments">
                    <div class="vt-attach-label"><i class="fas fa-paperclip"></i> <?= t('viewthread.attachments',[],'Attachments') ?></div>
                    <?php foreach ($post_attachments as $att): $is_img=strpos($att['mime_type'],'image')!==false; ?>
                    <?php if ($is_img): ?>
                    <div class="vt-attach-img-wrap">
                        <a href="index.php?p=download_attachment&id=<?= (int)$att['id'] ?>" target="_blank">
                            <img src="index.php?p=download_attachment&id=<?= (int)$att['id'] ?>" class="vt-attach-img"
                                 alt="<?= h($att['filename']) ?>" title="<?= h($att['filename']) ?> (<?= round($att['filesize']/1024,1) ?> KB)">
                        </a>
                    </div>
                    <?php else: ?>
                    <a href="index.php?p=download_attachment&id=<?= (int)$att['id'] ?>" class="vt-attach-item">
                        <i class="fas fa-file" style="opacity:0.4;"></i>
                        <?= h($att['filename']) ?>
                        <span style="font-size:0.84em;">(<?= round($att['filesize']/1024,1) ?> KB)</span>
                        <span style="font-size:0.78em;opacity:0.6;"><i class="fas fa-download"></i> <?= (int)$att['downloads'] ?></span>
                    </a>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($p['forum_signature'])): ?>
                <div class="vt-sig"><?= parseBBCode($p['forum_signature']) ?></div>
                <?php endif; ?>

                <?php if ($reactions_enabled): ?>
                <div class="vt-reactions">
                    <?php foreach ($post_reactions as $emoji=>$data): ?>
                    <button class="vt-reaction-btn <?= $data['mine']?'mine':'' ?>"
                            onclick="toggleReaction(<?= (int)$p['id'] ?>,'<?= $emoji ?>',this)"
                            data-emoji="<?= $emoji ?>">
                        <?= $available_reactions[$emoji]['emoji']??$emoji ?>
                        <span class="cnt"><?= $data['cnt'] ?></span>
                    </button>
                    <?php endforeach; ?>
                    <?php if ($myId>0): ?>
                    <div style="position:relative;">
                        <button class="vt-reaction-add" onclick="toggleReactionPicker(<?= (int)$p['id'] ?>)"><i class="fas fa-smile-plus"></i></button>
                        <div class="vt-reaction-picker" id="picker-<?= (int)$p['id'] ?>">
                            <?php foreach ($available_reactions as $key=>$r): ?>
                            <button onclick="toggleReaction(<?=(int)$p['id']?>,'<?=$key?>',null);closeAllPickers();" title="<?=$r['label']?>"><?=$r['emoji']?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach;
        $html = ob_get_clean();

        $count_stmt = $db->prepare("SELECT COUNT(p.id) FROM spike_posts p LEFT JOIN users u ON p.author_id = u.id WHERE p.thread_id=? AND (p.is_deleted IS NULL OR p.is_deleted=0)" . $block_filter_p . $del_user_filter);
        $count_stmt->execute([$thread_id]);
        $total_count = (int)$count_stmt->fetchColumn();
        $is_last     = ($ajax_offset + count($ajax_posts)) >= $total_count;

        echo json_encode([
            'ok'      => true,
            'html'    => $html,
            'page'    => $ajax_page,
            'is_last' => $is_last,
            'count'   => count($ajax_posts),
        ]);
    } catch (\Throwable $e) {
        error_log("[spike_infinite] " . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'load_failed']);
    }
    exit;
}

$single_post_id = (isset($_POST['submit_edit']) || isset($_POST['submit_reply'])) ? 0 : (int)($_GET['pid'] ?? 0);

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['submit_reply'])) {
    checkToken($_POST['csrf_token']??'');
    $slug_url = urlencode($thread['slug']);

    if (!($myId>0 && $myStanding<3 && !$thread['is_locked'] && $userPriv>=$effective_min_post && $is_verified === 1)) {
        header("Location: index.php?p=viewthread&slug=$slug_url&err=unauthorized_post"); exit;
    }

    $content = trim($_POST['reply_content'] ?? '');
    if (empty($content)) {
        $content = trim($_POST['reply_content_raw'] ?? '');
    }

    if (empty($content) || (empty(trim(strip_tags($content))) && strpos($content, '<img') === false)) {
        header("Location: index.php?p=viewthread&slug=$slug_url&err=empty_post"); exit;
    }

    if ($userPriv < 3) {
        $cooldown_limit = 120;
        $sl=$db->prepare("SELECT created_at FROM spike_posts WHERE author_id=? ORDER BY created_at DESC LIMIT 1");
        $sl->execute([$myId]); $lp=$sl->fetch();
        if ($lp && (time()-strtotime($lp['created_at']))<$cooldown_limit) {
            $wait=$cooldown_limit-(time()-strtotime($lp['created_at']));
            header("Location: index.php?p=viewthread&slug=$slug_url&err=spam_cooldown&wait=$wait"); exit;
        }
    }
    if ($userPriv<$min_auth_links && preg_match('/(http|https|www)/i',$content)) {
        header("Location: index.php?p=viewthread&slug=$slug_url&err=no_links_allowed"); exit;
    }
    $fw=spike_check_forbidden($content,$forbidden_words);
    if ($fw['blocked']??false) { header("Location: index.php?p=viewthread&slug=$slug_url&err=forbidden_word"); exit; }
    $content = spike_replace_forbidden($content,$forbidden_words);

    try {
        $db->beginTransaction();
        $db->prepare("INSERT INTO spike_posts (thread_id,author_id,content) VALUES (?,?,?)")->execute([$thread_id,$myId,$content]);
        $new_pid=(int)$db->lastInsertId();
        
        if (strpos($content, 'data:image') !== false) {
            $content = spike_process_inline_images($content, $attach_path, $attach_path_raw, $max_attach_size);
            $db->prepare("UPDATE spike_posts SET content = ? WHERE id = ?")->execute([$content, $new_pid]);
        }

        $db->prepare("UPDATE users SET forum_posts=forum_posts+1 WHERE id=?")->execute([$myId]);

        try {
            require_once __DIR__ . '/spike_notification_helper.php';
            spike_process_mentions($db, $new_pid, $thread_id, $myId, $content);
        } catch (\Throwable $e) { error_log("Spike mention notify: " . $e->getMessage()); }

        try {
            if ((int)$thread['author_id'] !== $myId)
                $db->prepare("INSERT INTO spike_notifications (user_id,source_user_id,thread_id,post_id,type) VALUES (?,?,?,?,?)")
                   ->execute([$thread['author_id'],$myId,$thread_id,$new_pid,'reply']);
            $subs=$db->prepare("SELECT user_id FROM spike_subscriptions WHERE thread_id=? AND user_id!=?");
            $subs->execute([$thread_id,$myId]);
            $ni=$db->prepare("INSERT IGNORE INTO spike_notifications (user_id,source_user_id,thread_id,post_id,type) VALUES (?,?,?,?,?)");
            foreach($subs->fetchAll(PDO::FETCH_COLUMN) as $su) {
                if ($su != $thread['author_id']) $ni->execute([$su,$myId,$thread_id,$new_pid,'subscription']);
            }
        } catch(\Throwable $e){ error_log("Spike notifications: ".$e->getMessage()); }

        $db->commit();
        if ($attachments_enabled) spike_process_attachments($db,$new_pid,$myId,$attach_path,$attach_path_raw,$max_attach_size,$allowed_mimes);
        $lp=(int)ceil(($total_posts+1)/SPIKE_POSTS_PER_PAGE);
        $r="index.php?p=viewthread&slug=$slug_url".($lp>1?"&page=$lp":'')."&msg=replied&undo_pid=$new_pid#post-$new_pid";
        header("Location: $r"); exit;
    } catch(\Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("Spike Reply: ".$e->getMessage());
        header("Location: index.php?p=viewthread&slug=$slug_url&err=db_error"); exit;
    }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['submit_edit'])) {
    checkToken($_POST['csrf_token']??'');
    $slug_url = urlencode($thread['slug']);
    $pid      = (int)($_POST['post_id']??0);
    $content  = trim($_POST['edit_content']??'');
    $reason   = trim(substr($_POST['edit_reason']??'',0,255));
    if (!$pid || (empty($content) || (empty(trim(strip_tags($content))) && strpos($content, '<img') === false))) {
        header("Location: index.php?p=viewthread&slug=$slug_url&err=empty_post#post-$pid"); exit;
    }
    $chk=$db->prepare("SELECT author_id,content FROM spike_posts WHERE id=? LIMIT 1");
    $chk->execute([$pid]); $pr=$chk->fetch();
    
    if (!$pr || (($pr['author_id'] != $myId || $userPriv < $effective_min_post || $is_verified !== 1) && $userPriv < 2)) {
        header("Location: index.php?p=viewthread&slug=$slug_url&err=unauthorized_edit"); exit;
    }
    
    $fw=spike_check_forbidden($content,$forbidden_words);
    if ($fw['blocked']??false) { header("Location: index.php?p=viewthread&slug=$slug_url&err=forbidden_word"); exit; }
    $content=spike_replace_forbidden($content,$forbidden_words);
    
    if (strpos($content, 'data:image') !== false) {
        $content = spike_process_inline_images($content, $attach_path, $attach_path_raw, $max_attach_size);
    }
    
    try {
        $db->beginTransaction();
        if (($spike_cfg['edit_history_enabled']??'1')==='1')
            $db->prepare("INSERT INTO spike_post_edits (post_id,editor_id,old_content,edit_reason) VALUES (?,?,?,?)")->execute([$pid,$myId,$pr['content'],$reason]);
        $db->prepare("UPDATE spike_posts SET content=?,edited_at=NOW(),edited_by=?,edit_count=edit_count+1 WHERE id=?")->execute([$content,$myId,$pid]);
        $db->commit();
        header("Location: index.php?p=viewthread&slug=$slug_url&msg=edited#post-$pid"); exit;
    } catch(\Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("Spike Edit: ".$e->getMessage());
        header("Location: index.php?p=viewthread&slug=$slug_url&err=db_error"); exit;
    }
}

if ($single_post_id > 0) {
    $stmt_p = $db->prepare("
        SELECT p.*, u.username, u.avatar_url, u.user_title, u.standing,
               u.forum_posts, u.forum_signature, u.priv_level,
               ue.username as editor_username
        FROM spike_posts p
        LEFT JOIN users u  ON p.author_id = u.id
        LEFT JOIN users ue ON p.edited_by = ue.id
        WHERE p.thread_id = ? AND p.id = ?
        " . $deleted_filter . $block_filter_p . $del_user_filter . "
    ");
    $stmt_p->execute([$thread_id, $single_post_id]);
} else {
    $stmt_p = $db->prepare("
        SELECT p.*, u.username, u.avatar_url, u.user_title, u.standing,
               u.forum_posts, u.forum_signature, u.priv_level,
               ue.username as editor_username
        FROM spike_posts p
        LEFT JOIN users u  ON p.author_id = u.id
        LEFT JOIN users ue ON p.edited_by = ue.id
        WHERE p.thread_id = ?
        " . $deleted_filter . $block_filter_p . $del_user_filter . "
        ORDER BY p.created_at ASC
        LIMIT ? OFFSET ?
    ");
    $stmt_p->execute([$thread_id, SPIKE_POSTS_PER_PAGE, $offset]);
}
$posts    = $stmt_p->fetchAll();
$post_ids = array_column($posts, 'id');

$reactions_by_post = [];
if ($reactions_enabled && !empty($post_ids)) {
    $rpl = implode(',', array_fill(0, count($post_ids), '?'));
    $s   = $db->prepare("SELECT r.post_id,r.emoji,COUNT(*) as cnt,MAX(CASE WHEN r.user_id=? THEN 1 ELSE 0 END) as i_reacted FROM spike_reactions r WHERE r.post_id IN ($rpl) GROUP BY r.post_id,r.emoji");
    $s->execute(array_merge([$myId], $post_ids));
    foreach ($s->fetchAll() as $row) {
        $reactions_by_post[$row['post_id']][$row['emoji']] = ['cnt'=>(int)$row['cnt'],'mine'=>(bool)$row['i_reacted']];
    }
}

$attachments_by_post = [];
if ($attachments_enabled && !empty($post_ids)) {
    $apl = implode(',', array_fill(0, count($post_ids), '?'));
    $s   = $db->prepare("SELECT * FROM spike_attachments WHERE post_id IN ($apl) ORDER BY created_at ASC");
    $s->execute($post_ids);
    foreach ($s->fetchAll() as $att) { $attachments_by_post[$att['post_id']][] = $att; }
}

$poll = null; $poll_options = []; $poll_votes_by_option = []; $my_poll_votes = [];
if ($polls_enabled && $current_page === 1) {
    try {
        $s = $db->prepare("SELECT * FROM spike_polls WHERE thread_id=? LIMIT 1");
        $s->execute([$thread_id]);
        $poll = $s->fetch();
        if ($poll && !empty($poll['id'])) {
            $s2 = $db->prepare("SELECT * FROM spike_poll_options WHERE poll_id=? ORDER BY pos ASC");
            $s2->execute([$poll['id']]); $poll_options = $s2->fetchAll();
            $s3 = $db->prepare("SELECT option_id,COUNT(*) as cnt,MAX(CASE WHEN user_id=? THEN 1 ELSE 0 END) as i_voted FROM spike_poll_votes WHERE poll_id=? GROUP BY option_id");
            $s3->execute([$myId,$poll['id']]);
            foreach ($s3->fetchAll() as $pv) {
                $poll_votes_by_option[$pv['option_id']] = (int)$pv['cnt'];
                if ($pv['i_voted']) $my_poll_votes[] = $pv['option_id'];
            }
        }
    } catch (\Throwable $e) {}
}

$is_subscribed = false;
if ($myId > 0) {
    try {
        $s = $db->prepare("SELECT id FROM spike_subscriptions WHERE user_id=? AND thread_id=? LIMIT 1");
        $s->execute([$myId,$thread_id]);
        $is_subscribed = (bool)$s->fetch();
    } catch (\Throwable $e) {}
}

$available_prefixes = [];
try { $available_prefixes = $db->query("SELECT * FROM spike_prefixes WHERE is_active=1 ORDER BY pos ASC")->fetchAll(); }
catch (\Throwable $e) {}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['mod_action']) && $userPriv>=2) {
    checkToken($_POST['csrf_token']??'');
    $slug_url = urlencode($thread['slug']);
    $action   = $_POST['mod_action'];

    if ($action==='delete_thread' && $userPriv>=4) {
        $db->beginTransaction();
        foreach(['spike_posts','spike_subscriptions','spike_reports'] as $tbl)
            $db->prepare("DELETE FROM $tbl WHERE thread_id=?")->execute([$thread_id]);
        $db->prepare("DELETE FROM spike_threads WHERE id=?")->execute([$thread_id]);
        if ($polls_enabled&&$poll) {
            foreach(['spike_poll_votes','spike_poll_options'] as $tbl)
                $db->prepare("DELETE FROM $tbl WHERE poll_id=?")->execute([$poll['id']]);
            $db->prepare("DELETE FROM spike_polls WHERE thread_id=?")->execute([$thread_id]);
        }
        $db->commit();
        aldhran_log("MOD_DELETE","Deleted thread #$thread_id",$myId);
        header("Location: index.php?p=viewboard&id=".$thread['board_id']."&msg=thread_deleted"); exit;
    }
    if ($action==='delete_post') {
        $pid=(int)($_POST['post_id']??0);
        $db->prepare("UPDATE spike_posts SET is_deleted=1, deleted_at=NOW(), deleted_by=? WHERE id=?")->execute([$myId,$pid]);
        aldhran_log("MOD_SOFT_DELETE","Marked post #$pid for deletion",$myId);
        header("Location: index.php?p=viewthread&slug=$slug_url&msg=post_marked_deleted"); exit;
    }
    if ($action==='restore_post' && $userPriv>=5) {
        $pid=(int)($_POST['post_id']??0);
        $db->prepare("UPDATE spike_posts SET is_deleted=0, deleted_at=NULL, deleted_by=NULL WHERE id=?")->execute([$pid]);
        aldhran_log("MOD_RESTORE_POST","Restored post #$pid",$myId);
        header("Location: index.php?p=viewthread&slug=$slug_url&msg=post_restored"); exit;
    }
    if ($action==='confirm_delete_post' && $userPriv>=5) {
        $pid=(int)($_POST['post_id']??0);
        if ($pid===$first_post_id) {
            header("Location: index.php?p=viewthread&slug=$slug_url&err=cannot_delete_op"); exit;
        }
        foreach(['spike_posts','spike_reactions','spike_reports'] as $tbl)
            $db->prepare("DELETE FROM $tbl WHERE ".($tbl==='spike_posts'?'id':'post_id')."=?")->execute([$pid]);
        $cnt=$db->prepare("SELECT COUNT(*) FROM spike_posts WHERE thread_id=?");
        $cnt->execute([$thread_id]);
        aldhran_log("MOD_HARD_DELETE","Permanently deleted post #$pid",$myId);
        if ((int)$cnt->fetchColumn()==0) {
            $db->prepare("DELETE FROM spike_threads WHERE id=?")->execute([$thread_id]);
            header("Location: index.php?p=viewboard&id=".$thread['board_id']."&msg=thread_auto_deleted"); exit;
        }
        header("Location: index.php?p=viewthread&slug=$slug_url&msg=post_deleted"); exit;
    }
    if ($action==='toggle_lock') {
        $v=$thread['is_locked']?0:1;
        $db->prepare("UPDATE spike_threads SET is_locked=? WHERE id=?")->execute([$v,$thread_id]);
        aldhran_log("MOD_LOCK",($v?"Locked":"Unlocked")." thread #$thread_id",$myId);
        header("Location: index.php?p=viewthread&slug=$slug_url"); exit;
    }
    if ($action==='toggle_sticky') {
        $v=$thread['is_sticky']?0:1;
        $db->prepare("UPDATE spike_threads SET is_sticky=? WHERE id=?")->execute([$v,$thread_id]);
        aldhran_log("MOD_STICKY",($v?"Pinned":"Unpinned")." thread #$thread_id",$myId);
        header("Location: index.php?p=viewthread&slug=$slug_url"); exit;
    }
}
?>