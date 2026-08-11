<?php
if (!defined('IN_CMS')) { exit; }

if (empty($cms_settings)) {
    try {
        $cms_settings = $db->query("SELECT setting_key, value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        $cms_settings = [];
    }
}

$mod_on = function(string $key) use ($cms_settings, $userPriv): bool {
    return ($cms_settings[$key] ?? '1') !== '0';
};

$_sidebar_module_map = [
    'spike'         => 'mod_forum',
    'viewboard'     => 'mod_forum',
    'viewthread'    => 'mod_forum',
    'newthread'     => 'mod_forum',
    'editpost'      => 'mod_forum',
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
];

$dynamic_menu = [];
try {
    $stmt_menu = $db->prepare(
        "SELECT title, slug, content, menu_category, min_priv
         FROM pages
         WHERE menu_category != 'none'
           AND min_priv <= ?
           AND (status = 'published' OR status IS NULL)
           AND (published_at IS NULL OR published_at <= NOW())
         ORDER BY menu_pos ASC, title ASC"
    );
    $stmt_menu->execute([(int)($userPriv ?? 0)]);
    while ($m_row = $stmt_menu->fetch(PDO::FETCH_ASSOC)) {
        $dynamic_menu[$m_row['menu_category']][] = $m_row;
    }
} catch (PDOException $e) {}

$renderLinks = function($cat_key) use ($dynamic_menu, $mod_on, $_sidebar_module_map) {
    if (!isset($dynamic_menu[$cat_key])) return;
    foreach ($dynamic_menu[$cat_key] as $item) {
        $content = $item['content'] ?? '';
        $title   = h($item['title']);
        $slug    = h($item['slug']);
        $target  = '';

        if (strpos($content, '[EXT]:') === 0) {
            $url    = h(substr($content, 6));
            $target = 'target="_blank" rel="noopener"';

        } elseif (strpos($content, '[MODULE]:') === 0) {
            $module_slug = trim(substr($content, 9));
            $mod_key     = $_sidebar_module_map[$module_slug] ?? null;
            if ($mod_key !== null && !$mod_on($mod_key)) {
                continue;
            }
            $url = "index.php?p=" . h($module_slug);

        } else {
            $url = "index.php?p=" . $slug;
        }

        echo '<li><a href="' . $url . '" ' . $target . '>'
           . '<i class="fas fa-link" style="font-size:0.8em; opacity:0.5;"></i> '
           . '<span>' . $title . '</span>'
           . '</a></li>';
    }
};

$_current_page = $_GET['p'] ?? 'home';

$_mobile_items = [
    ['href' => 'index.php?p=home', 'icon' => 'fa-home',  'label' => t('sidebar.nav_home',  [], 'Home'),  'slug' => 'home'],
    ['href' => '?p=search',        'icon' => 'fa-search','label' => t('sidebar.nav_search', [], 'Search'), 'slug' => 'search'],
];

if (!isset($_SESSION['user_id']) && $mod_on('mod_register'))
    $_mobile_items[] = ['href' => '?p=register', 'icon' => 'fa-user-plus',      'label' => t('sidebar.nav_register', [], 'Register'), 'slug' => 'register'];
if ($mod_on('mod_forum'))
    $_mobile_items[] = ['href' => '?p=spike',    'icon' => 'fa-comments',        'label' => t('sidebar.nav_forum',    [], 'Forum'),    'slug' => 'spike'];
if ($mod_on('mod_team'))
    $_mobile_items[] = ['href' => '?p=team',     'icon' => 'fa-shield-alt',      'label' => 'Team',                                    'slug' => 'team'];
if ($mod_on('mod_faq'))
    $_mobile_items[] = ['href' => '?p=faq',      'icon' => 'fa-question-circle', 'label' => t('sidebar.nav_faq',      [], 'FAQ'),      'slug' => 'faq'];
if ($mod_on('mod_pve'))
    $_mobile_items[] = ['href' => '?p=pve',      'icon' => 'fa-dragon',          'label' => t('sidebar.nav_pve',      [], 'PvE'),      'slug' => 'pve'];
if ($mod_on('mod_herald'))
    $_mobile_items[] = ['href' => '?p=herald',   'icon' => 'fa-bullhorn',        'label' => t('sidebar.nav_herald',  [], 'Herald'),   'slug' => 'herald'];
if ($mod_on('mod_rvr_map'))
    $_mobile_items[] = ['href' => '?p=rvr_map',  'icon' => 'fa-map-marked-alt',  'label' => t('sidebar.nav_rvrmap',  [], 'RvR Map'),  'slug' => 'rvr_map'];

foreach ($dynamic_menu as $cat => $items) {
    foreach ($items as $item) {
        $content = $item['content'] ?? '';

        if (strpos($content, '[EXT]:') === 0) {
            $url = h(substr($content, 6));

        } elseif (strpos($content, '[MODULE]:') === 0) {
            $module_slug = trim(substr($content, 9));
            $mod_key     = $_sidebar_module_map[$module_slug] ?? null;
            if ($mod_key !== null && !$mod_on($mod_key)) {
                continue;
            }
            $url = "index.php?p=" . h($module_slug);

        } else {
            $url = "index.php?p=" . h($item['slug']);
        }

        $_mobile_items[] = [
            'href'  => $url,
            'icon'  => 'fa-link',
            'label' => h($item['title']),
            'slug'  => h($item['slug']),
        ];
    }
}
?>

<aside class="sidebar" <?php if (!empty($hide_sidebar)) echo 'style="display:none !important;"'; ?>>
    <nav class="sidebar-nav-container">

        <h3 class="hide-mobile"><i class="fas fa-chess-rook"></i> <?= t('sidebar.section_stronghold') ?></h3>
        <ul>
            <li><a href="index.php?p=home"><i class="fas fa-home"></i> <span><?= t('sidebar.nav_home') ?></span></a></li>
            <?php if (!isset($_SESSION['user_id']) && $mod_on('mod_register')): ?>
                <li><a href="?p=register"><i class="fas fa-user-plus"></i> <span><?= t('sidebar.nav_register') ?></span></a></li>
            <?php endif; ?>
            <?php $renderLinks('stronghold'); ?>
        </ul>
        <h3 class="hide-mobile"><i class="fas fa-users"></i> <?= t('sidebar.section_community') ?></h3>
        <ul>
            <?php if ($mod_on('mod_forum')): ?>
                <li><a href="?p=spike"><i class="fas fa-comments"></i> <span><?= t('sidebar.nav_forum') ?></span></a></li>
            <?php endif; ?>
            <?php if ($mod_on('mod_team')): ?>
                <li><a href="?p=team"><i class="fas fa-shield-alt"></i> <span>Team</span></a></li>
            <?php endif; ?>
            <?php if ($mod_on('mod_faq')): ?>
                <li><a href="?p=faq"><i class="fas fa-question-circle"></i> <span><?= t('sidebar.nav_faq') ?></span></a></li>
            <?php endif; ?>
            <?php $renderLinks('community'); ?>
        </ul>

        <h3 class="hide-mobile"><i class="fas fa-book-open"></i> <?= t('sidebar.section_library') ?></h3>
        <ul>
            <?php if ($mod_on('mod_pve')): ?>
                <li><a href="?p=pve"><i class="fas fa-dragon"></i> <span><?= t('sidebar.nav_pve') ?></span></a></li>
            <?php endif; ?>
            <?php if ($mod_on('mod_herald')): ?>
                <li><a href="?p=herald"><i class="fas fa-bullhorn"></i> <span><?= t('sidebar.nav_herald') ?></span></a></li>
            <?php endif; ?>
            <?php if ($mod_on('mod_rvr_map')): ?>
                <li><a href="?p=rvr_map"><i class="fas fa-map-marked-alt"></i> <span><?= t('sidebar.nav_rvrmap') ?></span></a></li>
            <?php endif; ?>
            <?php $renderLinks('library'); ?>
        </ul>

        <?php if (function_exists('cms_run_hook')):
            $hook_nav = cms_run_hook('hook_sidebar_nav');
            if (!empty($hook_nav)) echo $hook_nav;
        endif; ?>

    </nav>
</aside>

<div class="mobile-nav-bar">
    <button class="mobile-nav-arrow mobile-nav-arrow--left" id="mobileNavLeft" aria-label="Scroll left">
        <i class="fas fa-chevron-left"></i>
    </button>

    <div class="mobile-nav-track" id="mobileNavTrack">
        <?php foreach ($_mobile_items as $_mi): ?>
        <a href="<?= $_mi['href'] ?>"
           class="mobile-nav-item <?= ($_current_page === $_mi['slug']) ? 'active' : '' ?>">
            <i class="fas <?= $_mi['icon'] ?>"></i>
            <span><?= $_mi['label'] ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <button class="mobile-nav-arrow mobile-nav-arrow--right" id="mobileNavRight" aria-label="Scroll right">
        <i class="fas fa-chevron-right"></i>
    </button>
</div>
<script>
(function () {
    const track = document.getElementById('mobileNavTrack');
    const btnL  = document.getElementById('mobileNavLeft');
    const btnR  = document.getElementById('mobileNavRight');
    if (!track || !btnL || !btnR) return;

    btnL.addEventListener('click', () => {
        const step = track.clientWidth / 3;
        track.scrollBy({ left: -step, behavior: 'smooth' });
    });
    
    btnR.addEventListener('click', () => {
        const step = track.clientWidth / 3;
        track.scrollBy({ left: step, behavior: 'smooth' });
    });

    const active = track.querySelector('.mobile-nav-item.active');
    if (active) {
        setTimeout(() => {
            active.scrollIntoView({ inline: 'center', behavior: 'smooth', block: 'nearest' });
        }, 150);
    }
})();
</script>