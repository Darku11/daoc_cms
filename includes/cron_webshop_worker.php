<?php
// SPDX-License-Identifier: GPL-3.0-only
// includes/cron_webshop_worker.php
// Run with Windows Task Scheduler or cron every 30 to 60 seconds: php cron_webshop_worker.php

define('IN_CMS', true);
require_once(__DIR__ . '/db.php');

const MAX_ATTEMPTS = 10;

$stmt = $db->prepare("
    SELECT * FROM webshop_orders
    WHERE delivered = 0 AND attempts < ?
    ORDER BY created_at ASC
    LIMIT 50
");
$stmt->execute([MAX_ATTEMPTS]);
$pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$pending) exit;

// Check only the pending recipients. This avoids coupling delivery retries to
// the richer admin status payload and works identically on both server cores.
$presence = aldhran_console_call('players/online', [
    'names' => array_values(array_unique(array_column($pending, 'player_name'))),
]);
if (!($presence['ok'] ?? false)) {
    error_log('[webshop_worker] Player presence check failed: ' . ($presence['error'] ?? 'unknown error'));
    exit;
}
$online_names = array_map('strtolower', $presence['online'] ?? []);

foreach ($pending as $order) {
    $playerLower = strtolower($order['player_name']);

    if (!in_array($playerLower, $online_names, true)) {
        continue; // Player not online, next cycle will retry
    }

    $result = aldhran_console_call('giveitem', [
        'name'    => $order['player_name'],
        'item_id' => $order['item_template_id'],
        'count'   => (int)$order['count'],
    ]);

    if ($result['ok'] ?? false) {
        $upd = $db->prepare("
            UPDATE webshop_orders
            SET delivered = 1, delivered_at = NOW(), last_attempt_at = NOW(), last_error = NULL
            WHERE id = ?
        ");
        $upd->execute([$order['id']]);
        error_log("[webshop_worker] Delivered order #{$order['id']} to {$order['player_name']}");
    } else {
        $upd = $db->prepare("
            UPDATE webshop_orders
            SET attempts = attempts + 1, last_attempt_at = NOW(), last_error = ?
            WHERE id = ?
        ");
        $upd->execute([substr($result['error'] ?? 'unknown', 0, 255), $order['id']]);
        error_log("[webshop_worker] Failed order #{$order['id']}: " . ($result['error'] ?? 'unknown'));
    }
}
