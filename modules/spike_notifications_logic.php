<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) { exit; }

require_once __DIR__ . '/spike_notification_helper.php';

$myId = (int)($currentUserId ?? 0);

// ── AJAX GET: unread count / full list ────────────────────────
if (isset($_GET['json'])) {
    header('Content-Type: application/json');

    if ($myId <= 0) {
        echo json_encode(['ok' => true, 'unread' => 0, 'notifications' => []]);
        exit;
    }

    $unread = spike_get_unread_count($db, $myId);

    if (isset($_GET['full'])) {
        $notifications = spike_get_notifications($db, $myId, 15);
        echo json_encode([
            'ok'            => true,
            'unread'        => $unread,
            'notifications' => $notifications,
        ]);
    } else {
        echo json_encode(['ok' => true, 'unread' => $unread]);
    }
    exit;
}

// ── AJAX POST: mark read ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if ($myId <= 0) { echo json_encode(['error' => 'not_logged_in']); exit; }

    checkToken($_POST['csrf_token'] ?? '');

    $action = $_POST['action'] ?? '';

    if ($action === 'mark_all_read') {
        spike_mark_notifications_read($db, $myId);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'mark_read') {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        spike_mark_notifications_read($db, $myId, $ids);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['error' => 'unknown_action']);
    exit;
}

// ── No direct page access intended ─────────────────────────────
header('Location: index.php?p=spike');
exit;
