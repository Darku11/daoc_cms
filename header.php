<?php
// SPDX-License-Identifier: GPL-3.0-only
$is_maintenance = $is_maintenance ?? (($GLOBALS['cms_settings']['maintenance_mode'] ?? '0') === '1');
require_once('includes/db.php');

$server_ip = (string)($GLOBALS['cms_settings']['game_server_ip'] ?? '127.0.0.1');
$server_port = (int)($GLOBALS['cms_settings']['game_server_port'] ?? 10300);
$server_online = false;
$status_cache = $_SESSION['cms_server_status'] ?? null;
if (is_array($status_cache)
    && ($status_cache['ip'] ?? '') === $server_ip
    && (int)($status_cache['port'] ?? 0) === $server_port
    && time() - (int)($status_cache['checked_at'] ?? 0) < 15) {
    $server_online = (bool)($status_cache['online'] ?? false);
} else {
    $fp = @fsockopen($server_ip, $server_port, $errno, $errstr, 1);
    if ($fp) {
        $server_online = true;
        fclose($fp);
    }
    $_SESSION['cms_server_status'] = [
        'ip' => $server_ip,
        'port' => $server_port,
        'online' => $server_online,
        'checked_at' => time(),
    ];
}

if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $stmt_u = $db->prepare("SELECT username, standing, priv_level, is_verified FROM users WHERE id = ?");
    $stmt_u->execute([$uid]);
    $u_data = $stmt_u->fetch();
    if ($u_data) {
        $_SESSION['username'] = $u_data['username'];
        $_SESSION['standing'] = (int)$u_data['standing'];
        $_SESSION['priv_level'] = ($_SESSION['standing'] >= 5) ? 0 : (int)$u_data['priv_level'];
        $_SESSION['is_verified'] = (int)$u_data['is_verified'];
    }
}

$header_priv = (int)($_SESSION['priv_level'] ?? 0);
$page = preg_replace('/[^a-z0-9_\-]/i', '', (string)($_GET['p'] ?? 'home'));
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
    : h($data['title'] ?? 'DAoC CMS - Chronicles of Atlantis');

if (!empty($data['meta_description'])) {
    $meta_desc = h($data['meta_description']);
} else {
    $raw_content = $data['content'] ?? 'Explore the realms of Atlantis. Join the DAoC CMS today.';
    $meta_desc = h(mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($raw_content))), 0, 160)) . '...';
}

$current_url = (defined('SITE_URL') ? SITE_URL : '') . '/index.php?p=' . urlencode($page);
$logo_url = (defined('SITE_URL') ? SITE_URL : '') . '/assets/img/logo.png';
$og_image = $logo_url;
$is_forum_page = in_array($page, ['spike','viewboard','viewthread','newthread'], true);

if ($page === 'viewthread') {
    if (!empty($_GET['slug'])) {
        $og_thread_slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string)$_GET['slug']));
        try {
            $s = $db->prepare("
                SELECT t.id, t.title, p.content
                FROM spike_threads t
                JOIN spike_boards b ON b.id = t.board_id
                JOIN spike_categories c ON c.id = b.cat_id
                JOIN spike_posts p ON p.thread_id = t.id
                WHERE t.slug = ? AND t.is_approved = 1
                  AND ? >= (CASE WHEN b.min_priv > 0 THEN b.min_priv ELSE c.min_priv END)
                ORDER BY p.created_at ASC LIMIT 1
            ");
            $s->execute([$og_thread_slug, $header_priv]);
            $og_t = $s->fetch();
            if ($og_t) {
                $meta_title = h($og_t['title']) . ' — DAoC CMS Forum';
                $meta_desc = h(mb_substr(trim(preg_replace('/\s+/',' ',strip_tags($og_t['content']))),0,160)) . '…';
                $current_url = (defined('SITE_URL') ? SITE_URL : '') . '/index.php?p=viewthread&slug=' . urlencode($og_thread_slug);
            }
        } catch (Throwable $e) {}
    } elseif (!empty($_GET['id'])) {
        try {
            $s = $db->prepare("
                SELECT t.id, t.title, t.slug, p.content
                FROM spike_threads t
                JOIN spike_boards b ON b.id = t.board_id
                JOIN spike_categories c ON c.id = b.cat_id
                JOIN spike_posts p ON p.thread_id = t.id
                WHERE t.id = ? AND t.is_approved = 1
                  AND ? >= (CASE WHEN b.min_priv > 0 THEN b.min_priv ELSE c.min_priv END)
                ORDER BY p.created_at ASC LIMIT 1
            ");
            $s->execute([(int)$_GET['id'], $header_priv]);
            $og_t = $s->fetch();
            if ($og_t) {
                $meta_title = h($og_t['title']) . ' — DAoC CMS Forum';
                $meta_desc = h(mb_substr(trim(preg_replace('/\s+/',' ',strip_tags($og_t['content']))),0,160)) . '…';
                $current_url = (defined('SITE_URL') ? SITE_URL : '') . '/index.php?p=viewthread&slug=' . urlencode($og_t['slug'] ?? 'thread-' . (int)$_GET['id']);
            }
        } catch (Throwable $e) {}
    }
}

if ($page === 'viewboard' && !empty($_GET['id'])) {
    try {
        $s = $db->prepare("
            SELECT b.title FROM spike_boards b
            JOIN spike_categories c ON c.id = b.cat_id
            WHERE b.id = ? AND ? >= (CASE WHEN b.min_priv > 0 THEN b.min_priv ELSE c.min_priv END)
            LIMIT 1
        ");
        $s->execute([(int)$_GET['id'], $header_priv]);
        $og_b = $s->fetch();
        if ($og_b) {
            $meta_title = h($og_b['title']) . ' — DAoC CMS Forum';
            $meta_desc = h(t('og.board_desc', [], 'Browse threads in') . ' ' . $og_b['title']);
            $current_url = (defined('SITE_URL') ? SITE_URL : '') . '/index.php?p=viewboard&id=' . (int)$_GET['id'];
        }
    } catch (Throwable $e) {}
}

$pm_unread = 0;
if (isset($_SESSION['user_id'])) {
    try {
        $stmt_pm = $db->prepare("SELECT COUNT(*) FROM pm_messages WHERE receiver_id = ? AND is_read = 0 AND deleted_by_receiver = 0");
        $stmt_pm->execute([(int)$_SESSION['user_id']]);
        $pm_unread = (int)$stmt_pm->fetchColumn();
    } catch (Throwable $e) {}
}

$open_reports_count = 0;
if ($header_priv >= 2 && $header_priv <= 3) {
    try {
        $open_reports_count = (int)$db->query("SELECT COUNT(*) FROM spike_reports WHERE status IN ('open', 'reviewing')")->fetchColumn();
    } catch (Throwable $e) {}
}

$_h_settings = [];
try {
    $_h_settings = $db->query("SELECT setting_key, value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {}

$active_theme = (string)($_h_settings['active_theme'] ?? 'default');
$site_name = h((string)($_h_settings['site_name'] ?? 'DAoC CMS'));
$_header_search_enabled = ($_h_settings['header_search_enabled'] ?? '1') !== '0';
$_html_lang = strtolower(trim((string)($_h_settings['default_language'] ?? $_h_settings['language'] ?? 'en')));
$_html_lang = preg_replace('/[^a-z0-9-]/', '', $_html_lang) ?: 'en';

$_h_mod_on = static function(string $key) use ($_h_settings): bool {
    return ($_h_settings[$key] ?? '1') !== '0';
};

$_header_nav_items = [];
try {
    if ($header_priv >= 4) {
        $stmt_hn = $db->prepare("SELECT title,slug,content,menu_category FROM pages WHERE menu_category != 'none' AND min_priv <= ? ORDER BY menu_pos ASC, title ASC");
        $stmt_hn->execute([$header_priv]);
    } else {
        $stmt_hn = $db->prepare("
            SELECT title,slug,content,menu_category FROM pages
            WHERE menu_category != 'none' AND min_priv <= ? AND status = 'published'
              AND (published_at IS NULL OR published_at <= NOW())
            ORDER BY menu_pos ASC, title ASC
        ");
        $stmt_hn->execute([$header_priv]);
    }
    $_header_nav_items = $stmt_hn->fetchAll();
} catch (PDOException $e) {}

$_h_module_map = [
    'spike'=>'mod_forum', 'viewboard'=>'mod_forum', 'viewthread'=>'mod_forum', 'newthread'=>'mod_forum', 'editpost'=>'mod_forum',
    'herald'=>'mod_herald', 'herald_char'=>'mod_herald', 'herald_guild'=>'mod_herald',
    'rvr_map'=>'mod_rvr_map', 'warmap'=>'mod_rvr_map', 'faq'=>'mod_faq', 'team'=>'mod_team', 'register'=>'mod_register',
    'pve'=>'mod_pve', 'pve_bestiary'=>'mod_pve', 'pve_boss'=>'mod_pve', 'pve_dungeons'=>'mod_pve',
    'pve_quests'=>'mod_pve', 'pve_quest_detail'=>'mod_pve', 'pve_item'=>'mod_pve', 'pve_items'=>'mod_pve',
];

$_css_module_map = [
    'spike'=>'spike', 'viewboard'=>'viewboard', 'viewthread'=>'viewthread', 'newthread'=>'newthread', 'editpost'=>'editpost',
    'herald'=>'herald', 'herald_char'=>'herald', 'herald_guild'=>'herald', 'login'=>'login', 'register'=>'register',
    'profile'=>'profile', 'team'=>'team', 'rvr_map'=>'rvr_map', 'pve'=>'pve', 'pve_view'=>'pve_view',
    'pve_bestiary'=>'pve_bestiary', 'pve_boss'=>'pve_boss', 'pve_quests'=>'pve_quests', 'pve_quest_detail'=>'pve_quest_detail',
    'pve_item'=>'pve_item', 'pve_items'=>'pve_items', 'pve_dungeons'=>'pve_dungeons', 'user'=>'user', 'user_edit'=>'user_edit',
    'verify'=>'verify', 'search'=>'search', 'notifications'=>'notifications', 'private_messages'=>'private_messages', 'spike_search'=>'spike',
];
$css_module = $_css_module_map[$page] ?? 'main';
$theme_qs = ($active_theme !== 'default') ? '&theme=' . urlencode($active_theme) : '';
$_req_email_verify = ($_h_settings['email_verification_required'] ?? '0') === '1';
$_req_admin_approval = ($_h_settings['admin_approval_required'] ?? '0') === '1';

if (($_h_settings['has_critical_error'] ?? '0') === '0' && isset($_COOKIE['dismiss_crit'])) {
    setcookie('dismiss_crit', '', time() - 3600, '/');
    unset($_COOKIE['dismiss_crit']);
}
?>
<!DOCTYPE html>
<html lang="<?= h($_html_lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $site_name ?> — <?= $meta_title ?></title>
    <meta name="description" content="<?= $meta_desc ?>">
    <meta property="og:type" content="<?= $page === 'viewthread' ? 'article' : 'website' ?>">
    <meta property="og:url" content="<?= h($current_url) ?>">
    <meta property="og:title" content="<?= $site_name ?> — <?= $meta_title ?>">
    <meta property="og:description" content="<?= $meta_desc ?>">
    <meta property="og:image" content="<?= h($og_image) ?>">
    <meta property="og:site_name" content="<?= $site_name ?>">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?= $site_name ?> — <?= $meta_title ?>">
    <meta name="twitter:description" content="<?= $meta_desc ?>">
    <meta name="twitter:image" content="<?= h($og_image) ?>">
    <?php if ($is_forum_page): ?><link rel="alternate" type="application/rss+xml" title="<?= $site_name ?> Forum Feed" href="<?= h((defined('SITE_URL') ? SITE_URL : '') . '/rss.php') ?>"><?php endif; ?>
    <?php if (class_exists('AldhranAssets')) echo AldhranAssets::render('frontend'); ?>
    <link rel="stylesheet" href="style.php?module=main<?= $theme_qs ?>">
    <?php if ($css_module !== 'main'): ?><link rel="stylesheet" href="style.php?module=<?= h($css_module) ?><?= $theme_qs ?>"><?php endif; ?>
    <link rel="stylesheet" href="style.php?module=header_responsive<?= $theme_qs ?>">
    <link rel="stylesheet" href="style.php?module=custom_css_mobile<?= $theme_qs ?>">
    <?php if (function_exists('cms_run_hook')) echo cms_run_hook('hook_head', 'raw'); ?>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;700;900&family=Cormorant+Garamond:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body<?php if (in_array($page, ['login','register','acp'], true)) echo ' class="cms-no-hero"'; ?>>
<canvas id="starfield"></canvas>
<div class="page-wrapper">

<?php if (!empty($is_maintenance) && $header_priv >= 5): ?>
<div class="maintenance-banner"><i class="fas fa-tools"></i> <?= t('header.maintenance_banner', [], 'Maintenance Mode is active – the site is hidden from regular users.') ?> <a href="acp.php?s=dashboard" class="maintenance-banner-link"><?= t('header.maintenance_banner_manage', [], 'Manage') ?></a></div>
<?php endif; ?>

<?php if ($header_priv >= 4 && ($_h_settings['has_critical_error'] ?? '0') === '1' && empty($_COOKIE['dismiss_crit'])): ?>
<div id="critical-error-banner" style="position:relative;z-index:9999;background:rgba(224,112,112,.2);border-bottom:1px solid rgba(224,112,112,.5);color:#e07070;padding:10px;text-align:center;font-size:.85em;letter-spacing:1px;">
    <i class="fas fa-exclamation-triangle"></i> <?= t('header.critical_log_notice', [], 'A critical system error has been logged in the Audit Trail.') ?>
    <a href="acp.php?s=admin_log" style="color:#e07070;margin-left:10px;font-weight:bold;text-decoration:underline;"><?= t('header.review_log', [], 'Review Log →') ?></a>
    <button type="button" aria-label="Close" style="position:absolute;right:12px;top:6px;background:none;border:0;color:#e07070;cursor:pointer;font-size:18px;" onclick="document.getElementById('critical-error-banner').remove();document.cookie='dismiss_crit=1; path=/; max-age=2592000';">×</button>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['user_id']) && ($_SESSION['is_verified'] ?? 1) === 0): ?>
    <?php if ($_req_email_verify): ?><div class="info-msg"><i class="fas fa-envelope-open-text"></i> <?= t('header.verify_notice', [], 'Please check your inbox and verify your email address to unlock all features.') ?></div>
    <?php elseif ($_req_admin_approval): ?><div class="info-msg"><i class="fas fa-user-clock"></i> <?= t('header.admin_approval_notice', [], 'Your account is currently pending activation by an administrator.') ?></div><?php endif; ?>
<?php endif; ?>

<header>
    <div class="header-content">
        <div class="header-left" style="display:flex;align-items:center;flex:1;min-width:0;">
            <div class="header-logo-container">
                <a href="index.php?p=home"><img src="assets/img/logo.png" alt="Logo" class="header-logo-img"></a>
                <div class="header-divider hide-mobile"></div>
                <span class="server-label hide-mobile">SERVER:
                    <?php if ($server_online): ?><span class="status-online" style="color:#00ff00;font-weight:bold;">ONLINE</span><?php else: ?><span class="status-offline" style="color:#ff4444;font-weight:bold;">OFFLINE</span><?php endif; ?>
                </span>
                <?php $discord_link = (string)($_h_settings['discord_link'] ?? ''); if ($discord_link !== ''): ?>
                    <div class="header-divider hide-mobile"></div>
                    <a href="<?= h($discord_link) ?>" target="_blank" rel="noopener noreferrer" class="discord-link hide-mobile" style="color:#7289da;text-decoration:none;font-size:1.2em;display:flex;align-items:center;gap:5px;"><i class="fab fa-discord"></i><span style="font-size:.7em;letter-spacing:1px;color:#fff;font-family:'Cinzel',serif;">DISCORD</span></a>
                <?php endif; ?>
            </div>

            <nav class="header-nav hide-mobile" id="headerNav">
                <?php if (!isset($_SESSION['user_id']) && $_h_mod_on('mod_register')): ?><div class="nav-sep"></div><a href="?p=register" class="<?= $page==='register'?'active':'' ?>"><?= t('sidebar.nav_register',[],'Register') ?></a><?php endif; ?>
                <?php if ($_h_mod_on('mod_forum')): ?><div class="nav-sep"></div><a href="?p=spike" class="<?= $page==='spike'?'active':'' ?>"><?= t('sidebar.nav_forum',[],'Forum') ?></a><?php endif; ?>
                <?php if ($_h_mod_on('mod_team')): ?><div class="nav-sep"></div><a href="?p=team" class="<?= $page==='team'?'active':'' ?>">Team</a><?php endif; ?>
                <?php if ($_h_mod_on('mod_faq')): ?><div class="nav-sep"></div><a href="?p=faq" class="<?= $page==='faq'?'active':'' ?>"><?= t('sidebar.nav_faq',[],'FAQ') ?></a><?php endif; ?>
                <?php if ($_h_mod_on('mod_pve')): ?><div class="nav-sep"></div><a href="?p=pve" class="<?= str_starts_with($page,'pve')?'active':'' ?>"><?= t('sidebar.nav_pve',[],'PvE') ?></a><?php endif; ?>
                <?php if ($_h_mod_on('mod_herald')): ?><div class="nav-sep"></div><a href="?p=herald" class="<?= str_starts_with($page,'herald')?'active':'' ?>"><?= t('sidebar.nav_herald',[],'Herald') ?></a><?php endif; ?>
                <?php if ($_h_mod_on('mod_rvr_map')): ?><div class="nav-sep"></div><a href="?p=rvr_map" class="<?= in_array($page,['rvr_map','warmap'],true)?'active':'' ?>"><?= t('sidebar.nav_rvrmap',[],'RvR Map') ?></a><?php endif; ?>
                <?php foreach ($_header_nav_items as $_ni):
                    $content = (string)($_ni['content'] ?? '');
                    if (strpos($content, '[EXT]:') === 0) {
                        $url = substr($content, 6); $external = true;
                    } elseif (strpos($content, '[MODULE]:') === 0) {
                        $mod_slug = trim(substr($content, 9));
                        $mod_key = $_h_module_map[$mod_slug] ?? null;
                        if ($mod_key !== null && !$_h_mod_on($mod_key)) continue;
                        $url = 'index.php?p=' . rawurlencode($mod_slug); $external = false;
                    } else {
                        $url = 'index.php?p=' . rawurlencode((string)$_ni['slug']); $external = false;
                    }
                ?>
                    <div class="nav-sep"></div><a href="<?= h($url) ?>" <?= $external ? 'target="_blank" rel="noopener noreferrer"' : '' ?> class="<?= $page === ($_ni['slug'] ?? '') ? 'active' : '' ?>"><?= h($_ni['title']) ?></a>
                <?php endforeach; ?>
                <div class="header-nav-more-wrap" id="headerNavMore"><button type="button" class="header-nav-more-btn" id="headerNavMoreBtn" aria-haspopup="true" aria-expanded="false"><span id="headerNavMoreLabel"><?= t('header.nav_more',[],'More') ?></span><i class="fas fa-chevron-down"></i></button><div class="header-nav-more-menu" id="headerNavMoreMenu"></div></div>
            </nav>
        </div>

        <div class="user-status" style="display:flex;align-items:center;gap:12px;">
            <?php if ($_header_search_enabled): ?>
            <form action="index.php" method="GET" class="header-search-form hide-mobile">
                <input type="hidden" name="p" value="search">
                <input type="text" name="q" class="header-search-input" placeholder="<?= h(t('header.search_placeholder', [], 'Search...')) ?>" required>
                <i class="fas fa-search header-search-icon"></i>
            </form>
            <?php endif; ?>

            <?php if (isset($_SESSION['user_id'])): ?>
            <div class="user-dropdown-wrap" id="userDropdownWrap">
                <div class="user-dropdown-trigger" id="userDropdownTrigger"><i class="fas fa-user-circle"></i><strong><?= h($_SESSION['username'] ?? 'User') ?></strong><i class="fas fa-chevron-down"></i></div>
                <div class="user-dropdown-menu" id="userDropdownMenu">
                    <a href="?p=profile" class="user-dropdown-item"><i class="fas fa-id-card"></i> <?= t('sidebar.nav_profile',[],'Profile') ?></a>
                    <a href="?p=private_messages" class="user-dropdown-item"><i class="fas fa-envelope"></i> <?= t('sidebar.nav_messages', [], 'Messages') ?><?php if ($pm_unread > 0): ?><span class="user-dropdown-count"><?= $pm_unread ?></span><?php endif; ?></a>
                    <?php if ($header_priv >= 2 && $header_priv <= 3 && $open_reports_count > 0): ?><div class="user-dropdown-divider"></div><a href="#" class="user-dropdown-item" onclick="reportsPopupOpen();return false;"><i class="fas fa-flag"></i> <?= t('sidebar.nav_reported_posts', [], 'Reported Posts') ?><span id="reportsBadgeCount" class="user-dropdown-count"><?= $open_reports_count ?></span></a><?php endif; ?>
                    <?php if ($header_priv >= 3): ?><div class="user-dropdown-divider"></div><a href="acp.php" class="user-dropdown-item user-dropdown-item--admin"><i class="fas fa-user-shield"></i> <?= t('sidebar.acp_title',[],'Control Panel') ?></a><?php endif; ?>
                    <div class="user-dropdown-divider"></div><a href="logout.php" class="user-dropdown-item user-dropdown-item--logout"><i class="fas fa-sign-out-alt"></i> <?= t('sidebar.nav_logout',[],'Logout') ?></a>
                </div>
            </div>
            <?php else: ?><a href="?p=login" class="header-auth-link"><?= t('sidebar.nav_login', [], 'Login') ?></a><?php endif; ?>
        </div>
    </div>
</header>

<?php if (function_exists('cms_run_hook') && isset($_SESSION['user_id'])) echo cms_run_hook('hook_after_header','script'); ?>

<script>
(function(){
    const canvas=document.getElementById('starfield');
    if(!canvas)return;
    const ctx=canvas.getContext('2d');
    if(!ctx)return;
    let stars=[];
    const COUNT=220,SPEED=.025;
    function resize(){canvas.width=window.innerWidth;canvas.height=window.innerHeight;}
    function initStars(){stars=[];for(let i=0;i<COUNT;i++)stars.push({x:Math.random()*canvas.width,y:Math.random()*canvas.height,r:Math.random()*1.2+.2,alpha:Math.random(),delta:(Math.random()*.003+.001)*(Math.random()>.5?1:-1),speed:SPEED*(Math.random()*.5+.3),gold:Math.random()>.85});}
    function draw(){const g=ctx.createRadialGradient(canvas.width/2,canvas.height/2,0,canvas.width/2,canvas.height/2,canvas.width*.9);g.addColorStop(0,'#090606');g.addColorStop(1,'#010101');ctx.fillStyle=g;ctx.fillRect(0,0,canvas.width,canvas.height);stars.forEach(s=>{s.alpha+=s.delta;if(s.alpha<=.05||s.alpha>=1)s.delta*=-1;s.y-=s.speed;if(s.y<0){s.y=canvas.height;s.x=Math.random()*canvas.width;}ctx.beginPath();ctx.arc(s.x,s.y,s.r,0,Math.PI*2);ctx.fillStyle=s.gold?`rgba(197,160,89,${s.alpha*.7})`:`rgba(210,215,255,${s.alpha*.65})`;ctx.fill();});requestAnimationFrame(draw);}
    resize();initStars();draw();window.addEventListener('resize',()=>{resize();initStars();});
})();
(function(){const wrap=document.getElementById('userDropdownWrap'),trigger=document.getElementById('userDropdownTrigger');if(!wrap||!trigger)return;trigger.addEventListener('click',e=>{e.stopPropagation();wrap.classList.toggle('open');});document.addEventListener('click',e=>{if(!wrap.contains(e.target))wrap.classList.remove('open');});})();
(function(){const hdr=document.querySelector('header');if(!hdr)return;function update(){hdr.classList.toggle('cms-scrolled',window.scrollY>80);}window.addEventListener('scroll',update,{passive:true});update();})();
(function(){
    const nav=document.getElementById('headerNav'),moreWrap=document.getElementById('headerNavMore');
    if(!nav||!moreWrap)return;
    const moreBtn=document.getElementById('headerNavMoreBtn'),moreLabel=document.getElementById('headerNavMoreLabel'),moreMenu=document.getElementById('headerNavMoreMenu'),moreText=moreLabel.textContent.trim();
    function getItems(){return Array.from(nav.children).filter(el=>el!==moreWrap);}
    function layout(){const items=getItems();items.forEach(el=>{el.style.display='';});moreWrap.style.display='none';moreMenu.innerHTML='';const available=nav.clientWidth,reserve=moreBtn.offsetWidth+20;let used=0,start=-1;for(let i=0;i<items.length;i++){used+=items[i].offsetWidth;if(used>available-reserve){start=i;break;}}if(start<0)return;if(items[start]?.classList.contains('nav-sep'))start++;if(start>=items.length)return;const overflow=items.slice(start).filter(el=>el.tagName==='A');overflow.forEach(link=>moreMenu.appendChild(link.cloneNode(true)));for(let i=start;i<items.length;i++)items[i].style.display='none';moreLabel.textContent=moreText+' ('+overflow.length+')';moreWrap.style.display='flex';}
    function close(){moreWrap.classList.remove('open');moreBtn.setAttribute('aria-expanded','false');}
    moreBtn.addEventListener('click',e=>{e.stopPropagation();const open=!moreWrap.classList.contains('open');if(open){const rect=moreBtn.getBoundingClientRect();moreMenu.style.top=(rect.bottom+8)+'px';moreMenu.style.right=(window.innerWidth-rect.right)+'px';moreWrap.classList.add('open');moreBtn.setAttribute('aria-expanded','true');}else close();});
    document.addEventListener('click',e=>{if(!moreWrap.contains(e.target)&&!moreMenu.contains(e.target))close();});let timer;window.addEventListener('resize',()=>{close();clearTimeout(timer);timer=setTimeout(layout,100);});window.addEventListener('scroll',close,{passive:true});if(document.fonts?.ready)document.fonts.ready.then(layout);window.addEventListener('load',layout);layout();
})();
</script>

<?php if ($header_priv >= 2 && $header_priv <= 3): ?>
<div id="reportsPopupBackdrop" class="reports-popup-backdrop" onclick="if(event.target===this)reportsPopupClose()">
    <div class="reports-popup-box"><div class="reports-popup-head"><span><i class="fas fa-flag"></i> <?= t('sidebar.nav_reported_posts', [], 'Reported Posts') ?></span><button type="button" class="reports-popup-close" onclick="reportsPopupClose()">&#x2715;</button></div><div class="reports-popup-body" id="reportsPopupBody"><div class="reports-popup-empty"><?= t('reports_popup.loading', [], 'Loading…') ?></div></div></div>
</div>
<style>
.reports-popup-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9998;align-items:center;justify-content:center}.reports-popup-backdrop.show{display:flex}.reports-popup-box{background:#0a0a0a;border:1px solid rgba(197,160,89,.25);max-width:640px;width:92%;max-height:80vh;display:flex;flex-direction:column}.reports-popup-head{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid rgba(197,160,89,.15);font-family:'Cinzel',serif;font-size:.85em;letter-spacing:2px;color:#c5a059;text-transform:uppercase}.reports-popup-close{background:none;border:0;color:#666;cursor:pointer}.reports-popup-body{padding:14px 18px;overflow-y:auto;font-family:sans-serif}.reports-popup-empty{color:#555;font-size:.85em;text-align:center;padding:20px 0}.reports-popup-item{border:1px solid #1a1a1a;padding:12px 14px;margin-bottom:12px}.reports-popup-item-meta{font-size:.75em;color:#666;margin-bottom:6px}.reports-popup-item-reason{color:#c5a059;font-size:.8em;margin-bottom:8px}.reports-popup-item-content{background:rgba(255,255,255,.02);border-left:2px solid #222;padding:8px 10px;color:#999;font-size:.8em;line-height:1.5;margin-bottom:10px;white-space:pre-wrap;word-break:break-word}.reports-popup-actions{display:flex;gap:8px;flex-wrap:wrap}.reports-popup-btn{background:transparent;border:1px solid #333;color:#888;padding:5px 12px;font-size:.75em;font-family:'Cinzel',serif;letter-spacing:1px;text-transform:uppercase;cursor:pointer}
</style>
<script>
function reportsPopupOpen(){document.getElementById('reportsPopupBackdrop').classList.add('show');reportsPopupLoad();}
function reportsPopupClose(){document.getElementById('reportsPopupBackdrop').classList.remove('show');}
function reportsPopupEsc(s){return(s??'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function reportsPopupLoad(){const body=document.getElementById('reportsPopupBody');body.innerHTML='<div class="reports-popup-empty"><?= h(t('reports_popup.loading', [], 'Loading…')) ?></div>';fetch('ajax_reports.php?action=list').then(r=>{if(!r.ok)throw new Error('HTTP '+r.status);return r.json();}).then(d=>{if(!d.ok||!Array.isArray(d.reports)){throw new Error('invalid_response');}if(!d.reports.length){body.innerHTML='<div class="reports-popup-empty"><?= h(t('reports_popup.empty', [], 'No open reports.')) ?></div>';return;}body.innerHTML=d.reports.map(reportsPopupRenderItem).join('');}).catch(()=>{body.innerHTML='<div class="reports-popup-empty"><?= h(t('reports_popup.error', [], 'Could not load reports.')) ?></div>';});}
function reportsPopupRenderItem(r){const esc=reportsPopupEsc;return `<div class="reports-popup-item" id="report-${Number(r.id)||0}"><div class="reports-popup-item-meta"><?= h(t('reports_popup.reported_by', [], 'Reported by')) ?> <strong>${esc(r.reporter_name)}</strong> · <?= h(t('reports_popup.post_by', [], 'Post by')) ?> <strong>${esc(r.post_author||'Unknown')}</strong> · ${esc(r.thread_title)}</div>${r.reason?`<div class="reports-popup-item-reason"><?= h(t('reports_popup.reason', [], 'Reason')) ?>: ${esc(r.reason)}${r.details?' — '+esc(r.details):''}</div>`:''}<div class="reports-popup-item-content">${esc(r.post_content)}</div><div class="reports-popup-actions"><button type="button" class="reports-popup-btn" onclick="reportsPopupSetStatus(${Number(r.id)||0},'reviewing')"><?= h(t('reports_popup.btn_review', [], 'Mark as Reviewing')) ?></button><button type="button" class="reports-popup-btn" onclick="reportsPopupSetStatus(${Number(r.id)||0},'resolved')"><?= h(t('reports_popup.btn_resolve', [], 'Resolve')) ?></button><button type="button" class="reports-popup-btn" onclick="reportsPopupSetStatus(${Number(r.id)||0},'dismissed')"><?= h(t('reports_popup.btn_dismiss', [], 'Dismiss')) ?></button></div></div>`;}
function reportsPopupSetStatus(reportId,newStatus){const fd=new FormData();fd.append('action','set_status');fd.append('report_id',reportId);fd.append('new_status',newStatus);fd.append('csrf_token',<?= json_encode(generateToken()) ?>);fetch('ajax_reports.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(!d.ok)return;if(newStatus!=='reviewing')document.getElementById('report-'+reportId)?.remove();const badge=document.getElementById('reportsBadgeCount');if(badge){badge.textContent=d.remaining;if(Number(d.remaining)===0)badge.closest('.user-dropdown-item')?.remove();}}).catch(()=>{});}
</script>
<?php endif; ?>
