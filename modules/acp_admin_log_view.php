<?php
// SPDX-License-Identifier: GPL-3.0-only
$_acp_auth = (defined('IN_ACP') && isset($userPriv) && $userPriv >= 4);
$_cms_auth = (isset($can_edit) && $can_edit);
if (!$_acp_auth && !$_cms_auth) return;

// ── Filter-Parameter ──────────────────────────────────────────
$f_user   = trim($_GET['f_user']   ?? '');
$f_action = trim($_GET['f_action'] ?? '');
$f_detail = trim($_GET['f_detail'] ?? '');
$f_from   = trim($_GET['f_from']   ?? '');
$f_to     = trim($_GET['f_to']     ?? '');

$limit  = 10;
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$base_url = defined('IN_ACP') ? 'acp.php?s=admin_log' : 'index.php?p=admin_log';

$filter_params = http_build_query(array_filter([
    'f_user'   => $f_user,
    'f_action' => $f_action,
    'f_detail' => $f_detail,
    'f_from'   => $f_from,
    'f_to'     => $f_to,
]));
$paginate_url = $base_url . ($filter_params ? '&' . $filter_params : '');

// ── Query bauen ───────────────────────────────────────────────
$named_params = [];
$where_named  = [];

if ($f_user !== '') {
    $where_named[]           = "u.username LIKE :f_user";
    $named_params[':f_user'] = '%' . $f_user . '%';
}
if ($f_action !== '') {
    $where_named[]             = "al.action_type LIKE :f_action";
    $named_params[':f_action'] = '%' . $f_action . '%';
}
if ($f_detail !== '') {
    $where_named[]             = "al.details LIKE :f_detail";
    $named_params[':f_detail'] = '%' . $f_detail . '%';
}
if ($f_from !== '') {
    $where_named[]           = "al.created_at >= :f_from";
    $named_params[':f_from'] = $f_from . ' 00:00:00';
}
if ($f_to !== '') {
    $where_named[]         = "al.created_at <= :f_to";
    $named_params[':f_to'] = $f_to . ' 23:59:59';
}

$where_sql = $where_named ? 'WHERE ' . implode(' AND ', $where_named) : '';

$stmt_count = $db->prepare("
    SELECT COUNT(al.id)
    FROM aldhran_logs al
    LEFT JOIN users u ON al.user_id = u.id
    $where_sql
");
$stmt_count->execute($named_params);
$total_rows  = (int)$stmt_count->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $limit));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $limit;

$stmt_logs = $db->prepare("
    SELECT al.*, u.username
    FROM aldhran_logs al
    LEFT JOIN users u ON al.user_id = u.id
    $where_sql
    ORDER BY al.id DESC
    LIMIT :limit OFFSET :offset
");
foreach ($named_params as $key => $val) {
    $stmt_logs->bindValue($key, $val);
}
$stmt_logs->bindValue(':limit',  $limit,  PDO::PARAM_INT);
$stmt_logs->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt_logs->execute();
$logs = $stmt_logs->fetchAll();

$has_filter = ($f_user || $f_action || $f_detail || $f_from || $f_to);

// ── Helpers ───────────────────────────────────────────────────
function acp_log_pagination(int $current, int $total, string $base, int $total_rows): string {
    if ($total <= 1) return '';
    $out   = '<div class="acp-pagination">';
    $range = 2;
    if ($current > 1)
        $out .= '<a href="' . $base . '&page=' . ($current - 1) . '" class="acp-page-btn">&laquo;</a>';
    else
        $out .= '<span class="acp-page-btn disabled">&laquo;</span>';
    if ($current > $range + 2) {
        $out .= '<a href="' . $base . '&page=1" class="acp-page-btn">1</a>';
        $out .= '<span class="acp-page-ellipsis">…</span>';
    }
    for ($i = max(1, $current - $range); $i <= min($total, $current + $range); $i++) {
        $out .= $i === $current
            ? '<span class="acp-page-btn active">' . $i . '</span>'
            : '<a href="' . $base . '&page=' . $i . '" class="acp-page-btn">' . $i . '</a>';
    }
    if ($current < $total - $range - 1) {
        $out .= '<span class="acp-page-ellipsis">…</span>';
        $out .= '<a href="' . $base . '&page=' . $total . '" class="acp-page-btn">' . $total . '</a>';
    }
    if ($current < $total)
        $out .= '<a href="' . $base . '&page=' . ($current + 1) . '" class="acp-page-btn">&raquo;</a>';
    else
        $out .= '<span class="acp-page-btn disabled">&raquo;</span>';
    $out .= '<span class="acp-page-info">' . $current . ' / ' . $total . ' &nbsp;·&nbsp; ' . number_format($total_rows) . ' entries</span>';
    $out .= '</div>';
    return $out;
}

function log_badge_color(string $action): string {
    if (str_contains($action, 'LOGIN'))       return 'rgba(50,150,50,0.15)';
    if (str_contains($action, 'LOGOUT'))      return 'rgba(100,100,100,0.15)';
    if (str_contains($action, 'BAN') || str_contains($action, 'KICK') || str_contains($action, 'SUSPEND') || str_contains($action, 'FAIL') || str_contains($action, 'ERROR')) return 'rgba(180,50,50,0.15)';
    if (str_contains($action, 'MAINTENANCE')) return 'rgba(197,160,89,0.12)';
    if (str_contains($action, 'EDIT') || str_contains($action, 'UPDATE')) return 'rgba(50,100,180,0.15)';
    if (str_contains($action, 'DELETE'))      return 'rgba(180,80,50,0.15)';
    return 'rgba(60,60,60,0.3)';
}
function log_badge_text(string $action): string {
    if (str_contains($action, 'LOGIN'))       return 'rgba(80,200,80,0.7)';
    if (str_contains($action, 'LOGOUT'))      return '#555';
    if (str_contains($action, 'BAN') || str_contains($action, 'KICK') || str_contains($action, 'SUSPEND') || str_contains($action, 'FAIL') || str_contains($action, 'ERROR')) return 'rgba(220,80,80,0.8)';
    if (str_contains($action, 'MAINTENANCE')) return 'rgba(197,160,89,0.8)';
    if (str_contains($action, 'EDIT') || str_contains($action, 'UPDATE')) return 'rgba(80,130,220,0.8)';
    if (str_contains($action, 'DELETE'))      return 'rgba(220,120,80,0.8)';
    return '#555';
}
?>

<!-- ── Header ── -->
<div class="acp-s-cc5279e3">
    <div class="acp-s-8e160d03">
        <div>
            <div class="acp-s-4d9c6cc3">Audit Trail</div>
            <div class="acp-s-cb49e237"><?php echo number_format($total_rows); ?> entries<?php echo $has_filter ? ' (filtered)' : ''; ?></div>
        </div>
        <?php if ($has_filter): ?>
            <span class="log-filter-active-badge">FILTER ACTIVE</span>
        <?php endif; ?>
    </div>
    <span class="acp-s-89044e12">AUDIT LOG</span>
</div>

<?php if (!empty($has_critical_error)): ?>
<div class="acp-s-984277dd">
    <div>
        <i class="fas fa-exclamation-triangle acp-s-b87c6413"></i>
        <strong><?= t('admin_log.critical_title', [], 'Critical Error Logged!') ?></strong> 
        <?= t('admin_log.critical_desc', [], 'Please review the recent FAIL or ERROR entries below.') ?>
    </div>
    <a href="<?php echo $base_url; ?>&dismiss_critical_errors=1&csrf_token=<?php echo $log_csrf_token; ?>" class="acp-s-eb0ab53d" onmouseover="this.style.background='rgba(224,112,112,0.1)'" onmouseout="this.style.background='transparent'">
        <i class="fas fa-check"></i> <?= t('admin_log.btn_acknowledge', [], 'Acknowledge') ?>
    </a>
</div>
<?php endif; ?>

<!-- ── Filter Form ── -->
<form method="GET" action="<?php echo defined('IN_ACP') ? 'acp.php' : 'index.php'; ?>">
    <?php if (defined('IN_ACP')): ?>
        <input type="hidden" name="s" value="admin_log">
    <?php else: ?>
        <input type="hidden" name="p" value="admin_log">
    <?php endif; ?>

    <div class="log-filter-form">
        <div>
            <label class="log-filter-label">User</label>
            <input type="text" name="f_user" class="log-filter-input"
                   placeholder="Username..." value="<?php echo h($f_user); ?>">
        </div>
        <div>
            <label class="log-filter-label">Action</label>
            <input type="text" name="f_action" class="log-filter-input"
                   placeholder="LOGIN, BAN..." value="<?php echo h($f_action); ?>">
        </div>
        <div>
            <label class="log-filter-label">Details</label>
            <input type="text" name="f_detail" class="log-filter-input"
                   placeholder="Search details..." value="<?php echo h($f_detail); ?>">
        </div>
        <div>
            <label class="log-filter-label">From</label>
            <input type="date" name="f_from" class="log-filter-input"
                   value="<?php echo h($f_from); ?>">
        </div>
        <div>
            <label class="log-filter-label">To</label>
            <input type="date" name="f_to" class="log-filter-input"
                   value="<?php echo h($f_to); ?>">
        </div>
        <div class="acp-s-99defc28">
            <button type="submit" class="log-filter-btn">
                <i class="fas fa-search"></i> Filter
            </button>
            <?php if ($has_filter): ?>
                <a href="<?php echo $base_url; ?>" class="log-filter-reset">
                    <i class="fas fa-times"></i> Reset
                </a>
            <?php endif; ?>
        </div>
    </div>
</form>

<!-- ── Table ── -->
<div class="acp-s-4258e182">
    <table class="log-table">
        <thead>
            <tr>
                <th class="acp-s-9ab095ba">#</th>
                <th class="acp-s-729192c0">Actor</th>
                <th class="acp-s-8f77872c">Action</th>
                <th>Details</th>
                <th class="acp-s-244638f5">Timestamp</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($logs): foreach ($logs as $l):
                $bg   = log_badge_color($l['action_type']);
                $text = log_badge_text($l['action_type']);
            ?>
            <tr>
                <td><span class="log-id">#<?php echo $l['id']; ?></span></td>
                <td><span class="log-actor"><?php echo h($l['username'] ?? 'System'); ?></span></td>
                <td>
                    <span class="log-badge" style="background:<?php echo $bg; ?>; color:<?php echo $text; ?>;">
                        <?php echo h($l['action_type']); ?>
                    </span>
                </td>
                <td>
                    <?php if ($l['target_id']): ?>
                        <div class="log-target">Target ID: <?php echo (int)$l['target_id']; ?></div>
                    <?php endif; ?>
                    <div class="log-details"><?php echo h($l['details']); ?></div>
                    <div class="log-ip"><i class="fas fa-globe acp-s-5e64d3a2"></i><?php echo h($l['ip_address']); ?></div>
                </td>
                <td><span class="log-ts"><?php echo h($l['created_at']); ?></span></td>
            </tr>
            <?php endforeach; else: ?>
            <tr>
                <td colspan="5" class="acp-s-c3d15726">
                    <?php echo $has_filter ? 'NO ENTRIES MATCH YOUR FILTER.' : 'THE CHRONICLES ARE SILENT.'; ?>
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php echo acp_log_pagination($page, $total_pages, $paginate_url, $total_rows); ?>