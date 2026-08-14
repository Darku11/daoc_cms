<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) die();

$_acp_auth = defined('IN_ACP') && isset($userPriv) && $userPriv >= 4;
$_cms_auth = isset($can_edit) && $can_edit;
if (!$_acp_auth && !$_cms_auth) return;
if (!isset($userPriv)) $userPriv = 0;

$settings = $settings ?? [];
if (!$settings) {
    $settings = $db->query("SELECT setting_key, value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
}
$is_maintenance = (($settings['maintenance_mode'] ?? '0') === '1');

$stmt_load = $db->prepare("SELECT value FROM settings WHERE setting_key = 'maintenance_text' LIMIT 1");
$stmt_load->execute();
$current_maint_text = (string)($stmt_load->fetchColumn() ?: 'Under Maintenance.');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_maintenance']) && $userPriv >= 5) {
    checkToken($_POST['csrf_token'] ?? '');
    $new_state = $is_maintenance ? '0' : '1';
    $db->prepare("INSERT INTO settings (setting_key, value) VALUES ('maintenance_mode', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")
       ->execute([$new_state]);
    $is_maintenance = $new_state === '1';
    $settings['maintenance_mode'] = $new_state;
    $GLOBALS['cms_settings']['maintenance_mode'] = $new_state;
    aldhran_log('MAINTENANCE_TOGGLE', 'Maintenance mode set to: ' . $new_state, (int)($_SESSION['user_id'] ?? 0));
    if (isset($GLOBALS['botDispatcher'])) {
        try {
            $GLOBALS['botDispatcher']->onMaintenanceToggle($is_maintenance, (int)($_SESSION['user_id'] ?? 0));
        } catch (Throwable $e) {
            error_log('BotDispatcher maintenance trigger failed: ' . $e->getMessage());
        }
    }
}

$gs_success = '';
$gs_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    checkToken($_POST['csrf_token'] ?? '');
    $updates = [];

    if (isset($_POST['site_name']))        $updates['site_name'] = trim((string)$_POST['site_name']);
    if (isset($_POST['default_language'])) $updates['default_language'] = trim((string)$_POST['default_language']);
    if (isset($_POST['active_theme']))     $updates['active_theme'] = trim((string)$_POST['active_theme']);
    if (isset($_POST['discord_link']))     $updates['discord_link'] = trim((string)$_POST['discord_link']);

    if (isset($_POST['default_hero_image'])) {
        $hero_val = trim((string)$_POST['default_hero_image']);
        if ($hero_val === '' || (mb_strlen($hero_val) <= 255
            && !preg_match('/[\'"()\\\\\s<>]/', $hero_val)
            && !preg_match('/^\s*(javascript|data|vbscript):/i', $hero_val))) {
            $updates['default_hero_image'] = $hero_val;
        } else {
            $gs_error = t('acp_gs_hero_invalid', [], 'Invalid default hero image path. Pick a file from the Content Manager Media Library or enter a plain path/URL without quotes or spaces.');
        }
    }

    $module_keys = [
        'mod_forum', 'mod_herald', 'mod_rvr_map', 'mod_faq', 'mod_team', 'mod_register',
        'mod_pve', 'itemshop_enabled', 'mod_imprint', 'header_search_enabled',
        'email_verification_required', 'admin_approval_required', 'use_resend_api',
    ];
    foreach ($module_keys as $mk) {
        $updates[$mk] = isset($_POST[$mk]) ? '1' : '0';
    }

    if ($userPriv >= 4 && isset($_POST['maint_message'])) {
        $updates['maintenance_text'] = trim((string)$_POST['maint_message']);
    }

    if ($userPriv >= 4) {
        if (isset($_POST['game_server_cms_api_url'])) {
            $cms_api_url = trim((string)$_POST['game_server_cms_api_url']);
            $cms_api_scheme = strtolower((string)parse_url($cms_api_url, PHP_URL_SCHEME));
            if (!preg_match('/[\r\n]/', $cms_api_url)
                && filter_var($cms_api_url, FILTER_VALIDATE_URL) !== false
                && in_array($cms_api_scheme, ['http', 'https'], true)) {
                $updates['game_server_cms_api_url'] = $cms_api_url;
            } else {
                $gs_error = 'The CMS guild-chat callback must be an absolute HTTP or HTTPS URL.';
            }
        }

        $gs_fields = [
            'game_server_ip', 'game_server_port', 'game_server_bridge_port',
            'game_server_shared_secret', 'game_server_bat_path',
            'game_server_console_host', 'game_server_console_port',
        ];
        $port_fields = ['game_server_port', 'game_server_bridge_port', 'game_server_console_port'];
        foreach ($gs_fields as $field) {
            if (!isset($_POST[$field])) continue;
            $val = trim((string)$_POST[$field]);
            if ($field === 'game_server_shared_secret' && $val === '') continue;

            if (in_array($field, $port_fields, true)) {
                $validated = filter_var($val, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1, 'max_range' => 65535],
                ]);
                if ($validated === false) {
                    $gs_error = 'Game, bridge and console ports must be between 1 and 65535.';
                    continue;
                }
                $val = (string)$validated;
            }
            $updates[$field] = $val;
        }
    }

    if ($gs_error === '') {
        $updates['settings_version'] = (string)time();
        $stmt = $db->prepare("INSERT INTO settings (setting_key, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
        foreach ($updates as $key => $val) $stmt->execute([$key, $val]);

        $settings = $db->query("SELECT setting_key, value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        $GLOBALS['cms_settings'] = $settings;
        $is_maintenance = (($settings['maintenance_mode'] ?? '0') === '1');
        $current_maint_text = $updates['maintenance_text'] ?? $current_maint_text;
        aldhran_log('SETTINGS_UPDATE', 'General settings updated via ACP (tab: ' . ($_POST['active_tab'] ?? '?') . ')', (int)($_SESSION['user_id'] ?? 0));
        $gs_success = 'Settings saved successfully.';
    }
}

$languages = $db->query("SELECT DISTINCT lang_code FROM cms_translations")->fetchAll(PDO::FETCH_COLUMN);
$available_themes = $db->query("SELECT DISTINCT theme_slug FROM aldhran_styles")->fetchAll(PDO::FETCH_COLUMN);
if (!$available_themes) $available_themes = ['default'];
if (!$languages) $languages = ['DE', 'EN'];

$game_server_shared_secret_is_set = false;
foreach (['game_server_shared_secret', 'game_server_bridge_secret', 'game_server_console_secret', 'igc_api_secret'] as $secret_key) {
    if (trim((string)($settings[$secret_key] ?? '')) !== '') {
        $game_server_shared_secret_is_set = true;
        break;
    }
}
$game_server_default_cms_api_url = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') . '/api_events.php' : '';

function gs_scan_server_paths(): array
{
    $found = [];
    $win_roots = [];
    foreach (range('C', 'Z') as $drive) {
        foreach ([
            $drive . ':\\Release', $drive . ':\\DOL', $drive . ':\\OpenDAoC',
            $drive . ':\\OpenDAoC-Core', $drive . ':\\DAoC', $drive . ':\\GameServer',
            $drive . ':\\server', $drive . ':\\GameServer\\Release', $drive . ':\\DOL\\Release',
            $drive . ':\\OpenDAoC\\Release', $drive . ':\\OpenDAoC\\Debug', $drive . ':\\DAoC\\Release',
        ] as $root) $win_roots[] = $root;
    }
    $webroot = dirname(dirname(__FILE__));
    foreach (['..', '..\\Release', '..\\DOL', '..\\OpenDAoC', '..\\GameServer'] as $rel) {
        $abs = realpath($webroot . DIRECTORY_SEPARATOR . $rel);
        if ($abs) $win_roots[] = $abs;
    }

    $targets = ['DOLServer.bat'=>1, 'DOLServer.exe'=>2, 'GameServer.dll'=>3, 'serverconfig.xml'=>4];
    foreach (array_unique($win_roots) as $root) {
        if (!is_dir($root)) continue;
        foreach ($targets as $filename => $priority) {
            $path = $root . DIRECTORY_SEPARATOR . $filename;
            if (file_exists($path)) $found[] = ['path'=>$path, 'priority'=>$priority];
        }
    }
    usort($found, static fn($a, $b) => $a['priority'] <=> $b['priority']);
    $seen = [];
    $result = [];
    foreach ($found as $entry) {
        $dir = dirname($entry['path']);
        if (!isset($seen[$dir])) {
            $seen[$dir] = true;
            $result[] = $entry['path'];
        }
    }
    return $result;
}

$server_scan_results = gs_scan_server_paths();
$server_saved_startup = (string)($settings['game_server_bat_path'] ?? '');
$server_auto_suggestion = $server_scan_results[0] ?? null;
if ($server_auto_suggestion && ($server_saved_startup === '' || !file_exists($server_saved_startup))) {
    try {
        $db->prepare("INSERT INTO settings (setting_key, value) VALUES ('game_server_bat_path', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")
           ->execute([$server_auto_suggestion]);
        $server_saved_startup = $server_auto_suggestion;
        aldhran_log('SETTINGS_AUTO', 'game_server_bat_path auto-set to: ' . $server_auto_suggestion, (int)($_SESSION['user_id'] ?? 0));
    } catch (Throwable $e) {}
}

$active_tab = (string)($_POST['active_tab'] ?? $_GET['tab'] ?? 'site');
$form_action = defined('IN_ACP') ? 'acp.php?s=general_settings' : 'index.php?p=general_settings';
$csrf = generateToken();

function gs(array $s, string $key, string $default = '1'): string { return (string)($s[$key] ?? $default); }
$core_label = gs($settings, 'game_server_core', 'dol') === 'opendaoc' ? 'OpenDAoC' : 'Dawn of Light (DOL)';
?>
<div class="gs-wrap">
    <?php if ($gs_success): ?><div class="acp-msg-success"><i class="fas fa-check-circle"></i> <?= h($gs_success) ?></div><?php endif; ?>
    <?php if ($gs_error): ?><div class="cm-msg-error-red"><i class="fas fa-triangle-exclamation"></i> <?= h($gs_error) ?></div><?php endif; ?>

    <div class="gs-tabs">
        <a class="gs-tab <?= $active_tab === 'site' ? 'active' : '' ?>" href="#" data-tab="site"><i class="fas fa-globe"></i> <?= t('acp_general_settings_site', [], 'Site') ?></a>
        <a class="gs-tab <?= $active_tab === 'appearance' ? 'active' : '' ?>" href="#" data-tab="appearance"><i class="fas fa-paint-brush"></i> <?= t('acp_general_settings_appereance', [], 'Design') ?></a>
        <a class="gs-tab <?= $active_tab === 'modules' ? 'active' : '' ?>" href="#" data-tab="modules"><i class="fas fa-puzzle-piece"></i> <?= t('acp_modules_description', [], 'Modules') ?></a>
        <a class="gs-tab <?= $active_tab === 'gameserver' ? 'active' : '' ?>" href="#" data-tab="gameserver"><i class="fas fa-server"></i> <?= t('acp_gameserver_description', [], 'Game Server') ?></a>
        <a class="gs-tab <?= $active_tab === 'maintenance' ? 'active' : '' ?>" href="#" data-tab="maintenance"><i class="fas fa-tools"></i> <?= t('acp_maintenance_mode', [], 'Maintenance') ?></a>
    </div>

    <form method="POST" action="<?= h($form_action) ?>">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="active_tab" value="<?= h($active_tab) ?>" id="gs-hidden-tab">

        <div class="gs-panel <?= $active_tab === 'site' ? 'active' : '' ?>" id="panel-site">
            <div class="gs-group">
                <div class="gs-group-head"><i class="fas fa-tag"></i> <?= t('acp_general_settings_identity', [], 'Identity') ?></div>
                <div class="gs-row"><div class="gs-row-label"><?= t('acp_general_settings_site_name', [], 'Site Name') ?><div class="gs-row-hint"><?= t('acp_general_settings_site_name_desc', [], 'Shown in browser and header.') ?></div></div><div><input type="text" name="site_name" class="gs-input" value="<?= h(gs($settings,'site_name','DAoC CMS')) ?>"></div></div>
                <div class="gs-row"><div class="gs-row-label"><?= t('acp_general_settings_discord_link', [], 'Discord Invite Link') ?><div class="gs-row-hint"><?= t('acp_general_settings_discord_link_desc', [], 'The URL to your Discord server.') ?></div></div><div><input type="text" name="discord_link" class="gs-input" value="<?= h(gs($settings,'discord_link','')) ?>" placeholder="https://discord.gg/..."></div></div>
                <div class="gs-row"><div class="gs-row-label"><?= t('general_settings_default_language', [], 'Default Language') ?></div><div><select name="default_language" class="gs-select"><?php foreach ($languages as $lang): ?><option value="<?= h($lang) ?>" <?= gs($settings,'default_language','EN') === $lang ? 'selected' : '' ?>><?= h(strtoupper($lang)) ?></option><?php endforeach; ?></select></div></div>
            </div>
            <div class="gs-save-bar"><button type="submit" name="save_settings" class="gs-btn-save"><i class="fas fa-save"></i> <?= t('acp_save_changes', [], 'Save Changes') ?></button></div>
        </div>

        <div class="gs-panel <?= $active_tab === 'appearance' ? 'active' : '' ?>" id="panel-appearance">
            <div class="gs-group">
                <div class="gs-group-head"><i class="fas fa-palette"></i> <?= t('acp_theme_and_layout', [], 'Theme & Layout') ?></div>
                <div class="gs-row"><div class="gs-row-label"><?= t('acp_active_theme', [], 'Active Theme') ?></div><div><select name="active_theme" class="gs-select"><?php foreach ($available_themes as $t_slug): ?><option value="<?= h($t_slug) ?>" <?= gs($settings,'active_theme','default') === $t_slug ? 'selected' : '' ?>><?= h(ucfirst($t_slug)) ?></option><?php endforeach; ?></select></div></div>
                <div class="gs-row"><div class="gs-row-label"><?= t('acp_default_hero_image', [], 'Default Hero Image') ?><div class="gs-row-hint"><?= t('acp_default_hero_image_desc', [], 'Used on frontend pages without their own hero image.') ?></div></div><div><input type="text" name="default_hero_image" id="gs-hero-input" class="gs-input" value="<?= h(gs($settings,'default_hero_image','')) ?>" oninput="gsHeroPreview()"><div class="cm-hero-preview" id="gs-hero-preview"></div></div></div>
            </div>
            <div class="gs-save-bar"><button type="submit" name="save_settings" class="gs-btn-save"><i class="fas fa-save"></i> <?= t('acp_save_changes', [], 'Save Changes') ?></button></div>
        </div>

        <div class="gs-panel <?= $active_tab === 'modules' ? 'active' : '' ?>" id="panel-modules">
            <div class="gs-group">
                <div class="gs-group-head"><i class="fas fa-cubes"></i> Site Modules</div>
                <div class="gs-module-grid">
                    <?php
                    $modules_list = [
                        'mod_forum'=>['Forum (Spike)','fa-comments','?p=spike'], 'mod_herald'=>['Herald','fa-trophy','?p=herald'],
                        'mod_rvr_map'=>['RvR Map','fa-map-location-dot','?p=rvr_map'], 'mod_faq'=>['FAQ','fa-question-circle','?p=faq'],
                        'mod_team'=>['Team Page','fa-users','?p=team'], 'mod_register'=>['Registration','fa-user-plus','?p=register'],
                        'mod_pve'=>['PvE','fa-dragon','?p=pve'], 'itemshop_enabled'=>[t('acp_mod_itemshop_label', [], 'PvE Itemshop'),'fa-store','?p=pve_items'],
                        'mod_imprint'=>['Imprint / Impressum','fa-file-signature','?p=imprint'], 'header_search_enabled'=>['Header Search','fa-search','Header'],
                        'email_verification_required'=>['Email Verification','fa-envelope-circle-check','System'], 'admin_approval_required'=>['Admin Approval','fa-user-lock','System'],
                        'use_resend_api'=>['Use Resend API','fa-paper-plane','System'],
                    ];
                    foreach ($modules_list as $key => $mod): $enabled = gs($settings, $key, '1') === '1'; ?>
                        <div class="gs-module-item"><div><div class="gs-module-name"><i class="fas <?= h($mod[1]) ?>"></i><?= h($mod[0]) ?></div><div class="gs-module-slug"><?= h($mod[2]) ?></div></div><label class="gs-toggle"><div class="gs-toggle-track"><input type="checkbox" name="<?= h($key) ?>" value="1" <?= $enabled ? 'checked' : '' ?> onchange="this.closest('.gs-toggle').querySelector('.gs-toggle-state').textContent=this.checked?'ON':'OFF'"><span class="gs-toggle-slider"></span></div><span class="gs-toggle-state"><?= $enabled ? 'ON' : 'OFF' ?></span></label></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="gs-save-bar"><button type="submit" name="save_settings" class="gs-btn-save"><i class="fas fa-save"></i> <?= t('acp_save_changes', [], 'Save Changes') ?></button></div>
        </div>

        <div class="gs-panel <?= $active_tab === 'gameserver' ? 'active' : '' ?>" id="panel-gameserver">
            <div class="gs-group">
                <div class="gs-group-head"><i class="fas fa-code-branch"></i> Server Core</div>
                <div class="gs-row"><div class="gs-row-label">Emulator<div class="gs-row-hint">Selected during setup. Changing the emulator after installation is intentionally not supported.</div></div><div class="gs-row-value"><strong><?= h($core_label) ?></strong></div></div>
            </div>

            <div class="gs-group">
                <div class="gs-group-head"><i class="fas fa-search"></i> Auto-Detection</div>
                <div class="acp-s-f8ec4b54">
                    <?php if ($server_auto_suggestion): ?>
                        <div class="gs-detect-box" id="gs-detect-box"><div class="gs-detect-icon gs-detect-icon--found"><i class="fas fa-check-circle"></i></div><div class="gs-detect-info"><div class="gs-detect-title">Game server installation detected</div><div class="gs-detect-path" id="gs-detect-path"><?= h($server_auto_suggestion) ?></div><?php if (count($server_scan_results)>1): ?><div class="gs-scan-list acp-s-cb458930" id="gs-scan-list"><?php foreach ($server_scan_results as $r): ?><span data-server-path="<?= h($r) ?>" onclick="gsUsePath(this.dataset.serverPath)"><?= h($r) ?></span><?php endforeach; ?></div><?php endif; ?></div><div class="gs-detect-actions"><button type="button" class="gs-btn-confirm" onclick="gsConfirmPath()"><i class="fas fa-check"></i> Use this path</button></div></div>
                    <?php else: ?>
                        <div class="gs-detect-box gs-detect-box--none"><div class="gs-detect-info"><div class="gs-detect-title">No game server installation detected automatically</div><div class="gs-detect-path--none">Enter the startup path manually below.</div></div></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="gs-group">
                <div class="gs-group-head"><i class="fas fa-network-wired"></i> Bridge Connection</div>
                <div class="gs-row"><div class="gs-row-label">Game Server IP<div class="gs-row-hint">IP of the game server, usually 127.0.0.1.</div></div><div><input type="text" name="game_server_ip" class="gs-input" value="<?= h(gs($settings,'game_server_ip','127.0.0.1')) ?>"></div></div>
                <div class="gs-row"><div class="gs-row-label">Game Server Port<div class="gs-row-hint">Public game port.</div></div><div><input type="number" name="game_server_port" min="1" max="65535" class="gs-input" value="<?= h(gs($settings,'game_server_port','10300')) ?>"></div></div>
                <div class="gs-row"><div class="gs-row-label">Bridge Port<div class="gs-row-hint">AldhranBridge TCP port.</div></div><div><input type="number" name="game_server_bridge_port" min="1" max="65535" class="gs-input" value="<?= h(gs($settings,'game_server_bridge_port','2000')) ?>"></div></div>
                <div class="gs-row"><div class="gs-row-label">CMS Guild Chat API<div class="gs-row-hint">Authenticated callback used by GuildChatBridge. World-event feeds are not part of this endpoint.</div></div><div><input type="url" name="game_server_cms_api_url" class="gs-input gs-input--wide" value="<?= h(gs($settings,'game_server_cms_api_url',$game_server_default_cms_api_url)) ?>" placeholder="https://example.com/api_events.php"></div></div>
                <div class="gs-row"><div class="gs-row-label">Shared Secret<div class="gs-row-hint">Used by the CMS, AldhranConsole and game-server bridge configuration.</div></div><div><input type="password" name="game_server_shared_secret" class="gs-input" value="" placeholder="<?= $game_server_shared_secret_is_set ? h(t('acp_secret_is_set', [], 'Secret is set (leave empty to keep)')) : '' ?>"></div></div>
                <div class="gs-row"><div class="gs-row-label">Game-server configuration<div class="gs-row-hint">Generates one file for AldhranBridge and GuildChatBridge. Save before downloading, place it at <code>config/daoc_cms_bridge.conf</code>, then restart the game server.</div></div><div><?php if ($userPriv >= 5 && $game_server_shared_secret_is_set): ?><a class="gs-btn-rescan" href="acp.php?s=general_settings&amp;download_bridge_config=1&amp;csrf_token=<?= h($csrf) ?>" download><i class="fas fa-download"></i> Download bridge config</a><?php elseif ($userPriv >= 5): ?><span class="gs-row-hint">Save a shared secret first.</span><?php else: ?><span class="gs-row-hint">A SuperAdmin can download the secret-bearing configuration.</span><?php endif; ?></div></div>
            </div>

            <div class="gs-group">
                <div class="gs-group-head"><i class="fas fa-plug"></i> AldhranConsole (HTTP API)</div>
                <div class="gs-row"><div class="gs-row-label">Console Host</div><div><input type="text" name="game_server_console_host" class="gs-input" value="<?= h(gs($settings,'game_server_console_host','127.0.0.1')) ?>"></div></div>
                <div class="gs-row"><div class="gs-row-label">Console Port</div><div><input type="number" name="game_server_console_port" min="1" max="65535" class="gs-input" value="<?= h(gs($settings,'game_server_console_port','5100')) ?>"></div></div>
            </div>

            <div class="gs-group">
                <div class="gs-group-head"><i class="fas fa-file-code"></i> Startup Script</div>
                <div class="gs-row"><div class="gs-row-label">Server Startup Path<div class="gs-row-hint">Full path to the runnable startup script or executable used for automatic restarts.</div></div><div><input type="text" name="game_server_bat_path" id="gs-bat-input" class="gs-input gs-input--wide" value="<?= h($server_saved_startup) ?>" placeholder="<?= PHP_OS_FAMILY === 'Windows' ? 'C:\\Path\\to\\game-server-start.bat' : '/opt/daoc/start-server.sh' ?>"></div></div>
            </div>

            <div class="gs-group">
                <div class="gs-group-head"><i class="fas fa-shield-alt"></i> Watchdog Script</div>
                <div class="acp-s-f8ec4b54"><p class="gs-row-hint">The watchdog restarts the configured server process after shutdown. It uses the startup path above and the emulator selected during setup.</p><?php if ($server_saved_startup): ?><a href="acp.php?s=general_settings&download_watchdog=1" class="gs-btn-save"><i class="fas fa-download"></i> Download daoc_cms_watchdog.bat</a><?php else: ?><div class="gs-row-hint">Save a valid startup path first.</div><?php endif; ?></div>
            </div>
            <div class="gs-save-bar"><button type="submit" name="save_settings" class="gs-btn-save"><i class="fas fa-save"></i> <?= t('acp_save_changes', [], 'Save Changes') ?></button></div>
        </div>

        <div class="gs-panel <?= $active_tab === 'maintenance' ? 'active' : '' ?>" id="panel-maintenance">
            <?php if ($userPriv >= 5): ?>
                <div class="gs-group"><div class="gs-group-head"><i class="fas fa-power-off"></i> <?= t('acp_maintenance_mode', [], 'Maintenance') ?></div><div class="gs-maint-banner"><div class="gs-maint-status"><div class="gs-maint-dot <?= $is_maintenance ? 'gs-maint-dot--on' : 'gs-maint-dot--off' ?>"></div><div><div class="gs-maint-label <?= $is_maintenance ? 'gs-maint-label--on' : 'gs-maint-label--off' ?>"><?= $is_maintenance ? 'Maintenance Active' : 'Site is Live' ?></div><div class="gs-maint-sub"><?= $is_maintenance ? 'Only SuperAdmins can access the site.' : 'All users can access the site normally.' ?></div></div></div><button type="submit" name="toggle_maintenance" class="gs-btn-toggle <?= $is_maintenance ? 'gs-btn-toggle--disable' : 'gs-btn-toggle--enable' ?>" <?= !$is_maintenance ? "onclick=\"return confirm('Enable maintenance mode? All non-admin users will be locked out.')\"" : '' ?>><i class="fas <?= $is_maintenance ? 'fa-power-off' : 'fa-tools' ?>"></i> <?= $is_maintenance ? 'Disable Maintenance' : 'Enable Maintenance' ?></button></div></div>
            <?php endif; ?>
            <div class="gs-group"><div class="gs-group-head"><i class="fas fa-comment-alt"></i> <?= t('acp_maintenance_message', [], 'Maintenance Message') ?></div><div class="gs-row"><div class="gs-row-label"><?= t('acp_message', [], 'Message') ?></div><div><textarea name="maint_message" class="gs-textarea"><?= h($current_maint_text) ?></textarea></div></div></div>
            <div class="gs-save-bar"><button type="submit" name="save_settings" class="gs-btn-save"><i class="fas fa-save"></i> <?= t('acp_save_changes', [], 'Save Changes') ?></button></div>
        </div>
    </form>
</div>

<script>
function gsHeroPreview() {
    const val = (document.getElementById('gs-hero-input')?.value || '').trim();
    const box = document.getElementById('gs-hero-preview');
    if (!box) return;
    box.innerHTML = '';
    if (!val) return;
    const img = document.createElement('img');
    img.src = val;
    img.alt = '';
    img.onerror = () => img.classList.add('is-missing');
    box.appendChild(img);
}
document.addEventListener('DOMContentLoaded', gsHeroPreview);

(function () {
    const tabs = document.querySelectorAll('.gs-tab');
    const panels = document.querySelectorAll('.gs-panel');
    const hidden = document.getElementById('gs-hidden-tab');
    tabs.forEach(tab => tab.addEventListener('click', function (e) {
        e.preventDefault();
        tabs.forEach(t => t.classList.remove('active'));
        panels.forEach(p => p.classList.remove('active'));
        this.classList.add('active');
        document.getElementById('panel-' + this.dataset.tab)?.classList.add('active');
        if (hidden) hidden.value = this.dataset.tab;
    }));
})();

function gsConfirmPath() {
    const pathEl = document.getElementById('gs-detect-path');
    const input = document.getElementById('gs-bat-input');
    if (pathEl && input) input.value = pathEl.textContent.trim();
}
function gsUsePath(path) {
    const input = document.getElementById('gs-bat-input');
    const pathEl = document.getElementById('gs-detect-path');
    if (input) input.value = path;
    if (pathEl) pathEl.textContent = path;
}
</script>
