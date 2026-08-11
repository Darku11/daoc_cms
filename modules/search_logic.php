<?php
if (!defined('IN_CMS')) exit;

if (file_exists('includes/spike_bb_helper.php')) {
    require_once('includes/spike_bb_helper.php');
}

$query   = isset($_GET['q']) ? trim($_GET['q']) : '';
$results = ['users' => [], 'threads' => [], 'posts' => []];

$userPriv = (int)($_SESSION['priv_level'] ?? 0);
$myId     = (int)($_SESSION['user_id'] ?? 0);

if (mb_strlen($query) >= 3) {
    $s = "%" . $query . "%";

    // 1. Search users (shadow-ban protection & block filter)
    if ($userPriv < 4) {
        $stmt_u = $db->prepare("SELECT id, username, avatar_url FROM users WHERE username LIKE ? AND (is_shadow_banned = 0 OR id = ?) AND id NOT IN (SELECT blocker_id FROM user_blocks WHERE blocked_id = ?) LIMIT 5");
        $stmt_u->execute([$s, $myId, $myId]);
    } else {
        $stmt_u = $db->prepare("SELECT id, username, avatar_url FROM users WHERE username LIKE ? LIMIT 5");
        $stmt_u->execute([$s]);
    }
    $results['users'] = $stmt_u->fetchAll();

    // 2. Search forum threads (including permission check)
    if ($userPriv < 4) {
        $stmt_t = $db->prepare("
            SELECT t.id, t.title 
            FROM spike_threads t 
            JOIN spike_boards b ON t.board_id = b.id
            JOIN spike_categories c ON b.cat_id = c.id
            LEFT JOIN users u ON t.author_id = u.id 
            WHERE t.title LIKE ? 
              AND t.is_approved = 1 
              AND ? >= (CASE WHEN b.min_priv > 0 THEN b.min_priv ELSE c.min_priv END)
              AND (u.is_shadow_banned = 0 OR t.author_id = ?) 
              AND t.author_id NOT IN (SELECT blocker_id FROM user_blocks WHERE blocked_id = ?) 
            LIMIT 10
        ");
        $stmt_t->execute([$s, $userPriv, $myId, $myId]);
    } else {
        $stmt_t = $db->prepare("SELECT t.id, t.title FROM spike_threads t WHERE t.title LIKE ? AND t.is_approved = 1 LIMIT 10");
        $stmt_t->execute([$s]);
    }
    $results['threads'] = $stmt_t->fetchAll();

    // 3. Search forum posts (including permission check)
    if ($userPriv < 4) {
        $stmt_p = $db->prepare("
            SELECT p.id, p.thread_id, p.content, t.title 
            FROM spike_posts p 
            JOIN spike_threads t ON p.thread_id = t.id 
            JOIN spike_boards b ON t.board_id = b.id
            JOIN spike_categories c ON b.cat_id = c.id
            LEFT JOIN users u ON p.author_id = u.id 
            WHERE p.content LIKE ? 
              AND t.is_approved = 1 
              AND ? >= (CASE WHEN b.min_priv > 0 THEN b.min_priv ELSE c.min_priv END)
              AND (u.is_shadow_banned = 0 OR p.author_id = ?) 
              AND p.author_id NOT IN (SELECT blocker_id FROM user_blocks WHERE blocked_id = ?) 
            LIMIT 10
        ");
        $stmt_p->execute([$s, $userPriv, $myId, $myId]);
    } else {
        $stmt_p = $db->prepare("SELECT p.id, p.thread_id, p.content, t.title FROM spike_posts p JOIN spike_threads t ON p.thread_id = t.id WHERE p.content LIKE ? AND t.is_approved = 1 LIMIT 10");
        $stmt_p->execute([$s]);
    }
    $results['posts'] = $stmt_p->fetchAll();
}