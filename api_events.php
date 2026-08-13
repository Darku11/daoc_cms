<?php
// SPDX-License-Identifier: GPL-3.0-only
require_once('includes/db.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $secret = $_POST['secret'] ?? '';
    $expectedSecret = trim((string)($GLOBALS['cms_settings']['game_server_shared_secret'] ?? ''));
    if ($expectedSecret === '') {
        $expectedSecret = trim((string)($GLOBALS['cms_settings']['game_server_bridge_secret'] ?? ''));
    }
    if ($expectedSecret === '' && defined('ASP_KEY')) {
        $expectedSecret = trim((string)ASP_KEY);
    }

    if ($secret === '' || $expectedSecret === '' || !hash_equals($expectedSecret, (string)$secret)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $type = $_POST['type'] ?? 'unknown';
    $message = $_POST['message'] ?? '';

    if (empty($message)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Empty message']);
        exit;
    }

    if ($type === 'guild_chat') {
        $guildName = $_POST['guild'] ?? '';
        $playerName = $_POST['player'] ?? '';

        $stmt = $db->query("SELECT guild_chat_sync FROM cms_bot_settings WHERE id = 1");
        $syncActive = (int)$stmt->fetchColumn();

        if ($syncActive === 1 && !empty($playerName)) {
            $channelId = null;

            if ($guildName === 'LookupViaCMS') {
                $stmt = $db->prepare("
                    SELECT g.GuildName, g.discord_channel_id 
                    FROM dolcharacters c 
                    JOIN guild g ON c.GuildID = g.GuildID 
                    WHERE c.Name = ? 
                    LIMIT 1
                ");
                $stmt->execute([$playerName]);
                $guildData = $stmt->fetch();

                if ($guildData) {
                    $guildName = $guildData['GuildName'];
                    $channelId = $guildData['discord_channel_id'];
                }
            } else {
                $stmt = $db->prepare("SELECT discord_channel_id FROM guild WHERE GuildName = ? LIMIT 1");
                $stmt->execute([$guildName]);
                $channelId = $stmt->fetchColumn();
            }

            if (!empty($channelId)) {
                require_once __DIR__ . '/includes/BotSettings.php';
                require_once __DIR__ . '/includes/BotEventDispatcher.php';
                
                $botSettings = new BotSettings($db);

                $dispatcher = new BotEventDispatcher($db, $botSettings);

                $dispatcher->dispatch('guild_chat_outbound', [
                    'channel_id' => $channelId,
                    'guild'      => $guildName,
                    'player'     => $playerName,
                    'message'    => $message
                ]);
            }
        }
        
        echo json_encode(['ok' => true]);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO cms_live_events (event_type, message) VALUES (?, ?)");
    $stmt->execute([$type, $message]);

    echo json_encode(['ok' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $lastId = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;

    $stmt = $db->prepare("SELECT id, event_type, message, created_at FROM cms_live_events WHERE id > ? ORDER BY id DESC LIMIT 5");
    $stmt->execute([$lastId]);
    $events = $stmt->fetchAll();

    $safeEvents = [];
    foreach ($events as $event) {
        $safeEvents[] = [
            'id' => (int)$event['id'],
            'type' => h($event['event_type']),
            'message' => h($event['message']),
            'time' => $event['created_at']
        ];
    }

    echo json_encode(['ok' => true, 'events' => $safeEvents]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
exit;
