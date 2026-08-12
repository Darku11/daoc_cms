<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

// Normal CMS execution starts here
ob_start();
define('IN_CMS', true);

require_once('includes/db.php');
require_once('includes/template_engine.php');
$timeout_duration = 1800;

if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];

    $stmt_act = $db->prepare("UPDATE users SET last_activity = ? WHERE id = ?");
    $stmt_act->execute([time(), $uid]);

    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout_duration)) {
        aldhran_log("SESSION_TIMEOUT", "User session expired", $uid);
        session_unset();
        session_destroy();
        header("Location: index.php?p=login&timeout=1");
        exit;
    }
    $_SESSION['last_activity'] = time();

    $stmt_auth = $db->prepare("SELECT id FROM users WHERE id = ? AND standing < 5");
    $stmt_auth->execute([$uid]);
    if (!$stmt_auth->fetch()) {
        aldhran_log("AUTH_KICK", "User kicked due to standing/ban", $uid);
        session_destroy();
        header("Location: index.php?p=login&msg=session_invalid");
        exit;
    }
}

// --- 2. GLOBAL VARIABLES ---
$page_slug     = preg_replace('/[^a-z0-9_\-]/i', '', $_GET['p'] ?? 'home');
$userPriv      = (int)($_SESSION['priv_level'] ?? 0);
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$can_edit      = ($userPriv >= 4);
$can_edit_um   = ($userPriv >= 3);
$myStanding    = (int)($_SESSION['user_standing'] ?? 0);

if ($myStanding >= 5) {
    session_destroy();
    die(t(
        'account_permanently_suspended',
        'Your access to DAoC CMS has been permanently suspended.'
    ));
}

if ($page_slug === 'logout') {
    require_once('logout.php');
    exit;
}

$unread_count = 0;
if ($currentUserId > 0) {
    $stmt_notif = $db->prepare("SELECT COUNT(id) as cnt FROM spike_notifications WHERE user_id = ? AND is_read = 0");
    $stmt_notif->execute([$currentUserId]);
    $unread_count = (int)$stmt_notif->fetch()['cnt'];
}

// --- 3. MAINTENANCE ---
clearstatcache();
$lock_path      = __DIR__ . '/maintenance.lock';
$is_maintenance = file_exists($lock_path);

if ($is_maintenance && $userPriv < 5 && $page_slug !== 'login') {
    $stmt_m = $db->prepare("SELECT value FROM settings WHERE setting_key = 'maintenance_text' LIMIT 1");
    $stmt_m->execute();
    $maintenance_msg = $stmt_m->fetchColumn() ?: t('maintenance.default_text', [], 'The site is currently under maintenance. Please check back soon.');
    while (ob_get_level()) { ob_end_clean(); }
    die("
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>DAoC CMS – Maintenance</title>
    <link href='https://fonts.googleapis.com/css2?family=Cinzel:wght@400;700&display=swap' rel='stylesheet'>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { background:#050505; color:#d4af37; display:flex; justify-content:center; align-items:center; min-height:100vh; font-family:serif; }
        .box { border:1px solid #d4af37; padding:50px; text-align:center; box-shadow:0 0 30px rgba(212,175,55,0.3); max-width:600px; width:90%; background:#000; overflow-wrap:break-word; word-break:break-word; }
        .box img { max-width:min(250px, 100%); height:auto; margin-bottom:20px; }
        .box h1 { font-family:'Cinzel',serif; font-size:clamp(1.3rem, 6vw, 2.2rem); letter-spacing:clamp(0px, 1vw, 5px); line-height:1.3; margin-bottom:20px; max-width:100%; overflow-wrap:anywhere; word-break:break-word; }
        .box p { color:#fff; font-style:italic; font-family:sans-serif; line-height:1.6; margin-bottom:30px; overflow-wrap:break-word; }
        .box a { display:inline-block; color:#d4af37; text-decoration:none; border:1px solid #d4af37; padding:10px 20px; font-size:0.8em; letter-spacing:2px; font-family:'Cinzel',serif; transition:background 0.2s; }
        .box a:hover { background:rgba(212,175,55,0.1); }
        @media (max-width: 480px) { .box { padding:30px 20px; width:94%; } }
    </style>
</head>
<body>
    <div class='box'>
        <img src='assets/img/logo.png' alt='DAoC CMS'>
        <h1>".t('index.maintenance_heading')."</h1>
        <p>" . h($maintenance_msg) . "</p>
        <a href='index.php?p=login'>Staff Login</a>
    </div>
</body>
</html>");
}

$cms_settings = [];
try {
    $cms_settings = $db->query("SELECT setting_key, value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {}

$hide_sidebar    = true;

$module_slug_map = [
    'spike'         => 'mod_forum',
    'viewboard'     => 'mod_forum',
    'viewthread'    => 'mod_forum',
    'newthread'     => 'mod_forum',
    'editpost'      => 'mod_forum',
    'spike_admin'   => 'mod_forum',
    'notifications' => 'mod_forum',
    'herald'        => 'mod_herald',
    'rvr_map'       => 'mod_rvr_map',
    'warmap'        => 'mod_rvr_map',
    'faq'           => 'mod_faq',
    'faq_admin'     => 'mod_faq',
    'team'          => 'mod_team',
    'register'      => 'mod_register',
    'pve'           => 'mod_pve',
    'pve_bestiary'  => 'mod_pve',
    'pve_dungeons'  => 'mod_pve',
    'pve_quests'    => 'mod_pve',
    'pve_items'     => 'mod_pve',
];

// --- 4. MODULE DISABLED / 404 ---
if ($userPriv < 4 && isset($module_slug_map[$page_slug])) {
    $required_setting = $module_slug_map[$page_slug];
    if (($cms_settings[$required_setting] ?? '1') === '0') {
        $article_title = '404';
        $module_content = '<div class="info-msg">' . t('general.module_disabled', [], 'This section is currently not available.') . '</div>';

        // Clear the output buffer and pass it directly to the template engine
        while (ob_get_level()) { ob_end_clean(); }
        cms_render_template('layout', get_defined_vars());
        exit;
    }
}

// --- 5. PAGE CONTENT FETCHING ---
if ($can_edit) {
    $stmt_page = $db->prepare("SELECT title, content, meta_title, meta_description, hero_image, status FROM pages WHERE slug = ?");
    $stmt_page->execute([$page_slug]);
} else {
    $stmt_page = $db->prepare("
        SELECT title, content, meta_title, meta_description, hero_image, status FROM pages
        WHERE slug = ?
          AND status = 'published'
          AND (published_at IS NULL OR published_at <= NOW())
          AND min_priv <= ?
    ");
    $stmt_page->execute([$page_slug, $userPriv]);
}
$data = $stmt_page->fetch();

// No per-page hero image: fall back to the site-wide default from
// General Settings. Applies to module-driven routes too (Forum, Herald,
// ...), since those still reach this point even without a pages row.
if (empty($data['hero_image'])) {
    $default_hero_stmt = $db->prepare("SELECT value FROM settings WHERE setting_key = 'default_hero_image' LIMIT 1");
    $default_hero_stmt->execute();
    $default_hero_image = (string)($default_hero_stmt->fetchColumn() ?: '');
    if ($default_hero_image !== '') {
        if (!is_array($data)) $data = [];
        $data['hero_image'] = $default_hero_image;
    }
}

if ($can_edit && isset($_POST['update_page_content'])) {
    checkToken($_POST['csrf_token'] ?? '');

    $target_slug  = preg_replace('/[^a-z0-9_\-]/i', '', $_POST['target_slug'] ?? '');
    $page_title   = $_POST['page_title']   ?? '';
    $page_content = $_POST['page_content'] ?? '';

    $stmt_save = $db->prepare("INSERT INTO pages (slug, title, content) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE title = VALUES(title), content = VALUES(content)");
    if ($stmt_save->execute([$target_slug, $page_title, $page_content])) {
        aldhran_log("PAGE_EDIT", "Page '$target_slug' updated via Architect Mode", $currentUserId);
        header("Location: index.php?p=" . urlencode($target_slug) . "&msg=success");
    } else {
        die(t('error_saving_page'));
    }
    exit;
}

// --- 6. BUSINESS LOGIC INCLUDES ---
$logic_map = [
    'discord_callback' => 'includes/discord_logic.php',
    'um'               => $can_edit_um ? 'modules/um_logic.php'        : null,
    'admin_log'        => $can_edit    ? 'modules/admin_log_logic.php'  : null,
];

if (isset($logic_map[$page_slug])) {
    if ($logic_map[$page_slug]) include($logic_map[$page_slug]);
} else {
    $normal_logic = "modules/{$page_slug}_logic.php";
    $spike_logic  = "modules/spike_{$page_slug}_logic.php";
    if (file_exists($normal_logic))    include($normal_logic);
    elseif (file_exists($spike_logic)) include($spike_logic);
}

$page_title_map = [
    'um'           => t('sidebar.nav_user_manager',  [], 'User Management'),
    'admin_log'    => t('sidebar.nav_admin_logs',    [], 'Admin Logs'),
    'portal_admin' => t('nav.portal_admin',          [], 'Portal Management'),
    'rvr_map'      => t('sidebar.nav_rvrmap',        [], 'RvR Map'),
    'warmap'       => t('sidebar.nav_rvrmap',        [], 'RvR Map'),
];
$article_title = $page_title_map[$page_slug] ?? h($data['title'] ?? ucwords(str_replace('_', ' ', $page_slug)));

// ==========================================
// START VIEW RENDERING (CONTENT BUFFERING)
// ==========================================
ob_start();

if (isset($_GET['edit_mode']) && $can_edit): ?>
    <div class="architect-editor-wrap" style="margin-bottom:40px; padding:25px; border:1px dashed rgba(197,160,89,0.2); background:rgba(197,160,89,0.02);">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo generateToken(); ?>">
            <input type="hidden" name="target_slug" value="<?php echo h($page_slug); ?>">
            <label style="display:block; font-size:0.65em; color:#333; text-transform:uppercase; letter-spacing:2px; font-family:'Cinzel',serif; margin-bottom:6px;">Page Title</label>
            <input type="text" name="page_title" value="<?php echo h($data['title'] ?? ''); ?>"
                   class="um-input" style="margin-bottom:20px;">
            <label style="display:block; font-size:0.65em; color:#333; text-transform:uppercase; letter-spacing:2px; font-family:'Cinzel',serif; margin-bottom:6px;">Content</label>
            <textarea id="editor" name="page_content"><?php echo $data['content'] ?? ''; ?></textarea>
            <div style="margin-top:20px; display:flex; gap:10px;">
                <button type="submit" name="update_page_content" class="btn-gold">Save Page</button>
                <a href="?p=<?php echo h($page_slug); ?>" style="color:#333; text-decoration:none; padding:10px; font-size:0.8em; font-family:'Cinzel',serif; text-transform:uppercase; letter-spacing:1px;">Cancel</a>
            </div>
        </form>
        <script src="assets/js/ckeditor.js"></script>
        <script>ClassicEditor.create(document.querySelector('#editor')).catch(e => console.error(e));</script>
    </div>
<?php endif;

if (!isset($_GET['edit_mode']) && !empty($data['content'])): ?>
    <div class="dynamic-content" style="margin-bottom:30px;"><?php echo $data['content']; ?></div>
<?php endif;

$pluginLoaded = false;
if (!isset($_GET['edit_mode'])) {
    $pluginBaseName = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', preg_replace('/[^a-z0-9_\-]/i', '', $page_slug))));
    $pluginClass    = $pluginBaseName . 'Plugin';
    $pluginFilePath = __DIR__ . '/plugins/' . $pluginClass . '.php';

    if (file_exists($pluginFilePath)) {
        $plugin = $GLOBALS['_cms_plugin_instances'][$page_slug] ?? null;
        if (!$plugin) {
            foreach ([__DIR__ . '/src/Contracts/PluginInterface.php', __DIR__ . '/src/Interface/PluginInterface.php'] as $_ip) {
                if (!interface_exists('PluginInterface') && file_exists($_ip)) { require_once $_ip; break; }
            }
            require_once $pluginFilePath;
            if (class_exists($pluginClass)) {
                try {
                    $plugin = new $pluginClass($db, $userPriv, $currentUserId);
                    if ($plugin instanceof \PluginInterface) {
                        $plugin->initialize();
                        if (method_exists($plugin, 'registerHooks')) $plugin->registerHooks();
                    } else {
                        error_log("Plugin [{$page_slug}] does not implement PluginInterface.");
                        $plugin = null;
                    }
                } catch (Throwable $e) {
                    error_log("Plugin error [{$page_slug}]: " . $e->getMessage());
                    $plugin = null;
                }
            }
        }
        if ($plugin instanceof \PluginInterface) {
            try {
                echo $plugin->render();
                $pluginLoaded = true;
            } catch (Throwable $e) {
                error_log("Plugin render error [{$page_slug}]: " . $e->getMessage());
            }
        }
    }
}

if (!$pluginLoaded) {
    $view_candidates = [
        "modules/{$page_slug}_view.php",
        "modules/spike_{$page_slug}_view.php",
        "modules/{$page_slug}.php",
        ($page_slug === 'login') ? 'login.php' : '',
    ];

    $view_to_include = '';
    foreach ($view_candidates as $candidate) {
        if ($candidate && file_exists($candidate)) {
            $view_to_include = $candidate;
            break;
        }
    }

    if ($view_to_include && !isset($_GET['edit_mode'])) {
        $active_style_name = $cms_settings['active_theme'] ?? 'default';
        $safe_style_name = preg_replace('/[^a-zA-Z0-9_\-]/', '', $active_style_name);
        $basename = basename($view_to_include);

        // Theme override path (matches htdocs/templates/ structure)
        $override_file = "templates/{$safe_style_name}/{$basename}";

        if (file_exists($override_file)) {
            include($override_file);
        } else {
            include($view_to_include);
        }
    } elseif (empty($data['content']) && !isset($_GET['edit_mode'])) {
        echo '<div class="info-msg">' . t('general.page_not_found', [], 'The page you are looking for does not exist yet.') . '</div>';
    }
}

// 7. END OUTPUT BUFFERING
$module_content = ob_get_clean();

// 8. PASS DATA TO THE MASTER LAYOUT
if (function_exists('cms_render_template')) {
    // get_defined_vars() dynamically passes ALL variables from this scope to the template.
    // This gives the header, footer, and layout access to variables such as
    // $data, $unread_count, $hide_sidebar, etc.
    cms_render_template('layout', get_defined_vars());
} else {
    // Fallback in case the template engine is not available for any reason
    die(t(
        'template_engine_not_loaded',
        'Architecture Error: Template engine not loaded.'
    ));
}
ob_end_flush();
?>
