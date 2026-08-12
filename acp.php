<?php
// SPDX-License-Identifier: GPL-3.0-only

define('IN_CMS', true);
define('IN_ACP', true);

require_once('includes/db.php');
require_once('includes/TOTP.php');

// ── Early AJAX intercepts ─────────────────────────────────────
if (isset($_GET['dqc_ajax'])) {
    acp_check_ajax_auth(4);
    $uid = (int)$_SESSION['user_id']; $currentUserId = $uid;
    include('modules/acp_dqc_view.php'); exit;
}
if (isset($_GET['s']) && $_GET['s'] === 'suit_creator' && isset($_GET['ajax'])) {
    acp_check_ajax_auth(3);
    $uid = (int)$_SESSION['user_id']; $currentUserId = $uid;
    include('modules/acp_suit_creator_logic.php'); exit;
}
if (isset($_GET['s']) && $_GET['s'] === 'item_creator' && isset($_GET['ajax'])) {
    acp_check_ajax_auth(4);
    $uid = (int)$_SESSION['user_id']; $currentUserId = $uid;
    include('modules/acp_item_creator_logic.php'); exit;
}
if (isset($_GET['s']) && $_GET['s'] === 'ability_editor' && isset($_GET['ajax'])) {
    acp_check_ajax_auth(4);
    $uid = (int)$_SESSION['user_id']; $currentUserId = $uid;
    include('modules/acp_ability_editor_logic.php'); exit;
}
if (isset($_GET['s']) && $_GET['s'] === 'bot_settings' && isset($_GET['ping_bot'])) {
    acp_check_ajax_auth(4);
    header('Content-Type: application/json');
    require_once __DIR__ . '/includes/BotSettings.php';
    $botSettings = new BotSettings($db);
    $result = $botSettings->sendCommand('ping');
    echo json_encode($result); exit;
}
if (isset($_GET['s']) && $_GET['s'] === 'translation_editor' && isset($_GET['ajax'])) {
    acp_check_ajax_auth(4);
    $uid = (int)$_SESSION['user_id']; $currentUserId = $uid;
    include('modules/acp_translation_editor_logic.php'); exit;
}
if (isset($_GET['s']) && $_GET['s'] === 'ingame_console' && isset($_GET['ajax'])) {
    acp_check_ajax_auth(4);
    $uid = (int)$_SESSION['user_id']; $currentUserId = $uid;
    $userPriv = (int)($_SESSION['priv_level'] ?? 0);
    $acp_username = h($_SESSION['username'] ?? 'Admin');
    include('modules/acp_ingame_console_logic.php'); exit;
}
// ── Watchdog.bat Download ─────────────────────────────────────
if (isset($_GET['s']) && $_GET['s'] === 'general_settings' && isset($_GET['download_watchdog'])) {
    acp_check_ajax_auth(4);

    $cfg = $db->query(
        "SELECT setting_key, value FROM settings
          WHERE setting_key IN ('game_server_bat_path', 'game_server_core')"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $startup_path = trim((string)($cfg['game_server_bat_path'] ?? ''));
    if ($startup_path === '' || !is_file($startup_path)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Configure a valid game server startup path before downloading the watchdog.';
        exit;
    }

    $core = strtolower((string)($cfg['game_server_core'] ?? 'dol'));
    $core_label = $core === 'opendaoc' ? 'OpenDAoC' : 'Dawn of Light';
    $startup_dir = str_replace('/', '\\', dirname($startup_path));
    $extension = strtolower((string)pathinfo($startup_path, PATHINFO_EXTENSION));
    $start_line = $extension === 'dll'
        ? 'dotnet "' . $startup_path . '"'
        : 'call "' . $startup_path . '"';

    $w  = '@echo off' . "\r\n";
    $w .= 'title DAoC CMS Watchdog' . "\r\n";
    $w .= 'set RETRIES=0' . "\r\n";
    $w .= ':loop' . "\r\n";
    $w .= 'echo [DAoC CMS Watchdog] Starting ' . $core_label . ' server (attempt %RETRIES%)...' . "\r\n";
    $w .= 'cd /d "' . $startup_dir . '"' . "\r\n";
    $w .= $start_line . "\r\n";
    $w .= 'echo [DAoC CMS Watchdog] Server stopped.' . "\r\n";
    $w .= 'set /a RETRIES+=1' . "\r\n";
    $w .= 'if %RETRIES% GEQ 3 (' . "\r\n";
    $w .= '  echo [DAoC CMS Watchdog] Too many restart attempts. Stopping watchdog.' . "\r\n";
    $w .= '  pause' . "\r\n";
    $w .= '  exit /b 1' . "\r\n";
    $w .= ')' . "\r\n";
    $w .= 'echo [DAoC CMS Watchdog] Restarting in 15 seconds...' . "\r\n";
    $w .= 'timeout /t 15 /nobreak > nul' . "\r\n";
    $w .= 'goto loop' . "\r\n";

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="daoc_cms_watchdog.bat"');
    header('Content-Length: ' . strlen($w));
    header('Cache-Control: no-cache');
    echo $w;
    exit;
}

if (isset($_GET['s']) && $_GET['s'] === 'cache' && isset($_GET['ajax'])) {
    acp_check_ajax_auth(4);
    $uid = (int)$_SESSION['user_id']; $currentUserId = $uid;
    include('modules/acp_cache_logic.php'); exit;
}
$_ai_ext_post_keys = ['cm_ai_action', 'tle_ai_action', 'te_ai_action', 'ca_ai_action'];
foreach ($_ai_ext_post_keys as $_ai_key) {
    if (isset($_POST[$_ai_key])) {
        acp_check_ajax_auth(4);
        $uid = (int)$_SESSION['user_id']; $currentUserId = $uid;
        include('modules/acp_all_views_ai_extensions.php'); exit;
    }
}
if (isset($_POST['ajax_action']) && str_starts_with($_POST['ajax_action'] ?? '', 'ai_') && ($_GET['s'] ?? '') === 'faq_admin') {
    acp_check_ajax_auth(4);
    $uid = (int)$_SESSION['user_id']; $currentUserId = $uid;
    include('modules/acp_faq_admin_ai_extension.php'); exit;
}
if (isset($_GET['s']) && $_GET['s'] === 'ai_suggestions' && isset($_GET['ajax'])) {
    acp_check_ajax_auth(4);
    $uid = (int)$_SESSION['user_id']; $currentUserId = $uid;
    include('modules/acp_ai_suggestions_view.php'); exit;
}

ob_start();

function acp_check_ajax_auth(int $min_priv = 4): void {
    $acp_auth_key = 'acp_authed_at';
    $acp_timeout  = 1800;
    if (!isset($_SESSION['user_id'])) { header('Content-Type: application/json'); echo json_encode(['error'=>'unauthenticated']); exit; }
    $userPriv = (int)($_SESSION['priv_level'] ?? 0);
    if ($userPriv < $min_priv) { header('Content-Type: application/json'); echo json_encode(['error'=>'forbidden']); exit; }
    $authed_at = $_SESSION[$acp_auth_key] ?? 0;
    if (!($authed_at > 0 && (time() - $authed_at) < $acp_timeout)) { header('Content-Type: application/json'); echo json_encode(['error'=>'session_expired']); exit; }
    $_SESSION[$acp_auth_key] = time();
}

if (!isset($_SESSION['user_id'])) { header("Location: index.php?p=login&ref=acp"); exit; }

$uid      = (int)$_SESSION['user_id'];
$userPriv = (int)($_SESSION['priv_level'] ?? 0);

if ($userPriv < 3) { header("Location: index.php"); exit; }

$stmt_auth = $db->prepare("SELECT id, password, is_2fa_enabled, totp_secret FROM users WHERE id = ? AND standing < 5");
$stmt_auth->execute([$uid]);
$authUser = $stmt_auth->fetch();
if (!$authUser) { session_destroy(); header("Location: index.php?p=login&msg=session_invalid"); exit; }

cms_load_language_context('core');

$acp_auth_key    = 'acp_authed_at';
$acp_timeout     = 1800;
$acp_login_error = '';
$acp_authed_at   = $_SESSION[$acp_auth_key] ?? 0;
$acp_is_authed   = ($acp_authed_at > 0 && (time() - $acp_authed_at) < $acp_timeout);

if (isset($_GET['acp_logout'])) { unset($_SESSION[$acp_auth_key]); header("Location: acp.php"); exit; }

$require_totp = ((int)$authUser['is_2fa_enabled'] === 1);
$force_block  = ($userPriv >= 5 && !$require_totp);

if ($force_block) {
    $acp_login_error = t('acp.require_2fa', [], 'Security Policy: SuperAdmins must enable 2FA in their Profile to access the ACP.');
} elseif (!$acp_is_authed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    checkToken($_POST['csrf_token'] ?? '');
    
    if ($require_totp && isset($_POST['acp_totp_code'])) {
        if (TOTP::verifyCode($authUser['totp_secret'], trim($_POST['acp_totp_code']))) {
            $_SESSION[$acp_auth_key] = time();
            header("Location: acp.php" . (isset($_GET['s']) ? '?s=' . preg_replace('/[^a-z0-9_]/', '', $_GET['s']) : ''));
            exit;
        } else {
            $acp_login_error = t('acp.reauth_totp_error', [], 'Invalid 2FA code. Please try again.');
            aldhran_log("ACP_REAUTH_FAIL", "Failed ACP 2FA attempt", $uid);
        }
    } elseif (!$require_totp && isset($_POST['acp_password'])) {
        if (aldhran_verify($_POST['acp_password'], $authUser['password'])) {
            $_SESSION[$acp_auth_key] = time();
            header("Location: acp.php" . (isset($_GET['s']) ? '?s=' . preg_replace('/[^a-z0-9_]/', '', $_GET['s']) : ''));
            exit;
        } else {
            $acp_login_error = t('acp.reauth_error', [], 'Invalid password. Please try again.');
            aldhran_log("ACP_REAUTH_FAIL", "Failed ACP password attempt", $uid);
        }
    }
}

if ($acp_is_authed) { $_SESSION[$acp_auth_key] = time(); }

// ── ACP Login Prompt ──────────────────────────────────────────
if (!$acp_is_authed):
    $csrf         = generateToken();
    $acp_username = h($_SESSION['username'] ?? 'Admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= t('acp.reauth_page_title', [], 'ACP - Authentication Required') ?></title>
    <link rel="stylesheet" href="assets/css/acp.css?v=<?= @filemtime(__DIR__ . '/assets/css/acp.css') ?: time() ?>">
    <?php if (function_exists('cms_run_hook')) echo cms_run_hook('hook_acp_head', 'raw'); ?>
</head>
<body class="acp-body acp-reauth-body">
    <div class="acp-starfield"><div class="acp-stars"></div></div>
    <div class="acp-reauth-box">
        <div class="acp-reauth-logo"><img src="assets/img/logo.png" alt="DAoC CMS"></div>
        <div class="acp-reauth-title"><?= t('acp.reauth_title', [], 'Control Panel Access') ?></div>
        <div class="acp-reauth-subtitle">
            <?= t('acp.reauth_subtitle', [], 'Confirm your identity to continue') ?><br>
            <strong><?= $acp_username ?></strong>
        </div>
        <?php if ($acp_login_error): ?>
            <div class="acp-reauth-error"><i class="fas fa-exclamation-circle"></i> <?= $acp_login_error ?></div>
        <?php endif; ?>
        
        <?php if (!$force_block): ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <?php if ($require_totp): ?>
            <div class="acp-reauth-field">
                <i class="fas fa-shield-alt"></i>
                <input type="text" name="acp_totp_code"
                       placeholder="<?= t('acp.reauth_totp_placeholder', [], '6-digit 2FA Code') ?>"
                       autofocus autocomplete="one-time-code" maxlength="6" pattern="\d{6}" required>
            </div>
            <?php else: ?>
            <div class="acp-reauth-field">
                <i class="fas fa-lock"></i>
                <input type="password" name="acp_password"
                       placeholder="<?= t('acp.reauth_placeholder', [], 'Your password') ?>"
                       autofocus autocomplete="current-password" required>
            </div>
            <?php endif; ?>
            <button type="submit" class="acp-reauth-btn">
                <i class="fas <?= $require_totp ? 'fa-key' : 'fa-shield-alt' ?>"></i>
                <?= t('acp.reauth_btn', [], 'Enter Control Panel') ?>
            </button>
        </form>
        <?php endif; ?>
        
        <a href="index.php" class="acp-reauth-back">
            <i class="fas fa-arrow-left"></i> <?= t('sidebar.nav_back_to_site', [], 'Back to Site') ?>
        </a>
    </div>
</body>
</html>
<?php
    if (ob_get_level() > 0) ob_end_flush();
    exit;
endif;

// ── Authenticated ─────────────────────────────────────────────
$acp_username  = h($_SESSION['username'] ?? 'Admin');
$currentUserId = $uid;
$section       = preg_replace('/[^a-z0-9_]/', '', $_GET['s'] ?? 'dashboard');
$lock_path     = __DIR__ . '/maintenance.lock';

if ($userPriv >= 5 && isset($_POST['toggle_maintenance'])) {
    checkToken($_POST['csrf_token'] ?? '');
    if (file_exists($lock_path)) { @unlink($lock_path); aldhran_log("MAINTENANCE_OFF", "Maintenance disabled via ACP", $uid); }
    else { @file_put_contents($lock_path, 'ACTIVE'); aldhran_log("MAINTENANCE_ON", "Maintenance enabled via ACP", $uid); }
    header("Location: acp.php?s=dashboard&msg=maintenance_toggled"); exit;
}

clearstatcache();
$is_maintenance = file_exists($lock_path);

$_acp_server_ip   = $GLOBALS['cms_settings']['game_server_ip'] ?? '127.0.0.1';
$_acp_server_port = (int)($GLOBALS['cms_settings']['game_server_port'] ?? 10300);
$_fp = @fsockopen($_acp_server_ip, $_acp_server_port, $_errno, $_errstr, 0.5);
$_acp_server_online = (bool)$_fp;
if ($_fp) fclose($_fp);

$_plugin_sections = [];
if (function_exists('cms_run_hook')) {
    $raw = cms_run_hook('hook_acp_register_section', 'raw');
    if (is_array($raw)) foreach ($raw as $entry) if (is_array($entry)) $_plugin_sections = array_merge($_plugin_sections, $entry);
}
$_plugin_views = [];
if (function_exists('cms_run_hook')) {
    $raw = cms_run_hook('hook_acp_register_view', 'raw');
    if (is_array($raw)) foreach ($raw as $entry) if (is_array($entry)) $_plugin_views = array_merge($_plugin_views, $entry);
}

// ── Funktions-Kategorien ────────────────────────────────────────
$acp_categories = [
    'community'   => ['key' => 'acp_cat_community',   'fallback' => 'Community',     'icon' => 'fa-people-group',            'order' => 10],
    'content'     => ['key' => 'acp_cat_content',     'fallback' => 'Content',       'icon' => 'fa-pen-nib',                 'order' => 20],
    'gamecontent' => ['key' => 'acp_cat_gamecontent', 'fallback' => 'Game Content',  'icon' => 'fa-dragon',                  'order' => 30],
    'server'      => ['key' => 'acp_cat_server',      'fallback' => 'Server',        'icon' => 'fa-server',                  'order' => 40],
    'ai'          => ['key' => 'acp_cat_ai',          'fallback' => 'AI & Bot',      'icon' => 'fa-robot',                   'order' => 50],
    'system'      => ['key' => 'acp_cat_system',      'fallback' => 'System',        'icon' => 'fa-screwdriver-wrench',      'order' => 60],
    'plugins'     => ['key' => 'acp_cat_plugins',     'fallback' => 'Plugins',       'icon' => 'fa-puzzle-piece',            'order' => 70],
];

$allowed_sections = [
    'dashboard'          => ['min_priv'=>3,'label'=>'Dashboard',           'icon'=>'fa-tachometer-alt',   'category'=>null,        'desc'=>''],
    'um'                 => ['min_priv'=>3,'label'=>'User Manager',        'icon'=>'fa-users-gear',       'category'=>'community',  'desc'=>'Accounts & privileges'],
    'mob_editor'         => ['min_priv'=>3,'label'=>'Mob Editor',          'icon'=>'fa-map-location-dot', 'category'=>'gamecontent','desc'=>'Visual spawn editor'],
    'content_manager'    => ['min_priv'=>4,'label'=>'Content Manager',      'icon'=>'fa-edit',             'category'=>'content',    'desc'=>'Pages & navigation'],
    'general_settings'   => ['min_priv'=>4,'label'=>'General Settings',     'icon'=>'fa-cog',              'category'=>'system',     'desc'=>'Site config & modules'],
    'theme_editor'       => ['min_priv'=>4,'label'=>'Theme Editor',         'icon'=>'fa-paint-brush',      'category'=>'content',    'desc'=>'CSS variable editor'],
    'translation_editor' => ['min_priv'=>4,'label'=>'Translation Editor',   'icon'=>'fa-language',         'category'=>'content',    'desc'=>'Manage all language strings'],
    'admin_log'          => ['min_priv'=>4,'label'=>'Admin Log',            'icon'=>'fa-history',          'category'=>'system',     'desc'=>'All admin actions'],
    'admin_ip_audit'     => ['min_priv'=>4,'label'=>'IP Audit',             'icon'=>'fa-fingerprint',      'category'=>'system',     'desc'=>'Logins & households'],
    'error_log'          => ['min_priv'=>5,'label'=>'PHP Error Log',        'icon'=>'fa-bug',              'category'=>'system',     'desc'=>'Trace down PHP issues'],
    'spike_admin'        => ['min_priv'=>4,'label'=>'Forum Admin',          'icon'=>'fa-comments',         'category'=>'community',  'desc'=>'Boards, threads, posts'],
    'faq_admin'          => ['min_priv'=>3,'label'=>'FAQ Manager',          'icon'=>'fa-question-circle',  'category'=>'community',  'desc'=>'Categories & entries'],
    'plugin_manager'     => ['min_priv'=>4,'label'=>'Plugin Manager',       'icon'=>'fa-puzzle-piece',     'category'=>'system',     'desc'=>'Install & manage plugins'],
    'core_architect'     => ['min_priv'=>3,'label'=>'Core Architect',       'icon'=>'fa-drafting-compass', 'category'=>'gamecontent','desc'=>'Economy analytics & simulation'],
    'dqc'                => ['min_priv'=>3,'label'=>'Dataquest Creator',    'icon'=>'fa-scroll',           'category'=>'gamecontent','desc'=>'Visual quest editor & simulator'],
    'item_creator'       => ['min_priv'=>3,'label'=>'Item Creator',         'icon'=>'fa-shield-alt',       'category'=>'gamecontent','desc'=>'Create & edit item templates'],
    'suit_creator'       => ['min_priv'=>3,'label'=>'Suit Creator',         'icon'=>'fa-tshirt',           'category'=>'gamecontent','desc'=>'Build & distribute gear sets'],
    'ability_editor'     => ['min_priv'=>4,'label'=>'Ability Editor',       'icon'=>'fa-bolt',             'category'=>'gamecontent','desc'=>'Spells, Lines, Styles & NPCs'],
    'bot_settings'       => ['min_priv'=>4,'label'=>'Bot & AI Settings',    'icon'=>'fa-robot',            'category'=>'ai',         'desc'=>'Discord bot & AI provider'],
    'bot_commands'       => ['min_priv'=>4,'label'=>'Bot Commands',         'icon'=>'fa-terminal',         'category'=>'ai',         'desc'=>'AuthLevel & permission overrides'],
    'ai_suggestions'     => ['min_priv'=>4,'label'=>'AI Suggestions',       'icon'=>'fa-lightbulb',        'category'=>'ai',         'desc'=>'Accept or reject AI proposals'],
    'ingame_console'     => ['min_priv'=>4,'label'=>'Ingame Console',       'icon'=>'fa-terminal',         'category'=>'server',     'desc'=>'Realtime player management via the game server console'],
    'server_properties'  => ['min_priv'=>4,'label'=>'Server Properties',    'icon'=>'fa-sliders-h',        'category'=>'server',     'desc'=>'Game server properties & rates'],
    'cache'              => ['min_priv'=>3,'label'=>'Cache Manager',        'icon'=>'fa-database',         'category'=>'system',     'desc'=>'CSS, OPcache & browser headers'],
	'backup'             => ['min_priv'=>4,'label'=>'Backup Manager',    'icon'=>'fa-archive',          'category'=>'system',     'desc'=>'Manage System & Database-Backups'],
	'char_editor'        => ['min_priv'=>3,'label'=>'Character Editor',  'icon'=>'fa-user-ninja',       'category'=>'gamecontent','desc'=>'Editor for Playercharacters'],
	'zones_editor'       => ['min_priv'=>5,'label'=>'Zones Editor',      'icon'=>'fa-map',              'category'=>'gamecontent','desc'=>'Edit Zone Properties'],
	'global_constants'   => ['min_priv'=>3,'label'=>'Global Constants',  'icon'=>'fa-book-atlas',       'category'=>'gamecontent','desc'=>'Constants & ID references'],
];
$allowed_sections = array_merge($allowed_sections, $_plugin_sections);

foreach ($allowed_sections as $_as_key => &$_as_cfg) {
    if (!array_key_exists('category', $_as_cfg) || ($_as_cfg['category'] === null && $_as_key !== 'dashboard')) {
        $_as_cfg['category'] = ($_as_key === 'dashboard') ? null : 'plugins';
    }
    if (!isset($_as_cfg['desc'])) $_as_cfg['desc'] = '';
}
unset($_as_cfg);

if (!isset($allowed_sections[$section]) || $userPriv < $allowed_sections[$section]['min_priv']) {
    $section = 'dashboard';
    if ($userPriv < $allowed_sections['dashboard']['min_priv']) {
        header("Location: index.php");
        exit;
    }
}

$logic_map = [
    'um'                 => 'modules/acp_um_logic.php',
    'admin_log'          => 'modules/acp_admin_log_logic.php',
    'error_log'          => 'modules/acp_error_log_logic.php',
    'spike_admin'        => 'modules/acp_spike_admin_logic.php',
    'theme_editor'       => 'modules/acp_theme_editor_logic.php',
    'translation_editor' => 'modules/acp_translation_editor_logic.php',
    'plugin_manager'     => 'modules/acp_plugin_manager_logic.php',
    'item_creator'       => 'modules/acp_item_creator_logic.php',
    'suit_creator'       => 'modules/acp_suit_creator_logic.php',
    'ability_editor'     => 'modules/acp_ability_editor_logic.php',
    'ingame_console'     => 'modules/acp_ingame_console_logic.php',
    'server_properties'  => 'modules/acp_server_properties_logic.php',
    'cache'              => 'modules/acp_cache_logic.php',
	'backup'             => 'modules/acp_backup_logic.php',
	'char_editor'        => 'modules/acp_char_editor_logic.php',
	'zones_editor'       => 'modules/acp_zones_editor_logic.php',
	'global_constants'   => 'modules/acp_global_constants_logic.php',
];
if (isset($logic_map[$section]) && file_exists($logic_map[$section])) {
    include($logic_map[$section]);
    if (isset($_GET['ajax'])) exit;
}

$view_map = [
    'um'                 => 'modules/acp_um_view.php',
    'mob_editor'         => daoc_game_server_is_opendaoc()
                                ? 'modules/acp_mob_editor_opendaoc.php'
                                : 'modules/acp_mob_editor.php',
    'content_manager'    => 'modules/acp_content_manager.php',
    'general_settings'   => 'modules/acp_general_settings.php',
    'theme_editor'       => 'modules/acp_theme_editor_view.php',
    'translation_editor' => 'modules/acp_translation_editor_view.php',
    'admin_log'          => 'modules/acp_admin_log_view.php',
    'admin_ip_audit'     => 'modules/acp_admin_ip_audit_view.php',
    'error_log'          => 'modules/acp_error_log_view.php',
    'spike_admin'        => 'modules/acp_spike_admin_view.php',
    'faq_admin'          => 'modules/acp_faq_admin_view.php',
    'plugin_manager'     => 'modules/acp_plugin_manager_view.php',
    'core_architect'     => 'modules/acp_core_architect_view.php',
    'dqc'                => 'modules/acp_dqc_view.php',
    'item_creator'       => 'modules/acp_item_creator_view.php',
    'suit_creator'       => 'modules/acp_suit_creator_view.php',
    'ability_editor'     => 'modules/acp_ability_editor_view.php',
    'bot_settings'       => 'modules/acp_bot_settings_view.php',
    'bot_commands'       => 'modules/acp_bot_commands_view.php',
    'ai_suggestions'     => 'modules/acp_ai_suggestions_view.php',
    'ingame_console'     => 'modules/acp_ingame_console_view.php',
    'server_properties'  => 'modules/acp_server_properties_view.php',
    'cache'              => 'modules/acp_cache_view.php',
	'backup'             => 'modules/acp_backup_view.php',
	'char_editor'        => 'modules/acp_char_editor_view.php',
	'zones_editor'       => 'modules/acp_zones_editor_view.php',
	'global_constants'   => 'modules/acp_global_constants_view.php',
];

if (isset($_GET['ajax'])) exit;

$_ai_pending_count = 0;
try { $_ai_pending_count = (int)$db->query("SELECT COUNT(*) FROM cms_ai_suggestions WHERE status='pending'")->fetchColumn(); } catch (\Throwable $e) {}

$acp_remaining  = max(0, $acp_timeout - (time() - $_SESSION[$acp_auth_key]));
$_section_label = $allowed_sections[$section]['label'] ?? $section;
$_section_icon  = $allowed_sections[$section]['icon']  ?? 'fa-circle';

$_nav_slots = [
    ['s'=>'dashboard',        'icon'=>'fa-tachometer-alt',  'label'=>'Home',      'min_priv'=>3],
    ['s'=>'um',               'icon'=>'fa-users-gear',       'label'=>'Users',     'min_priv'=>3],
    ['s'=>'content_manager',  'icon'=>'fa-edit',             'label'=>'Content',   'min_priv'=>4],
    ['s'=>'mob_editor',       'icon'=>'fa-map-location-dot', 'label'=>'Mobs',      'min_priv'=>3],
    // sep
    ['s'=>'core_architect',   'icon'=>'fa-drafting-compass', 'label'=>'Architect', 'min_priv'=>3],
    ['s'=>'dqc',              'icon'=>'fa-scroll',           'label'=>'DQC',       'min_priv'=>3],
    ['s'=>'item_creator',     'icon'=>'fa-shield-alt',       'label'=>'Items',     'min_priv'=>3],
    ['s'=>'ability_editor',   'icon'=>'fa-bolt',             'label'=>'Abilities', 'min_priv'=>4],
    // sep
    ['s'=>'ai_suggestions',   'icon'=>'fa-lightbulb',        'label'=>'AI',        'min_priv'=>4],
    ['s'=>'bot_settings',     'icon'=>'fa-robot',            'label'=>'Bot',       'min_priv'=>4],
    ['s'=>'general_settings', 'icon'=>'fa-cog',              'label'=>'Settings',  'min_priv'=>4],
    ['s'=>'cache',            'icon'=>'fa-database',         'label'=>'Cache',     'min_priv'=>3],
    ['s'=>'admin_log',        'icon'=>'fa-history',          'label'=>'Log',       'min_priv'=>4],
	['s'=>'backup',           'icon'=>'fa-archive',          'label'=>'Backup',    'min_priv'=>4],
];
$_qb_seps = [3, 7];

foreach ($_plugin_sections as $slug => $sec) {
    $_nav_slots[] = [
        's'        => $slug,
        'icon'     => $sec['icon']     ?? 'fa-puzzle-piece',
        'label'    => $sec['label']    ?? $slug,
        'min_priv' => $sec['min_priv'] ?? 5,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DAoC CMS ACP - <?= h($_section_label) ?></title>
    <link rel="stylesheet" href="assets/css/acp.css?v=<?= @filemtime(__DIR__ . '/assets/css/acp.css') ?: time() ?>">
    <link rel="stylesheet" href="style.php?module=acp_<?= h($section) ?>&v=<?= time() ?>">
    <?php if (function_exists('cms_run_hook')) echo cms_run_hook('hook_acp_head', 'raw'); ?>
    <?= AldhranAssets::renderTokens($GLOBALS['cms_settings']) ?>
    <?= AldhranAssets::render('acp') ?>
</head>
<body class="acp-body">

<div class="acp-starfield"><div class="acp-stars"></div></div>

<header class="acp-hdr">
    <a href="acp.php" class="acp-hdr-logo">Control Panel</a>
    <div class="acp-hdr-divider"></div>
    <div class="acp-hdr-clock"><i class="fas fa-moon"></i><span id="acp-clock">00:00</span></div>
    <div class="acp-hdr-divider"></div>
    <div class="acp-hdr-pill <?= $_acp_server_online ? 'acp-hdr-pill--online' : 'acp-hdr-pill--offline' ?>">
        <div class="acp-hdr-dot <?= $_acp_server_online ? 'acp-hdr-dot--on' : 'acp-hdr-dot--off' ?>"></div>
        <?= $_acp_server_online ? 'SERVER ONLINE' : 'SERVER OFFLINE' ?>
    </div>
    <?php if ($userPriv >= 4): ?>
    <div class="acp-s-e4f5a21d"></div>
    <button class="acp-hdr-restart" onclick="rstOpen()" title="Schedule game server restart">
        <i class="fas fa-power-off"></i> RESTART
    </button>
    <?php endif; ?>
    <?php if ($is_maintenance): ?>
    <div class="acp-s-90bc8497"></div>
    <div class="acp-hdr-pill acp-hdr-pill--maint">
        <i class="fas fa-triangle-exclamation acp-s-28caee94"></i>
        <?= t('acp.maintenance_badge', [], 'MAINTENANCE') ?>
    </div>
    <?php endif; ?>
    <?php if ($section !== 'dashboard'): ?>
    <div class="acp-hdr-divider"></div>
    <div class="acp-hdr-bc">
        <a href="acp.php">ACP</a>
        <span>&#x203A;</span>
        <span class="cur"><i class="fas <?= h($_section_icon) ?> acp-s-8d868b70" ></i><?= h($_section_label) ?></span>
    </div>
    <?php endif; ?>
    <div class="acp-hdr-right">
        <a href="index.php" class="acp-hdr-site-btn" target="_blank">
            <i class="fas fa-arrow-up-right-from-square"></i> <?= t('acp_to_site', [], 'Site') ?>
        </a>
        <div class="acp-hdr-divider acp-s-e9af27f3"></div>
        <span class="acp-hdr-user">
            <i class="fas fa-user-shield acp-s-8bf9f212"></i>
            <?= $acp_username ?>
            <small id="acp-session-timer"></small>
        </span>
        <a href="acp.php?acp_logout=1" class="acp-hdr-icon" title="Lock ACP"><i class="fas fa-lock"></i></a>
        <a href="logout.php" class="acp-hdr-icon logout" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
    </div>
</header>

<?php if ($userPriv >= 4): ?>
<div class="rst-backdrop" id="rstBackdrop" onclick="if(event.target===this)rstClose()">
    <div class="rst-modal">
        <div class="rst-head">
          <div class="rst-title"><i class="fas fa-power-off"></i> <?= t('server_restart.title', [], 'Schedule Server Restart') ?></div>
<button class="rst-close" onclick="rstClose()">&#x2715;</button>
</div>
<div class="rst-field">
    <label class="rst-label"><?= t('server_restart.delay.label', [], 'Reboot in (minutes)') ?> <span class="acp-s-94294764"><?= t('server_restart.delay.required', [], '*') ?></span></label>
    <input type="number" id="rst-delay" class="rst-input" min="0" max="60" value="5" placeholder="<?= t('server_restart.delay.placeholder', [], '0 = immediate') ?>">
    <div class="rst-hint"><?= t('server_restart.delay.hint', [], '0 = restart immediately after announcement') ?></div>
</div>
<div class="rst-field">
    <label class="rst-label"><?= t('server_restart.announcement.label', [], 'Announcement') ?> <span class="acp-s-6ba8f8cb"><?= t('server_restart.announcement.optional', [], '(optional)') ?></span></label>
    <input type="text" id="rst-announce" class="rst-input"
           placeholder="<?= t('server_restart.announcement.placeholder', [], 'e.g. Restart for maintenance. Please save your progress.') ?>">
    <div class="rst-hint"><?= t('server_restart.announcement.hint', [], 'Broadcast to all online players before the countdown') ?></div>
</div>
<div class="rst-result" id="rst-result"></div>
<div class="rst-actions">
    <button class="rst-btn rst-btn--cancel" onclick="rstClose()"><?= t('server_restart.action.cancel', [], 'Cancel') ?></button>
    <button class="rst-btn rst-btn--go" id="rst-go" onclick="rstConfirm()">
        <i class="fas fa-power-off"></i> <?= t('server_restart.action.confirm', [], 'Schedule Restart') ?>
    </button>
</div>
    </div>
</div>
<?php endif; ?>

<div class="acp-stage">
<?php
if ($section === 'dashboard') {
    include('modules/acp_dashboard_view.php');
} elseif (isset($view_map[$section]) && file_exists($view_map[$section])) {
    include($view_map[$section]);
} elseif (isset($_plugin_views[$section]) && is_callable($_plugin_views[$section])) {
    echo AldhranAssets::pluginContainerOpen($section);
    echo call_user_func($_plugin_views[$section]);
    echo AldhranAssets::pluginContainerClose();
} else {
    echo '<div class="acp-empty">No view registered for: <code>' . h($section) . '</code></div>';
}
?>
</div>

<div class="acp-qb-wrap" id="acp-qb-wrap">
    <div class="acp-qb-toggle" id="acp-qb-toggle" onclick="acpQbToggle()">
        <i class="fas fa-chevron-down"></i> QUICKBAR
    </div>
    <div class="acp-qb" id="acp-qb">
        <?php
        $sn = 1;
        foreach ($_nav_slots as $idx => $slot):
            if ($userPriv < $slot['min_priv']) { continue; }
            if (in_array($idx - 1, $_qb_seps)): ?>
        <div class="acp-qb-sep"></div>
        <?php endif;
            $active = ($section === $slot['s']);
            $bdg    = ($slot['s'] === 'ai_suggestions' && $_ai_pending_count > 0) ? $_ai_pending_count : 0;
        ?>
        <a href="acp.php?s=<?= $slot['s'] ?>" class="acp-slot<?= $active ? ' is-active' : '' ?>" title="<?= h($slot['label']) ?>">
            <i class="fas <?= h($slot['icon']) ?>"></i>
            <span class="acp-slot-lbl"><?= h($slot['label']) ?></span>
            <span class="acp-slot-n"><?= $sn ?></span>
            <?php if ($bdg): ?><span class="acp-slot-bdg"><?= $bdg ?></span><?php endif; ?>
        </a>
        <?php $sn++; endforeach; ?>
        <div class="acp-qb-sep"></div>
        <a href="logout.php" class="acp-slot acp-s-289bd5ea" title="Logout">
            <i class="fas fa-sign-out-alt"></i>
            <span class="acp-slot-lbl">Logout</span>
            <span class="acp-slot-n">0</span>
        </a>
    </div>
</div>

<div id="toast"></div>

<script>
(function(){
    var rem = <?= (int)$acp_remaining ?>;
    var el  = document.getElementById('acp-session-timer');
    if (!el || rem <= 0) return;
    function fmt(s){var m=Math.floor(s/60),x=s%60;return m+':'+(x<10?'0':'')+x;}
    el.textContent='('+fmt(rem)+')';
    setInterval(function(){
        if(--rem<=0){location.reload();return;}
        el.textContent='('+fmt(rem)+')';
        if(rem<=120)el.style.color='#b85050';
    },1000);
})();

(function(){
    var el=document.getElementById('acp-clock');
    function tick(){if(!el)return;var n=new Date();el.textContent=n.getHours().toString().padStart(2,'0')+':'+n.getMinutes().toString().padStart(2,'0');}
    tick(); setInterval(tick,1000);
})();

var _qbc = localStorage.getItem('acp_qb')!=='0';
function acpQbToggle(){ _qbc=!_qbc; localStorage.setItem('acp_qb',_qbc?'1':'0'); acpQbApply(); }
function acpQbApply(){
    var qb=document.getElementById('acp-qb'), btn=document.getElementById('acp-qb-toggle');
    if(!qb||!btn)return;
    qb.classList.toggle('is-collapsed',!_qbc);
    btn.classList.toggle('is-collapsed',!_qbc);
}
acpQbApply();

// ── Restart Modal ────────────────────────────────────────────
<?php if ($userPriv >= 4): ?>
var _rstCsrf = "<?= generateToken() ?>";

function rstOpen() {
    document.getElementById('rstBackdrop').classList.add('show');
    document.getElementById('rst-delay').focus();
    document.getElementById('rst-result').style.display = 'none';
}
function rstClose() {
    document.getElementById('rstBackdrop').classList.remove('show');
}

function rstConfirm() {
    var delay    = parseInt(document.getElementById('rst-delay').value) || 0;
    var announce = document.getElementById('rst-announce').value.trim();
    var btn      = document.getElementById('rst-go');
    var result   = document.getElementById('rst-result');

    btn.disabled = true;
    btn.classList.add('is-loading');
    result.style.display = 'none';

    var fd = new FormData();
    fd.append('csrf_token',    _rstCsrf);
    fd.append('delay_minutes', delay);
    if (announce) fd.append('announcement', announce);

    fetch('ajax_restart.php', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            btn.disabled = false;
            btn.classList.remove('is-loading');
            result.style.display = 'block';
            if (d.ok) {
                result.className = 'rst-result ok';
                result.textContent = '\u2713 ' + (d.result || 'Restart scheduled.');
                setTimeout(rstClose, 3000);
            } else {
                result.className = 'rst-result fail';
                result.textContent = '\u2717 ' + (d.error || 'Unknown error.');
            }
        })
        .catch(function(){
            btn.disabled = false;
            btn.classList.remove('is-loading');
            result.style.display = 'block';
            result.className = 'rst-result fail';
            result.textContent = '\u2717 Network error.';
        });
}

document.addEventListener('keydown', function(e){ if(e.key==='Escape') rstClose(); });
<?php endif; ?>
</script>

<?php if (ob_get_level() > 0) ob_end_flush(); ?>
</body>
</html>
