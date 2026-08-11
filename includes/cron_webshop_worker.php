<?php
// includes/cron_webshop_worker.php
// Run with Windows Task Scheduler or cron every 30 to 60 seconds: php cron_webshop_worker.php

define('IN_CMS', true);
require_once(__DIR__ . '/db.php');

define('IGC_SERVICE_URL', $GLOBALS['cms_settings']['igc_service_url'] ?? 'http://127.0.0.1:5100');
define('IGC_SECRET',      $GLOBALS['cms_settings']['igc_api_secret']  ?? '');

const MAX_ATTEMPTS = 10;

function igc_call(string $endpoint, array $payload = [], string $method = 'POST'): array {
    $url = IGC_SERVICE_URL . '/' . ltrim($endpoint, '/');
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Aldhran-Secret: ' . IGC_SECRET],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $raw = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($raw, true);
    return $data ?? ['ok' => false, 'error' => 'invalid_response'];
}

// Fetch the online list once, instead of per-order (saves bridge roundtrips)
$status = igc_call('status', [], 'GET');
if (!($status['ok'] ?? false)) {
    error_log('[webshop_worker] Bridge unreachable, skipping cycle.');
    exit;
}
$online_names = array_map(
    fn($p) => strtolower($p['Name']),
    $status['players'] ?? []
);

$stmt = $db->prepare("
    SELECT * FROM webshop_orders
    WHERE delivered = 0 AND attempts < ?
    ORDER BY created_at ASC
    LIMIT 50
");
$stmt->execute([MAX_ATTEMPTS]);
$pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($pending as $order) {
    $playerLower = strtolower($order['player_name']);

    if (!in_array($playerLower, $online_names, true)) {
        continue; // Player not online, next cycle will retry
    }

    $result = igc_call('giveitem', [
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