<?php
ob_start();
require_once('includes/db.php');

$css_version  = '1';
$active_theme = 'default';
try {
    $stmt = $db->prepare("SELECT setting_key, value FROM settings WHERE setting_key IN ('active_theme','css_version')");
    $stmt->execute();
    $settings     = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $active_theme = $settings['active_theme'] ?? 'default';
    $css_version  = $settings['css_version']  ?? '1';
} catch (PDOException $e) {}

$module = preg_replace('/[^a-z0-9_]/', '', $_GET['module'] ?? 'main');

$base = [
    'acp_global', 'main', 'hero',
    'header_starfield', 'header_page_wrapper', 'header_responsive',
    'header_user_dropdown', 'header_nav',
    'dynamic_content', 'footer', 'realm_effects', 'acp_mobile',
    'sidebar', 'profile', 'team', 'maintenance_banner',
    'custom_css_mobile', 'spike_editor',
];

$forum = array_merge($base, ['spike_forum', 'spike_mobile', 'viewthread_mobile']);

$stacks = [
    'main'             => $base,
    'spike'            => $forum,
    'viewthread'       => array_merge($forum, ['spike_viewthread', 'spike_mentions']),
    'viewboard'        => $forum,
    'newthread'        => array_merge($forum, ['spike_newthread', 'spike_mentions']),
    'editpost'         => array_merge($forum, ['spike_newthread', 'spike_mentions']),
    'acp_spike'        => ['acp_global', 'acp_admin', 'acp_spike'],
    'acp_um'           => ['acp_global', 'acp_admin', 'acp_um'],
    'acp_admin'        => ['acp_global', 'acp_admin'],
    'acp_global'       => ['acp_global'],
    'acp_theme_editor' => ['acp_global', 'acp_admin', 'theme_editor'],
    'acp_dashboard'    => ['acp_global', 'acp_admin', 'acp_dashboard'],
    'herald'           => array_merge($base, ['herald', 'herald_view']),
    'herald_char'      => array_merge($base, ['herald', 'herald_view']),
    'herald_guild'     => array_merge($base, ['herald', 'herald_view']),
    'login'            => array_merge($base, ['login']),
    'register'         => array_merge($base, ['register']),
    'pve'              => array_merge($base, ['pve']),
    'pve_view'         => array_merge($base, ['pve_view']),
    'pve_bestiary'     => array_merge($base, ['pve_bestiary']),
    'pve_boss'         => array_merge($base, ['pve_boss']),
    'pve_quests'       => array_merge($base, ['pve_quests']),
    'pve_quest_detail' => array_merge($base, ['pve_quest_detail']),
    'pve_item'         => array_merge($base, ['pve_item']),
    'pve_items'        => array_merge($base, ['itemshop']),
    'pve_dungeons'     => array_merge($base, ['pve_dungeons']),
    'user'             => array_merge($base, ['user_view']),
    'user_edit'        => array_merge($base, ['user_edit']),
    'verify'           => array_merge($base, ['verify']),
    'search'           => array_merge($base, ['search_view']),
    'notifications'    => array_merge($base, ['notifications']),
    'private_messages' => array_merge($base, ['private_messages']),
    'spike_search'     => $forum,
];

$modules_to_load = $stacks[$module] ?? ['acp_global', $module];

require_once('includes/theme_chain.php');
$theme_chain = aldhran_resolve_theme_chain($db, $active_theme);

// ETag based on actual content (MAX last_updated of loaded modules),
// not just css_version - otherwise DB CSS edits stay invisible in browser cache.
$etag = md5($active_theme . $module . $css_version);
try {
    $ph          = implode(',', array_fill(0, count($modules_to_load), '?'));
    $theme_ph    = implode(',', array_fill(0, count($theme_chain), '?'));
    $stmt_etag = $db->prepare(
        "SELECT MAX(last_updated) FROM aldhran_styles
         WHERE module_key IN ($ph) AND is_active=1 AND theme_slug IN ($theme_ph)"
    );
    $stmt_etag->execute(array_merge($modules_to_load, $theme_chain));
    $last_mod = $stmt_etag->fetchColumn();
    if ($last_mod) {
        $etag = md5($active_theme . $module . $css_version . $last_mod);
    }
} catch (PDOException $e) {}

header("Content-Type: text/css; charset=UTF-8");
header("X-Content-Type-Options: nosniff");
header("Cache-Control: public, max-age=86400");
header("ETag: \"$etag\"");

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === "\"$etag\"") {
    header("HTTP/1.1 304 Not Modified");
    ob_end_clean();
    exit;
}

try {
    foreach ($theme_chain as $chain_theme) {
        $stmt = $db->prepare("SELECT module_key,css_content FROM aldhran_styles WHERE theme_slug=? AND module_key IN ($ph) AND is_active=1");
        $stmt->execute(array_merge([$chain_theme], $modules_to_load));
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($modules_to_load as $mod) {
            if (!empty($rows[$mod])) {
                echo "/* === " . strtoupper($mod) . " [" . strtoupper($chain_theme) . "] === */\n";
                echo $rows[$mod] . "\n\n";
            }
        }
    }
} catch (PDOException $e) {
    error_log("[style.php] DB Error: " . $e->getMessage());
    echo "/* CSS load error */";
}

ob_end_flush();