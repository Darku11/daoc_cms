<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) { exit; }

$userPriv = (int)($_SESSION['priv_level'] ?? 0);
$myId     = (int)($_SESSION['user_id']    ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_anon']) && $myId > 0) {
    if (isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $db->prepare("UPDATE users SET is_anonymous = 1 - is_anonymous WHERE id = ?")->execute([$myId]);
        header("Location: index.php?p=spike");
        exit;
    }
}

$stmt_cat = $db->prepare("
    SELECT DISTINCT c.* 
    FROM spike_categories c
    LEFT JOIN spike_boards b ON b.cat_id = c.id
    WHERE c.min_priv <= ? OR b.min_priv <= ?
    ORDER BY c.pos ASC
");
$stmt_cat->execute([$userPriv, $userPriv]);
$categories = $stmt_cat->fetchAll();

$forum_structure = [];
foreach ($categories as $cat) {
    $cat_id     = (int)$cat['id'];
    $stmt_board = $db->prepare("
        SELECT b.*,
        (SELECT COUNT(*) FROM spike_threads WHERE board_id = b.id) as thread_count,
        (SELECT COUNT(*) FROM spike_posts p2 JOIN spike_threads t2 ON p2.thread_id = t2.id WHERE t2.board_id = b.id) as post_count,
        (SELECT p3.created_at FROM spike_posts p3 JOIN spike_threads t3 ON p3.thread_id = t3.id WHERE t3.board_id = b.id ORDER BY p3.id DESC LIMIT 1) as last_post_date,
        (SELECT u3.username FROM spike_posts p4 JOIN spike_threads t4 ON p4.thread_id = t4.id JOIN users u3 ON p4.author_id = u3.id WHERE t4.board_id = b.id ORDER BY p4.id DESC LIMIT 1) as last_post_user
        FROM spike_boards b
        WHERE b.cat_id = ? AND b.min_priv <= ?
        ORDER BY b.pos ASC
    ");
    $stmt_board->execute([$cat_id, $userPriv]);
    $boards = $stmt_board->fetchAll();

    if (empty($boards) && (int)$cat['min_priv'] > $userPriv) {
        continue;
    }

    $cat_boards = [];
    foreach ($boards as $b) {
        $b['thread_count'] = (int)$b['thread_count'];
        $b['post_count']   = (int)$b['post_count'];
        $b['cat_min_post'] = (int)($cat['min_priv_post'] ?? 0);
        $b['graphic_url'] = (string)($b['graphic_url'] ?? '');
        $cat_boards[] = $b;
    }
    $forum_structure[] = ['info' => $cat, 'boards' => $cat_boards];
}

$stmt_online = $db->prepare("SELECT id, username, priv_level, is_anonymous FROM users WHERE last_activity > ? ORDER BY username ASC");
$stmt_online->execute([time() - 300]);
$raw_online_users = $stmt_online->fetchAll();

$online_users = [];
$is_my_anon_status = 0;

foreach ($raw_online_users as $ou) {
    if ((int)$ou['id'] === $myId) {
        $is_my_anon_status = (int)$ou['is_anonymous'];
    }

    if ((int)$ou['is_anonymous'] === 1) {
        if ($userPriv >= 4 || (int)$ou['id'] === $myId) {
            $online_users[] = $ou;
        }
    } else {
        $online_users[] = $ou;
    }
}

$forum_stats = ['total_threads' => 0, 'total_posts' => 0, 'total_members' => 0, 'newest_member' => ''];
try {
    $forum_stats['total_threads'] = (int)$db->query("SELECT COUNT(*) FROM spike_threads")->fetchColumn();
    $forum_stats['total_posts']   = (int)$db->query("SELECT COUNT(*) FROM spike_posts")->fetchColumn();
    $forum_stats['total_members'] = (int)$db->query("SELECT COUNT(*) FROM users WHERE standing < 5")->fetchColumn();
    $nm = $db->query("SELECT username FROM users WHERE standing < 5 ORDER BY id DESC LIMIT 1")->fetch();
    $forum_stats['newest_member'] = $nm['username'] ?? '';
} catch (\Throwable $e) {
    error_log("Spike stats error: " . $e->getMessage());
}

$recent_members = [];
try {
    $stmt_recent_members = $db->query(
        "SELECT id, username, avatar_url, created_at
           FROM users
          WHERE standing < 5
          ORDER BY created_at DESC, id DESC
          LIMIT 1"
    );
    $recent_members = $stmt_recent_members->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log("Spike recent members error: " . $e->getMessage());
}

$latest_posts = [];
try {
    $block_filter_p = ($myId > 0 && $userPriv < 2) ? " AND p.author_id NOT IN (SELECT blocker_id FROM user_blocks WHERE blocked_id = " . $myId . ")" : "";
    $stmt_latest = $db->prepare("
        SELECT p.created_at, t.id as thread_id, t.title as thread_title,
               u.id as author_id, u.username, u.avatar_url
        FROM spike_posts p
        JOIN spike_threads t ON p.thread_id = t.id
        JOIN spike_boards b ON t.board_id = b.id
        JOIN spike_categories c ON b.cat_id = c.id
        JOIN users u ON p.author_id = u.id
        WHERE b.min_priv <= ? {$block_filter_p}
        ORDER BY p.created_at DESC
        LIMIT 5
    ");
    $stmt_latest->execute([$userPriv]);
    $latest_posts = $stmt_latest->fetchAll();
} catch (\Throwable $e) {
    error_log("Spike latest posts error: " . $e->getMessage());
}

$spike_settings = [];
try {
    $spike_settings = $db->query("SELECT setting_key, setting_value FROM spike_settings")
                         ->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (\Throwable $e) {}
