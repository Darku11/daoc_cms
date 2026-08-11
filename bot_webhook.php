<?php
define('IN_CMS', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/BotSettings.php';
require_once __DIR__ . '/includes/BotEventDispatcher.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

aldhran_rate_limit('bot_webhook_' . ($_SERVER['REMOTE_ADDR'] ?? '0'), 60, 60);

// Needed for signature verification
$raw = file_get_contents('php://input');

$botSettings = new BotSettings($db);

// Verify webhook signature (HMAC-SHA256)
// Secret is stored in cms_bot_settings as 'socket_secret'
$webhook_secret = $botSettings->data['socket_secret'] ?? '';
if (!empty($webhook_secret)) {
    $provided_sig = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
    $expected_sig = 'sha256=' . hash_hmac('sha256', $raw, $webhook_secret);
    if (!hash_equals($expected_sig, $provided_sig)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Invalid signature']);
        exit;
    }
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

$dispatcher = new BotEventDispatcher($db, $botSettings);
$response   = $dispatcher->handleIncoming($payload);

http_response_code(200);
header('Content-Type: application/json');
echo json_encode($response);
exit;
