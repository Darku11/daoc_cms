<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_ACP')) exit;
if ($userPriv < 4) { echo '<div class="acp-empty">Access denied.</div>'; return; }

try { $db->exec("ALTER TABLE `cms_bot_settings` ADD COLUMN `bot_script_path` VARCHAR(500) DEFAULT NULL"); } catch (Exception $e) {}

$msg_ok  = '';
$msg_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_bot_settings']) && $userPriv >= 5) {
    checkToken($_POST['csrf_token'] ?? '');

    $tab = $_POST['active_tab'] ?? 'bot';

    if ($tab === 'bot') {
        $newToken  = trim($_POST['discord_token'] ?? '');
        $newSecret = trim($_POST['socket_secret'] ?? '');
        
        $enableGuildChatSync = isset($_POST['guild_chat_sync']) ? 1 : 0;

        // Automatic DB migration for Guild Chat Sync & Live Events
        // Direct execution with error suppression avoids potential permission issues with SHOW COLUMNS
        try { $db->exec("ALTER TABLE `guild` ADD COLUMN `discord_channel_id` VARCHAR(50) DEFAULT NULL"); } catch (Exception $e) {}
        try { $db->exec("ALTER TABLE `cms_bot_settings` ADD COLUMN `guild_chat_sync` TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS `cms_live_events` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `event_type` varchar(50) NOT NULL,
                  `message` text NOT NULL,
                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`),
                  KEY `idx_event_type` (`event_type`),
                  KEY `idx_created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
            ");
        } catch (Exception $e) {}
        $stmt = $db->prepare("
            UPDATE cms_bot_settings SET
                discord_token        = IF(:discord_token != '', :discord_token_new, discord_token),
				socket_secret        = IF(:socket_secret != '', :socket_secret_new, socket_secret),
                bot_host             = :bot_host,
                bot_port             = :bot_port,
                bot_channel_id       = :bot_channel_id,
                admin_role_id        = :admin_role_id,
                reboot_delay_default = :reboot_delay,
                use_tls              = :use_tls,
                is_active            = :is_active,
                guild_chat_sync      = :guild_chat_sync,
                bot_script_path      = :bot_script_path
            WHERE id = 1
        ");
        $ok = $stmt->execute([
            ':discord_token'      => $newToken,
			':discord_token_new'  => $newToken,
			':socket_secret'      => $newSecret,
			':socket_secret_new'  => $newSecret,
            ':bot_host'       => trim($_POST['bot_host'] ?? '127.0.0.1'),
            ':bot_port'       => max(1, min(65535, (int)($_POST['bot_port'] ?? 15000))),
            ':bot_channel_id' => trim($_POST['bot_channel_id'] ?? ''),
            ':admin_role_id'  => trim($_POST['admin_role_id'] ?? ''),
            ':reboot_delay'   => max(0, (int)($_POST['reboot_delay_default'] ?? 5)),
            ':use_tls'        => isset($_POST['use_tls']) ? 1 : 0,
            ':is_active'      => isset($_POST['is_active']) ? 1 : 0,
            ':guild_chat_sync'=> $enableGuildChatSync,
            ':bot_script_path'=> trim($_POST['bot_script_path'] ?? ''),
        ]);
        
        if (empty($msg_err)) {
            $ok ? $msg_ok = 'Bot settings saved.' : $msg_err = 'Failed to save bot settings.';
        }

    } elseif ($tab === 'ai') {
        $provider  = in_array($_POST['ai_provider'] ?? '', ['none','gemini','lm_studio','groq','openai','anthropic'])
                     ? $_POST['ai_provider'] : 'none';
        $rawApiKey = trim($_POST['ai_api_key'] ?? '');
        if ($rawApiKey === '••••••••') $rawApiKey = ''; // Placeholder = don't change
        $apiUrl = trim($_POST['ai_api_url'] ?? 'http://localhost:1234/v1');
        $model  = trim($_POST['ai_model'] ?? '');

        $aiManager = new AiManager($db, $botSettings, $currentUserId, $userPriv);

        // Update the active provider pointer and its shared token/temperature limits.
        $stmt = $db->prepare("
            UPDATE cms_bot_settings SET
                ai_provider     = :provider,
                ai_max_tokens   = :max_tokens,
                ai_temperature  = :temperature
            WHERE id = 1
        ");
        $ok = $stmt->execute([
            ':provider'    => $provider,
            ':max_tokens'  => max(100, min(8000, (int)($_POST['ai_max_tokens'] ?? 1000))),
            ':temperature' => max(0.0, min(2.0, (float)($_POST['ai_temperature'] ?? 0.7))),
        ]);

        // Save key/URL/model ONLY for the provider chosen in the form —
        // other provider configs remain untouched as a result.
        if ($provider !== 'none') {
            $ok = $aiManager->saveProviderKey($provider, $rawApiKey, $apiUrl, $model) && $ok;
        }

        if (isset($_POST['module_prompts']) && is_array($_POST['module_prompts'])) {
            $sp = $db->prepare("
                INSERT INTO cms_ai_settings (setting_key, setting_value, provider, module_context)
                VALUES ('system_prompt', :val, 'all', :module)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = :uid
            ");
            foreach ($_POST['module_prompts'] as $mod => $prompt) {
                $mod = preg_replace('/[^a-z0-9_]/', '', $mod);
                $sp->execute([':val' => trim($prompt), ':module' => $mod, ':uid' => $currentUserId]);
            }
        }

        $ok ? $msg_ok = 'AI settings saved.' : $msg_err = 'Failed to save AI settings.';
        aldhran_log("AI_SETTINGS_UPDATE", "AI provider set to: $provider", $currentUserId);
    }

    // Reload BotSettings
    $botSettings->load();
}

// ── Test: Socket Ping ─────────────────────────────────────────
if (isset($_GET['ping_bot'])) {
    header('Content-Type: application/json');
    $result = $botSettings->sendCommand('ping');
    echo json_encode($result);
    exit;
}

// Note: "Start Bot" is handled by the standalone ajax_bot_start.php
// (not inline here) so its JSON response isn't wrapped in this page's HTML.

// ── Data ──────────────────────────────────────────────────────
$bs          = $botSettings->data;
$active_tab  = $_POST['active_tab'] ?? $_GET['tab'] ?? ($userPriv >= 5 ? 'bot' : 'status');

if ($userPriv < 5 && in_array($active_tab, ['bot', 'ai'])) {
    $active_tab = 'status';
}

$csrf        = generateToken();

// Load provider-specific configs (model/URL/key status per provider,
// never the decrypted key itself) — basis for the live switch
$aiManagerView    = new AiManager($db, $botSettings, $currentUserId, $userPriv);
$providerConfigs  = $aiManagerView->getAllProviderConfigs();
$activeProvider   = $bs['ai_provider'] ?? 'none';
$activeProviderCfg = $providerConfigs[$activeProvider] ?? ['api_url' => '', 'model' => '', 'has_key' => false];

$prompts_raw = $db->query("
    SELECT module_context, setting_value
    FROM cms_ai_settings
    WHERE setting_key = 'system_prompt' AND provider = 'all' AND module_context IS NOT NULL
")->fetchAll(PDO::FETCH_KEY_PAIR);

$module_prompts = [
    'item_creator'       => ['label' => 'Item Creator',       'icon' => 'fa-shield-alt'],
    'mob_editor'         => ['label' => 'Mob Editor',         'icon' => 'fa-map-location-dot'],
    'error_log'          => ['label' => 'Error Log',          'icon' => 'fa-bug'],
    'theme_editor'       => ['label' => 'Theme Editor',       'icon' => 'fa-paint-brush'],
    'translation_editor' => ['label' => 'Translation Editor', 'icon' => 'fa-language'],
    'discord'            => ['label' => 'Discord Bot',        'icon' => 'fa-comments'],
];

// AI Log Stats
$log_stats = $db->query("
    SELECT
        COUNT(*)                                              AS total,
        SUM(CASE WHEN status='ok'    THEN 1 ELSE 0 END)      AS ok,
        SUM(CASE WHEN status='error' THEN 1 ELSE 0 END)      AS errors,
        SUM(COALESCE(prompt_tokens,0)+COALESCE(completion_tokens,0)) AS total_tokens,
        AVG(duration_ms)                                      AS avg_ms
    FROM cms_ai_logs
    WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
")->fetch();

$pending_suggestions = (int)$db->query("SELECT COUNT(*) FROM cms_ai_suggestions WHERE status='pending'")->fetchColumn();
try {
    $queue_count = (int)$db->query("SELECT COUNT(*) FROM cms_ai_tasks WHERE status='queued'")->fetchColumn();
} catch (\Throwable $e) {
    $queue_count = 0;
}
?>

<div class="acp-s-decf5232">

    <?php if ($msg_ok): ?>
        <div class="bs-msg-ok"><i class="fas fa-check-circle"></i> <?= h($msg_ok) ?></div>
    <?php endif; ?>
    <?php if ($msg_err): ?>
        <div class="bs-msg-err"><i class="fas fa-exclamation-circle"></i> <?= h($msg_err) ?></div>
    <?php endif; ?>

    <!-- ── Tabs ── -->
    <div class="bs-tabs">
        <?php if ($userPriv >= 5): ?>
        <a class="bs-tab <?= $active_tab === 'bot'    ? 'active' : '' ?>" href="#" data-tab="bot">
           <i class="fab fa-discord"></i> <?= t('acp_bot_settings_tab_bot', [], 'Bot Config') ?>
        </a>
        <a class="bs-tab <?= $active_tab === 'ai'     ? 'active' : '' ?>" href="#" data-tab="ai">
            <i class="fas fa-robot"></i> <?= t('acp_bot_settings_tab_ai', [], 'AI Config') ?>
        </a>
        <?php endif; ?>
        <a class="bs-tab <?= $active_tab === 'status' ? 'active' : '' ?>" href="#" data-tab="status">
            <i class="fas fa-chart-bar"></i> <?= t('acp_bot_settings_tab_status', [], 'Status') ?>
        </a>
    </div>

    <?php if ($userPriv >= 5): ?>
    <form method="POST" action="acp.php?s=bot_settings" id="botSettingsForm">
    <input type="hidden" name="csrf_token"  value="<?= $csrf ?>">
    <input type="hidden" name="active_tab"  value="<?= h($active_tab) ?>" id="bs-hidden-tab">
    <input type="hidden" name="save_bot_settings" value="1">

    <!-- ══ TAB: BOT ══ -->
    <div class="bs-panel <?= $active_tab === 'bot' ? 'active' : '' ?>" id="panel-bot">

        <div class="bs-group">
            <div class="bs-group-head"><i class="fas fa-power-off"></i> <?= t('acp_bot_settings_global_state', [], 'Global State') ?></div>
            <div class="bs-row">
                <div class="bs-row-label"><?= t('acp_bot_settings_bot_active', [], 'Bot Active') ?>
                    <div class="bs-row-hint"><?= t('acp_bot_settings_bot_active_hint', [], 'Master switch. Disables all bot functions.') ?></div>
                </div>
                <div>
                    <label class="bs-toggle">
                        <div class="bs-toggle-track">
                            <input type="checkbox" name="is_active" value="1"
                                   <?= ($bs['is_active'] ?? 0) ? 'checked' : '' ?>
                                   onchange="this.closest('.bs-toggle').querySelector('.bs-toggle-state').textContent=this.checked?'ENABLED':'DISABLED'">
                            <span class="bs-toggle-slider"></span>
                        </div>
                        <span class="bs-toggle-state"><?= ($bs['is_active'] ?? 0) ? 'ENABLED' : 'DISABLED' ?></span>
                    </label>
                </div>
            </div>
            
            <div class="bs-row">
                <div class="bs-row-label">In-Game Guild Chat Sync
                    <div class="bs-row-hint">Synchronize DAoC guild chat with dedicated Discord channels. This will automatically add a new column to the game server 'guild' database table.</div>
                </div>
                <div>
                    <label class="bs-toggle">
                        <div class="bs-toggle-track">
                            <input type="checkbox" name="guild_chat_sync" id="toggleGuildChatSync" value="1"
                                   <?= ($bs['guild_chat_sync'] ?? 0) ? 'checked' : '' ?>
                                   data-was-checked="<?= ($bs['guild_chat_sync'] ?? 0) ? 'true' : 'false' ?>"
                                   onchange="handleGuildChatSyncToggle(this)">
                            <span class="bs-toggle-slider"></span>
                        </div>
                        <span class="bs-toggle-state" id="guildChatSyncLabel"><?= ($bs['guild_chat_sync'] ?? 0) ? 'ENABLED' : 'DISABLED' ?></span>
                    </label>
                </div>
            </div>
        </div>

        <div class="bs-group">
            <div class="bs-group-head"><i class="fab fa-discord"></i> <?= t('acp_bot_settings_discord', [], 'Discord') ?></div>
            <div class="bs-row">
                <div class="bs-row-label"><?= t('acp_bot_settings_bot_token', [], 'Bot Token') ?>
                    <div class="bs-row-hint"><?= t('acp_bot_settings_bot_token_hint', [], 'Never displayed. Paste new value to update.') ?></div>
                </div>
                <div>
                    <?php $hasToken = !empty($bs['discord_token']); ?>
                    <input type="password" name="discord_token" class="bs-input"
                           value=""
                           placeholder="<?= $hasToken ? t('acp_bot_settings_token_set_replace', [], 'Token is set – paste to replace') : t('acp_bot_settings_token_placeholder', [], 'Paste Discord Bot Token…') ?>"
                           autocomplete="new-password">
                    <?php if ($hasToken): ?>
                    <div style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-size:0.72em;font-family:sans-serif;color:#4a9a66;">
                        <i class="fas fa-lock" style="font-size:0.9em;"></i> <?= t('acp_bot_settings_token_configured', [], 'Token is configured.') ?>
                    </div>
                    <?php else: ?>
                    <div style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-size:0.72em;font-family:sans-serif;color:#666;">
                        <i class="fas fa-exclamation-circle" style="font-size:0.9em;"></i> <?= t('acp_bot_settings_token_missing', [], 'No token set yet.') ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="bs-row">
                <div class="bs-row-label"><?= t('acp_bot_settings_channel_id', [], 'Bot Channel ID') ?>
                    <div class="bs-row-hint"><?= t('acp_bot_settings_channel_id_hint', [], 'Default channel for bot output.') ?></div>
                </div>
                <div>
                    <input type="text" name="bot_channel_id" class="bs-input"
                           value="<?= h($bs['bot_channel_id'] ?? '') ?>" placeholder="e.g. 1234567890">
                </div>
            </div>
            <div class="bs-row">
                <div class="bs-row-label"><?= t('acp_bot_settings_admin_role_id', [], 'Admin Role ID') ?>
                    <div class="bs-row-hint"><?= t('acp_bot_settings_admin_role_hint', [], 'Discord role that can use admin commands.') ?></div>
                </div>
                <div>
                    <input type="text" name="admin_role_id" class="bs-input"
                           value="<?= h($bs['admin_role_id'] ?? '') ?>" placeholder="e.g. 9876543210">
                </div>
            </div>
            <div class="bs-row">
                <div class="bs-row-label"><?= t('acp_bot_settings_reboot_delay', [], 'Default Reboot Delay') ?>
                    <div class="bs-row-hint"><?= t('acp_bot_settings_reboot_delay_hint', [], 'Seconds between !reboot command and actual reboot.') ?></div>
                </div>
                <div>
                    <input type="number" name="reboot_delay_default" class="bs-input bs-input-sm"
                           value="<?= (int)($bs['reboot_delay_default'] ?? 5) ?>" min="0" max="600"> sec
                </div>
            </div>
        </div>

        <div class="bs-group">
            <div class="bs-group-head"><i class="fas fa-plug"></i> <?= t('acp_bot_settings_connection', [], 'Bot Connection') ?></div>
            <div class="bs-row">
                <div class="bs-row-label"><?= t('acp_bot_settings_socket_secret', [], 'Socket Secret') ?>
                    <div class="bs-row-hint"><?= t('acp_bot_settings_socket_secret_hint', [], 'HMAC key for Bridge auth. NOT the Discord token.') ?></div>
                </div>
                <div>
                    <?php $hasSecret = !empty($bs['socket_secret']); ?>
                    <input type="password" name="socket_secret" class="bs-input"
                           value=""
                           placeholder="<?= $hasSecret ? 'Secret is set – paste to replace' : 'Random secret key…' ?>"
                           autocomplete="new-password">
                    <?php if ($hasSecret): ?>
                    <div style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-size:0.72em;font-family:sans-serif;color:#4a9a66;">
                        <i class="fas fa-lock" style="font-size:0.9em;"></i> <?= t('acp_bot_settings_secret_configured', [], 'Socket secret is configured.') ?>
                    </div>
                    <?php else: ?>
                    <div style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-size:0.72em;font-family:sans-serif;color:#666;">
                        <i class="fas fa-exclamation-circle" style="font-size:0.9em;"></i> <?= t('acp_bot_settings_secret_missing', [], 'No secret set yet.') ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="bs-row">
                <div class="bs-row-label"><?= t('acp_bot_settings_bot_host', [], 'Bot Host') ?>
                    <div class="bs-row-hint"><?= t('acp_bot_settings_bot_host_hint', [], 'IP/hostname where the bot process runs.') ?></div>
                </div>
                <div>
                    <input type="text" name="bot_host" class="bs-input"
                           value="<?= h($bs['bot_host'] ?? '127.0.0.1') ?>" placeholder="127.0.0.1">
                </div>
            </div>
            <div class="bs-row">
                <div class="bs-row-label"><?= t('acp_bot_settings_bot_port', [], 'Bot Port') ?></div>
                <div>
                    <input type="number" name="bot_port" class="bs-input bs-input-sm"
                           value="<?= (int)($bs['bot_port'] ?? 15000) ?>" min="1024" max="65535">
                </div>
            </div>
            <div class="bs-row">
                <div class="bs-row-label"><?= t('acp_bot_settings_bot_script_path', [], 'Bot Script Path') ?>
                    <div class="bs-row-hint"><?= t('acp_bot_settings_bot_script_path_hint', [], 'Path to bot.js, relative to this CMS folder. Required to use the "Start Bot" button.') ?></div>
                </div>
                <div>
                    <input type="text" name="bot_script_path" class="bs-input"
                           value="<?= h($bs['bot_script_path'] ?? '') ?>" placeholder="../bot/bot.js">
                </div>
            </div>
            <div class="bs-row">
               <div class="bs-row-label"><?= t('acp_bot_settings_use_tls', [], 'Use TLS') ?> <div class="bs-row-hint"><?= t('acp_bot_settings_use_tls_hint', [], 'Required when bot runs on a remote server.') ?></div> </div>
                <div>
                    <label class="bs-toggle">
                        <div class="bs-toggle-track">
                            <input type="checkbox" name="use_tls" value="1"
                                   <?= ($bs['use_tls'] ?? 0) ? 'checked' : '' ?>
                                   onchange="this.closest('.bs-toggle').querySelector('.bs-toggle-state').textContent=this.checked?'ON':'OFF'">
                            <span class="bs-toggle-slider"></span>
                        </div>
                        <span class="bs-toggle-state"><?= ($bs['use_tls'] ?? 0) ? 'ON' : 'OFF' ?></span>
                    </label>
                </div>
            </div>
        </div>

        <div class="bs-save-bar">
           <button type="submit" class="bs-btn-save"><i class="fas fa-save"></i> <?= t('acp_bot_settings_save_bot', [], 'Save Bot Config') ?></button>
        </div>
    </div>

    <!-- ══ TAB: AI ══ -->
    <div class="bs-panel <?= $active_tab === 'ai' ? 'active' : '' ?>" id="panel-ai">

        <div class="bs-group">
            <div class="bs-group-head"><i class="fas fa-robot"></i> <?= t('acp_bot_settings_provider', [], 'Provider') ?></div>
            <div class="bs-row">
                <div class="bs-row-label"><?= t('acp_bot_settings_ai_provider', [], 'AI Provider') ?>
                    <div class="bs-row-hint"><?= t('acp_bot_settings_ai_provider_hint', [], 'Active provider for all AI requests.') ?></div>
                </div>
                <div>
                    <select name="ai_provider" class="bs-select" id="bs-provider-select">
                        <?php
                        $provider_labels = [
                            'none'      => t('acp_bot_settings_provider_none', [], 'None'),
                            'gemini'    => 'Gemini',
                            'lm_studio' => 'LM Studio',
                            'groq'      => 'Groq',
                            'openai'    => 'OpenAI',
                            'anthropic' => 'Anthropic (Claude)',
                        ];
                        foreach ($provider_labels as $p => $label): ?>
                            <option value="<?= $p ?>" <?= $activeProvider === $p ? 'selected' : '' ?>>
                                <?= h($label) ?><?php if (!empty($providerConfigs[$p]['has_key'])): ?> &check;<?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="bs-row-hint" style="margin-top:6px;">
                        <?= t('acp_bot_settings_provider_switch_hint', [], 'Each provider keeps its own saved key — no re-entry needed on switch.') ?>
                    </div>
                </div>
            </div>
            <div class="bs-row" id="bs-row-apikey">
                <div class="bs-row-label"><?= t('acp_bot_settings_api_key', [], 'API Key') ?>
                    <div class="bs-row-hint"><?= t('acp_bot_settings_api_key_hint', [], 'Stored AES-256 encrypted. Leave blank to keep current.') ?></div>
                </div>
                <div>
                    <input type="password" name="ai_api_key" class="bs-input" id="bs-input-apikey"
                           value=""
                           placeholder="<?= $activeProviderCfg['has_key'] ? t('acp_bot_settings_api_key_set_replace', [], 'Key is set – paste new key to replace') : t('acp_bot_settings_api_key_placeholder', [], 'Paste API key…') ?>"
                           autocomplete="new-password">
                    <div id="bs-apikey-status" style="
                        display: inline-flex; align-items: center; gap: 6px;
                        margin-top: 6px; font-size: 0.72em;
                        font-family: sans-serif; color: <?= $activeProviderCfg['has_key'] ? '#4a9a66' : '#666' ?>;
                    ">
                        <i class="fas <?= $activeProviderCfg['has_key'] ? 'fa-lock' : 'fa-exclamation-circle' ?>" style="font-size:0.9em;" id="bs-apikey-status-icon"></i>
                        <span id="bs-apikey-status-text"><?= $activeProviderCfg['has_key'] ? t('acp_bot_settings_api_key_configured', [], 'API key is configured and encrypted.') : t('acp_bot_settings_api_key_missing', [], 'No API key set yet.') ?></span>
                    </div>
                </div>
            </div>
            <div class="bs-row" id="bs-row-apiurl">
                <div class="bs-row-label">API URL
                    <div class="bs-row-hint">LM Studio: http://localhost:1234/v1 · Groq: auto</div>
                </div>
                <div>
                    <input type="text" name="ai_api_url" class="bs-input" id="bs-input-apiurl"
                           value="<?= h($activeProviderCfg['api_url'] ?: 'http://localhost:1234/v1') ?>">
                </div>
            </div>
            <div class="bs-row">
                <div class="bs-row-label"><?= t('acp_bot_settings_model', [], 'Model') ?>
                    <div class="bs-row-hint"><?= t('acp_bot_settings_model_hint', [], 'e.g. gemini-2.0-flash · llama3') ?></div>
                </div>
                <div>
                    <input type="text" name="ai_model" class="bs-input" id="bs-input-model"
                           value="<?= h($activeProviderCfg['model'] ?? '') ?>" placeholder="model name">
                </div>
            </div>
            <div class="bs-row">
                <div class="bs-row-label"><?= t('acp_bot_settings_max_tokens', [], 'Max Tokens') ?></div>
                <div>
                    <input type="number" name="ai_max_tokens" class="bs-input bs-input-sm"
                           value="<?= (int)($bs['ai_max_tokens'] ?? 1000) ?>" min="100" max="8000">
                </div>
            </div>
            <div class="bs-row">
                <div class="bs-row-label"><?= t('acp_bot_settings_temperature', [], 'Temperature') ?>
                    <div class="bs-row-hint"><?= t('acp_bot_settings_temperature_hint', [], '0.0 = deterministic · 2.0 = very creative') ?></div>
                </div>
                <div>
                    <input type="number" name="ai_temperature" class="bs-input bs-input-sm"
                           value="<?= number_format((float)($bs['ai_temperature'] ?? 0.7), 2) ?>"
                           min="0" max="2" step="0.05">
                </div>
            </div>
        </div>

        <div class="bs-group">
            <div class="bs-group-head"><i class="fas fa-terminal"></i> <?= t('acp_bot_settings_module_prompts', [], 'Module System Prompts') ?></div>
            <?php foreach ($module_prompts as $mod => $info): ?>
            <div class="bs-row">
                <div class="bs-row-label">
                    <i class="fas <?= $info['icon'] ?>" style="margin-right:6px; opacity:0.5;"></i>
                    <?= h($info['label']) ?>
                    <div class="bs-row-hint"><?= t('acp_bot_settings_module_prompt_hint', [], 'System prompt for AI requests from this module.') ?></div>
                </div>
                <div>
                    <textarea name="module_prompts[<?= h($mod) ?>]" class="bs-textarea"
                              rows="3"><?= h($prompts_raw[$mod] ?? '') ?></textarea>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="bs-save-bar">
            <button type="submit" class="bs-btn-save"><i class="fas fa-save"></i> <?= t('acp_bot_settings_save_ai', [], 'Save AI Config') ?></button>
        </div>
    </div>

    </form>
    <?php endif; ?>

    <!-- ══ TAB: STATUS ══ -->
    <div class="bs-panel <?= $active_tab === 'status' ? 'active' : '' ?>" id="panel-status">

        <!-- Stats Strip -->
        <div class="bs-stat-grid">
            <div class="bs-stat-card">
                <div class="bs-stat-value"><?= number_format((int)($log_stats['total'] ?? 0)) ?></div>
                <div class="bs-stat-label"><?= t('acp_bot_settings_ai_calls', [], 'AI Calls (7d)') ?></div>
            </div>
            <div class="bs-stat-card">
                <div class="bs-stat-value" style="color:#50c878;"><?= number_format((int)($log_stats['ok'] ?? 0)) ?></div>
                <div class="bs-stat-label"><?= t('acp_bot_settings_successful', [], 'Successful') ?></div>
            </div>
            <div class="bs-stat-card">
                <div class="bs-stat-value" style="color:#e07070;"><?= number_format((int)($log_stats['errors'] ?? 0)) ?></div>
                <div class="bs-stat-label"><?= t('acp_bot_settings_errors', [], 'Errors') ?></div>
            </div>
            <div class="bs-stat-card">
                <div class="bs-stat-value"><?= number_format((int)($log_stats['total_tokens'] ?? 0)) ?></div>
                <div class="bs-stat-label"><?= t('acp_bot_settings_total_tokens', [], 'Total Tokens') ?></div>
            </div>
            <div class="bs-stat-card">
                <div class="bs-stat-value"><?= $log_stats['avg_ms'] ? round($log_stats['avg_ms']) . 'ms' : '—' ?></div>
                <div class="bs-stat-label"><?= t('acp_bot_settings_avg_response', [], 'Avg Response') ?></div>
            </div>
            <div class="bs-stat-card">
                <div class="bs-stat-value" style="<?= $pending_suggestions > 0 ? 'color:#e8c97a;' : '' ?>">
                    <?= $pending_suggestions ?>
                </div>
                <div class="bs-stat-label"><?= t('acp_bot_settings_pending', [], 'Pending Suggestions') ?></div>
            </div>
            <div class="bs-stat-card">
                <div class="bs-stat-value"><?= $queue_count ?></div>
                <div class="bs-stat-label"><?= t('acp_bot_settings_queue', [], 'Queued Tasks') ?></div>
            </div>
            <div class="bs-stat-card">
                <div class="bs-stat-value">
                    <span class="bs-provider-dot <?= h($bs['ai_provider'] ?? 'none') ?>"></span>
                    <?= h(ucfirst($bs['ai_provider'] ?? 'none')) ?>
                </div>
                <div class="bs-stat-label"><?= t('acp_bot_settings_active_provider', [], 'Active Provider') ?></div>
            </div>
        </div>

        <!-- Bot Ping -->
        <div class="bs-group">
            <div class="bs-group-head"><i class="fas fa-plug"></i> <?= t('acp_bot_settings_socket_test', [], 'Socket Bridge Test') ?></div>
            <div style="padding: 16px 18px;">
                <button class="bs-ping-btn" onclick="bsPing()">
                    <i class="fas fa-satellite-dish"></i> <?= t('acp_bot_settings_ping_bot', [], 'Ping Bot') ?>
                </button>
                <button class="bs-ping-btn" onclick="bsStartBot()" style="margin-left:10px;">
                    <i class="fas fa-play"></i> <?= t('acp_bot_settings_start_bot', [], 'Start Bot') ?>
                </button>
                <div class="bs-ping-result" id="bs-ping-result"></div>
                <div class="bs-ping-result" id="bs-start-result"></div>
            </div>
        </div>

        <!-- Recent AI Logs -->
        <?php
        $recent_logs = $db->query("
            SELECT l.task_id, l.provider, l.module_context, l.task_type,
                   l.status, l.duration_ms,
                   COALESCE(l.prompt_tokens,0)+COALESCE(l.completion_tokens,0) AS tokens,
                   l.created_at, u.username
            FROM cms_ai_logs l
            LEFT JOIN users u ON u.id = l.user_id
            ORDER BY l.created_at DESC LIMIT 20
        ")->fetchAll();
        ?>
        <?php if ($recent_logs): ?>
        <div class="bs-group">
            <div class="bs-group-head"><i class="fas fa-list"></i> <?= t('acp_bot_settings_recent_requests', [], 'Recent AI Requests') ?></div>
            <div style="overflow-x:auto;">
                <table class="bs-log-table">
                    <thead>
                        <tr>
                            <th><?= t('acp_bot_settings_time', [], 'Time') ?></th><th><?= t('acp_bot_settings_user', [], 'User') ?></th><th><?= t('acp_bot_settings_module', [], 'Module') ?></th>
                            <th><?= t('acp_bot_settings_task', [], 'Task') ?></th><th><?= t('acp_bot_settings_provider', [], 'Provider') ?></th><th><?= t('acp_bot_settings_tokens', [], 'Tokens') ?></th>
                            <th>ms</th><th><?= t('acp_bot_settings_status', [], 'Status') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recent_logs as $l): ?>
                        <tr>
                            <td><?= date('d.m H:i', strtotime($l['created_at'])) ?></td>
                            <td><?= h($l['username'] ?? 'system') ?></td>
                            <td><?= h($l['module_context'] ?? '—') ?></td>
                            <td><?= h($l['task_type'] ?? '—') ?></td>
                            <td><?= h($l['provider']) ?></td>
                            <td><?= $l['tokens'] ?: '—' ?></td>
                            <td><?= $l['duration_ms'] ? $l['duration_ms'].'ms' : '—' ?></td>
                            <td class="bs-log-<?= h($l['status']) ?>"><?= h($l['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($pending_suggestions > 0): ?>
        <div style="padding: 12px 0;">
            <a href="acp.php?s=ai_suggestions" style="
                display: inline-flex; align-items: center; gap: 8px;
                font-family: 'Cinzel', serif; font-size: 0.62em;
                letter-spacing: 2px; text-transform: uppercase;
                color: #c5a059; text-decoration: none;
                border: 1px solid rgba(197,160,89,0.25);
                padding: 8px 18px;
                transition: background 0.2s;
            " onmouseover="this.style.background='rgba(197,160,89,0.06)'"
               onmouseout="this.style.background='transparent'">
                <i class="fas fa-robot"></i>
                <?= t('acp_bot_settings_review', [], 'Review Suggestions') ?> <?= $pending_suggestions ?> <?= $pending_suggestions > 1 ? 'Pending Suggestions' : 'Pending Suggestion' ?> →
            </a>
        </div>
        <?php endif; ?>

    </div>

</div>

<script>
function handleGuildChatSyncToggle(checkbox) {
    const label = document.getElementById('guildChatSyncLabel');
    const wasChecked = checkbox.getAttribute('data-was-checked') === 'true';

    if (checkbox.checked) {
        label.textContent = 'ENABLED';
        if (!wasChecked) {
            const confirmMsg = "Enabling this feature will automatically execute an 'ALTER TABLE' command on your game server 'guild' database table to add a 'discord_channel_id' column. This action is required to link in-game guilds to Discord channels.\n\nDo you want to proceed?";
            if (!confirm(confirmMsg)) {
                checkbox.checked = false;
                label.textContent = 'DISABLED';
            } else {
                checkbox.setAttribute('data-was-checked', 'true');
            }
        }
    } else {
        label.textContent = 'DISABLED';
    }
}

// Provider configs (model/URL/key status) for switching without a reload.
// NEVER contains the decrypted API key.
const BS_PROVIDER_CONFIGS = <?= json_encode($providerConfigs, JSON_UNESCAPED_SLASHES) ?>;
const BS_DEFAULT_URLS = { lm_studio: 'http://localhost:1234/v1', gemini: '', groq: '', openai: '', anthropic: '', none: '' };
const BS_LABEL_HAVE_KEY   = <?= json_encode(t('acp_bot_settings_api_key_configured', [], 'API key is configured and encrypted.')) ?>;
const BS_LABEL_NO_KEY     = <?= json_encode(t('acp_bot_settings_api_key_missing', [], 'No API key set yet.')) ?>;
const BS_LABEL_SET_REPLACE= <?= json_encode(t('acp_bot_settings_api_key_set_replace', [], 'Key is set – paste new key to replace')) ?>;
const BS_LABEL_PLACEHOLDER= <?= json_encode(t('acp_bot_settings_api_key_placeholder', [], 'Paste API key…')) ?>;

(function() {
    const sel = document.getElementById('bs-provider-select');
    if (!sel) return;

    sel.addEventListener('change', function() {
        const p   = sel.value;
        const cfg = BS_PROVIDER_CONFIGS[p] || { api_url: '', model: '', has_key: false };

        const keyInput   = document.getElementById('bs-input-apikey');
        const urlInput   = document.getElementById('bs-input-apiurl');
        const modelInput = document.getElementById('bs-input-model');
        const statusIcon = document.getElementById('bs-apikey-status-icon');
        const statusText = document.getElementById('bs-apikey-status-text');

        // Key field is always cleared on switch — the already-saved
        // key is never loaded into the frontend, only its status is shown.
        if (keyInput) {
            keyInput.value = '';
            keyInput.placeholder = cfg.has_key ? BS_LABEL_SET_REPLACE : BS_LABEL_PLACEHOLDER;
        }
        if (urlInput)   urlInput.value   = cfg.api_url || BS_DEFAULT_URLS[p] || '';
        if (modelInput) modelInput.value = cfg.model || '';
        if (statusIcon) statusIcon.className = 'fas ' + (cfg.has_key ? 'fa-lock' : 'fa-exclamation-circle');
        if (statusText) statusText.textContent = cfg.has_key ? BS_LABEL_HAVE_KEY : BS_LABEL_NO_KEY;

        const statusWrap = document.getElementById('bs-apikey-status');
        if (statusWrap) statusWrap.style.color = cfg.has_key ? '#4a9a66' : '#666';
    });
})();

(function() {
    const tabs   = document.querySelectorAll('.bs-tab');
    const panels = document.querySelectorAll('.bs-panel');
    const hidden = document.getElementById('bs-hidden-tab');
    tabs.forEach(tab => {
        tab.addEventListener('click', e => {
            e.preventDefault();
            const t = tab.dataset.tab;
            tabs.forEach(x => x.classList.remove('active'));
            panels.forEach(x => x.classList.remove('active'));
            tab.classList.add('active');
            document.getElementById('panel-' + t)?.classList.add('active');
            if (hidden) hidden.value = t;
        });
    });
})();

function bsPing() {
    const el = document.getElementById('bs-ping-result');
    el.textContent = 'Pinging…';
    el.style.color = '#555';
    fetch('acp.php?s=bot_settings&ping_bot=1')
        .then(r => r.json())
        .then(d => {
            if (d.status === 'ok') {
                el.textContent = '✓ Bot responded: ' + JSON.stringify(d.result ?? d);
                el.style.color = '#50c878';
            } else {
                el.textContent = '✗ ' + (d.message || JSON.stringify(d));
                el.style.color = '#e07070';
            }
        })
        .catch(e => { el.textContent = '✗ Request failed: ' + e; el.style.color = '#e07070'; });
}

function bsStartBot() {
    const el = document.getElementById('bs-start-result');
    el.textContent = 'Starting…';
    el.style.color = '#555';
    fetch('ajax_bot_start.php?csrf_token=<?= urlencode($csrf) ?>')
        .then(r => r.json())
        .then(d => {
            if (d.status === 'ok') {
                el.textContent = '✓ ' + (d.message || 'Started.');
                el.style.color = '#50c878';
            } else {
                el.textContent = '✗ ' + (d.message || JSON.stringify(d));
                el.style.color = '#e07070';
            }
        })
        .catch(e => { el.textContent = '✗ Request failed: ' + e; el.style.color = '#e07070'; });
}
</script>
