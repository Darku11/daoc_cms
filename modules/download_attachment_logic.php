<?php
if (!defined('IN_CMS')) { exit; }

$att_id = (int)($_GET['id'] ?? 0);
if ($att_id <= 0) {
    header("Location: index.php?p=spike");
    exit;
}

$stmt = $db->prepare("
    SELECT a.*, p.thread_id
    FROM spike_attachments a
    JOIN spike_posts p ON a.post_id = p.id
    WHERE a.id = ?
");
$stmt->execute([$att_id]);
$att = $stmt->fetch();

if (!$att) {
    // index.php: 404
    return;
}

$stmt_t = $db->prepare("
    SELECT b.min_priv, c.min_priv as cat_min_priv
    FROM spike_posts p
    JOIN spike_threads t  ON p.thread_id = t.id
    JOIN spike_boards b   ON t.board_id  = b.id
    JOIN spike_categories c ON b.cat_id  = c.id
    WHERE p.id = ?
");
$stmt_t->execute([$att['post_id']]);
$access = $stmt_t->fetch();

if ($access) {
    $min_priv = ($access['min_priv'] > 0) ? (int)$access['min_priv'] : (int)$access['cat_min_priv'];
    $userPriv = (int)($_SESSION['priv_level'] ?? 0);
    if ($userPriv < 4 && $userPriv < $min_priv) {
        header("Location: index.php?p=spike&err=no_access");
        exit;
    }
}

// Build the path using the same logic as in viewthread_logic.php.
$spike_cfg = [];
try {
    foreach ($db->query("SELECT setting_key, setting_value FROM spike_settings")->fetchAll() as $s) {
        $spike_cfg[$s['setting_key']] = $s['setting_value'];
    }
} catch (\Throwable $e) {}

$attach_path_raw = $spike_cfg['attachment_path'] ?? 'uploads/forum/';
if (strpos($attach_path_raw, '/') === 0 || (strlen($attach_path_raw) > 1 && $attach_path_raw[1] === ':')) {
    $attach_path = rtrim($attach_path_raw, '/\\') . DIRECTORY_SEPARATOR;
} else {
    $attach_path = rtrim(__DIR__ . '/../' . $attach_path_raw, '/\\') . DIRECTORY_SEPARATOR;
}

$file_path = $attach_path . $att['stored_name'];

if (!file_exists($file_path) || !is_readable($file_path)) {
    error_log("Spike download: file not found: $file_path");
    return; // zeigt 404
}

// Increase Download-Counter
$db->prepare("UPDATE spike_attachments SET downloads = downloads + 1 WHERE id = ?")
   ->execute([$att_id]);

// Serve the file and clear the output buffer first.
while (ob_get_level()) { ob_end_clean(); }

$mime = $att['mime_type'] ?: 'application/octet-stream';
$safe_name = rawurlencode($att['filename']);

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . addslashes($att['filename']) . '"; filename*=UTF-8\'\'' . $safe_name);
header('Content-Length: ' . filesize($file_path));
header('Cache-Control: private, no-cache');
header('Pragma: no-cache');

readfile($file_path);
exit;