<?php
// SPDX-License-Identifier: GPL-3.0-only
class BotEventDispatcher {

    private PDO $db;
    private BotSettings $botSettings;

    public const EVT_SUGGESTION_ACCEPTED  = 'suggestion_accepted';
    public const EVT_SUGGESTION_REJECTED  = 'suggestion_rejected';
    public const EVT_USER_BANNED          = 'user_banned';
    public const EVT_USER_UNBANNED        = 'user_unbanned';
    public const EVT_SERVER_MAINTENANCE   = 'server_maintenance';
    public const EVT_SERVER_LIVE          = 'server_live';
    public const EVT_BROADCAST            = 'broadcast';
    public const EVT_ITEM_CREATED         = 'item_created';
    public const EVT_ITEM_UPDATED         = 'item_updated';
    public const EVT_MOB_UPDATED          = 'mob_updated';
    public const EVT_QUEST_UPDATED        = 'quest_updated';
    public const EVT_AI_RESULT_READY      = 'ai_result_ready';
    public const EVT_BOT_RELOAD_COMMANDS  = 'reload_commands';
    public const EVT_GM_CALL_RESPONSE     = 'gm_call_response';

    public const INCOMING_STATUS          = 'status';
    public const INCOMING_PLAYERS         = 'players';
    public const INCOMING_CHAR_LOOKUP     = 'char_lookup';
    public const INCOMING_GUILD_LOOKUP    = 'guild_lookup';
    public const INCOMING_AI_ASK         = 'ai_ask';
    public const INCOMING_LEADERBOARD    = 'leaderboard';
    public const INCOMING_GM_CALL        = 'gm_call';         
    public const INCOMING_FORBIDDEN_HIT  = 'forbidden_word_hit'; 
    public const INCOMING_GUILD_CHAT     = 'guild_chat';
    public const INCOMING_CREATE_GUILD_CHANNEL = 'create_guild_channel';

    public function __construct(PDO $db, BotSettings $botSettings) {
        $this->db          = $db;
        $this->botSettings = $botSettings;
    }

    public function dispatch(string $event, array $data = [], ?int $userId = null): array {
        if (!$this->botSettings->isActive()) {
            return ['status' => 'skipped', 'reason' => 'bot_inactive'];
        }
        $result = $this->botSettings->sendCommand($event, $data);
        aldhran_log('BOT_EVENT_DISPATCH', "Event: $event | Status: " . ($result['status'] ?? 'unknown'), $userId);
        return $result;
    }

    public function onSuggestionAccepted(array $suggRow, array $applyResult, int $adminId): void {
        if (!$this->botSettings->isActive()) return;
        $this->dispatch(self::EVT_SUGGESTION_ACCEPTED, [
            'suggestion_id' => $suggRow['id'],
            'module'        => $suggRow['module_context'] ?? 'unknown',
            'target_type'   => $suggRow['target_type']   ?? '',
            'target_id'     => $suggRow['target_id']     ?? 0,
            'applied'       => $applyResult['ok']        ?? false,
            'message'       => $applyResult['message']   ?? '',
            'admin_id'      => $adminId,
        ], $adminId);
    }

    public function onSuggestionRejected(array $suggRow, string $note, int $adminId): void {
        if (!$this->botSettings->isActive()) return;
        $this->dispatch(self::EVT_SUGGESTION_REJECTED, [
            'suggestion_id' => $suggRow['id'],
            'module'        => $suggRow['module_context'] ?? 'unknown',
            'note'          => $note,
            'admin_id'      => $adminId,
        ], $adminId);
    }

    public function onUserBanned(int $targetUserId, string $reason, int $adminId): void {
        if (!$this->botSettings->isActive()) return;
        $stmt = $this->db->prepare("SELECT username, discord_id FROM users WHERE id = ?");
        $stmt->execute([$targetUserId]);
        $user = $stmt->fetch();
        $this->dispatch(self::EVT_USER_BANNED, [
            'target_user_id' => $targetUserId,
            'username'       => $user['username']   ?? 'Unknown',
            'discord_id'     => $user['discord_id'] ?? null,
            'reason'         => $reason,
            'admin_id'       => $adminId,
        ], $adminId);
    }

    public function onUserUnbanned(int $targetUserId, int $adminId): void {
        if (!$this->botSettings->isActive()) return;
        $stmt = $this->db->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$targetUserId]);
        $user = $stmt->fetch();
        $this->dispatch(self::EVT_USER_UNBANNED, [
            'target_user_id' => $targetUserId,
            'username'       => $user['username'] ?? 'Unknown',
            'admin_id'       => $adminId,
        ], $adminId);
    }

    public function onMaintenanceToggle(bool $isNowActive, int $adminId): void {
        if (!$this->botSettings->isActive()) return;
        $event = $isNowActive ? self::EVT_SERVER_MAINTENANCE : self::EVT_SERVER_LIVE;
        $this->dispatch($event, [
            'maintenance_active' => $isNowActive,
            'admin_id'           => $adminId,
            'timestamp'          => time(),
        ], $adminId);
    }

    public function onBroadcast(string $message, int $adminId, ?string $channel = null): array {
        if (!$this->botSettings->isActive()) {
            return ['status' => 'skipped', 'reason' => 'bot_inactive'];
        }
        return $this->dispatch(self::EVT_BROADCAST, [
            'message'    => $message,
            'channel_id' => $channel ?? ($this->botSettings->data['bot_channel_id'] ?? null),
            'admin_id'   => $adminId,
        ], $adminId);
    }

    public function onItemChanged(int $itemId, string $itemName, string $changeType, int $adminId): void {
        if (!$this->botSettings->isActive()) return;
        $event = $changeType === 'created' ? self::EVT_ITEM_CREATED : self::EVT_ITEM_UPDATED;
        $this->dispatch($event, [
            'item_id'   => $itemId,
            'item_name' => $itemName,
            'change'    => $changeType,
            'admin_id'  => $adminId,
        ], $adminId);
    }

    public function onMobUpdated(int $mobId, string $mobName, int $adminId): void {
        if (!$this->botSettings->isActive()) return;
        $this->dispatch(self::EVT_MOB_UPDATED, [
            'mob_id'   => $mobId,
            'mob_name' => $mobName,
            'admin_id' => $adminId,
        ], $adminId);
    }

    public function onQuestUpdated(int $questId, string $questName, int $adminId): void {
        if (!$this->botSettings->isActive()) return;
        $this->dispatch(self::EVT_QUEST_UPDATED, [
            'quest_id'   => $questId,
            'quest_name' => $questName,
            'admin_id'   => $adminId,
        ], $adminId);
    }

    public function onAiTaskDone(array $taskRow): void {
        if (!$this->botSettings->isActive()) return;
        $this->dispatch(self::EVT_AI_RESULT_READY, [
            'task_id' => $taskRow['task_id'],
            'module'  => $taskRow['module_context'],
            'user_id' => $taskRow['user_id'],
            'status'  => $taskRow['status'],
        ], $taskRow['user_id'] ?? null);
    }

    public function onCommandsUpdated(int $adminId): void {
        if (!$this->botSettings->isActive()) return;
        $this->dispatch(self::EVT_BOT_RELOAD_COMMANDS, [
            'admin_id'  => $adminId,
            'timestamp' => time(),
        ], $adminId);
    }

    public function onGmCallResponded(int $callId, string $response, int $adminId): void {
        if (!$this->botSettings->isActive()) return;

        try {
            $stmt = $this->db->prepare("SELECT discord_user, discord_id, channel FROM spike_gm_calls WHERE id = ? LIMIT 1");
            $stmt->execute([$callId]);
            $call = $stmt->fetch();
        } catch (\Throwable $e) { return; }

        if (!$call) return;

        $this->dispatch(self::EVT_GM_CALL_RESPONSE, [
            'call_id'      => $callId,
            'discord_user' => $call['discord_user'] ?? '',
            'discord_id'   => $call['discord_id']   ?? null,
            'channel'      => $call['channel']       ?? null,
            'response'     => $response,
            'admin_id'     => $adminId,
        ], $adminId);
    }

    public function handleIncoming(array $payload): array {
        $command   = $payload['action']               ?? '';
        $timestamp = (int)($payload['meta']['timestamp'] ?? 0);
        $signature = $payload['signature']            ?? '';

        if (!$this->botSettings->verifySignature($command, $timestamp, $signature)) {
            return ['status' => 'error', 'message' => 'Invalid signature'];
        }

        $params = $payload['params'] ?? [];

        $response = match($command) {
            self::INCOMING_STATUS       => $this->handleStatus($params),
            self::INCOMING_PLAYERS      => $this->handlePlayers($params),
            self::INCOMING_CHAR_LOOKUP  => $this->handleCharLookup($params),
            self::INCOMING_GUILD_LOOKUP => $this->handleGuildLookup($params),
            self::INCOMING_AI_ASK       => $this->handleAiAsk($params),
            self::INCOMING_LEADERBOARD  => $this->handleLeaderboard($params),
            self::INCOMING_GM_CALL      => $this->handleGmCall($params),      
            self::INCOMING_FORBIDDEN_HIT=> $this->handleForbiddenWordHit($params), 
            self::INCOMING_GUILD_CHAT   => $this->handleGuildChat($params),
            self::INCOMING_CREATE_GUILD_CHANNEL => $this->handleCreateGuildChannel($params),
            'ping'                      => ['status' => 'ok', 'result' => 'pong', 'time' => time()],
            default                     => ['status' => 'error', 'message' => "Unknown command: $command"],
        };

        return $response;
    }

    private function handleStatus(array $params): array {
        $ip   = $GLOBALS['cms_settings']['game_server_ip']   ?? '127.0.0.1';
        $port = (int)($GLOBALS['cms_settings']['game_server_port'] ?? 10300);
        $fp   = @fsockopen($ip, $port, $e, $es, 1);
        $online = (bool)$fp;
        if ($fp) fclose($fp);

        $players = 0;
        try {
            $stmt = $this->db->query("SELECT COUNT(*) FROM `dolcharacters` WHERE `LastTimeRowUpdated` > DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
            if ($stmt) $players = (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {}

        return [
            'status' => 'ok',
            'result' => [
                'server_online'  => $online,
                'players_online' => $players,
                'timestamp'      => time(),
            ],
        ];
    }

    private function handlePlayers(array $params): array {
        try {
            $stmt = $this->db->query("
                SELECT `Name` as name, `Class` as class_id, `Level` as level, `Realm` as realm_id
                FROM   `dolcharacters`
                WHERE  `LastTimeRowUpdated` > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                ORDER  BY `Level` DESC
                LIMIT  25
            ");
            if (!$stmt) throw new \Exception("Query failed: " . ($this->db->errorInfo()[2] ?? 'Unknown Error'));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($rows as &$r) {
                $r['level'] = (int)$r['level'];
                $r['class_id'] = (int)$r['class_id'];
                $r['realm_id'] = (int)$r['realm_id'];
            }
            
            return ['status' => 'ok', 'result' => ['players' => $rows, 'count' => count($rows)]];
        } catch (\Throwable $e) { 
            aldhran_log('BOT_PLAYERS_ERROR', $e->getMessage() . ' | ' . $e->getTraceAsString());
            return ['status' => 'error', 'message' => 'DB error: ' . $e->getMessage()];
        }
    }

    private function handleCharLookup(array $params): array {
        $rawName = $params['name'] ?? $params['character'] ?? $params['query'] ?? reset($params) ?? '';
        if (is_array($rawName)) $rawName = '';
        
        $name = preg_replace('/[^a-zA-Z0-9_\- ]/', '', (string)$rawName);
        
        if (!$name) {
            return ['status' => 'error', 'message' => 'No character name provided'];
        }

        try {
            $stmt = $this->db->prepare("
                SELECT `Name` as name, 
                       `Class` as class_id, 
                       `Level` as level, 
                       COALESCE(`Experience`, 0) as experience,
                       `Realm` as realm_id, 
                       COALESCE(`RealmPoints`, 0) as realm_points, 
                       NULL as realm_rank,
                       `GuildID`, 
                       `CreationDate` as creation_date
                FROM   `dolcharacters`
                WHERE  `Name` = ?
                LIMIT  1
            ");
            if (!$stmt) throw new \Exception("Prepare fail: " . ($this->db->errorInfo()[2] ?? 'Unknown Error'));
            
            $ok = $stmt->execute([$name]);
            if (!$ok) throw new \Exception("Exec fail: " . ($stmt->errorInfo()[2] ?? 'Unknown Error'));
            
            $char = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$char) {
                return ['status' => 'error', 'message' => "Character '$name' not found"];
            }

            $char['guild_name'] = '';
            if (!empty($char['GuildID'])) {
                $gStmt = $this->db->prepare("SELECT `GuildName` FROM `guild` WHERE `GuildID` = ? LIMIT 1");
                $gStmt->execute([$char['GuildID']]);
                $gName = $gStmt->fetchColumn();
                if ($gName) {
                    $char['guild_name'] = $gName;
                }
            }
            unset($char['GuildID']);
            
            $char['class_id']     = (int)$char['class_id'];
            $char['level']        = (int)$char['level'];
            $char['experience']   = (int)$char['experience'];
            $char['realm_id']     = (int)$char['realm_id'];
            $char['realm_points'] = (int)$char['realm_points'];
            
            return ['status' => 'ok', 'result' => $char];
            
        } catch (\Throwable $e) {
            aldhran_log('BOT_CHARLOOKUP_ERROR', $e->getMessage() . ' | ' . $e->getTraceAsString());
            return ['status' => 'error', 'message' => 'DB error'];
        }
    }

    private function handleGuildLookup(array $params): array {
        $rawName = $params['name'] ?? $params['guild'] ?? $params['query'] ?? reset($params) ?? '';
        if (is_array($rawName)) $rawName = '';
        
        $name = preg_replace('/[^a-zA-Z0-9_\- ]/', '', (string)$rawName);
        if (!$name) return ['status' => 'error', 'message' => 'No guild name provided'];

        try {
            $stmt = $this->db->prepare("
                SELECT `GuildID`,
                       `GuildName` as name, 
                       `Realm` as realm, 
                       `RealmPoints` as realm_points
                FROM   `guild`
                WHERE  `GuildName` = ?
                LIMIT  1
            ");
            if (!$stmt) throw new \Exception("Prepare fail: " . ($this->db->errorInfo()[2] ?? 'Unknown'));
            
            $ok = $stmt->execute([$name]);
            if (!$ok) throw new \Exception("Exec fail: " . ($stmt->errorInfo()[2] ?? 'Unknown'));
            
            $guild = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$guild) return ['status' => 'error', 'message' => "Guild '$name' not found"];
            
            $cStmt = $this->db->prepare("SELECT COUNT(*) FROM `dolcharacters` WHERE `GuildID` = ?");
            $cStmt->execute([$guild['GuildID']]);
            $guild['member_count'] = (int)$cStmt->fetchColumn();
            unset($guild['GuildID']);
            
            $guild['realm'] = (int)$guild['realm'];
            $guild['realm_points'] = (int)$guild['realm_points'];
            
            return ['status' => 'ok', 'result' => $guild];
            
        } catch (\Throwable $e) {
            aldhran_log('BOT_GUILDLOOKUP_ERROR', $e->getMessage() . ' | ' . $e->getTraceAsString());
            return ['status' => 'error', 'message' => 'DB error'];
        }
    }

    private function handleAiAsk(array $params): array {
        $question      = trim($params['question']        ?? '');
        $discordUserId = $params['discord_user_id']      ?? null;

        if (!$question) return ['status' => 'error', 'message' => 'No question provided'];

        try {
            $cmd = $this->db->prepare("SELECT min_authlevel, is_enabled FROM cms_bot_commands WHERE command = 'aisk' LIMIT 1");
            $cmd->execute();
            $cmdRow = $cmd->fetch();
            if ($cmdRow && !$cmdRow['is_enabled']) {
                return ['status' => 'error', 'message' => 'AI commands are currently disabled.'];
            }
        } catch (\Throwable $e) {}

        $cmsUserId   = null;
        $cmsUserPriv = 1;
        if ($discordUserId) {
            try {
                $uStmt = $this->db->prepare("SELECT id, priv_level FROM users WHERE discord_id = ? LIMIT 1");
                $uStmt->execute([$discordUserId]);
                $uRow = $uStmt->fetch();
                if ($uRow) {
                    $cmsUserId   = (int)$uRow['id'];
                    $cmsUserPriv = (int)$uRow['priv_level'];
                }
            } catch (\Throwable $e) {}
        }

        try {
            global $botSettings;
            $ai     = new AiManager($this->db, $botSettings, $cmsUserId, $cmsUserPriv);
            $result = $ai->request('discord', 'answer_question', [
                'question'     => $question,
                'discord_user' => $discordUserId,
                'source'       => 'discord_bot',
            ]);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'AI request failed: ' . $e->getMessage()];
        }

        if (($result['status'] ?? '') !== 'ok') {
            return ['status' => 'error', 'message' => $result['message'] ?? 'AI error'];
        }

        return [
            'status' => 'ok',
            'result' => [
                'answer'  => $result['result']['suggestion'] ?? '',
                'task_id' => $result['task_id'],
            ],
        ];
    }

    private function handleLeaderboard(array $params): array {
        $type = in_array($params['type'] ?? '', ['realm_points','level','kills']) ? $params['type'] : 'realm_points';

        try {
            $col  = match($type) {
                'level' => '`Level`',
                'kills' => '(`KillsAlbionPlayers` + `KillsMidgardPlayers` + `KillsHiberniaPlayers`)',
                default => '`RealmPoints`',
            };
            $stmt = $this->db->query("
                SELECT `Name` as name, `Realm` as realm_id, $col AS score
                FROM   `dolcharacters`
                ORDER  BY $col DESC
                LIMIT  10
            ");
            if (!$stmt) throw new \Exception("Query fail: " . ($this->db->errorInfo()[2] ?? 'Unknown'));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach($rows as &$r) {
                $r['realm_id'] = (int)$r['realm_id'];
                $r['score']    = (int)$r['score'];
            }

            return ['status' => 'ok', 'result' => ['type' => $type, 'entries' => $rows]];
        } catch (\Throwable $e) { 
            aldhran_log('BOT_LEADERBOARD_ERROR', $e->getMessage() . ' | ' . $e->getTraceAsString());
            return ['status' => 'error', 'message' => 'DB error'];
        }
    }

    private function handleGmCall(array $params): array {
        $discord_user = trim(substr($params['discord_user'] ?? 'Unknown', 0, 100));
        $discord_id   = trim(substr($params['discord_id']   ?? '',        0, 30));
        $message      = trim(substr($params['message']      ?? '',        0, 2000));
        $channel      = trim(substr($params['channel']      ?? '',        0, 100));

        if (empty($message)) {
            return ['status' => 'error', 'message' => 'Empty GM call message'];
        }

        try {
            $this->db->prepare("
                INSERT INTO spike_gm_calls (discord_user, discord_id, message, channel, status, created_at)
                VALUES (?, ?, ?, ?, 'open', NOW())
            ")->execute([$discord_user, $discord_id ?: null, $message, $channel ?: null]);

            $call_id = (int)$this->db->lastInsertId();

            $staff = $this->db->query("SELECT id FROM users WHERE priv_level >= 3 AND standing < 5")->fetchAll(PDO::FETCH_COLUMN);
            $stmt_notif = $this->db->prepare("
                INSERT INTO spike_notifications (user_id, type, created_at)
                VALUES (?, 'gm_call', NOW())
            ");
            foreach ($staff as $staff_uid) {
                $stmt_notif->execute([$staff_uid]);
            }

            aldhran_log('GM_CALL_RECEIVED', "GM Call from Discord: $discord_user — " . mb_substr($message, 0, 100));

            return [
                'status' => 'ok',
                'result' => [
                    'call_id' => $call_id,
                    'message' => 'GM call received and forwarded to staff.',
                ],
            ];

        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'DB error'];
        }
    }

    private function handleForbiddenWordHit(array $params): array {
        $discord_user = trim(substr($params['discord_user'] ?? 'Unknown', 0, 100));
        $discord_id   = trim($params['discord_id']   ?? '');
        $word         = trim(substr($params['word']         ?? '',        0, 255));
        $message      = trim(substr($params['message']      ?? '',        0, 1000));
        $channel      = trim($params['channel']      ?? '');

        $is_discord_word = false;
        try {
            $stmt = $this->db->prepare("SELECT id, action FROM spike_forbidden_words WHERE word = ? AND scope IN ('discord','both') LIMIT 1");
            $stmt->execute([strtolower($word)]);
            $fw_row = $stmt->fetch();
            if ($fw_row) $is_discord_word = true;
        } catch (\Throwable $e) {}

        aldhran_log(
            'FORBIDDEN_WORD_DISCORD',
            "User: $discord_user | Word: $word | Channel: $channel | Msg: " . mb_substr($message, 0, 200)
        );

        if ($discord_id && $is_discord_word && ($fw_row['action'] ?? '') === 'flag') {
            try {
                $uStmt = $this->db->prepare("SELECT id FROM users WHERE discord_id = ? LIMIT 1");
                $uStmt->execute([$discord_id]);
                $cms_user = $uStmt->fetch();
                if ($cms_user) {
                    aldhran_log(
                        'SECURITY_FLAG',
                        "Discord forbidden word by CMS user #{$cms_user['id']} ($discord_user): $word",
                        null,
                        $cms_user['id']
                    );
                }
            } catch (\Throwable $e) {}
        }

        return [
            'status' => 'ok',
            'result' => [
                'logged'    => true,
                'in_db'     => $is_discord_word,
                'call_id'   => null,
            ],
        ];
    }

    private function handleGuildChat(array $params): array {
        $channelId = trim($params['channel_id'] ?? '');
        $sender    = trim(substr($params['discord_user'] ?? $params['sender'] ?? 'Discord', 0, 50));
        $message   = trim(substr($params['message'] ?? '', 0, 2000));

        if (empty($channelId) || empty($message)) {
            return ['status' => 'error', 'message' => 'channel_id or message missing'];
        }

        $stmt = $this->db->prepare("SELECT GuildName FROM guild WHERE discord_channel_id = ? LIMIT 1");
        $stmt->execute([$channelId]);
        $guildName = $stmt->fetchColumn();

        if (empty($guildName)) {
            return ['status' => 'error', 'message' => 'No guild linked to this Discord channel'];
        }

        try {
            $host   = $GLOBALS['cms_settings']['game_server_console_host'] ?? '127.0.0.1';
            $port   = $GLOBALS['cms_settings']['game_server_console_port'] ?? 5100;
            $secret = $GLOBALS['cms_settings']['game_server_console_secret'] ?? '';

            $ch = curl_init("http://{$host}:{$port}/guildchat");
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'X-Aldhran-Secret: ' . $secret,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'guild'   => $guildName,
                'sender'  => $sender,
                'message' => $message
            ]));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);

            $response = curl_exec($ch);
            $errno    = curl_errno($ch);
            $error    = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno !== 0) {
                error_log("handleGuildChat: curl failed to {$host}:{$port} - [$errno] $error");
                return ['status' => 'error', 'message' => "Bridge unreachable: $error"];
            }
            if ($httpCode < 200 || $httpCode >= 300) {
                error_log("handleGuildChat: game server returned HTTP $httpCode | Body: $response");
                return ['status' => 'error', 'message' => "Game server returned HTTP $httpCode"];
            }

            return ['status' => 'ok', 'result' => 'Sent to ingame', 'game_server_response' => $response];
        } catch (\Throwable $e) {
            error_log("handleGuildChat: exception - " . $e->getMessage());
            return ['status' => 'error', 'message' => 'Bridge error'];
        }
    }
    
    private function handleCreateGuildChannel(array $params): array {
        $discord_id = $params['discord_id'] ?? null;
        $guildname  = $params['guildname'] ?? '';
        
        if (!$discord_id) return ['status' => 'error', 'message' => 'No discord ID'];

        try {
            if ($guildname !== '') {
                $stmt = $this->db->prepare("
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
                $stmt = $this->db->prepare("
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
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'Database error while checking guild membership.'];
        }

        if (!$guild) {
            return ['status' => 'error', 'message' => 'You are not currently in a guild' . ($guildname ? ' with that name' : '') . ', or your in-game account is not linked.'];
        }

        if (!empty($guild['discord_channel_id'])) {
            return ['status' => 'error', 'message' => "Your guild **{$guild['GuildName']}** already has a channel: <#{$guild['discord_channel_id']}>"];
        }

        $res = $this->dispatch('create_guild_channel', [
            'guild_name' => $guild['GuildName'],
            'discord_id' => $discord_id
        ]);

        if (($res['status'] ?? '') !== 'ok' || empty($res['channel_id'])) {
            return ['status' => 'error', 'message' => 'Error creating channel in Discord: ' . ($res['message'] ?? 'Unknown')];
        }

        try {
            $upd = $this->db->prepare("UPDATE guild SET discord_channel_id = ? WHERE GuildID = ?");
            $upd->execute([$res['channel_id'], $guild['GuildID']]);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => "Channel <#{$res['channel_id']}> was created but could not be linked in the DAoC CMS."];
        }

        return ['status' => 'ok', 'result' => "Guild channel for **{$guild['GuildName']}** was created successfully: <#{$res['channel_id']}>"];
    }
}