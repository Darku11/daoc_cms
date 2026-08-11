<?php
/**
 * api_bot_config.php – DAoC CMS
 *
 * Provides the Discord bot with its startup configuration.
 * Authentication uses BOT_BOOTSTRAP_SECRET from config.php.
 * This is intentionally separate from socket_secret.
 */
require_once('includes/config.php');
require_once('includes/db.php');

header('Content-Type: application/json');

$secret = $_SERVER['HTTP_X_DAOC_CMS_BOOTSTRAP'] ?? '';

// Validate the bootstrap secret from config.php independently of the socket secret.
if (empty($secret) || !defined('BOT_BOOTSTRAP_SECRET') || !hash_equals(BOT_BOOTSTRAP_SECRET, $secret)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$stmt = $db->query("SELECT * FROM cms_bot_settings WHERE id = 1");
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$settings) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Bot settings not found']);
    exit;
}

echo json_encode([
    'ok' => true,
    'config' => [
        'discord_token'   => $settings['discord_token'],
        'socket_port'     => (int)($settings['bot_port']         ?? 15000),
        'socket_secret'   => $settings['socket_secret'],
        'is_active'       => (int)($settings['is_active']        ?? 0),
        'guild_chat_sync' => (int)($settings['guild_chat_sync']  ?? 0),
    ]
]);
