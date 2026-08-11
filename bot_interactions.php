<?php
define('IN_CMS', true);
require_once __DIR__ . '/includes/db.php';

function verify_discord_signature(string $body, string $signature, string $timestamp, string $public_key): bool {
    if (!function_exists('sodium_crypto_sign_verify_detached')) {
        error_log('[bot_interactions] sodium_crypto_sign_verify_detached not available!');
        return false;
    }
    try {
        $sig_bytes = sodium_hex2bin($signature);
        $key_bytes = sodium_hex2bin($public_key);
        $msg       = $timestamp . $body;
        return sodium_crypto_sign_verify_detached($sig_bytes, $msg, $key_bytes);
    } catch (Throwable $e) {
        error_log('[bot_interactions] Signature verify error: ' . $e->getMessage());
        return false;
    }
}

function checkBotCommand(string $command, PDO $db, int $userPriv = 1, ?int $cmsUserId = null): ?string {
    try {
        $stmt = $db->prepare("
            SELECT id, is_enabled, min_authlevel, cooldown_seconds
            FROM   cms_bot_commands
            WHERE  command = ?
            LIMIT  1
        ");
        $stmt->execute([$command]);
        $cmd = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("[checkBotCommand] DB error: " . $e->getMessage());
        return null;
    }

    if (!$cmd) return null;

    if (!(int)$cmd['is_enabled']) {
        return "❌ This command is currently disabled.";
    }

    $cmdId    = (int)$cmd['id'];
    $minLevel = (int)$cmd['min_authlevel'];

    if ($cmsUserId) {
        try {
            $ovStmt = $db->prepare("
                SELECT is_allowed FROM cms_bot_command_permissions
                WHERE  command_id = ? AND scope = 'user' AND scope_value = ?
                LIMIT  1
            ");
            $ovStmt->execute([$cmdId, $cmsUserId]);
            $userOverride = $ovStmt->fetch(PDO::FETCH_ASSOC);
            if ($userOverride !== false) {
                return $userOverride['is_allowed']
                    ? null
                    : "❌ You don't have permission to use this command.";
            }
        } catch (Throwable $e) {}
    }

    try {
        $ovStmt = $db->prepare("
            SELECT is_allowed FROM cms_bot_command_permissions
            WHERE  command_id = ? AND scope = 'authlevel' AND scope_value = ?
            LIMIT  1
        ");
        $ovStmt->execute([$cmdId, $userPriv]);
        $levelOverride = $ovStmt->fetch(PDO::FETCH_ASSOC);
        if ($levelOverride !== false) {
            return $levelOverride['is_allowed']
                ? null
                : "❌ Your auth level doesn't have permission to use this command.";
        }
    } catch (Throwable $e) {}

    if ($userPriv < $minLevel) {
        return "❌ This command requires auth level {$minLevel} or higher.";
    }

    return null;
}

$raw_body  = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_SIGNATURE_ED25519'] ?? '';
$timestamp = $_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'] ?? '';

$public_key = '';
try {
    $pk_row     = $db->query("SELECT discord_public_key FROM cms_bot_settings WHERE id = 1 LIMIT 1")->fetch();
    $public_key = $pk_row['discord_public_key'] ?? '';
} catch (Throwable $e) {
    error_log('[bot_interactions] DB error loading public key: ' . $e->getMessage());
}

if (empty($public_key) || !verify_discord_signature($raw_body, $signature, $timestamp, $public_key)) {
    http_response_code(401);
    die('Invalid request signature');
}

$payload = json_decode($raw_body, true);
if (!is_array($payload)) {
    http_response_code(400);
    die('Invalid JSON');
}

header('Content-Type: application/json');

$type = (int)($payload['type'] ?? 0);

if ($type === 1) {
    echo json_encode(['type' => 1]);
    exit;
}

if ($type === 2) {
    require_once __DIR__ . '/includes/BotSettings.php';
    require_once __DIR__ . '/includes/AiManager.php';
    require_once __DIR__ . '/includes/BotEventDispatcher.php';

    $botSettings = new BotSettings($db);

    if (!$botSettings->isActive()) {
        echo json_encode(['type' => 4, 'data' => ['content' => '⚠️ The bot is currently disabled.', 'flags' => 64]]);
        exit;
    }

    $command    = $payload['data']['name']    ?? '';
    $options    = $payload['data']['options'] ?? [];
    $member     = $payload['member']          ?? [];
    $user       = $member['user']             ?? ($payload['user'] ?? []);
    $discord_id = $user['id']                 ?? null;
    $roles      = $member['roles']            ?? [];

    $admin_role_id = $botSettings->data['admin_role_id'] ?? '';
    $is_admin      = !empty($admin_role_id) && in_array($admin_role_id, $roles);

    $cms_user_id   = null;
    $cms_user_priv = 1;
    if ($discord_id) {
        try {
            $u = $db->prepare("SELECT id, priv_level FROM users WHERE discord_id = ? LIMIT 1");
            $u->execute([$discord_id]);
            $u_row = $u->fetch();
            if ($u_row) {
                $cms_user_id   = (int)$u_row['id'];
                $cms_user_priv = (int)$u_row['priv_level'];
            }
        } catch (Throwable $e) {}
    }

    if ($is_admin && $cms_user_priv < 4) {
        $cms_user_priv = 4;
    }

    $opt = function(string $name) use ($options): ?string {
        foreach ($options as $o) {
            if (($o['name'] ?? '') === $name) return (string)($o['value'] ?? '');
        }
        return null;
    };

    $dispatcher = new BotEventDispatcher($db, $botSettings);

    $response_data = match($command) {

        'status' => (function() use ($db, $cms_user_id, $cms_user_priv): array {
            $err = checkBotCommand('status', $db, $cms_user_priv, $cms_user_id);
            if ($err) return ['content' => $err, 'flags' => 64];

            $ip   = $GLOBALS['cms_settings']['game_server_ip'] ?? '127.0.0.1';
            $port = (int)($GLOBALS['cms_settings']['game_server_port'] ?? 10300);
            $fp   = @fsockopen($ip, $port, $e, $es, 2);
            $online = (bool)$fp;
            if ($fp) fclose($fp);

            $players = 0;
            try {
                $players = (int)$db->query(
                    "SELECT COUNT(*) FROM dolcharacters WHERE LastPlayed > DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
                )->fetchColumn();
            } catch (Throwable $e) {}

            $status_icon = $online ? '🟢' : '🔴';
            $status_text = $online ? 'Online' : 'Offline';

            return [
                'embeds' => [[
                    'title'     => '⚔️ DAoC CMS Server Status',
                    'color'     => $online ? 0x27ae60 : 0xc0392b,
                    'fields'    => [
                        ['name' => 'Status',         'value' => "$status_icon $status_text", 'inline' => true],
                        ['name' => 'Players Online', 'value' => "👥 $players",              'inline' => true],
                        ['name' => 'Address',        'value' => "`$ip:$port`",               'inline' => true],
                    ],
                    'footer'    => ['text' => 'DAoC CMS'],
                    'timestamp' => date('c'),
                ]]
            ];
        })(),

        'players' => (function() use ($db, $cms_user_id, $cms_user_priv): array {
            $err = checkBotCommand('players', $db, $cms_user_priv, $cms_user_id);
            if ($err) return ['content' => $err, 'flags' => 64];

            try {
                $rows = $db->query("
                    SELECT Name AS name, Class AS class_id, Level AS level, Realm AS realm_id
                    FROM   dolcharacters
                    WHERE  LastPlayed > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                    ORDER  BY Level DESC
                    LIMIT  20
                ")->fetchAll();
            } catch (Throwable $e) { $rows = []; }

            if (empty($rows)) return ['content' => '👥 No players currently online.'];

            $realm_names = [1 => 'Albion', 2 => 'Midgard', 3 => 'Hibernia'];
            $lines = [];
            foreach ($rows as $r) {
                $realm   = $realm_names[(int)$r['realm_id']] ?? 'Unknown';
                $lines[] = "`Lv{$r['level']}` **{$r['name']}** — {$realm}";
            }

            return [
                'embeds' => [[
                    'title'       => '👥 Players Online (' . count($rows) . ')',
                    'description' => implode("\n", $lines),
                    'color'       => 0x2980b9,
                    'footer'      => ['text' => 'DAoC CMS'],
                    'timestamp'   => date('c'),
                ]]
            ];
        })(),

        'char' => (function() use ($db, $opt, $cms_user_id, $cms_user_priv): array {
            $err = checkBotCommand('char', $db, $cms_user_priv, $cms_user_id);
            if ($err) return ['content' => $err, 'flags' => 64];

            $name = preg_replace('/[^a-zA-Z0-9_\- ]/', '', $opt('name') ?? '');
            if (!$name) return ['content' => '❌ Please provide a character name.', 'flags' => 64];

            try {
                $stmt = $db->prepare("
                    SELECT `Name` AS name, `Class` AS class_id, `Level` AS level, `Realm` AS realm_id,
                           `RealmPoints` AS realm_points, `GuildID`, `CreationDate` AS creation_date,
                           (`KillsAlbionPlayers`+`KillsMidgardPlayers`+`KillsHiberniaPlayers`) AS kill_count
                    FROM   `dolcharacters` 
                    WHERE  `Name` = ? 
                    LIMIT  1
                ");
                $stmt->execute([$name]);
                $char = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                if (function_exists('aldhran_log')) {
                    aldhran_log('BOT_CHAR_ERROR', $e->getMessage() . ' | ' . $e->getTraceAsString());
                }
                return ['content' => '❌ Database error.', 'flags' => 64];
            }

            if (!$char) return ['content' => "❌ Character **{$name}** not found.", 'flags' => 64];

            $char['guild_name'] = '';
            if (!empty($char['GuildID'])) {
                try {
                    $gStmt = $db->prepare("SELECT `GuildName` FROM `guild` WHERE `GuildID` = ? LIMIT 1");
                    $gStmt->execute([$char['GuildID']]);
                    $char['guild_name'] = $gStmt->fetchColumn() ?: '';
                } catch (Throwable $e) {}
            }

            $realm_names = [1 => 'Albion', 2 => 'Midgard', 3 => 'Hibernia'];
            $realm = $realm_names[(int)$char['realm_id']] ?? 'Unknown';
            $guild = !empty($char['guild_name']) ? $char['guild_name'] : '—';
            $realm_rank = "N/A"; 

            return [
                'embeds' => [[
                    'title'  => "⚔️ {$char['name']}",
                    'color'  => match((int)$char['realm_id']) { 1 => 0xc0392b, 2 => 0x2980b9, 3 => 0x27ae60, default => 0x95a5a6 },
                    'fields' => [
                        ['name' => 'Realm',        'value' => $realm,                                  'inline' => true],
                        ['name' => 'Level',        'value' => (string)$char['level'],                  'inline' => true],
                        ['name' => 'Class',        'value' => "ID {$char['class_id']}",                'inline' => true],
                        ['name' => 'Guild',        'value' => $guild,                                  'inline' => true],
                        ['name' => 'Realm Points', 'value' => number_format((int)$char['realm_points']), 'inline' => true],
                        ['name' => 'Realm Rank',   'value' => $realm_rank,                             'inline' => true],
                    ],
                    'footer'    => ['text' => 'DAoC CMS'],
                    'timestamp' => date('c'),
                ]]
            ];
        })(),

        'guild' => (function() use ($db, $opt, $cms_user_id, $cms_user_priv): array {
            $err = checkBotCommand('guild', $db, $cms_user_priv, $cms_user_id);
            if ($err) return ['content' => $err, 'flags' => 64];

            $name = preg_replace('/[^a-zA-Z0-9_\- ]/', '', $opt('name') ?? '');
            if (!$name) return ['content' => '❌ Please provide a guild name.', 'flags' => 64];

            try {
                $stmt = $db->prepare("
                    SELECT `GuildID`, `GuildName` AS name, `Realm` AS realm, `RealmPoints` AS realm_points
                    FROM   `guild`
                    WHERE  `GuildName` = ?
                    LIMIT  1
                ");
                $stmt->execute([$name]);
                $guild = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$guild) return ['content' => "❌ Guild **{$name}** not found.", 'flags' => 64];

                $cStmt = $db->prepare("SELECT COUNT(*) FROM `dolcharacters` WHERE `GuildID` = ?");
                $cStmt->execute([$guild['GuildID']]);
                $guild['member_count'] = (int)$cStmt->fetchColumn();

            } catch (Throwable $e) {
                if (function_exists('aldhran_log')) {
                    aldhran_log('BOT_GUILD_ERROR', $e->getMessage() . ' | ' . $e->getTraceAsString());
                }
                return ['content' => '❌ Database error.', 'flags' => 64];
            }

            $realm_names = [1 => 'Albion', 2 => 'Midgard', 3 => 'Hibernia'];
            $realm = $realm_names[(int)$guild['realm']] ?? 'Unknown';

            return [
                'embeds' => [[
                    'title'  => "🛡️ {$guild['name']}",
                    'color'  => 0xc5a059,
                    'fields' => [
                        ['name' => 'Realm',        'value' => $realm,                                  'inline' => true],
                        ['name' => 'Members',      'value' => (string)$guild['member_count'],          'inline' => true],
                        ['name' => 'Realm Points', 'value' => number_format((int)$guild['realm_points']), 'inline' => true],
                    ],
                    'footer'    => ['text' => 'DAoC CMS'],
                    'timestamp' => date('c'),
                ]]
            ];
        })(),

        'leaderboard' => (function() use ($db, $opt, $cms_user_id, $cms_user_priv): array {
            $err = checkBotCommand('leaderboard', $db, $cms_user_priv, $cms_user_id);
            if ($err) return ['content' => $err, 'flags' => 64];

            $type    = $opt('type') ?? 'realm_points';
            $allowed = ['realm_points', 'level', 'kills'];
            if (!in_array($type, $allowed)) $type = 'realm_points';

            $col = match($type) {
                'level' => '`Level`',
                'kills' => '`KillsAlbionPlayers`+`KillsMidgardPlayers`+`KillsHiberniaPlayers`',
                default => '`RealmPoints`',
            };
            $title_map = [
                'realm_points' => '🏆 Realm Points Leaderboard',
                'level'        => '📈 Level Leaderboard',
                'kills'        => '⚔️ Kill Leaderboard',
            ];

            try {
                $rows = $db->query("
                    SELECT `Name` AS name, `Realm` AS realm_id, ({$col}) AS score
                    FROM   `dolcharacters`
                    ORDER  BY ({$col}) DESC
                    LIMIT  10
                ")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { $rows = []; }

            $medals      = ['🥇', '🥈', '🥉'];
            $realm_emoji = [1 => '🔴', 2 => '🔵', 3 => '🟢'];
            $lines = [];
            foreach ($rows as $i => $r) {
                $pos     = $medals[$i] ?? ('`' . ($i + 1) . '.`');
                $realm   = $realm_emoji[(int)$r['realm_id']] ?? '⚪';
                $score   = number_format((int)$r['score']);
                $lines[] = "{$pos} {$realm} **{$r['name']}** — {$score}";
            }

            return [
                'embeds' => [[
                    'title'       => $title_map[$type],
                    'description' => implode("\n", $lines) ?: 'No data.',
                    'color'       => 0xc5a059,
                    'footer'      => ['text' => 'DAoC CMS'],
                    'timestamp'   => date('c'),
                ]]
            ];
        })(),

        'aisk' => (function() use ($db, $botSettings, $opt, $discord_id, $cms_user_id, $cms_user_priv): array {
            $err = checkBotCommand('aisk', $db, $cms_user_priv, $cms_user_id);
            if ($err) return ['content' => $err, 'flags' => 64];

            $question = trim($opt('question') ?? '');
            if (!$question) return ['content' => '❌ Please provide a question.', 'flags' => 64];

            try {
                $ai     = new AiManager($db, $botSettings, $cms_user_id, $cms_user_priv);
                $result = $ai->request('discord', 'answer_question', [
                    'question'     => $question,
                    'discord_user' => $discord_id,
                    'source'       => 'discord_slash',
                ]);
            } catch (Throwable $e) {
                return ['content' => '❌ AI request failed: ' . $e->getMessage(), 'flags' => 64];
            }

            if (($result['status'] ?? '') !== 'ok') {
                return ['content' => '❌ ' . ($result['message'] ?? 'AI error'), 'flags' => 64];
            }

            $answer = $result['result']['suggestion'] ?? '(no answer)';
            if (mb_strlen($answer) > 3900) $answer = mb_substr($answer, 0, 3900) . '…';

            return [
                'embeds' => [[
                    'title'       => '🤖 AI Answer',
                    'description' => $answer,
                    'color'       => 0x8e44ad,
                    'fields'      => [
                        ['name' => 'Question', 'value' => mb_substr($question, 0, 200), 'inline' => false],
                    ],
                    'footer'    => ['text' => 'DAoC CMS AI · ' . ($result['provider'] ?? '')],
                    'timestamp' => date('c'),
                ]]
            ];
        })(),

        'broadcast' => (function() use ($db, $botSettings, $opt, $discord_id, $cms_user_id, $cms_user_priv): array {
            $err = checkBotCommand('broadcast', $db, $cms_user_priv, $cms_user_id);
            if ($err) return ['content' => $err, 'flags' => 64];

            $message = trim($opt('message') ?? '');
            if (!$message) return ['content' => '❌ Please provide a message.', 'flags' => 64];

            $webhook_url = $botSettings->data['discord_webhook_url'] ?? '';
            if ($webhook_url) {
                $ch = curl_init($webhook_url);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode(['content' => "📢 **Broadcast:** {$message}"]),
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 5,
                ]);
                curl_exec($ch);
                curl_close($ch);
            }

            if (function_exists('aldhran_log')) {
                aldhran_log('BOT_BROADCAST', "Discord broadcast by user $discord_id: $message", $cms_user_id);
            }
            return ['content' => "✅ Broadcast sent: **{$message}**", 'flags' => 64];
        })(),

        'gmcall' => (function() use ($db, $botSettings, $opt, $discord_id, $user, $cms_user_id, $cms_user_priv): array {
            $err = checkBotCommand('gmcall', $db, $cms_user_priv, $cms_user_id);
            if ($err) return ['content' => $err, 'flags' => 64];

            $message = trim($opt('message') ?? '');
            if (!$message) return ['content' => '❌ Please describe your issue.', 'flags' => 64];

            $caller = $user['username'] ?? 'Unknown';
            if (function_exists('aldhran_log')) {
                aldhran_log('BOT_GMCALL', "GM call from Discord user $caller ($discord_id): $message");
            }

            $channel_id    = $botSettings->data['bot_channel_id']  ?? '';
            $admin_role_id = $botSettings->data['admin_role_id']   ?? '';
            $discord_token = $botSettings->data['discord_token']   ?? '';

            if ($channel_id && $discord_token) {
                $ping    = $admin_role_id ? "<@&{$admin_role_id}>" : '@here';
                $content = "{$ping} 🆘 **GM Call** from **{$caller}**: {$message}";
                $ch = curl_init("https://discord.com/api/v10/channels/{$channel_id}/messages");
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode(['content' => $content]),
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', "Authorization: Bot {$discord_token}"],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 5,
                ]);
                curl_exec($ch);
                curl_close($ch);
            }

            return ['content' => '✅ Your GM call has been sent. A staff member will contact you shortly.', 'flags' => 64];
        })(),

        'createguildchannel' => (function() use ($db, $cms_user_id, $cms_user_priv, $discord_id, $dispatcher, $opt): array {
            $err = checkBotCommand('createguildchannel', $db, $cms_user_priv, $cms_user_id);
            if ($err) return ['content' => $err, 'flags' => 64];

            $guildname = preg_replace('/[^a-zA-Z0-9_\- ]/', '', $opt('guildname') ?? '');

            try {
                if ($guildname !== '') {
                    $stmt = $db->prepare("
                        SELECT g.GuildID, g.GuildName, g.discord_channel_id 
                        FROM users u 
                        JOIN dolcharacters c ON u.username = c.AccountName 
                        JOIN guild g ON c.GuildID = g.GuildID 
                        WHERE u.discord_id = ? AND g.GuildName = ?
                        ORDER BY c.LastPlayed DESC 
                        LIMIT 1
                    ");
                    $stmt->execute([$discord_id, $guildname]);
                } else {
                    $stmt = $db->prepare("
                        SELECT g.GuildID, g.GuildName, g.discord_channel_id 
                        FROM users u 
                        JOIN dolcharacters c ON u.username = c.AccountName 
                        JOIN guild g ON c.GuildID = g.GuildID 
                        WHERE u.discord_id = ?
                        ORDER BY c.LastPlayed DESC 
                        LIMIT 1
                    ");
                    $stmt->execute([$discord_id]);
                }
                $guild = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                return ['content' => '❌ Database error while checking guild membership.', 'flags' => 64];
            }

            if (!$guild) {
                return ['content' => '❌ You are not currently in a guild' . ($guildname ? ' with that name' : '') . ', or your ingame account is not linked.', 'flags' => 64];
            }

            if (!empty($guild['discord_channel_id'])) {
                return ['content' => "❌ Your guild **{$guild['GuildName']}** already has a channel: <#{$guild['discord_channel_id']}>", 'flags' => 64];
            }

            $res = $dispatcher->dispatch('create_guild_channel', [
                'guild_name' => $guild['GuildName'],
                'discord_id' => $discord_id
            ]);

            if (($res['status'] ?? '') !== 'ok' || empty($res['channel_id'])) {
                return ['content' => '❌ Failed to create the Discord channel: ' . ($res['message'] ?? 'Unknown'), 'flags' => 64];
            }

            try {
                $upd = $db->prepare("UPDATE guild SET discord_channel_id = ? WHERE GuildID = ?");
                $upd->execute([$res['channel_id'], $guild['GuildID']]);
            } catch (Throwable $e) {
                return ['content' => "⚠️ Channel <#{$res['channel_id']}> was created, but could not be linked in the DAoC CMS.", 'flags' => 64];
            }

            return ['content' => "✅ Guild channel for **{$guild['GuildName']}** was successfully created: <#{$res['channel_id']}>", 'flags' => 64];
        })(),

        'help' => (function() use ($db, $cms_user_id, $cms_user_priv): array {
            $all_commands = [
                ['cmd' => 'status',      'desc' => 'Show server status and player count'],
                ['cmd' => 'players',     'desc' => 'List currently online players'],
                ['cmd' => 'char',        'desc' => 'Look up a character — `/char <name>`'],
                ['cmd' => 'guild',       'desc' => 'Look up a guild — `/guild <name>`'],
                ['cmd' => 'leaderboard', 'desc' => 'Top 10 — `/leaderboard [realm_points|level|kills]`'],
                ['cmd' => 'aisk',        'desc' => 'Ask the AI a question — `/aisk <question>`'],
                ['cmd' => 'gmcall',      'desc' => 'Send a support request — `/gmcall <message>`'],
                ['cmd' => 'broadcast',   'desc' => 'Post a broadcast message — `/broadcast <msg>`'],
                ['cmd' => 'createguildchannel', 'desc' => 'Creates a private guild channel for your ingame guild'],
            ];

            $fields = [];
            foreach ($all_commands as $c) {
                $err = checkBotCommand($c['cmd'], $db, $cms_user_priv, $cms_user_id);
                if ($err === null) {
                    $fields[] = ['name' => "`/{$c['cmd']}`", 'value' => $c['desc'], 'inline' => false];
                }
            }

            if (empty($fields)) {
                return ['content' => '❌ You don\'t have access to any commands.', 'flags' => 64];
            }

            return [
                'embeds' => [[
                    'title'       => '⚔️ DAoC CMS Bot Commands',
                    'color'       => 0xc5a059,
                    'description' => 'All commands available to you:',
                    'fields'      => $fields,
                    'footer'      => ['text' => 'DAoC CMS'],
                    'timestamp'   => date('c'),
                ]]
            ];
        })(),

        default => ['content' => "❌ Unknown command: `/{$command}`", 'flags' => 64],
    };

    echo json_encode(['type' => 4, 'data' => $response_data]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown interaction type']);
