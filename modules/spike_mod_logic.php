<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) { exit; }

$userPriv = (int)($_SESSION['priv_level'] ?? $GLOBALS['userPriv'] ?? 0);
$myId     = (int)($_SESSION['user_id'] ?? $currentUserId ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $userPriv < 3) {
    header("Location: index.php?p=forum");
    exit;
}

checkToken($_POST['csrf_token'] ?? '');

$action = $_POST['mod_action'] ?? 'NONE';
$t_id   = (int)($_POST['thread_id'] ?? 0);
$b_id   = (int)($_POST['board_id'] ?? 0);
$p_id   = (int)($_POST['post_id'] ?? 0);

switch ($action) {
    case 'toggle_lock':
        $db->prepare("UPDATE spike_threads SET is_locked = 1 - is_locked WHERE id = ?")->execute([$t_id]);
        aldhran_log("MOD_LOCK", "Toggled lock for thread #$t_id", $myId);
        break;

    case 'toggle_sticky':
        $db->prepare("UPDATE spike_threads SET is_sticky = 1 - is_sticky WHERE id = ?")->execute([$t_id]);
        aldhran_log("MOD_STICKY", "Toggled sticky for thread #$t_id", $myId);
        break;

    case 'delete_post':
        $stmt_check = $db->prepare("SELECT id FROM spike_posts WHERE thread_id = ? ORDER BY created_at ASC LIMIT 1");
        $stmt_check->execute([$t_id]);
        $first_post = $stmt_check->fetch();

        if ($p_id > 0 && $first_post && (int)$first_post['id'] !== $p_id) {
            $db->prepare("DELETE FROM spike_posts WHERE id = ?")->execute([$p_id]);
            aldhran_log("MOD_DELETE_POST", "Deleted post #$p_id in thread #$t_id", $myId);
        }
        break;

    case 'delete_thread':
        if ($userPriv >= 4) {
            try {
                $db->beginTransaction();
                $db->prepare("DELETE FROM spike_posts WHERE thread_id = ?")->execute([$t_id]);
                $db->prepare("DELETE FROM spike_threads WHERE id = ?")->execute([$t_id]);
                $db->commit();
                aldhran_log("MOD_DELETE_THREAD", "Permanently deleted thread #$t_id", $myId);
                $target = ($b_id > 0) ? "viewboard&id=$b_id" : "forum";
                header("Location: index.php?p=$target&msg=deleted");
                exit;
            } catch (Exception $e) {
                $db->rollBack();
                error_log("delete_thread failed: " . $e->getMessage());
                header("Location: index.php?p=viewthread&id=" . $t_id . "&err=delete_failed"); exit;
            }
        }
        break;
}

header("Location: index.php?p=viewthread&id=$t_id&msg=success");
exit;