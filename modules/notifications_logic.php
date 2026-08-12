<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) { exit; }

$myId = (int)($_SESSION['user_id'] ?? 0);
if ($myId <= 0) {
    header("Location: index.php?p=login");
    exit;
}

// ACTION: MARK ALL READ
if (isset($_POST['mark_all_read'])) {
    checkToken($_POST['csrf_token'] ?? '');
    $db->prepare("UPDATE spike_notifications SET is_read = 1 WHERE user_id = ?")
       ->execute([$myId]);
    header("Location: index.php?p=notifications&msg=cleared"); exit;
}

// ACTION: DELETE SINGLE
// Destructive actions require POST and CSRF validation.
if (isset($_POST['delete_notif'])) {
    checkToken($_POST['csrf_token'] ?? '');
    $nid = (int)$_POST['delete_notif'];
    // user_id in the WHERE clause prevents users from deleting others' notifications
    $db->prepare("DELETE FROM spike_notifications WHERE id = ? AND user_id = ?")
       ->execute([$nid, $myId]);
    header("Location: index.php?p=notifications"); exit;
}

// FETCH NOTIFICATIONS
$stmt_fetch = $db->prepare("
    SELECT n.*, u.username, t.title as thread_title
    FROM spike_notifications n
    JOIN users u ON n.source_user_id = u.id
    JOIN spike_threads t ON n.thread_id = t.id
    WHERE n.user_id = ?
    ORDER BY n.is_read ASC, n.created_at DESC
    LIMIT 30
");
$stmt_fetch->execute([$myId]);
$notifications = $stmt_fetch->fetchAll();

// Unread count for the view
$stmt_unread = $db->prepare("SELECT COUNT(*) FROM spike_notifications WHERE user_id = ? AND is_read = 0");
$stmt_unread->execute([$myId]);
$unread_count = (int)$stmt_unread->fetchColumn();
