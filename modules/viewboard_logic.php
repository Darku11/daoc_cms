<?php
require_once(__DIR__ . '/../includes/spike_bb_helper.php');
if (!defined('IN_CMS')) { exit; }

$userPriv   = (int)($_SESSION['priv_level'] ?? 0);
$myId       = (int)($_SESSION['user_id']    ?? 0);
$myStanding = (int)($_SESSION['standing']   ?? 0);
$board_id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt_board = $db->prepare("
    SELECT b.*, c.min_priv as cat_min_view, c.min_priv_post as cat_min_post
    FROM spike_boards b
    JOIN spike_categories c ON b.cat_id = c.id
    WHERE b.id = ?
");
$stmt_board->execute([$board_id]);
$board_info = $stmt_board->fetch();

if (!$board_info) {
    header("Location: index.php?p=spike&err=not_found"); exit;
}

$effective_min_view = ($board_info['min_priv'] > 0) ? (int)$board_info['min_priv'] : (int)$board_info['cat_min_view'];
$effective_min_post = ($board_info['min_priv_post'] > 0) ? (int)$board_info['min_priv_post'] : (int)$board_info['cat_min_post'];

if ($userPriv < 4 && $userPriv < $effective_min_view) {
    header("Location: index.php?p=spike&err=no_access"); exit;
}

$stmt_all_b = $db->prepare("
    SELECT b.id, b.title, c.title AS cat_title, c.pos AS cat_pos,
           CASE WHEN b.min_priv > 0 THEN b.min_priv ELSE c.min_priv END AS effective_min_view
    FROM spike_boards b
    JOIN spike_categories c ON b.cat_id = c.id
    WHERE b.id != ?
      AND (? >= 4 OR ? >= CASE WHEN b.min_priv > 0 THEN b.min_priv ELSE c.min_priv END)
    ORDER BY c.pos ASC, b.pos ASC
");
$stmt_all_b->execute([$board_id, $userPriv, $userPriv]);
$all_boards = $stmt_all_b->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_mod_action'], $_POST['selected_threads']) && $userPriv >= 2) {
    checkToken($_POST['csrf_token'] ?? '');
    $thread_ids = array_map('intval', $_POST['selected_threads']);
    if (!empty($thread_ids)) {
        $ph     = implode(',', array_fill(0, count($thread_ids), '?'));
        $action = $_POST['mod_batch_action'] ?? '';
        try {
            $db->beginTransaction();
            if ($action === 'move' && (int)($_POST['target_board'] ?? 0) > 0 && $userPriv >= 2) {
                $tb = (int)$_POST['target_board'];

                $target_stmt = $db->prepare("
                    SELECT b.id
                    FROM spike_boards b
                    JOIN spike_categories c ON b.cat_id = c.id
                    WHERE b.id = ?
                      AND b.id != ?
                      AND (? >= 4 OR ? >= CASE WHEN b.min_priv > 0 THEN b.min_priv ELSE c.min_priv END)
                    LIMIT 1
                ");
                $target_stmt->execute([$tb, $board_id, $userPriv, $userPriv]);
                if (!$target_stmt->fetchColumn()) {
                    throw new RuntimeException('Invalid or inaccessible target board.');
                }

                $db->prepare("UPDATE spike_threads SET board_id=? WHERE id IN ($ph) AND board_id=?")
                   ->execute(array_merge([$tb], $thread_ids, [$board_id]));
                aldhran_log("BATCH_MOD", "Moved threads to board #$tb", $myId);
            } elseif ($action === 'delete' && $userPriv >= 4) {
                $db->prepare("DELETE FROM spike_posts   WHERE thread_id IN ($ph)")->execute($thread_ids);
                $db->prepare("DELETE FROM spike_threads WHERE id IN ($ph)")->execute($thread_ids);
                aldhran_log("BATCH_MOD", "Deleted multiple threads", $myId);
            } elseif ($action === 'toggle_lock') {
                $db->prepare("UPDATE spike_threads SET is_locked=1-is_locked WHERE id IN ($ph)")->execute($thread_ids);
                aldhran_log("BATCH_MOD", "Toggled lock for multiple threads", $myId);
            } elseif ($action === 'toggle_sticky') {
                $db->prepare("UPDATE spike_threads SET is_sticky=1-is_sticky WHERE id IN ($ph)")->execute($thread_ids);
                aldhran_log("BATCH_MOD", "Toggled sticky for multiple threads", $myId);
            } elseif ($action === 'approve') {
                $db->prepare("UPDATE users u JOIN spike_threads t ON u.id = t.author_id SET u.forum_posts = u.forum_posts + 1 WHERE t.id IN ($ph) AND t.is_approved = 0")->execute($thread_ids);
                $db->prepare("UPDATE spike_threads SET is_approved=1 WHERE id IN ($ph)")->execute($thread_ids);
                aldhran_log("BATCH_MOD", "Approved multiple threads", $myId);
            }
            $db->commit();
            header("Location: index.php?p=viewboard&id=$board_id&msg=mod_success"); exit;
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Batch Mod Error: " . $e->getMessage());
        }
    }
}

$post_del_filter = ($userPriv < 5) ? " AND (sp.is_deleted IS NULL OR sp.is_deleted = 0)" : "";
$user_del_filter = ($userPriv < 2) ? " AND (sp.author_id > 0 AND su.id IS NOT NULL)" : "";

$threads = [];
try {
    $stmt_threads = $db->prepare("
        SELECT t.*, u.username, u.user_title, t.slug,
               p.label as prefix_label, p.color as prefix_color, p.bg_color as prefix_bg,
               (SELECT COUNT(sp.id) FROM spike_posts sp LEFT JOIN users su ON sp.author_id = su.id WHERE sp.thread_id = t.id" . $post_del_filter . $user_del_filter . ") as reply_count,
               (SELECT sp.created_at FROM spike_posts sp LEFT JOIN users su ON sp.author_id = su.id WHERE sp.thread_id = t.id" . $post_del_filter . $user_del_filter . " ORDER BY sp.created_at DESC LIMIT 1) as last_post_date,
               (SELECT su.username FROM spike_posts sp LEFT JOIN users su ON sp.author_id = su.id WHERE sp.thread_id = t.id" . $post_del_filter . $user_del_filter . " ORDER BY sp.created_at DESC LIMIT 1) as last_post_user,
               (SELECT is_deleted FROM spike_posts WHERE thread_id = t.id ORDER BY created_at ASC LIMIT 1) as op_deleted
        FROM spike_threads t
        LEFT JOIN users u ON t.author_id = u.id
        LEFT JOIN spike_prefixes p ON t.prefix_id = p.id AND p.is_active = 1
        WHERE t.board_id = ? AND (t.is_approved = 1 OR t.author_id = ? OR ? >= 2)
          AND (? >= 2 OR u.id IS NOT NULL)
        ORDER BY t.is_sticky DESC, last_post_date DESC
    ");
    $stmt_threads->execute([$board_id, $myId, $userPriv, $userPriv]);
    $threads = $stmt_threads->fetchAll();
} catch (\Throwable $e) {
    error_log("Spike Viewboard Threads Error: " . $e->getMessage());
}

$board_online_users = [];
if ($myId > 0) {
    try {
        $db->prepare("UPDATE users SET current_location = ? WHERE id = ?")->execute(["board_{$board_id}", $myId]);
    } catch (\Throwable $e) {}
}

try {
    $stmt_bo = $db->prepare("SELECT id, username, priv_level, is_anonymous FROM users WHERE last_activity > ? AND current_location = ? ORDER BY username ASC");
    $stmt_bo->execute([time() - 300, "board_{$board_id}"]);
    $raw_board_users = $stmt_bo->fetchAll();

    foreach ($raw_board_users as $ou) {
        if ((int)$ou['is_anonymous'] === 1) {
            if ($userPriv >= 4 || (int)$ou['id'] === $myId) {
                $board_online_users[] = $ou;
            }
        } else {
            $board_online_users[] = $ou;
        }
    }
} catch (\Throwable $e) {}
