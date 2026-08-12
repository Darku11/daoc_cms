<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_ACP')) exit;

if (!isset($userPriv))      $userPriv      = (int)($_SESSION['priv_level'] ?? 0);
if (!isset($currentUserId)) $currentUserId = (int)($_SESSION['user_id']    ?? 0);

$server_ip   = $GLOBALS['cms_settings']['game_server_ip']   ?? '127.0.0.1';
$server_port = (int)($GLOBALS['cms_settings']['game_server_port'] ?? 10300);
$fp = @fsockopen($server_ip, $server_port, $errno, $errstr, 1);
$server_online = (bool)$fp;
if ($fp) fclose($fp);
$GLOBALS['acp_server_online'] = $server_online;

$total_users  = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$online_users = (int)$db->query("SELECT COUNT(*) FROM users WHERE last_activity > " . (time() - 300))->fetchColumn();
$total_chars  = (int)$db->query("SELECT COUNT(*) FROM dolcharacters")->fetchColumn();
$stmt_posts   = $db->query("SELECT COUNT(*) FROM spike_posts");
$total_posts  = $stmt_posts ? $stmt_posts->fetchColumn() : '—';

$stmt_dirty      = $db->query("SELECT slug FROM pages WHERE content LIKE '%<style%' OR content LIKE '%<script%'");
$dirty_slugs_all = $stmt_dirty ? $stmt_dirty->fetchAll(PDO::FETCH_COLUMN) : [];
$dirty_dismissed_raw = $GLOBALS['cms_settings']['acp_dirty_pages_dismissed'] ?? '[]';
$dirty_dismissed = json_decode($dirty_dismissed_raw, true);
if (!is_array($dirty_dismissed)) $dirty_dismissed = [];
$dirty_slugs_new = array_values(array_diff($dirty_slugs_all, $dirty_dismissed));
$dirty_pages     = count($dirty_slugs_new);
if (isset($_GET['dismiss_dirty_pages']) && $userPriv >= 5) {
    checkToken($_GET['csrf'] ?? '');
    $db->prepare("INSERT INTO settings (setting_key, value) VALUES ('acp_dirty_pages_dismissed', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")
       ->execute([json_encode($dirty_slugs_all)]);
    header('Location: acp.php?s=dashboard');
    exit;
}

$ai_pending = 0;
try {
    $ai_pending = (int)$db->query("SELECT COUNT(*) FROM cms_ai_suggestions WHERE status = 'pending'")->fetchColumn();
} catch (\Throwable $e) {}

$stmt_ips = $db->query("
    SELECT u.last_ip AS ip_address, COUNT(*) AS reg_count
    FROM users u
    LEFT JOIN household_registrations hr ON hr.ip_address = u.last_ip
    WHERE u.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
      AND u.last_ip NOT IN ('', '0.0.0.0', '127.0.0.1', '::1')
      AND hr.ip_address IS NULL
    GROUP BY u.last_ip
    HAVING reg_count >= 3
    ORDER BY reg_count DESC, u.last_ip ASC
");
$suspicious_ips = $stmt_ips ? $stmt_ips->fetchAll(PDO::FETCH_ASSOC) : [];

$suspicious_ips_hash = md5(json_encode($suspicious_ips));
$sec_warn_dismissed = $GLOBALS['cms_settings']['acp_sec_warn_dismissed'] ?? '';
$show_sec_warn = (!empty($suspicious_ips) && $suspicious_ips_hash !== $sec_warn_dismissed);

if (isset($_GET['dismiss_sec_warn']) && $userPriv >= 4) {
    checkToken($_GET['csrf'] ?? '');
    $db->prepare("INSERT INTO settings (setting_key, value) VALUES ('acp_sec_warn_dismissed', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")
       ->execute([$suspicious_ips_hash]);
    header('Location: acp.php?s=dashboard');
    exit;
}

$req_admin = ($GLOBALS['cms_settings']['admin_approval_required'] ?? '0') === '1';
$pending_users = 0;
$pending_user_list = [];
if ($req_admin) {
    $pending_users = (int)$db->query("SELECT COUNT(*) FROM users WHERE is_verified = 0")->fetchColumn();
    if ($pending_users > 0) {
        $stmt_pending = $db->query("SELECT id, username, email, created_at FROM users WHERE is_verified = 0 ORDER BY id DESC LIMIT 10");
        $pending_user_list = $stmt_pending ? $stmt_pending->fetchAll(PDO::FETCH_ASSOC) : [];
    }
}

$bot_active = false;
$ai_provider = 'none';

try {
    $bs_row = $db->query("SELECT is_active, ai_provider FROM cms_bot_settings WHERE id = 1")->fetch();

    if ($bs_row) {
        $bot_active = (bool)$bs_row['is_active'];
        $ai_provider = $bs_row['ai_provider'] ?? 'none';
    }
} catch (\Throwable $e) {
}

$_acp_section_defs = $allowed_sections ?? [];
$_acp_cat_defs      = $acp_categories  ?? [];

$_all_modules = [];
foreach ($_acp_section_defs as $_s_key => $_s_cfg) {
    if ($_s_key === 'dashboard') continue;
    if ($userPriv < ($_s_cfg['min_priv'] ?? 5)) continue;

    $_cat_id  = $_s_cfg['category'] ?? 'plugins';
    $_cat_cfg = $_acp_cat_defs[$_cat_id] ?? ['key' => 'acp_cat_plugins', 'fallback' => 'Plugins', 'icon' => 'fa-puzzle-piece', 'order' => 99];

    $_all_modules[] = [
        's'              => $_s_key,
        'icon'           => $_s_cfg['icon']  ?? 'fa-circle',
        'label'          => $_s_cfg['label'] ?? $_s_key,
        'desc'           => $_s_cfg['desc']  ?? '',
        'category'       => $_cat_id,
        'category_label' => t($_cat_cfg['key'], [], $_cat_cfg['fallback']),
        'category_icon'  => $_cat_cfg['icon']  ?? 'fa-puzzle-piece',
        'category_order' => $_cat_cfg['order'] ?? 99,
    ];
}

usort($_all_modules, function ($a, $b) {
    if ($a['category_order'] !== $b['category_order']) return $a['category_order'] <=> $b['category_order'];
    return strcasecmp($a['label'], $b['label']);
});

$_modules_json = json_encode(array_values($_all_modules));
?>
<div class="d-wrap">
<?php if (function_exists('cms_run_hook')) echo cms_run_hook('hook_acp_dashboard_top', 'acp_card'); ?>

<?php if ($dirty_pages > 0): ?>
<div class="d-warn">
    <i class="fas fa-exclamation-triangle"></i>
    <?= $dirty_pages ?> <?= t('acp_dirtypage_warning', [], 'page(s) contain raw &lt;style&gt; or &lt;script&gt; tags that may break the theme.') ?>
    <a href="acp.php?s=content_manager"><?= t('general_review', [], 'Review') ?></a>
    <a href="acp.php?s=dashboard&dismiss_dirty_pages=1&csrf=<?= h(generateToken()) ?>"
       class="d-warn-dismiss"
       title="<?= t('dash_dismiss_warning', [], 'Hide until a new affected page is added') ?>"
       onclick="return confirm('<?= t('dash_dismiss_confirm', [], 'Hide warning until a new page with <style>/<script> is added?') ?>');">
        <i class="fas fa-times"></i>
    </a>
</div>
<?php endif; ?>

<?php if ($ai_pending > 0): ?>
<div class="d-warn" style="border-color:rgba(197,160,89,0.18);background:rgba(197,160,89,0.03);color:rgba(197,160,89,0.7);">
    <i class="fas fa-robot"></i>
    <strong><?= $ai_pending ?></strong> <?= t('ai_suggestions_1', [], 'AI suggestions') ?><?= $ai_pending > 1 ? 's' : '' ?> <?= t('ai_suggestions_2', [], 'waiting for review') ?>.
    <a href="acp.php?s=ai_suggestions" style="color:rgba(197,160,89,0.7);"><?= t('general_review', [], 'Review') ?> →</a>
</div>
<?php endif; ?>

<?php if ($show_sec_warn && $userPriv >= 4): ?>
<div class="d-warn" style="border-color:rgba(224,112,112,0.3);background:rgba(224,112,112,0.05);color:#e07070;position:relative;">
    <i class="fas fa-shield-virus"></i>
    <strong><?= t('acp_dash_security_warn', [], 'Security Warning:') ?></strong> <?= t('acp_dash_multiple_regs', [], 'Three or more registrations from the same IP within the last 24 hours.') ?>
    <a href="acp.php?s=dashboard&dismiss_sec_warn=1&csrf=<?= h(generateToken()) ?>"
       class="d-warn-dismiss"
       style="position:absolute; right:15px; top:12px; color:#e07070; text-decoration:none;"
       title="<?= t('dash_dismiss_warning', [], 'Hide until a new affected item is added') ?>">
        <i class="fas fa-times"></i>
    </a>
    <ul style="margin: 5px 0 0 25px; padding: 0; font-family: monospace; font-size: 0.9em;">
        <?php foreach ($suspicious_ips as $sip): ?>
            <li>IP: <?= h($sip['ip_address']) ?> (<?= $sip['reg_count'] ?> <?= t('acp_dash_regs', [], 'registrations') ?>)</li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($pending_users > 0 && $userPriv >= 4): ?>
<div class="d-warn acp-s-f3b73f85">
    <i class="fas fa-user-clock"></i>
    <strong><?= t('acp_dash_admin_approval', [], 'Admin Approval:') ?></strong> <?= $pending_users ?> <?= t('acp_dash_pending_users', [], 'account(s) waiting for manual verification.') ?>
    <a href="acp.php?s=um" class="acp-s-0ccf2f90"><?= t('acp_dash_open_um', [], 'Open User Manager &rarr;') ?></a>
    <?php if (!empty($pending_user_list)): ?>
    <ul class="acp-s-ed70993b">
        <?php foreach ($pending_user_list as $pu): ?>
            <li>
                <strong><?= h($pu['username']) ?></strong> (<?= h($pu['email']) ?>)
                &ndash; <span class="acp-s-709e873c"><?= date('d.m.Y H:i', strtotime($pu['created_at'])) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="d-search">
    <div class="d-search-box">
        <i class="fas fa-search"></i>
        <input type="text" class="d-search-input" id="acp-srch"
               placeholder="<?= t('dash_search_placeholder', [], 'Search functions...') ?>" autocomplete="off" spellcheck="false">
        <button type="button" class="d-search-browse" id="acp-srch-browse"
               title="<?= t('dash_browse_all_title', [], 'Browse all functions by category') ?>">
            <i class="fas fa-grip"></i>
        </button>
        <span class="d-search-key">/ or Ctrl+K</span>
    </div>
    <div class="d-search-drop" id="acp-srch-drop"></div>
</div>

<div class="d-spotlight">
    <div class="d-spo">
        <div class="d-spo-val"><?= number_format($total_users) ?></div>
        <div class="d-spo-lbl"><?= t('dash_stat_accounts', [], 'Accounts') ?></div>
        <div class="d-spo-sub"><?= $online_users ?> <?= t('dash_stat_online_now', [], 'online now') ?></div>
    </div>
    <div class="d-spo">
        <div class="d-spo-val"><?= number_format($total_chars) ?></div>
        <div class="d-spo-lbl"><?= t('dashboard.stat_characters', [], 'Characters') ?></div>
    </div>
    <div class="d-spo">
        <div class="d-spo-val"><?= is_numeric($total_posts) ? number_format((int)$total_posts) : '—' ?></div>
        <div class="d-spo-lbl"><?= t('dash_stat_forum_posts', [], 'Forum Posts') ?></div>
    </div>
    <div class="d-spo">
        <div class="d-spo-val" style="font-size:1em;color:<?= $server_online ? '#6aaa70' : '#b85050' ?>;">
            <?= $server_online ? 'ONLINE' : 'OFFLINE' ?>
        </div>
        <div class="d-spo-lbl"><?= t('acp_gameserver_description', [], 'Game Server') ?></div>
        <div class="d-spo-sub" style="color:<?= $bot_active ? '#7289da' : 'inherit' ?>;">
            Bot <?= $bot_active ? t('dash_bot_active', [], 'active') . ' · ' . h(ucfirst($ai_provider)) : t('dash_bot_inactive', [], 'inactive') ?>
        </div>
    </div>
</div>
<div class="d-cols">
    
    <?php if ($userPriv >= 4): ?>
    <div class="d-window">
        <div class="d-window-bar">
            <span><i class="fas fa-history"></i> <?= t('dash_widget_admin_log', [], 'Recent Admin Actions') ?></span>
            <a href="acp.php?s=admin_log"><?= t('dash_widget_view_all', [], 'View All &raquo;') ?></a>
        </div>
        <div class="d-window-body acp-s-87f683d7">
            <?php
            try {
                $stmt_logs = $db->query("
                    SELECT al.action_type, al.created_at, u.username 
                    FROM aldhran_logs al 
                    LEFT JOIN users u ON al.user_id = u.id 
                    ORDER BY al.id DESC LIMIT 3
                ");
                $recent_logs = $stmt_logs->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($recent_logs)): ?>
                    <div class="d-row"><div class="d-row-lbl"><?= t('dash_log_empty', [], 'No recent actions found.') ?></div></div>
                <?php else:
                    foreach ($recent_logs as $log): ?>
                        <div class="d-row">
                            <div class="d-row-lbl">
                                <i class="fas fa-chevron-right"></i> 
                                <strong class="acp-s-32ff785a"><?= h($log['username'] ?? 'System') ?></strong>
                                <?= h($log['action_type']) ?>
                            </div>
                            <div class="d-row-val acp-s-e8fcb710">
                                <?= date('d.m. H:i', strtotime($log['created_at'])) ?>
                            </div>
                        </div>
                    <?php endforeach; 
                endif;
            } catch (\Throwable $e) {
                echo '<div class="d-row"><div class="d-row-lbl acp-s-e0936291">' . t('dash_log_error', [], 'Error loading logs.') . '</div></div>';
            }
            ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="d-window">
        <div class="d-window-bar">
            <span><i class="fas fa-flag"></i> <?= t('dash_widget_forum_reports', [], 'Open Forum Reports') ?></span>
            <a href="acp.php?s=spike_admin"><?= t('dash_widget_manage', [], 'Manage &raquo;') ?></a>
        </div>
        <div class="d-window-body acp-s-87f683d7">
            <?php
            try {
                $stmt_reports = $db->query("
                    SELECT r.id, r.status, r.created_at, u.username AS reporter_name, t.title AS thread_title 
                    FROM spike_reports r 
                    JOIN users u ON r.reporter_id = u.id 
                    JOIN spike_threads t ON r.thread_id = t.id 
                    WHERE r.status IN ('open', 'reviewing') 
                    ORDER BY r.created_at DESC LIMIT 5
                ");
                $recent_reports = $stmt_reports->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($recent_reports)): ?>
                    <div class="d-row"><div class="d-row-lbl"><?= t('dash_reports_empty', [], 'No open reports. All clear.') ?></div></div>
                <?php else:
                    foreach ($recent_reports as $rep): 
                        $status_color = $rep['status'] === 'reviewing' ? 'var(--blue)' : 'var(--amber-warn)';
                    ?>
                        <div class="d-row">
                            <div class="d-row-lbl acp-s-59a42155">
                                <i class="fas fa-flag" style="color:<?= $status_color ?>;"></i> 
                                <strong class="acp-s-4c01fa33"><?= h($rep['reporter_name']) ?></strong>
                                <span class="acp-s-101037d2">&rarr; <?= h($rep['thread_title']) ?></span>
                            </div>
                            <div class="d-row-val" style="font-family:monospace; font-size:0.9em; text-transform:uppercase; color:<?= $status_color ?>;">
                                <?= h($rep['status']) ?>
                            </div>
                        </div>
                    <?php endforeach; 
                endif;
            } catch (\Throwable $e) {
                echo '<div class="d-row"><div class="d-row-lbl acp-s-e0936291">' . t('dash_reports_error', [], 'Error loading reports.') . '</div></div>';
            }
            ?>
        </div>
    </div>

</div>
<script>
(function(){
    var mods      = <?= $_modules_json ?>;
    var inp       = document.getElementById('acp-srch');
    var drop      = document.getElementById('acp-srch-drop');
    var browseBtn = document.getElementById('acp-srch-browse');
    if (!inp||!drop) return;
    var hi = -1;
    var browseMode = false;

    function buildItem(m){
        var a=document.createElement('a');
        a.className='d-sr'; a.href='acp.php?s='+m.s;
        a.innerHTML='<span class="d-sr-ico"><i class="fas '+m.icon+'"></i></span><span><div class="d-sr-lbl">'+m.label+'</div><div class="d-sr-dsc">'+m.desc+'</div></span>';
        return a;
    }

    function renderGrouped(){
        drop.innerHTML=''; hi=-1;
        var byCat = {};
        mods.forEach(function(m){
            if(!byCat[m.category]) byCat[m.category] = {label:m.category_label, icon:m.category_icon, order:m.category_order, items:[]};
            byCat[m.category].items.push(m);
        });
        var cats = Object.keys(byCat).map(function(k){ return byCat[k]; });
        cats.sort(function(a,b){ return a.order - b.order; });
        cats.forEach(function(cat){
            var head=document.createElement('div');
            head.className='d-cat-head';
            head.innerHTML='<i class="fas '+cat.icon+'"></i>'+cat.label;
            drop.appendChild(head);
            cat.items.forEach(function(m){ drop.appendChild(buildItem(m)); });
        });
        drop.classList.add('open');
    }

    function render(q){
        drop.innerHTML=''; hi=-1;
        q=q.toLowerCase().trim();
        if(!q){
            if(browseMode){ renderGrouped(); } else { drop.classList.remove('open'); }
            return;
        }
        if(browseMode){ browseMode=false; if(browseBtn) browseBtn.classList.remove('is-active'); }
        var hits=mods.filter(function(m){
            return m.label.toLowerCase().includes(q)
                || m.desc.toLowerCase().includes(q)
                || m.category_label.toLowerCase().includes(q);
        });
        if(!hits.length){drop.innerHTML='<div class="d-sr-empty"><i class="fas fa-search"></i>&nbsp;<?= t('dash_search_no_results', [], 'No matching functions found.') ?></div>';drop.classList.add('open');return;}
        hits.forEach(function(m){ drop.appendChild(buildItem(m)); });
        drop.classList.add('open');
    }

    function closeDrop(){
        drop.classList.remove('open');
        browseMode = false;
        if (browseBtn) browseBtn.classList.remove('is-active');
    }

    if (browseBtn) {
        browseBtn.addEventListener('click', function(e){
            e.preventDefault();
            browseMode = !browseMode;
            browseBtn.classList.toggle('is-active', browseMode);
            if (browseMode) { inp.value=''; renderGrouped(); inp.focus(); }
            else { drop.classList.remove('open'); }
        });
    }

    inp.addEventListener('input',function(){render(this.value);});
    inp.addEventListener('keydown',function(e){
        var items=drop.querySelectorAll('.d-sr');
        if(e.key==='ArrowDown'){e.preventDefault();hi=Math.min(hi+1,items.length-1);}
        else if(e.key==='ArrowUp'){e.preventDefault();hi=Math.max(hi-1,0);}
        else if(e.key==='Enter'&&hi>=0){items[hi].click();return;}
        else if(e.key==='Escape'){inp.value='';closeDrop();return;}
        items.forEach(function(el,i){el.classList.toggle('hi',i===hi);});
    });
    document.addEventListener('click',function(e){
        if(!inp.contains(e.target) && !drop.contains(e.target) && !(browseBtn && browseBtn.contains(e.target))) closeDrop();
    });
    document.addEventListener('keydown',function(e){
        if((e.key==='/'||(e.ctrlKey&&e.key==='k'))&&document.activeElement!==inp){e.preventDefault();inp.focus();inp.select();}
    });
})();

</script>
