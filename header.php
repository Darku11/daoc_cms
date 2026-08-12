<?php
// SPDX-License-Identifier: GPL-3.0-only
$is_maintenance = $is_maintenance ?? false;
require_once('includes/db.php');

$server_ip     = $GLOBALS['cms_settings']['game_server_ip'] ?? '127.0.0.1';
$server_port   = (int)($GLOBALS['cms_settings']['game_server_port'] ?? 10300);
$server_online = false;
$fp = @fsockopen($server_ip, $server_port, $errno, $errstr, 1);
if ($fp) { $server_online = true; fclose($fp); }

if (isset($_SESSION['user_id'])) {
    $uid    = (int)$_SESSION['user_id'];
    $stmt_u = $db->prepare("SELECT username, standing, priv_level, is_verified FROM users WHERE id = ?");
    $stmt_u->execute([$uid]);
    $u_data = $stmt_u->fetch();
    if ($u_data) {
        $_SESSION['username']    = $u_data['username'];
        $_SESSION['standing']    = (int)$u_data['standing'];
        $_SESSION['priv_level']  = ($_SESSION['standing'] >= 5) ? 0 : (int)$u_data['priv_level'];
        $_SESSION['is_verified'] = (int)$u_data['is_verified'];
    }
}

$header_priv = (int)($_SESSION['priv_level'] ?? 0);
$page = $_GET['p'] ?? 'home';
if (!isset($data) && $page) {
    if ($header_priv >= 4) {
        $stmt_p = $db->prepare("SELECT title, content, meta_title, meta_description FROM pages WHERE slug = ?");
        $stmt_p->execute([$page]);
    } else {
        $stmt_p = $db->prepare("
            SELECT title, content, meta_title, meta_description
            FROM pages
            WHERE slug = ?
              AND status = 'published'
              AND (published_at IS NULL OR published_at <= NOW())
              AND min_priv <= ?
        ");
        $stmt_p->execute([$page, $header_priv]);
    }
    $data = $stmt_p->fetch();
}

$meta_title = !empty($data['meta_title'])
    ? h($data['meta_title'])
    : h($data['title'] ?? "DAoC CMS - Chronicles of Atlantis");

if (!empty($data['meta_description'])) {
    $meta_desc = h($data['meta_description']);
} else {
    $raw_content = $data['content'] ?? "Explore the realms of Atlantis. Join the DAoC CMS today.";
    $meta_desc   = h(mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($raw_content))), 0, 160)) . "...";
}

$current_url  = (defined('SITE_URL') ? SITE_URL : '') . "/index.php?p=" . urlencode($page);
$logo_url     = (defined('SITE_URL') ? SITE_URL : '') . "/assets/img/logo.png";
$og_image     = $logo_url;
$is_forum_page = in_array($page, ['spike','viewboard','viewthread','newthread']);

if ($page === 'viewthread') {
    $og_thread_id   = 0;
    $og_thread_slug = '';

    if (!empty($_GET['slug'])) {
        $og_thread_slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['slug']));
        try {
            $s = $db->prepare("
                SELECT t.id, t.title, p.content
                FROM spike_threads t
                JOIN spike_boards b ON b.id = t.board_id
                JOIN spike_categories c ON c.id = b.cat_id
                JOIN spike_posts p ON p.thread_id = t.id
                WHERE t.slug = ?
                  AND t.is_approved = 1
                  AND ? >= (CASE WHEN b.min_priv > 0 THEN b.min_priv ELSE c.min_priv END)
                ORDER BY p.created_at ASC
                LIMIT 1
            ");
            $s->execute([$og_thread_slug, $header_priv]);
            $og_t = $s->fetch();
            if ($og_t) {
                $meta_title  = h($og_t['title']) . ' — DAoC CMS Forum';
                $meta_desc   = mb_substr(trim(preg_replace('/\s+/',' ',strip_tags($og_t['content']))),0,160) . '…';
                $current_url = (defined('SITE_URL')?SITE_URL:'') . '/index.php?p=viewthread&slug=' . urlencode($og_thread_slug);
            }
        } catch (\Throwable $e) {}
    } elseif (!empty($_GET['id'])) {
        try {
            $s = $db->prepare("
                SELECT t.id, t.title, t.slug, p.content
                FROM spike_threads t
                JOIN spike_boards b ON b.id = t.board_id
                JOIN spike_categories c ON c.id = b.cat_id
                JOIN spike_posts p ON p.thread_id = t.id
                WHERE t.id = ?
                  AND t.is_approved = 1
                  AND ? >= (CASE WHEN b.min_priv > 0 THEN b.min_priv ELSE c.min_priv END)
                ORDER BY p.created_at ASC
                LIMIT 1
            ");
            $s->execute([(int)$_GET['id'], $header_priv]);
            $og_t = $s->fetch();
            if ($og_t) {
                $meta_title  = h($og_t['title']) . ' — DAoC CMS Forum';
                $meta_desc   = mb_substr(trim(preg_replace('/\s+/',' ',strip_tags($og_t['content']))),0,160) . '…';
                $current_url = (defined('SITE_URL')?SITE_URL:'') . '/index.php?p=viewthread&slug=' . urlencode($og_t['slug'] ?? 'thread-'.(int)$_GET['id']);
            }
        } catch (\Throwable $e) {}
    }
}

if ($page === 'viewboard' && !empty($_GET['id'])) {
    try {
        $s = $db->prepare("
            SELECT b.title
            FROM spike_boards b
            JOIN spike_categories c ON c.id = b.cat_id
            WHERE b.id = ?
              AND ? >= (CASE WHEN b.min_priv > 0 THEN b.min_priv ELSE c.min_priv END)
            LIMIT 1
        ");
        $s->execute([(int)$_GET['id'], $header_priv]);
        $og_b = $s->fetch();
        if ($og_b) {
            $meta_title  = h($og_b['title']) . ' — DAoC CMS Forum';
            $meta_desc   = t('og.board_desc',[],'Browse threads in') . ' ' . h($og_b['title']);
            $current_url = (defined('SITE_URL')?SITE_URL:'') . '/index.php?p=viewboard&id=' . (int)$_GET['id'];
        }
    } catch (\Throwable $e) {}
}

$pm_unread = 0;
if (isset($_SESSION['user_id'])) {
    try {
        $stmt_pm = $db->prepare("SELECT COUNT(*) FROM pm_messages WHERE receiver_id = ? AND is_read = 0 AND deleted_by_receiver = 0");
        $stmt_pm->execute([(int)$_SESSION['user_id']]);
        $pm_unread = (int)$stmt_pm->fetchColumn();
    } catch (Exception $e) {}
}

$open_reports_count = 0;
if ($header_priv >= 2 && $header_priv <= 3) {
    try {
        $stmt_rep = $db->prepare("SELECT COUNT(*) FROM spike_reports WHERE status IN ('open', 'reviewing')");
        $stmt_rep->execute();
        $open_reports_count = (int)$stmt_rep->fetchColumn();
    } catch (Exception $e) {}
}

$active_theme = 'default';
try {
    $r = $db->prepare("SELECT value FROM settings WHERE setting_key='active_theme' LIMIT 1");
    $r->execute();
    $active_theme = $r->fetchColumn() ?: 'default';
} catch (PDOException $e) {}

$site_name = 'DAoC CMS';
try {
    $r = $db->prepare("SELECT value FROM settings WHERE setting_key='site_name' LIMIT 1");
    $r->execute();
    $site_name = h($r->fetchColumn() ?: 'DAoC CMS');
} catch (PDOException $e) {}

$_header_nav_enabled = true;
$_h_settings = [];
try {
    $_h_settings = $db->query("SELECT setting_key, value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {}

$_h_mod_on = function(string $key) use ($_h_settings): bool {
    return ($_h_settings[$key] ?? '1') !== '0';
};

$_header_nav_items = [];
if ($_header_nav_enabled) {
    try {
        if ($header_priv >= 4) {
            $stmt_hn = $db->prepare("SELECT title,slug,content,menu_category FROM pages WHERE menu_category != 'none' AND min_priv <= ? ORDER BY menu_pos ASC, title ASC");
            $stmt_hn->execute([$header_priv]);
        } else {
            $stmt_hn = $db->prepare("
                SELECT title,slug,content,menu_category
                FROM pages
                WHERE menu_category != 'none'
                  AND min_priv <= ?
                  AND status = 'published'
                  AND (published_at IS NULL OR published_at <= NOW())
                ORDER BY menu_pos ASC, title ASC
            ");
            $stmt_hn->execute([$header_priv]);
        }
        $_header_nav_items = $stmt_hn->fetchAll();
    } catch (PDOException $e) {}
}

$_h_module_map = [
    'spike'        => 'mod_forum',
    'viewboard'    => 'mod_forum',
    'viewthread'   => 'mod_forum',
    'newthread'    => 'mod_forum',
    'herald'           => 'herald',
    'herald_char'      => 'herald',
    'herald_guild'     => 'herald',
    'rvr_map'      => 'mod_rvr_map',
    'warmap'       => 'mod_rvr_map',
    'faq'          => 'mod_faq',
    'team'         => 'mod_team',
    'register'     => 'mod_register',
    'pve'          => 'mod_pve',
    'pve_bestiary' => 'mod_pve',
    'pve_dungeons' => 'mod_pve',
    'pve_quests'   => 'mod_pve',
];

$_css_module_map = [
    'spike'            => 'spike',
    'viewboard'        => 'viewboard',
    'viewthread'       => 'viewthread',
    'newthread'        => 'newthread',
    'editpost'         => 'editpost',
    'herald'           => 'herald',
    'herald_char'      => 'herald',
    'herald_guild'     => 'herald',
    'login'            => 'login',
    'register'         => 'register',
    'profile'          => 'profile',
    'team'             => 'team',
    'rvr_map'          => 'rvr_map',
    'pve'              => 'pve',
    'pve_view'         => 'pve_view',
    'pve_bestiary'     => 'pve_bestiary',
    'pve_boss'         => 'pve_boss',
    'pve_quests'       => 'pve_quests',
    'pve_quest_detail' => 'pve_quest_detail',
    'pve_item'         => 'pve_item',
    'pve_items'        => 'pve_items',
    'pve_dungeons'     => 'pve_dungeons',
    'user'             => 'user',
    'user_edit'        => 'user_edit',
    'verify'           => 'verify',
    'search'           => 'search',
    'notifications'    => 'notifications',
    'private_messages' => 'private_messages',
    'spike_search'     => 'spike',
];
$css_module = $_css_module_map[$page] ?? 'main';
$theme_qs   = ($active_theme !== 'default') ? '&theme=' . urlencode($active_theme) : '';

$_req_email_verify = ($_h_settings['email_verification_required'] ?? '0') === '1';
$_req_admin_approval = ($_h_settings['admin_approval_required'] ?? '0') === '1';

if (($_h_settings['has_critical_error'] ?? '0') === '0' && isset($_COOKIE['dismiss_crit'])) {
    setcookie('dismiss_crit', '', time() - 3600, '/');
    unset($_COOKIE['dismiss_crit']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $site_name ?> — <?= $meta_title ?></title>
    <meta name="description"        content="<?= $meta_desc ?>">

    <meta property="og:type"        content="<?= $page==='viewthread'?'article':'website' ?>">
    <meta property="og:url"         content="<?= $current_url ?>">
    <meta property="og:title"       content="<?= $site_name ?> — <?= $meta_title ?>">
    <meta property="og:description" content="<?= $meta_desc ?>">
    <meta property="og:image"       content="<?= $og_image ?>">
    <meta property="og:site_name"   content="<?= $site_name ?>">

    <meta name="twitter:card"        content="summary">
    <meta name="twitter:title"       content="<?= $site_name ?> — <?= $meta_title ?>">
    <meta name="twitter:description" content="<?= $meta_desc ?>">
    <meta name="twitter:image"       content="<?= $og_image ?>">

    <?php if ($is_forum_page): ?>
    <link rel="alternate" type="application/rss+xml"
          title="<?= $site_name ?> Forum Feed"
          href="<?= (defined('SITE_URL')?SITE_URL:'') ?>/rss.php">
    <?php endif; ?>

    <?php if (class_exists('AldhranAssets')) echo AldhranAssets::render('frontend'); ?>
    <link rel="stylesheet" href="style.php?module=main<?= $theme_qs ?>">
    <?php if ($css_module !== 'main'): ?>
    <link rel="stylesheet" href="style.php?module=<?= $css_module ?><?= $theme_qs ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="style.php?module=header_responsive<?= $theme_qs ?>">
    <link rel="stylesheet" href="style.php?module=custom_css_mobile<?= $theme_qs ?>">

    <?php if (function_exists('cms_run_hook')) echo cms_run_hook('hook_head', 'raw'); ?>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;700;900&family=Cormorant+Garamond:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body<?php
    $_no_hero_slugs = ['login', 'register', 'acp'];
    if (in_array($page, $_no_hero_slugs, true)) echo ' class="cms-no-hero"';
?>>

<canvas id="starfield"></canvas>
<div class="page-wrapper">
<div id="cms-live-event-banner" style="position: fixed; top: -100px; left: 50%; transform: translateX(-50%); z-index: 10000; background: rgba(10,10,10,0.95); border: 1px solid rgba(197,160,89,0.5); border-top: none; color: #ccc; padding: 12px 30px; border-radius: 0 0 8px 8px; font-family: 'Cinzel', serif; font-size: 0.85em; letter-spacing: 1px; transition: top 0.5s ease-in-out; text-align: center; min-width: 350px; box-shadow: 0 4px 15px rgba(0,0,0,0.8); pointer-events: none;">
    <span id="cms-live-event-text"></span>
</div>
<?php if (!empty($is_maintenance) && $header_priv >= 5): ?>
<div class="maintenance-banner">
    <i class="fas fa-tools"></i>
    <?= t('header.maintenance_banner',[],'Maintenance Mode is active – the site is hidden from regular users.') ?>
    <a href="acp.php?s=dashboard" class="maintenance-banner-link">
        <?= t('header.maintenance_banner_manage',[],'Manage') ?>
    </a>
</div>
<?php endif; ?>

<?php if ($header_priv >= 4 && ($_h_settings['has_critical_error'] ?? '0') === '1' && empty($_COOKIE['dismiss_crit'])): ?>
<div id="critical-error-banner" style="position: relative; z-index: 9999; background: rgba(224,112,112,0.2); border-bottom: 1px solid rgba(224,112,112,0.5); color: #e07070; padding: 10px; text-align: center; font-size: 0.85em; letter-spacing: 1px;">
    <i class="fas fa-exclamation-triangle"></i>
    <?= t('header.critical_log_notice', [], 'A critical system error has been logged in the Audit Trail.') ?>
    <a href="acp.php?s=admin_log" style="color:#e07070; margin-left:10px; font-weight:bold; text-decoration:underline;"><?= t('header.review_log', [], 'Review Log &rarr;') ?></a>
    <i class="fas fa-times" style="position: absolute; right: 15px; top: 12px; cursor: pointer; color: #e07070;" onclick="document.getElementById('critical-error-banner').style.display='none'; document.cookie='dismiss_crit=1; path=/; max-age=2592000';"></i>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['user_id']) && ($_SESSION['is_verified'] ?? 1) === 0): ?>
    <?php if ($_req_email_verify): ?>
        <div style="background: rgba(197,160,89,0.15); border-bottom: 1px solid rgba(197,160,89,0.3); color: #c5a059; padding: 10px; text-align: center; font-size: 0.85em; letter-spacing: 1px;">
            <i class="fas fa-envelope-open-text"></i>
            <?= t('header.verify_notice', [], 'Please check your inbox and verify your email address to unlock all features.') ?>
        </div>
    <?php elseif ($_req_admin_approval): ?>
        <div style="background: rgba(52,152,219,0.15); border-bottom: 1px solid rgba(52,152,219,0.3); color: #3498db; padding: 10px; text-align: center; font-size: 0.85em; letter-spacing: 1px;">
            <i class="fas fa-user-clock"></i>
            <?= t('header.admin_approval_notice', [], 'Your account has been registered and is currently pending activation by an administrator.') ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<header>
    <div class="header-content">
        <div class="header-left" style="display:flex;align-items:center;flex:1;min-width:0;">
            <div class="header-logo-container">
                <a href="index.php?p=home">
                    <img src="assets/img/logo.png" alt="Logo" class="header-logo-img">
                </a>
                <div class="header-divider hide-mobile"></div>
                <span class="server-label hide-mobile">SERVER:
                    <?php if ($server_online): ?>
                        <span class="status-online" style="color:#00ff00;font-weight:bold;">ONLINE</span>
                    <?php else: ?>
                        <span class="status-offline" style="color:#ff4444;font-weight:bold;">OFFLINE</span>
                    <?php endif; ?>
                </span>
                <?php $discord_link = $_h_settings['discord_link'] ?? ''; ?>
                <?php if (!empty($discord_link)): ?>
                <div class="header-divider hide-mobile"></div>
                <a href="<?= h($discord_link) ?>" target="_blank" class="discord-link hide-mobile"
                   style="color:#7289da;text-decoration:none;font-size:1.2em;display:flex;align-items:center;gap:5px;">
                    <i class="fab fa-discord"></i>
                    <span style="font-size:0.7em;letter-spacing:1px;color:#fff;font-family:'Cinzel',serif;">DISCORD</span>
                </a>
                <?php endif; ?>
            </div>

            <?php if ($_header_nav_enabled): ?>
            <nav class="header-nav hide-mobile" id="headerNav">

                <?php if (!isset($_SESSION['user_id']) && $_h_mod_on('mod_register')): ?>
                    <div class="nav-sep"></div>
                    <a href="?p=register" class="<?= ($page==='register')?'active':'' ?>"><?= t('sidebar.nav_register',[],'Register') ?></a>
                <?php endif; ?>
                <?php if ($_h_mod_on('mod_forum')): ?>
                    <div class="nav-sep"></div>
                    <a href="?p=spike" class="<?= ($page==='spike')?'active':'' ?>"><?= t('sidebar.nav_forum',[],'Forum') ?></a>
                <?php endif; ?>
                <?php if ($_h_mod_on('mod_team')): ?>
                    <div class="nav-sep"></div>
                    <a href="?p=team" class="<?= ($page==='team')?'active':'' ?>">Team</a>
                <?php endif; ?>
                <?php if ($_h_mod_on('mod_faq')): ?>
                    <div class="nav-sep"></div>
                    <a href="?p=faq" class="<?= ($page==='faq')?'active':'' ?>"><?= t('sidebar.nav_faq',[],'FAQ') ?></a>
                <?php endif; ?>
                <?php if ($_h_mod_on('mod_pve')): ?>
                    <div class="nav-sep"></div>
                    <a href="?p=pve" class="<?= ($page==='pve')?'active':'' ?>"><?= t('sidebar.nav_pve',[],'PvE') ?></a>
                <?php endif; ?>
                <?php if ($_h_mod_on('mod_herald')): ?>
                    <div class="nav-sep"></div>
                    <a href="?p=herald" class="<?= ($page==='herald')?'active':'' ?>"><?= t('sidebar.nav_herald',[],'Herald') ?></a>
                <?php endif; ?>
                <?php if ($_h_mod_on('mod_rvr_map')): ?>
                    <div class="nav-sep"></div>
                    <a href="?p=rvr_map" class="<?= ($page==='rvr_map')?'active':'' ?>"><?= t('sidebar.nav_rvrmap',[],'RvR Map') ?></a>
                <?php endif; ?>
                <?php foreach ($_header_nav_items as $_ni):
                    $content = $_ni['content'] ?? '';
                    if (strpos($content, '[EXT]:') === 0) {
                        $url = h(substr($content, 6)); $tgt = 'target="_blank" rel="noopener"';
                    } elseif (strpos($content, '[MODULE]:') === 0) {
                        $mod_slug = trim(substr($content, 9));
                        $mod_key  = $_h_module_map[$mod_slug] ?? null;
                        if ($mod_key !== null && !$_h_mod_on($mod_key)) continue;
                        $url = "index.php?p=" . h($mod_slug); $tgt = '';
                    } else {
                        $url = "index.php?p=" . h($_ni['slug']); $tgt = '';
                    }
                ?>
                    <div class="nav-sep"></div>
                    <a href="<?= $url ?>" <?= $tgt ?> class="<?= ($page===$_ni['slug'])?'active':'' ?>"><?= h($_ni['title']) ?></a>
                <?php endforeach; ?>
                <div class="header-nav-more-wrap" id="headerNavMore">
                    <button type="button" class="header-nav-more-btn" id="headerNavMoreBtn" aria-haspopup="true" aria-expanded="false">
                        <span id="headerNavMoreLabel"><?= t('header.nav_more',[],'More') ?></span>
                        <i class="fas fa-chevron-down" style="font-size:0.8em;"></i>
                    </button>
                    <div class="header-nav-more-menu" id="headerNavMoreMenu"></div>
                </div>
            </nav>
            <?php endif; ?>
        </div>

        <div class="user-status" style="display:flex;align-items:center;gap:12px;">
            <form action="index.php" method="GET" class="header-search-form hide-mobile" style="position:relative; display:flex; align-items:center;">
                <input type="hidden" name="p" value="search">
                <input type="text" name="q" placeholder="<?= t('header.search_placeholder', [], 'Search...') ?>" required
                       style="background:rgba(0,0,0,0.5); border:1px solid rgba(197,160,89,0.3); color:#ccc; padding:6px 12px 6px 30px; border-radius:20px; font-family:sans-serif; font-size:11px; outline:none; width:140px; transition:all 0.3s;"
                       onfocus="this.style.width='200px'; this.style.background='rgba(0,0,0,0.8)'; this.style.borderColor='rgba(197,160,89,0.8)';"
                       onblur="if(this.value==='') { this.style.width='140px'; this.style.background='rgba(0,0,0,0.5)'; this.style.borderColor='rgba(197,160,89,0.3)'; }">
                <i class="fas fa-search" style="position:absolute; left:12px; color:rgba(197,160,89,0.6); font-size:11px; pointer-events:none;"></i>
            </form>

            <?php if (isset($_SESSION['user_id'])): ?>
            <div class="user-dropdown-wrap" id="userDropdownWrap">
                <div class="user-dropdown-trigger" id="userDropdownTrigger">
                    <i class="fas fa-user-circle" style="font-size:0.9em;"></i>
                    <strong><?= h($_SESSION['username']??'User') ?></strong>
                    <i class="fas fa-chevron-down" style="font-size:0.6em;opacity:0.5;margin-left:3px;"></i>
                </div>
                <div class="user-dropdown-menu" id="userDropdownMenu">
                    <a href="?p=profile" class="user-dropdown-item">
                        <i class="fas fa-id-card"></i> <?= t('sidebar.nav_profile',[],'Profile') ?>
                    </a>
                    <a href="?p=private_messages" class="user-dropdown-item">
                        <i class="fas fa-envelope"></i> <?= t('sidebar.nav_messages', [], 'Messages') ?>
                        <?php if ($pm_unread > 0): ?>
                            <span style="background:#c5a059;color:#000;font-size:9px;font-weight:bold;padding:1px 6px;border-radius:8px;margin-left:6px;font-family:sans-serif;">
                                <?= $pm_unread ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    <?php if ($header_priv >= 2 && $header_priv <= 3 && $open_reports_count > 0): ?>
                    <div class="user-dropdown-divider"></div>
                    <a href="#" class="user-dropdown-item" onclick="reportsPopupOpen(); return false;">
                        <i class="fas fa-flag"></i> <?= t('sidebar.nav_reported_posts', [], 'Reported Posts') ?>
                        <span id="reportsBadgeCount" style="background:#e07070;color:#000;font-size:9px;font-weight:bold;padding:1px 6px;border-radius:8px;margin-left:6px;font-family:sans-serif;">
                            <?= $open_reports_count ?>
                        </span>
                    </a>
                    <?php endif; ?>
                    <?php if ($header_priv>=3): ?>
                    <div class="user-dropdown-divider"></div>
                    <a href="acp.php" class="user-dropdown-item user-dropdown-item--admin">
                        <i class="fas fa-user-shield"></i> <?= t('sidebar.acp_title',[],'Control Panel') ?>
                    </a>
                    <?php endif; ?>
                    <div class="user-dropdown-divider"></div>
                    <a href="logout.php" class="user-dropdown-item user-dropdown-item--logout">
                        <i class="fas fa-sign-out-alt"></i> <?= t('sidebar.nav_logout',[],'Logout') ?>
                    </a>
                </div>
            </div>
            <?php else: ?>
                <a href="?p=login" class="header-auth-link"><?= t('sidebar.nav_login', [], 'Login') ?></a>
            <?php endif; ?>
        </div>
    </div>
</header>

<style>
@media (max-width: 768px) {
    #headerNav, .header-logo-container .hide-mobile, .header-search-form {
        display: none !important;
    }
}
</style>

<?php if (function_exists('cms_run_hook') && isset($_SESSION['user_id'])) echo cms_run_hook('hook_after_header','script'); ?>

<script>
(function() {
    const canvas = document.getElementById('starfield');
    const ctx    = canvas.getContext('2d');
    let stars    = [];
    const COUNT  = 220, SPEED = 0.025;
    function resize() { canvas.width=window.innerWidth; canvas.height=window.innerHeight; }
    function initStars() {
        stars=[];
        for(let i=0;i<COUNT;i++) stars.push({x:Math.random()*canvas.width,y:Math.random()*canvas.height,r:Math.random()*1.2+0.2,alpha:Math.random(),delta:(Math.random()*0.003+0.001)*(Math.random()>0.5?1:-1),speed:SPEED*(Math.random()*0.5+0.3),gold:Math.random()>0.85});
    }
    function draw() {
        const g=ctx.createRadialGradient(canvas.width/2,canvas.height/2,0,canvas.width/2,canvas.height/2,canvas.width*0.9);
        g.addColorStop(0,'#090606');g.addColorStop(1,'#010101');
        ctx.fillStyle=g;ctx.fillRect(0,0,canvas.width,canvas.height);
        stars.forEach(s=>{
            s.alpha+=s.delta;if(s.alpha<=0.05||s.alpha>=1)s.delta*=-1;
            s.y-=s.speed;if(s.y<0){s.y=canvas.height;s.x=Math.random()*canvas.width;}
            ctx.beginPath();ctx.arc(s.x,s.y,s.r,0,Math.PI*2);
            ctx.fillStyle=s.gold?`rgba(197,160,89,${s.alpha*0.7})`:`rgba(210,215,255,${s.alpha*0.65})`;
            ctx.fill();
        });
        requestAnimationFrame(draw);
    }
    resize();initStars();draw();
    window.addEventListener('resize',()=>{resize();initStars();});
})();

(function(){
    const wrap=document.getElementById('userDropdownWrap');
    const trigger=document.getElementById('userDropdownTrigger');
    if(!wrap||!trigger)return;
    trigger.addEventListener('click',function(e){e.stopPropagation();wrap.classList.toggle('open');});
    document.addEventListener('click',function(e){if(!wrap.contains(e.target))wrap.classList.remove('open');});
})();

(function () {
    const hdr = document.querySelector('header');
    if (!hdr) return;
    const THRESHOLD = 80;
    function toggleScrolled() {
        if (window.scrollY > THRESHOLD) hdr.classList.add('cms-scrolled');
        else hdr.classList.remove('cms-scrolled');
    }
    window.addEventListener('scroll', toggleScrolled, { passive: true });
    toggleScrolled();
})();

(function () {
    const nav      = document.getElementById('headerNav');
    const moreWrap = document.getElementById('headerNavMore');
    if (!nav || !moreWrap) return;

    const moreBtn   = document.getElementById('headerNavMoreBtn');
    const moreLabel = document.getElementById('headerNavMoreLabel');
    const moreMenu  = document.getElementById('headerNavMoreMenu');
    const moreText  = moreLabel.textContent.trim();

    function getItems() {
        return Array.from(nav.children).filter(el => el !== moreWrap);
    }

    function layout() {
        const items = getItems();
        items.forEach(el => { el.style.display = ''; });
        moreWrap.style.display = 'none';
        moreMenu.innerHTML = '';

        moreWrap.style.flexShrink = '0';

        const totalLinks = items.filter(el => el.tagName === 'A').length;
        moreLabel.textContent = moreText + ' (' + totalLinks + ')';

        const available = nav.clientWidth;
        const reserve = moreBtn.offsetWidth + 20;
        let used = 0;
        let overflowAt = -1;

        for (let i = 0; i < items.length; i++) {
            used += items[i].offsetWidth;
            if (used > available - reserve) { overflowAt = i; break; }
        }

        if (overflowAt === -1) return;

        let start = overflowAt;
        if (items[start] && items[start].classList.contains('nav-sep')) start++;
        if (start >= items.length) return;

        const applyOverflow = (startIndex) => {
            moreMenu.innerHTML = '';
            const overflowing = items.slice(startIndex).filter(el => el.tagName === 'A');

            overflowing.forEach(link => {
                const clone = link.cloneNode(true);
                clone.style.display = '';
                moreMenu.appendChild(clone);
            });

            for (let i = startIndex; i < items.length; i++) {
                items[i].style.display = 'none';
            }

            moreLabel.textContent = moreText + ' (' + overflowing.length + ')';
            moreWrap.style.display = 'flex';
        };

        applyOverflow(start);

        while (moreWrap.getBoundingClientRect().right > nav.getBoundingClientRect().right && start > 0) {
            start--;
            if (items[start] && items[start].classList.contains('nav-sep')) start--;

            if (start >= 0) {
                applyOverflow(start);
            } else {
                break;
            }
        }
    }

    moreBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        const willOpen = !moreWrap.classList.contains('open');
        if (willOpen) {
            positionMenu();
            moreWrap.classList.add('open');
            moreBtn.setAttribute('aria-expanded', 'true');
        } else {
            moreWrap.classList.remove('open');
            moreBtn.setAttribute('aria-expanded', 'false');
        }
    });

    document.addEventListener('click', function (e) {
        if (!moreWrap.contains(e.target) && !moreMenu.contains(e.target)) {
            moreWrap.classList.remove('open');
            moreBtn.setAttribute('aria-expanded', 'false');
        }
    });

    function positionMenu() {
        const rect = moreBtn.getBoundingClientRect();
        moreMenu.style.top   = (rect.bottom + 8) + 'px';
        moreMenu.style.right = (window.innerWidth - rect.right) + 'px';
        moreMenu.style.left  = 'auto';
    }

    function closeMenu() {
        moreWrap.classList.remove('open');
        moreBtn.setAttribute('aria-expanded', 'false');
    }

    let resizeTimer;
    window.addEventListener('resize', function () {
        closeMenu();
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(layout, 100);
    });
    window.addEventListener('scroll', closeMenu, { passive: true });

    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(layout);
    }
    window.addEventListener('load', layout);
    layout();
})();
(function() {
    let lastEventId = 0;
    const banner = document.getElementById('cms-live-event-banner');
    const textEl = document.getElementById('cms-live-event-text');
    let eventQueue = [];
    let isDisplaying = false;

    function pollEvents() {
        fetch('api_events.php?last_id=' + lastEventId)
            .then(r => r.json())
            .then(data => {
                if (data.ok && data.events && data.events.length > 0) {
                    const evts = data.events.reverse();
                    evts.forEach(e => {
                        if (e.id > lastEventId) lastEventId = e.id;
                        eventQueue.push(e);
                    });
                    showNextEvent();
                }
            }).catch(() => {});
    }

    function showNextEvent() {
        if (isDisplaying || eventQueue.length === 0) return;
        isDisplaying = true;

        const ev = eventQueue.shift();
        let icon = '<i class="fas fa-bell" style="color:#c5a059; margin-right:8px;"></i>';

        if (ev.type === 'kill') icon = '<i class="fas fa-skull" style="color:#e07070; margin-right:8px;"></i>';
        if (ev.type === 'keep') icon = '<i class="fas fa-chess-rook" style="color:#c5a059; margin-right:8px;"></i>';
        if (ev.type === 'relic') icon = '<i class="fas fa-gem" style="color:#3498db; margin-right:8px;"></i>';

        textEl.innerHTML = icon + ev.message;
        banner.style.top = '0px';

        setTimeout(() => {
            banner.style.top = '-100px';
            setTimeout(() => {
                isDisplaying = false;
                showNextEvent();
            }, 600);
        }, 4500);
    }

    setInterval(pollEvents, 5000);
})();
</script>

<?php if ($header_priv >= 2 && $header_priv <= 3): ?>
<div id="reportsPopupBackdrop" class="reports-popup-backdrop" onclick="if(event.target===this)reportsPopupClose()">
    <div class="reports-popup-box">
        <div class="reports-popup-head">
            <span><i class="fas fa-flag"></i> <?= t('sidebar.nav_reported_posts', [], 'Reported Posts') ?></span>
            <button type="button" class="reports-popup-close" onclick="reportsPopupClose()">&#x2715;</button>
        </div>
        <div class="reports-popup-body" id="reportsPopupBody">
            <div class="reports-popup-empty"><?= t('reports_popup.loading', [], 'Loading…') ?></div>
        </div>
    </div>
</div>

<style>
.reports-popup-backdrop {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.7);
    z-index: 9998;
    align-items: center;
    justify-content: center;
}
.reports-popup-backdrop.show { display: flex; }
.reports-popup-box {
    background: #0a0a0a;
    border: 1px solid rgba(197,160,89,0.25);
    max-width: 640px;
    width: 92%;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
}
.reports-popup-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px;
    border-bottom: 1px solid rgba(197,160,89,0.15);
    font-family: 'Cinzel', serif;
    font-size: 0.85em;
    letter-spacing: 2px;
    color: #c5a059;
    text-transform: uppercase;
}
.reports-popup-close {
    background: none;
    border: none;
    color: #666;
    cursor: pointer;
    font-size: 14px;
}
.reports-popup-close:hover { color: #c5a059; }
.reports-popup-body {
    padding: 14px 18px;
    overflow-y: auto;
    font-family: sans-serif;
}
.reports-popup-empty { color: #555; font-size: 0.85em; text-align: center; padding: 20px 0; }
.reports-popup-item {
    border: 1px solid #1a1a1a;
    padding: 12px 14px;
    margin-bottom: 12px;
}
.reports-popup-item-meta {
    font-size: 0.75em;
    color: #666;
    margin-bottom: 6px;
}
.reports-popup-item-reason { color: #c5a059; font-size: 0.8em; margin-bottom: 8px; }
.reports-popup-item-content {
    background: rgba(255,255,255,0.02);
    border-left: 2px solid #222;
    padding: 8px 10px;
    color: #999;
    font-size: 0.8em;
    line-height: 1.5;
    margin-bottom: 10px;
    white-space: pre-wrap;
    word-break: break-word;
}
.reports-popup-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.reports-popup-btn {
    background: transparent;
    border: 1px solid #333;
    color: #888;
    padding: 5px 12px;
    font-size: 0.75em;
    font-family: 'Cinzel', serif;
    letter-spacing: 1px;
    text-transform: uppercase;
    cursor: pointer;
    transition: all 0.15s;
}
.reports-popup-btn:hover { border-color: #c5a059; color: #c5a059; }
.reports-popup-btn--resolve:hover { border-color: #50c878; color: #50c878; }
.reports-popup-btn--dismiss:hover { border-color: #666; color: #999; }
</style>

<script>
function reportsPopupOpen() {
    document.getElementById('reportsPopupBackdrop').classList.add('show');
    reportsPopupLoad();
}
function reportsPopupClose() {
    document.getElementById('reportsPopupBackdrop').classList.remove('show');
}

function reportsPopupLoad() {
    const body = document.getElementById('reportsPopupBody');
    body.innerHTML = '<div class="reports-popup-empty"><?= t('reports_popup.loading', [], 'Loading…') ?></div>';

    fetch('ajax_reports.php?action=list')
        .then(r => r.json())
        .then(d => {
            if (!d.ok) {
                body.innerHTML = '<div class="reports-popup-empty"><?= t('reports_popup.error', [], 'Could not load reports.') ?></div>';
                return;
            }
            if (d.reports.length === 0) {
                body.innerHTML = '<div class="reports-popup-empty"><?= t('reports_popup.empty', [], 'No open reports.') ?></div>';
                return;
            }
            body.innerHTML = d.reports.map(r => reportsPopupRenderItem(r)).join('');
        })
        .catch(() => {
            body.innerHTML = '<div class="reports-popup-empty"><?= t('reports_popup.error', [], 'Could not load reports.') ?></div>';
        });
}

function reportsPopupRenderItem(r) {
    const esc = s => (s ?? '').toString()
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    return `
        <div class="reports-popup-item" id="report-${r.id}">
            <div class="reports-popup-item-meta">
                <?= t('reports_popup.reported_by', [], 'Reported by') ?> <strong>${esc(r.reporter_name)}</strong>
                &middot; <?= t('reports_popup.post_by', [], 'Post by') ?> <strong>${esc(r.post_author || 'Unknown')}</strong>
                &middot; ${esc(r.thread_title)}
            </div>
            ${r.reason ? `<div class="reports-popup-item-reason"><?= t('reports_popup.reason', [], 'Reason') ?>: ${esc(r.reason)}${r.details ? ' — ' + esc(r.details) : ''}</div>` : ''}
            <div class="reports-popup-item-content">${esc(r.post_content)}</div>
            <div class="reports-popup-actions">
                <button type="button" class="reports-popup-btn" onclick="reportsPopupSetStatus(${r.id}, 'reviewing')">
                    <?= t('reports_popup.btn_review', [], 'Mark as Reviewing') ?>
                </button>
                <button type="button" class="reports-popup-btn reports-popup-btn--resolve" onclick="reportsPopupSetStatus(${r.id}, 'resolved')">
                    <?= t('reports_popup.btn_resolve', [], 'Resolve') ?>
                </button>
                <button type="button" class="reports-popup-btn reports-popup-btn--dismiss" onclick="reportsPopupSetStatus(${r.id}, 'dismissed')">
                    <?= t('reports_popup.btn_dismiss', [], 'Dismiss') ?>
                </button>
            </div>
        </div>`;
}

function reportsPopupSetStatus(reportId, newStatus) {
    const fd = new FormData();
    fd.append('action',       'set_status');
    fd.append('report_id',    reportId);
    fd.append('new_status',   newStatus);
    fd.append('csrf_token',   '<?= generateToken() ?>');

    fetch('ajax_reports.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) return;

            if (newStatus !== 'reviewing') {
                const el = document.getElementById('report-' + reportId);
                if (el) el.remove();
                if (!document.querySelector('.reports-popup-item')) {
                    document.getElementById('reportsPopupBody').innerHTML =
                        '<div class="reports-popup-empty"><?= t('reports_popup.empty', [], 'No open reports.') ?></div>';
                }
            }

            const badge = document.getElementById('reportsBadgeCount');
            if (badge) {
                badge.textContent = d.remaining;
                if (d.remaining === 0) {
                    const item = badge.closest('.user-dropdown-item');
                    if (item) item.remove();
                }
            }
        })
        .catch(() => {});
}
</script>
<?php endif; ?>
