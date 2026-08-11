<?php
if (!defined('IN_CMS')) die();

$_acp_auth = (defined('IN_ACP') && isset($userPriv) && $userPriv >= 4);
$_cms_auth = (isset($can_edit) && $can_edit);
if (!$_acp_auth && !$_cms_auth) return;
if (!isset($userPriv)) $userPriv = 0;

$current_maint_text = '';
$stmt_load = $db->prepare("SELECT value FROM settings WHERE setting_key = 'maintenance_text' LIMIT 1");
$stmt_load->execute();
$m_data = $stmt_load->fetch();
$current_maint_text = $m_data['value'] ?? 'Under Maintenance.';

// ── Maintenance Toggle ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_maintenance']) && $userPriv >= 5) {
    checkToken($_POST['csrf_token'] ?? '');
    $new_state = $is_maintenance ? '0' : '1';
    $db->prepare("INSERT INTO settings (setting_key, value) VALUES ('maintenance_mode', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")
       ->execute([$new_state]);
    aldhran_log("MAINTENANCE_TOGGLE", "Maintenance mode set to: " . $new_state, $_SESSION['user_id']);
    $is_maintenance = (bool)$new_state;
    if (isset($GLOBALS['botDispatcher'])) {
        try { $GLOBALS['botDispatcher']->onMaintenanceToggle($is_maintenance, (int)$_SESSION['user_id']); }
        catch (\Throwable $e) { error_log("BotDispatcher maintenance trigger failed: " . $e->getMessage()); }
    }
}

// ── Save Settings ────────────────────────────────────────
$gs_success = '';
$gs_error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    checkToken($_POST['csrf_token'] ?? '');

    $updates = [];
    if (isset($_POST['site_name']))        $updates['site_name']        = trim($_POST['site_name']);
    if (isset($_POST['default_language'])) $updates['default_language'] = trim($_POST['default_language']);
    if (isset($_POST['active_theme']))     $updates['active_theme']     = trim($_POST['active_theme']);
    if (isset($_POST['discord_link']))     $updates['discord_link']     = trim($_POST['discord_link']);

    if (isset($_POST['default_hero_image'])) {
        $hero_val = trim($_POST['default_hero_image']);
        // Same interpolation target as the per-page hero_image field
        // (hero.php drops it straight into background-image:url('...')),
        // so it gets the same reject-list validation.
        if ($hero_val === ''
            || (mb_strlen($hero_val) <= 255
                && !preg_match('/[\'"()\\\\\s<>]/', $hero_val)
                && !preg_match('/^\s*(javascript|data|vbscript):/i', $hero_val))) {
            $updates['default_hero_image'] = $hero_val;
        } else {
            $gs_error = t('acp_gs_hero_invalid', [], 'Invalid default hero image path. Pick a file from the Content Manager Media Library or enter a plain path/URL without quotes or spaces.');
        }
    }

    $module_keys = ['mod_forum', 'mod_herald', 'mod_rvr_map', 'mod_faq', 'mod_team', 'mod_register', 'mod_pve', 'itemshop_enabled', 'mod_imprint', 'email_verification_required', 'admin_approval_required', 'use_resend_api'];
    foreach ($module_keys as $mk) {
        $updates[$mk] = isset($_POST[$mk]) ? '1' : '0';
    }

    if ($userPriv >= 4 && isset($_POST['maint_message'])) {
        $updates['maintenance_text'] = trim($_POST['maint_message']);
    }

    // ── Game Server Settings ──────────────────────────────────
    if ($userPriv >= 4) {
        if (isset($_POST['game_server_core'])) {
            $game_server_core = strtolower(trim((string)$_POST['game_server_core']));
            if (in_array($game_server_core, ['opendaoc', 'dol'], true)) {
                $updates['game_server_core'] = $game_server_core;
            } else {
                $gs_error = 'Invalid game server core selected.';
            }
        }

        $gs_fields = ['game_server_ip', 'game_server_port', 'game_server_bridge_port', 'game_server_bridge_secret', 'game_server_bat_path', 'game_server_console_host', 'game_server_console_port', 'game_server_console_secret'];
        foreach ($gs_fields as $field) {
            if (isset($_POST[$field])) {
                $val = trim($_POST[$field]);
                if (($field === 'game_server_bridge_secret' || $field === 'game_server_console_secret') && $val === '') {
                    continue;
                }
                $updates[$field] = $val;
            }
        }
    }

    $stmt = $db->prepare("INSERT INTO settings (setting_key, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
    foreach ($updates as $key => $val) {
        $stmt->execute([$key, $val]);
    }

    $settings = $db->query("SELECT setting_key, value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $GLOBALS['cms_settings'] = $settings;
    aldhran_log("SETTINGS_UPDATE", "General settings updated via ACP (tab: " . ($_POST['active_tab'] ?? '?') . ")", $_SESSION['user_id']);
    $gs_success = "Settings saved successfully.";
    $current_maint_text = $updates['maintenance_text'] ?? $current_maint_text;
}

// ── Load Data  ───────────────────────────────────────────────
if (empty($settings)) {
    $settings = $db->query("SELECT setting_key, value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
}
$languages        = $db->query("SELECT DISTINCT lang_code FROM cms_translations")->fetchAll(PDO::FETCH_COLUMN);
$available_themes = $db->query("SELECT DISTINCT theme_slug FROM aldhran_styles")->fetchAll(PDO::FETCH_COLUMN);
if (empty($available_themes)) $available_themes = ['default'];
if (empty($languages))        $languages        = ['DE', 'EN'];

// ── Game Server: Auto-Detection ───────────────────────────────

function gs_scan_dol_paths(): array
{
    // Priority: .bat > .exe > GameServer.dll > serverconfig.xml
    // .bat is the preferred restart entrypoint on Windows.
    $found = []; // ['path' => ..., 'type' => ..., 'priority' => ...]

    $win_roots = [];
    foreach (range('C', 'Z') as $drive) {
        foreach ([
            $drive . ':\\Release',
            $drive . ':\\DOL',
            $drive . ':\\DAoC',
            $drive . ':\\daoc',
            $drive . ':\\dol',
            $drive . ':\\GameServer',
            $drive . ':\\server',
            $drive . ':\\GameServer\\Release',
            $drive . ':\\DOL\\Release',
            $drive . ':\\DAoC\\Release',
        ] as $root) {
            $win_roots[] = $root;
        }
    }
    // Also check adjacent folder
    $webroot = dirname(dirname(__FILE__));
    foreach (['..', '..\\Release', '..\\DOL', '..\\GameServer'] as $rel) {
        $abs = realpath($webroot . DIRECTORY_SEPARATOR . $rel);
        if ($abs) $win_roots[] = $abs;
    }

    $targets = [
        'DOLServer.bat'    => 1,
        'DOLServer.exe'    => 2,
        'GameServer.dll'   => 3,
        'serverconfig.xml' => 4,
    ];

    foreach (array_unique($win_roots) as $root) {
        if (!is_dir($root)) continue;
        foreach ($targets as $filename => $priority) {
            $path = $root . DIRECTORY_SEPARATOR . $filename;
            if (file_exists($path)) {
                $found[] = ['path' => $path, 'type' => $filename, 'priority' => $priority];
            }
        }
    }

    usort($found, fn($a, $b) => $a['priority'] <=> $b['priority']);

    $seen_dirs = [];
    $result    = [];
    foreach ($found as $entry) {
        $dir = dirname($entry['path']);
        if (!isset($seen_dirs[$dir])) {
            $seen_dirs[$dir] = true;
            $result[] = $entry['path'];
        }
    }

    return $result;
}

$dol_scan_results    = gs_scan_dol_paths();
$dol_saved_bat       = $settings['game_server_bat_path'] ?? '';
$dol_auto_suggestion = !empty($dol_scan_results) ? $dol_scan_results[0] : null;

$dol_auto_saved_msg = '';
if ($dol_auto_suggestion) {
    $needs_update = empty($dol_saved_bat) || !file_exists($dol_saved_bat);
    if ($needs_update) {
        try {
            $db->prepare(
                "INSERT INTO settings (setting_key, value)
                 VALUES ('game_server_bat_path', ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)"
            )->execute([$dol_auto_suggestion]);
            $dol_saved_bat     = $dol_auto_suggestion;
            $dol_auto_saved_msg = $dol_auto_suggestion;
            aldhran_log('SETTINGS_AUTO', 'game_server_bat_path auto-set to: ' . $dol_auto_suggestion, (int)($_SESSION['user_id'] ?? 0));
        } catch (\Throwable $e) {}
    }
}

$active_tab  = $_POST['active_tab'] ?? $_GET['tab'] ?? 'site';
$form_action = defined('IN_ACP') ? 'acp.php?s=general_settings' : 'index.php?p=general_settings';
$csrf        = generateToken();

function gs($s, $key, $default = '1') {
    return $s[$key] ?? $default;
}
?>


<div class="gs-wrap">

    <?php if ($gs_success): ?>
        <div class="acp-msg-success"><i class="fas fa-check-circle"></i> <?= h($gs_success) ?></div>
    <?php endif; ?>
    <?php if ($gs_error): ?>
        <div class="cm-msg-error-red"><i class="fas fa-triangle-exclamation"></i> <?= h($gs_error) ?></div>
    <?php endif; ?>

    <div class="gs-tabs">
        <a class="gs-tab <?= $active_tab === 'site'        ? 'active' : '' ?>" href="#" data-tab="site"><i class="fas fa-globe"></i> <?= t('acp_general_settings_site', [], 'Site') ?></a>
        <a class="gs-tab <?= $active_tab === 'appearance'  ? 'active' : '' ?>" href="#" data-tab="appearance"><i class="fas fa-paint-brush"></i> <?= t('acp_general_settings_appereance', [], 'Design') ?></a>
        <a class="gs-tab <?= $active_tab === 'modules'     ? 'active' : '' ?>" href="#" data-tab="modules"><i class="fas fa-puzzle-piece"></i> <?= t('acp_modules_description', [], 'Modules') ?></a>
        <a class="gs-tab <?= $active_tab === 'gameserver'  ? 'active' : '' ?>" href="#" data-tab="gameserver"><i class="fas fa-server"></i> <?= t('acp_gameserver_description', [], 'Game Server') ?></a>
        <a class="gs-tab <?= $active_tab === 'maintenance' ? 'active' : '' ?>" href="#" data-tab="maintenance">
            <i class="fas fa-tools"></i> <?= t('acp_maintenance_mode', [], 'Maintenance') ?>
            <?php if ($is_maintenance): ?><span style="margin-left:7px;color:#e07070;font-family:sans-serif;letter-spacing:0;text-transform:none;font-size:0.8em;">●</span><?php endif; ?>
        </a>
    </div>

    <form method="POST" action="<?= $form_action ?>">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="active_tab" value="<?= h($active_tab) ?>" id="gs-hidden-tab">

        <div class="gs-panel <?= $active_tab === 'site' ? 'active' : '' ?>" id="panel-site">
            <div class="gs-group">
                <div class="gs-group-head"><i class="fas fa-tag"></i> <?= t('acp_general_settings_identity', [], 'Identity') ?></div>
                <div class="gs-row">
                    <div class="gs-row-label"><?= t('acp_general_settings_site_name', [], 'Site Name') ?><div class="gs-row-hint"><?= t('acp_general_settings_site_name_desc', [], 'Shown in browser and header.') ?></div></div>
                    <div><input type="text" name="site_name" class="gs-input" value="<?= h(gs($settings,'site_name','DAoC CMS')) ?>" placeholder="DAoC CMS"></div>
                </div>
                <div class="gs-row">
                    <div class="gs-row-label"><?= t('acp_general_settings_discord_link', [], 'Discord Invite Link') ?><div class="gs-row-hint"><?= t('acp_general_settings_discord_link_desc', [], 'The URL to your Discord server.') ?></div></div>
                    <div><input type="text" name="discord_link" class="gs-input" value="<?= h(gs($settings,'discord_link','')) ?>" placeholder="https://discord.gg/..."></div>
                </div>
                <div class="gs-row">
                    <div class="gs-row-label"><?= t('general_settings_default_language', [], 'Default Language') ?><div class="gs-row-hint"><?= t('acp_general_settings_default_language_desc', [], 'Set the default language of the CMS') ?></div></div>
                    <div>
                        <select name="default_language" class="gs-select">
                            <?php foreach ($languages as $lang): ?>
                                <option value="<?= h($lang) ?>" <?= gs($settings,'default_language','EN') === $lang ? 'selected' : '' ?>><?= h(strtoupper($lang)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="gs-save-bar"><button type="submit" name="save_settings" class="gs-btn-save"><i class="fas fa-save"></i>&nbsp; <?= t('acp_save_changes', [], 'Save Changes') ?></button></div>
        </div>

        <div class="gs-panel <?= $active_tab === 'appearance' ? 'active' : '' ?>" id="panel-appearance">
            <div class="gs-group">
                <div class="gs-group-head"><i class="fas fa-palette"></i> <?= t('acp_theme_and_layout', [], 'Theme &amp; Layout') ?></div>
                <div class="gs-row">
                    <div class="gs-row-label"><?= t('acp_active_theme', [], 'Active Theme') ?><div class="gs-row-hint"><?= t('css_stack_loaded_from_db', [], 'CSS stack loaded via style.php.') ?></div></div>
                    <div>
                        <select name="active_theme" class="gs-select">
                            <?php foreach ($available_themes as $t_slug): ?>
                                <option value="<?= h($t_slug) ?>" <?= gs($settings,'active_theme','default') === $t_slug ? 'selected' : '' ?>><?= h(ucfirst($t_slug)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="gs-row">
                    <div class="gs-row-label"><?= t('acp_default_hero_image', [], 'Default Hero Image') ?><div class="gs-row-hint"><?= t('acp_default_hero_image_desc', [], 'Used on every frontend page that has no Hero Image of its own set in Content Manager. Leave empty to keep the plain dark page head as default.') ?></div></div>
                    <div>
                        <input type="text" name="default_hero_image" id="gs-hero-input" class="gs-input"
                               value="<?= h(gs($settings,'default_hero_image','')) ?>"
                               placeholder="e.g. assets/img/media/castle.jpg — leave empty for no site-wide default"
                               oninput="gsHeroPreview()">
                        <div class="gs-row-hint"><?= t('acp_default_hero_image_hint2', [], 'Copy a file\'s URL from Content Manager -> Media Library and paste it here.') ?></div>
                        <div class="cm-hero-preview" id="gs-hero-preview"></div>
                    </div>
                </div>
            </div>
            <div class="gs-save-bar"><button type="submit" name="save_settings" class="gs-btn-save"><i class="fas fa-save"></i>&nbsp; <?= t('acp_save_changes', [], 'Save Changes') ?></button></div>
        </div>

        <div class="gs-panel <?= $active_tab === 'modules' ? 'active' : '' ?>" id="panel-modules">
            <div class="gs-group">
                <div class="gs-group-head">
                    <i class="fas fa-cubes"></i> Site Modules
                    <span style="margin-left:auto;font-family:sans-serif;letter-spacing:0;text-transform:none;font-size:0.85em;color:#333;">Disabled modules are hidden from nav and return 404.</span>
                </div>
                <div class="gs-module-grid">
                    <?php
                    $modules_list = [
                        'mod_forum'                   => ['label' => 'Forum (Spike)',  'icon' => 'fa-comments',        'slug' => '?p=spike'],
                        'mod_herald'                  => ['label' => 'Herald',         'icon' => 'fa-trophy',           'slug' => '?p=herald'],
                        'mod_rvr_map'                 => ['label' => 'RvR Map',        'icon' => 'fa-map-location-dot', 'slug' => '?p=rvr_map'],
                        'mod_faq'                     => ['label' => 'FAQ',            'icon' => 'fa-question-circle',  'slug' => '?p=faq'],
                        'mod_team'                    => ['label' => 'Team Page',      'icon' => 'fa-users',            'slug' => '?p=team'],
                        'mod_register'                => ['label' => 'Registration',   'icon' => 'fa-user-plus',        'slug' => '?p=register'],
                        'mod_pve'                     => ['label' => 'PvE',            'icon' => 'fa-dragon',           'slug' => '?p=pve'],
                        'itemshop_enabled'            => ['label' => t('acp_mod_itemshop_label', [], 'PvE Itemshop'), 'icon' => 'fa-store', 'slug' => '?p=pve_items&filter=shop'],
                        'mod_imprint'                 => ['label' => 'Imprint / Impressum', 'icon' => 'fa-file-signature', 'slug' => '?p=imprint'],
                        'email_verification_required' => ['label' => 'Email Verification', 'icon' => 'fa-envelope-circle-check', 'slug' => 'System'],
                        'admin_approval_required'     => ['label' => 'Admin Approval', 'icon' => 'fa-user-lock',        'slug' => 'System'],
                        'use_resend_api'              => ['label' => 'Use Resend API', 'icon' => 'fa-paper-plane',      'slug' => 'System'],
                    ];
                    foreach ($modules_list as $key => $mod):
                        $enabled = gs($settings, $key, '1') === '1';
                    ?>
                    <div class="gs-module-item">
                        <div>
                            <div class="gs-module-name"><i class="fas <?= $mod['icon'] ?>"></i><?= $mod['label'] ?></div>
                            <div class="gs-module-slug"><?= $mod['slug'] ?></div>
                        </div>
                        <label class="gs-toggle">
                            <div class="gs-toggle-track">
                                <input type="checkbox" name="<?= $key ?>" value="1"
                                       <?= $enabled ? 'checked' : '' ?>
                                       onchange="this.closest('.gs-toggle').querySelector('.gs-toggle-state').textContent=this.checked?'ON':'OFF'">
                                <span class="gs-toggle-slider"></span>
                            </div>
                            <span class="gs-toggle-state"><?= $enabled ? 'ON' : 'OFF' ?></span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="gs-save-bar"><button type="submit" name="save_settings" class="gs-btn-save"><i class="fas fa-save"></i>&nbsp; <?= t('acp_save_changes', [], 'Save Changes') ?></button></div>
        </div>

        <div class="gs-panel <?= $active_tab === 'gameserver' ? 'active' : '' ?>" id="panel-gameserver">

            <div class="gs-group">
                <div class="gs-group-head"><i class="fas fa-code-branch"></i> Server Core</div>
                <div class="gs-row">
                    <div class="gs-row-label">Emulator
                        <div class="gs-row-hint">OpenDAoC is the primary RC2 target. DOL keeps the RC1-compatible schema mappings.</div>
                    </div>
                    <div>
                        <select name="game_server_core" class="gs-select">
                            <option value="opendaoc" <?= gs($settings, 'game_server_core', 'dol') === 'opendaoc' ? 'selected' : '' ?>>OpenDAoC</option>
                            <option value="dol" <?= gs($settings, 'game_server_core', 'dol') === 'dol' ? 'selected' : '' ?>>Dawn of Light (legacy)</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="gs-group">
                <div class="gs-group-head"><i class="fas fa-search"></i> Auto-Detection</div>
                <div style="padding:16px 18px;">

                    <?php if ($dol_auto_suggestion): ?>
                    <div class="gs-detect-box" id="gs-detect-box">
                        <div class="gs-detect-icon gs-detect-icon--found"><i class="fas fa-check-circle"></i></div>
                        <div class="gs-detect-info">
                            <div class="gs-detect-title">DOL Server detected</div>
                            <div class="gs-detect-path" id="gs-detect-path"><?= h($dol_auto_suggestion) ?></div>
                            <?php if (count($dol_scan_results) > 1): ?>
                            <div class="gs-scan-list acp-s-cb458930" id="gs-scan-list">
                                <?php foreach ($dol_scan_results as $r): ?>
                                <span onclick="gsUsePath('<?= addslashes(h($r)) ?>')"><?= h($r) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="gs-detect-actions">
                            <?php if (count($dol_scan_results) > 1): ?>
                            <button type="button" class="gs-btn-rescan" onclick="gsToggleScanList()">
                                <i class="fas fa-list"></i> <?= count($dol_scan_results) ?> found
                            </button>
                            <?php endif; ?>
                            <button type="button" class="gs-btn-confirm" onclick="gsConfirmPath()">
                                <i class="fas fa-check"></i> Use this path
                            </button>
                        </div>
                    </div>

                    <?php else: ?>
                    <div class="gs-detect-box gs-detect-box--none">
                        <div class="gs-detect-icon gs-detect-icon--none"><i class="fas fa-question-circle"></i></div>
                        <div class="gs-detect-info">
                            <div class="gs-detect-title acp-s-6ba8f8cb">No DOL server detected automatically</div>
                            <div class="gs-detect-path--none">Please enter the path manually below.</div>
                        </div>
                        <div class="gs-detect-actions">
                            <button type="button" class="gs-btn-rescan" onclick="window.location.reload()">
                                <i class="fas fa-sync-alt"></i> Rescan
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($dol_saved_bat): ?>
                    <div class="acp-s-750a70d4">
                        <i class="fas fa-save acp-s-4d29eea1"></i>
                        Currently saved: <span class="acp-s-10baa7d4"><?= h($dol_saved_bat) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="gs-group">
                <div class="gs-group-head"><i class="fas fa-network-wired"></i> Bridge Connection</div>
                <div class="gs-row">
                    <div class="gs-row-label">Game Server IP<div class="gs-row-hint">IP of the DOL server (usually 127.0.0.1).</div></div>
                    <div><input type="text" name="game_server_ip" class="gs-input" value="<?= h(gs($settings,'game_server_ip','127.0.0.1')) ?>" placeholder="127.0.0.1"></div>
                </div>
                <div class="gs-row">
                    <div class="gs-row-label">Game Server Port<div class="gs-row-hint">Public game port (default: 10300).</div></div>
                    <div><input type="number" name="game_server_port" class="gs-input" value="<?= h(gs($settings,'game_server_port','10300')) ?>" placeholder="10300"></div>
                </div>
                <div class="gs-row">
                    <div class="gs-row-label">Bridge Port<div class="gs-row-hint">AldhranBridge TCP port (default: 2000).</div></div>
                    <div><input type="number" name="game_server_bridge_port" class="gs-input" value="<?= h(gs($settings,'game_server_bridge_port','2000')) ?>" placeholder="2000"></div>
                </div>
                <div class="gs-row">
                    <div class="gs-row-label">Bridge Secret<div class="gs-row-hint">Must match BRIDGE_SECRET in AldhranBridge.cs.</div></div>
                    <div><input type="password" name="game_server_bridge_secret" class="gs-input" value="" placeholder="<?= !empty($settings['game_server_bridge_secret']) ? t('acp_secret_is_set', [], 'Secret is set (leave empty to keep)') : '' ?>"></div>
                </div>
            </div>

            <div class="gs-group">
                <div class="gs-group-head"><i class="fas fa-plug"></i> AldhranConsole (HTTP API)</div>
                <div class="gs-row">
                    <div class="gs-row-label">Console Host<div class="gs-row-hint">Host where AldhranConsole runs (usually 127.0.0.1).</div></div>
                    <div><input type="text" name="game_server_console_host" class="gs-input" value="<?= h(gs($settings,'game_server_console_host','127.0.0.1')) ?>" placeholder="127.0.0.1"></div>
                </div>
                <div class="gs-row">
                    <div class="gs-row-label">Console Port<div class="gs-row-hint">AldhranConsole HTTP port (default: 5100).</div></div>
                    <div><input type="number" name="game_server_console_port" class="gs-input" value="<?= h(gs($settings,'game_server_console_port','5100')) ?>" placeholder="5100"></div>
                </div>
                <div class="gs-row">
                    <div class="gs-row-label">Console Secret<div class="gs-row-hint">Must match Console:ApiSecret in appsettings.json.</div></div>
                    <div><input type="password" name="game_server_console_secret" class="gs-input" value="" placeholder="<?= !empty($settings['game_server_console_secret']) ? t('acp_secret_is_set', [], 'Secret is set (leave empty to keep)') : '' ?>"></div>
                </div>
            </div>

            <div class="gs-group">
                <div class="gs-group-head"><i class="fas fa-file-code"></i> Startup Script</div>
                <div class="gs-row">
                    <div class="gs-row-label">Server .bat Path
                        <div class="gs-row-hint">Full path to DOLServer.bat. Used for automatic restart after shutdown.</div>
                    </div>
                    <div>
                        <input type="text" name="game_server_bat_path" id="gs-bat-input"
                               class="gs-input gs-input--wide"
                               value="<?= h($dol_saved_bat) ?>"
                               placeholder="<?= PHP_OS_FAMILY === 'Windows' ? 'C:\\Release\\DOLServer.bat' : '/opt/daoc/Release/DOLServer.sh' ?>">
                        <div class="acp-s-4a74dca2">
                            <?php if ($dol_auto_suggestion && $dol_saved_bat !== $dol_auto_suggestion): ?>
                            Auto-detected: <span onclick="gsConfirmPath()" class="acp-s-4ac342c2"><?= h($dol_auto_suggestion) ?></span>
                            <span onclick="gsConfirmPath()" class="acp-s-cfadd431">← use this</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="gs-group">
                <div class="gs-group-head"><i class="fas fa-shield-alt"></i> Watchdog Script</div>
                <div class="acp-s-f8ec4b54">
                    <div class="acp-s-a2457f2e">
                        The watchdog is a <code class="acp-s-65ded8da">daoc_cms_watchdog.bat</code>
                        that runs permanently and automatically restarts DOLServer when it shuts down.
                        Place it in the same folder as <code class="acp-s-65ded8da">DOLServer.bat</code>
                        and run it instead of starting DOL directly.<br><br>
                        <span class="acp-s-757462cb">The file is generated based on the saved .bat path above.</span>
                    </div>

                    <?php
                    $saved_bat = gs($settings, 'game_server_bat_path', '');
                    $watchdog_dir = $saved_bat ? dirname($saved_bat) : '?';
                    ?>

                    <div style="background:#050505;border:1px solid #111;padding:10px 14px;font-family:'Courier New',monospace;font-size:0.72em;color:#444;margin-bottom:14px;line-height:1.8;">
                        <span style="color:#333;">@echo off</span><br>
                        <span style="color:#555;">set RETRIES=0</span><br>
                        <span style="color:#c5a059;">:loop</span><br>
                        <span style="color:#555;">echo [DAoC CMS Watchdog] Starting DOL Server...</span><br>
                        <span style="color:#555;">cd /d "</span><span style="color:#888;"><?= h($saved_bat ? dirname($saved_bat) : 'C:\Release') ?></span><span style="color:#555;">"</span><br>
                        call "<span style="color:#888;"><?= h($saved_bat ?: 'C:\Release\DOLServer.bat') ?></span>"<br>
                        <span style="color:#555;">set /a RETRIES+=1</span><br>
                        <span style="color:#555;">if %RETRIES% GEQ 3 ( echo Too many restarts. &amp; pause &amp; exit /b 1 )</span><br>
                        <span style="color:#555;">echo [DAoC CMS Watchdog] Restarting in 15 seconds...</span><br>
                        timeout /t 15 /nobreak > nul<br>
                        <span style="color:#555;">set RETRIES=0</span><br>
                        <span style="color:#c5a059;">goto loop</span>
                    </div>

                    <?php if ($saved_bat): ?>
                    <a href="acp.php?s=general_settings&download_watchdog=1"
                       class="gs-btn-save" style="display:inline-flex;align-items:center;gap:8px;text-decoration:none;">
                        <i class="fas fa-download"></i> Download daoc_cms_watchdog.bat
                    </a>
                    <div style="margin-top:8px;font-size:0.68em;color:#2a2a2a;font-family:sans-serif;">
                        Place in: <span style="font-family:'Courier New',monospace;color:#333;"><?= h($watchdog_dir) ?></span>
                    </div>
                    <?php else: ?>
                    <div style="font-size:0.72em;color:#444;font-family:sans-serif;">
                        <i class="fas fa-exclamation-triangle" style="color:#554400;margin-right:5px;"></i>
                        Save a valid .bat path above first to generate the watchdog.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="gs-save-bar"><button type="submit" name="save_settings" class="gs-btn-save"><i class="fas fa-save"></i>&nbsp; <?= t('acp_save_changes', [], 'Save Changes') ?></button></div>
        </div>

        <div class="gs-panel <?= $active_tab === 'maintenance' ? 'active' : '' ?>" id="panel-maintenance">
            <?php if ($userPriv >= 5): ?>
            <div class="gs-group">
                <div class="gs-group-head"><i class="fas fa-power-off"></i> <?= t('acp_maintenance_mode', [], 'Maintenance') ?></div>
                <div class="gs-maint-banner">
                    <div class="gs-maint-status">
                        <div class="gs-maint-dot <?= $is_maintenance ? 'gs-maint-dot--on' : 'gs-maint-dot--off' ?>"></div>
                        <div>
                            <div class="gs-maint-label <?= $is_maintenance ? 'gs-maint-label--on' : 'gs-maint-label--off' ?>">
                                <?= $is_maintenance ? 'Maintenance Active' : 'Site is Live' ?>
                            </div>
                            <div class="gs-maint-sub">
                                <?= $is_maintenance ? 'Only admins (level ≥5) can access the site.' : 'All users can access the site normally.' ?>
                            </div>
                        </div>
                    </div>
                    <button type="submit" name="toggle_maintenance"
                            class="gs-btn-toggle <?= $is_maintenance ? 'gs-btn-toggle--disable' : 'gs-btn-toggle--enable' ?>"
                            <?= !$is_maintenance ? "onclick=\"return confirm('Enable maintenance mode? All non-admin users will be locked out.')\"" : '' ?>>
                        <i class="fas <?= $is_maintenance ? 'fa-power-off' : 'fa-tools' ?>"></i>
                        <?= $is_maintenance ? 'Disable Maintenance' : 'Enable Maintenance' ?>
                    </button>
                </div>
            </div>
            <?php endif; ?>
            <div class="gs-group">
                <div class="gs-group-head"><i class="fas fa-comment-alt"></i><?= t('acp_maintenance_message', [], 'Maintenance Message') ?></div>
                <div class="gs-row">
                    <div class="gs-row-label"><?= t('acp_message', [], 'Message') ?><div class="gs-row-hint"><?= t('acp_html_allowed', [], 'HTML allowed. Shown to visitors when maintenance is active.') ?></div></div>
                    <div><textarea name="maint_message" class="gs-textarea"><?= h($current_maint_text) ?></textarea></div>
                </div>
            </div>
            <div class="gs-save-bar"><button type="submit" name="save_settings" class="gs-btn-save"><i class="fas fa-save"></i>&nbsp; <?= t('acp_save_changes', [], 'Save Changes') ?></button></div>
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
    img.onclick = () => cmHeroLightboxOpen(val);
    box.appendChild(img);
}
function cmHeroLightboxOpen(src) {
    let lb = document.getElementById('cm-hero-lightbox');
    if (!lb) {
        lb = document.createElement('div');
        lb.id = 'cm-hero-lightbox';
        lb.className = 'cm-hero-lightbox';
        lb.appendChild(document.createElement('img'));
        lb.addEventListener('click', () => lb.classList.remove('open'));
        document.body.appendChild(lb);
    }
    lb.querySelector('img').src = src;
    lb.classList.add('open');
}
document.addEventListener('DOMContentLoaded', gsHeroPreview);

(function () {
    const tabs   = document.querySelectorAll('.gs-tab');
    const panels = document.querySelectorAll('.gs-panel');
    const hidden = document.getElementById('gs-hidden-tab');
    tabs.forEach(function(tab) {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            const target = this.dataset.tab;
            tabs.forEach(function(t)   { t.classList.remove('active'); });
            panels.forEach(function(p) { p.classList.remove('active'); });
            this.classList.add('active');
            const panel = document.getElementById('panel-' + target);
            if (panel) panel.classList.add('active');
            if (hidden) hidden.value = target;
        });
    });
})();

// ── Game Server Detection Helpers ────────────────────────────
function gsConfirmPath() {
    const pathEl = document.getElementById('gs-detect-path');
    const input  = document.getElementById('gs-bat-input');
    if (pathEl && input) {
        input.value = pathEl.textContent.trim();
        input.style.borderColor = 'rgba(80,200,120,0.4)';
        setTimeout(function(){ input.style.borderColor = ''; }, 2000);
        // Ensure correct tab is active
        document.getElementById('gs-hidden-tab').value = 'gameserver';
    }
}

function gsUsePath(path) {
    const input = document.getElementById('gs-bat-input');
    const pathEl = document.getElementById('gs-detect-path');
    if (input)  input.value = path;
    if (pathEl) pathEl.textContent = path;
    gsToggleScanList();
    if (input) {
        input.style.borderColor = 'rgba(80,200,120,0.4)';
        setTimeout(function(){ input.style.borderColor = ''; }, 2000);
    }
}

function gsToggleScanList() {
    const list = document.getElementById('gs-scan-list');
    if (list) list.style.display = list.style.display === 'none' ? 'block' : 'none';
}
</script>
