<?php
if (!defined('IN_CMS')) { exit; }

header('Content-Type: application/json');

$q       = trim(substr($_GET['q'] ?? $_POST['q'] ?? '', 0, 40));
$exclude = (int)($_GET['exclude'] ?? $currentUserId ?? 0); // exclude the current user

if (mb_strlen($q) < 1) {
    echo json_encode(['ok' => true, 'users' => []]);
    exit;
}

try {
    $stmt = $db->prepare("
        SELECT
            u.id,
            u.username,
            u.avatar      AS avatar,
            u.priv_level,
            u.forum_posts AS post_count
        FROM users u
        WHERE u.username LIKE ?
          AND u.id != ?
          AND (u.banned IS NULL OR u.banned = 0)
        ORDER BY u.forum_posts DESC, u.username ASC
        LIMIT 8
    ");
    $stmt->execute([$q . '%', $exclude]);
    $users = $stmt->fetchAll();

    // Avatar fallback
    foreach ($users as &$u) {
        $u['avatar_url'] = !empty($u['avatar'])
            ? h($u['avatar'])
            : 'assets/img/default_avatar.png';
        unset($u['avatar']); // don't expose the raw path
    }
    unset($u);

    echo json_encode(['ok' => true, 'users' => $users]);

} catch (\Throwable $e) {
    error_log("[spike_mention] search error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'users' => [], 'error' => 'search_failed']);
}