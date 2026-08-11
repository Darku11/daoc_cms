<?php
if (!defined('IN_CMS')) { exit; }
if ($userPriv < 4) { die("Access Denied."); }
?>
<link rel="stylesheet" href="style.php?module=acp_admin">
<?php
// --- IP WHITELISTS ---
if (isset($_POST['approve_ip']) && !empty($_POST['ip_to_approve'])) {
    checkToken($_POST['csrf_token'] ?? '');
    // Validate IP format before storing in DB
    $ip_to_save = trim($_POST['ip_to_approve'] ?? '');
    if (!filter_var($ip_to_save, FILTER_VALIDATE_IP)) {
        // Invalid IP - no entry
        header("Location: " . (defined('IN_ACP') ? 'acp.php?s=admin_ip_audit&msg=invalid_ip' : 'index.php?p=admin_ip_audit&msg=invalid_ip'));
        exit;
    }
    $admin_id   = $_SESSION['user_id']   ?? 0;
    $admin_name = $_SESSION['username']  ?? 'System';

    $stmt_ins = $db->prepare("INSERT IGNORE INTO household_registrations (ip_address, approved_by, reason) VALUES (?, ?, 'Manual GM Approval')");
    if ($stmt_ins->execute([$ip_to_save, $admin_name])) {
        aldhran_log("IP_APPROVED", "GM $admin_name approved IP: $ip_to_save", $admin_id);
        header("Location: " . (defined('IN_ACP') ? 'acp.php?s=admin_ip_audit&msg=approved' : 'index.php?p=admin_ip_audit&msg=approved'));
        exit;
    }
}

// --- DUPLICATE IPs ---
$stmt_audit = $db->query("
    SELECT LastLoginIP, COUNT(Account_ID) as AccountCount, GROUP_CONCAT(Name SEPARATOR ', ') as AccountNames
    FROM account
    WHERE LastLoginIP != '' AND LastLoginIP != '127.0.0.1'
    GROUP BY LastLoginIP
    HAVING AccountCount > 1
");
$results = $stmt_audit->fetchAll();

$whitelisted = [];
if ($results) {
    $ips       = array_column($results, 'LastLoginIP');
    $placeholders = implode(',', array_fill(0, count($ips), '?'));
    $stmt_wl   = $db->prepare("SELECT ip_address, approved_by FROM household_registrations WHERE ip_address IN ($placeholders)");
    $stmt_wl->execute($ips);
    foreach ($stmt_wl->fetchAll() as $row) {
        $whitelisted[$row['ip_address']] = $row['approved_by'];
    }
}
?>

<div class="admin-container">
    <div class="acp-s-2f1ca4ad">
        <h2 class="acp-s-32715235">Household &amp; IP Audit</h2>
        <span class="acp-s-e334cb31">STATUTE 1 ENFORCEMENT</span>
    </div>

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'approved'): ?>
        <div class="msg-success"><i class="fas fa-check-circle"></i> IP successfully approved.</div>
    <?php endif; ?>

    <table class="spawn-table">
        <thead>
            <tr>
                <th>IP Address</th>
                <th>Count</th>
                <th>Detected Accounts</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($results): foreach ($results as $row):
            $ip            = $row['LastLoginIP'];
            $is_registered = isset($whitelisted[$ip]);
        ?>
            <tr>
                <td><code class="acp-s-3af10c1c"><?php echo h($ip); ?></code></td>
                <td class="acp-s-13076d95"><strong class="acp-s-6cb5bd76"><?php echo (int)$row['AccountCount']; ?></strong></td>
                <td><span class="acp-s-8997a2c0"><?php echo h($row['AccountNames']); ?></span></td>
                <td class="<?php echo $is_registered ? 'status-approved' : 'status-violation'; ?>">
                    <?php if ($is_registered): ?>
                        <i class="fas fa-check-shield"></i> APPROVED
                    <?php else: ?>
                        <i class="fas fa-exclamation-triangle"></i> VIOLATION
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!$is_registered): ?>
                    <form method="POST" class="acp-s-5677b988">
                        <input type="hidden" name="csrf_token"   value="<?php echo generateToken(); ?>">
                        <input type="hidden" name="ip_to_approve" value="<?php echo h($ip); ?>">
                        <button type="submit" name="approve_ip" class="btn-gold acp-s-a0c9f93a"
                               >
                            APPROVE IP
                        </button>
                    </form>
                    <?php endif; ?>
                    <a href="acp.php?s=admin_log&q=<?php echo urlencode($ip); ?>" class="acp-s-e7959c7c" >LOGS</a>
                </td>
            </tr>
        <?php endforeach; else: ?>
            <tr>
                <td colspan="5" class="acp-s-f4738be3">No IP overlaps detected.</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
