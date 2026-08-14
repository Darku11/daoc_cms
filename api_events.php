<?php
// SPDX-License-Identifier: GPL-3.0-only
require_once('includes/db.php');

header('Content-Type: application/json');

function api_events_reply(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

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
        $guildName = trim(substr((string)($_POST['guild'] ?? ''), 0, 255));
        $playerName = trim(substr((string)($_POST['player'] ?? ''), 0, 255));

        try {
            $stmt = $db->query("SELECT guild_chat_sync FROM cms_bot_settings WHERE id = 1");
            if ((int)$stmt->fetchColumn() !== 1) {
                api_events_reply(['ok' => false, 'error' => 'Guild chat sync is disabled.'], 409);
            }

            if ($playerName === '') {
                api_events_reply(['ok' => false, 'error' => 'Player name is missing.'], 400);
            }

            $guildTable = daoc_game_table_sql($db, 'guild');
            $characterTable = daoc_game_table_sql($db, 'dolcharacters');
            $channelId = null;

            if ($guildName === '' || $guildName === 'LookupViaCMS') {
                $stmt = $db->prepare("
                    SELECT g.GuildName, g.discord_channel_id
                    FROM {$characterTable} c
                    JOIN {$guildTable} g ON c.GuildID = g.GuildID
                    WHERE c.Name = ?
                    LIMIT 1
                ");
                $stmt->execute([$playerName]);
                $guildData = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$guildData) {
                    api_events_reply(['ok' => false, 'error' => 'No guild membership was found for this player.'], 404);
                }

                $guildName = (string)$guildData['GuildName'];
                $channelId = $guildData['discord_channel_id'];
            } else {
                $stmt = $db->prepare("SELECT discord_channel_id FROM {$guildTable} WHERE GuildName = ? LIMIT 1");
                $stmt->execute([$guildName]);
                $channelId = $stmt->fetchColumn();
            }

            if (empty($channelId)) {
                api_events_reply(['ok' => false, 'error' => 'The in-game guild has no linked Discord channel.'], 404);
            }

            require_once __DIR__ . '/includes/botsettings.php';
            require_once __DIR__ . '/includes/BotEventDispatcher.php';

            $botSettings = new BotSettings($db);
            $dispatcher = new BotEventDispatcher($db, $botSettings);
            $delivery = $dispatcher->dispatch('guild_chat_outbound', [
                'channel_id' => (string)$channelId,
                'guild'      => $guildName,
                'player'     => $playerName,
                'message'    => $message,
            ]);

            if (($delivery['status'] ?? '') !== 'ok') {
                $reason = (string)($delivery['message'] ?? $delivery['reason'] ?? 'Discord delivery failed.');
                api_events_reply(['ok' => false, 'error' => $reason], 502);
            }

            api_events_reply([
                'ok' => true,
                'guild' => $guildName,
                'channel_id' => (string)$channelId,
            ]);
        } catch (Throwable $e) {
            error_log('Guild chat event delivery failed: ' . $e->getMessage());
            api_events_reply(['ok' => false, 'error' => 'Guild chat delivery failed.'], 500);
        }
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
