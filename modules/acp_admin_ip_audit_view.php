<?php
if (!defined('IN_ACP')) exit;
if ($userPriv < 4) return;

// ── 1. IP Approve ─────────────────────────────────────────────
if (isset($_POST['approve_ip']) && !empty($_POST['ip_to_approve'])) {
    checkToken($_POST['csrf_token'] ?? '');
    $ip_to_save = trim($_POST['ip_to_approve'] ?? '');
    if (!filter_var($ip_to_save, FILTER_VALIDATE_IP)) {
        header("Location: " . (defined('IN_ACP') ? 'acp.php?s=admin_ip_audit&msg=invalid_ip' : 'index.php?p=admin_ip_audit&msg=invalid_ip'));
        exit;
    }
    $admin_id   = $_SESSION['user_id']   ?? 0;
    $admin_name = $_SESSION['username']  ?? 'System';

    $stmt_ins = $db->prepare("INSERT IGNORE INTO household_registrations (ip_address, approved_by, reason) VALUES (?, ?, ?)");
    if ($stmt_ins->execute([$ip_to_save, $admin_name, t('ipa_reason_manual_approval', [], 'Manual GM Approval')])) {
        aldhran_log("IP_APPROVED", "GM $admin_name approved IP: $ip_to_save", $admin_id);
        header("Location: acp.php?s=admin_ip_audit&msg=approved");
        exit;
    }
}

// ── 1.1 IP Reject / Standing 3 Enforcement ────────────────────
if (isset($_POST['reject_ip']) && !empty($_POST['ip_to_reject'])) {
    checkToken($_POST['csrf_token'] ?? '');
    $ip_to_reject = trim($_POST['ip_to_reject'] ?? '');
    if (!filter_var($ip_to_reject, FILTER_VALIDATE_IP)) {
        header("Location: acp.php?s=admin_ip_audit&msg=invalid_ip");
        exit;
    }
    $admin_id   = $_SESSION['user_id']   ?? 0;
    $admin_name = $_SESSION['username']  ?? 'System';

    $stmt_acc = $db->prepare("SELECT Name FROM account WHERE LastLoginIP = ?");
    $stmt_acc->execute([$ip_to_reject]);
    $accs = $stmt_acc->fetchAll(PDO::FETCH_COLUMN);

    if ($accs) {
        $inQuery = implode(',', array_fill(0, count($accs), '?'));
        // NOTE: users.standing is a string enum (Active/Suspended/Warning/Restricted)
        // elsewhere in the codebase (see acp_admin_user_manager_logic.php). Writing a
        // raw integer here would corrupt that column, so we map to 'Restricted'.
        $params = array_merge(['Restricted', t('ipa_reason_reject', [], 'Dual Logging Violation - Unconfirmed IP Audit')], $accs);
        $db->prepare("UPDATE users SET standing = ?, standing_reason = ? WHERE username IN ($inQuery)")->execute($params);

        aldhran_log("IP_REJECTED", "GM $admin_name rejected IP $ip_to_reject and set " . count($accs) . " accounts to Restricted standing", $admin_id);
    }
    header("Location: acp.php?s=admin_ip_audit&msg=rejected");
    exit;
}

// ── 2. Load IP overlaps ──────────────────────────────────────
$stmt_audit = $db->query("
    SELECT LastLoginIP,
           COUNT(Account_ID) AS AccountCount,
           GROUP_CONCAT(Name ORDER BY Name SEPARATOR ', ') AS AccountNames
    FROM account
    WHERE LastLoginIP != '' AND LastLoginIP != '127.0.0.1'
    GROUP BY LastLoginIP
    HAVING AccountCount > 1
    ORDER BY AccountCount DESC
");
$results = $stmt_audit->fetchAll(PDO::FETCH_ASSOC);

$approved_ips = [];
if ($results) {
    $all_ips  = array_column($results, 'LastLoginIP');
    $phs      = implode(',', array_fill(0, count($all_ips), '?'));
    $stmt_reg = $db->prepare("SELECT ip_address, approved_by FROM household_registrations WHERE ip_address IN ($phs)");
    $stmt_reg->execute($all_ips);
    foreach ($stmt_reg->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $approved_ips[$r['ip_address']] = $r['approved_by'];
    }
}

$csrf         = generateToken();
$total        = count($results);
$violations   = 0;
$approved_cnt = 0;
foreach ($results as $row) {
    if (isset($approved_ips[$row['LastLoginIP']])) $approved_cnt++;
    else $violations++;
}
?>

<!-- Summary Strip -->
<div class="ipa-summary">
    <div class="ipa-sum-item">
        <div class="ipa-sum-dot acp-s-01662d30"></div>
        <span class="ipa-sum-val acp-s-2a05ad1f"><?= $total ?></span>
        <span class="ipa-sum-lbl"><?= t('ipa_shared_ips', [], 'Shared IPs') ?></span>
    </div>
    <div class="ipa-sum-item">
        <div class="ipa-sum-dot acp-s-2485ee01"></div>
        <span class="ipa-sum-val acp-s-4bfd80bc"><?= $approved_cnt ?></span>
        <span class="ipa-sum-lbl"><?= t('ipa_approved', [], 'Approved') ?></span>
    </div>
    <div class="ipa-sum-item">
        <div class="ipa-sum-dot acp-s-bd048893"></div>
        <span class="ipa-sum-val acp-s-e0936291"><?= $violations ?></span>
        <span class="ipa-sum-lbl"><?= t('ipa_violations', [], 'Violations') ?></span>
    </div>
    <div class="ipa-sum-item acp-s-13598fc1">
        <span class="acp-s-e0726836">
            <?= t('ipa_statute_desc', [], 'Accounts sharing the same IP') ?>
        </span>
    </div>
</div>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'approved'): ?>
<div class="acp-msg-success acp-s-8e86974b">
    <i class="fas fa-check-circle"></i> <?= t('ipa_msg_approved', [], 'IP approved and registered successfully.') ?>
</div>
<?php endif; ?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'rejected'): ?>
<div class="acp-msg-success acp-s-3dd4ae69">
    <i class="fas fa-gavel"></i> <?= t('ipa_msg_rejected', [], 'IP rejected and affected accounts restricted to Standing 3.') ?>
</div>
<?php endif; ?>

<!-- Table -->
<div class="acp-s-8a1f98dd">
    <?php if (empty($results)): ?>
    <div class="ipa-empty">
        <i class="fas fa-shield-alt acp-s-6d4039ea"></i>
        <?= t('ipa_no_overlaps', [], 'No IP overlaps detected') ?>
    </div>
    <?php else: ?>
    <table class="ipa-table">
        <thead>
            <tr>
                <th><?= t('ipa_th_ip', [], 'IP Address') ?></th>
                <th><?= t('ipa_th_count', [], 'Count') ?></th>
                <th><?= t('ipa_th_detected_accounts', [], 'Detected Accounts') ?></th>
                <th><?= t('ipa_th_status', [], 'Status') ?></th>
                <th class="acp-s-f6e3d7fe"><?= t('ipa_th_actions', [], 'Actions') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($results as $row):
            $ip           = $row['LastLoginIP'];
            $is_approved  = isset($approved_ips[$ip]);
            $approved_by  = $approved_ips[$ip] ?? null;
            $acc_count    = (int)$row['AccountCount'];
            $acc_names    = explode(', ', $row['AccountNames']);
        ?>
        <tr>
            <!-- IP -->
            <td>
                <div class="ipa-ip"><?= h($ip) ?></div>
                <div class="ipa-ip-count"><?= $acc_count ?> <?= $acc_count !== 1 ? t('ipa_accounts_plural', [], 'accounts') : t('ipa_account_singular', [], 'account') ?></div>
            </td>

            <!-- Count -->
            <td>
                <span style="font-family:'Cinzel',serif; font-size:1em;
                      color:<?= $acc_count >= 3 ? 'var(--red)' : ($acc_count == 2 ? 'var(--amber-warn)' : 'var(--parch-dim)') ?>;">
                    <?= $acc_count ?>
                </span>
            </td>

            <!-- Accounts -->
            <td>
                <div class="ipa-accounts">
                    <?php foreach (array_slice($acc_names, 0, 6) as $acc): ?>
                    <span class="ipa-account-tag ipa-account-tag--multi">
                        <?= h(trim($acc)) ?>
                    </span>
                    <?php endforeach; ?>
                    <?php if (count($acc_names) > 6): ?>
                    <span class="ipa-account-tag acp-s-440923ce">
                        +<?= count($acc_names) - 6 ?> <?= t('ipa_more', [], 'more') ?>
                    </span>
                    <?php endif; ?>
                </div>
            </td>

            <!-- Status -->
            <td>
                <?php if ($is_approved): ?>
                <span class="ipa-status acp-s-66ae0f2a"
                     >
                    <i class="fas fa-check acp-s-39a2a027"></i> <?= t('ipa_status_approved', [], 'Approved') ?>
                </span>
                <?php if ($approved_by): ?>
                <div class="acp-s-d178f385">
                    <?= t('ipa_by', [], 'by') ?> <?= h($approved_by) ?>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <span class="ipa-status acp-s-2dc22f33"
                     >
                    <i class="fas fa-exclamation-triangle acp-s-39a2a027"></i> <?= t('ipa_status_violation', [], 'Violation') ?>
                </span>
                <?php endif; ?>
            </td>

            <!-- Actions -->
            <td class="acp-s-f6e3d7fe">
                <div class="ipa-actions acp-s-36b745ae">
                    <?php if (!$is_approved): ?>
                    <form method="POST" class="acp-s-bfa03e44">
                        <input type="hidden" name="csrf_token"   value="<?= $csrf ?>">
                        <input type="hidden" name="ip_to_approve" value="<?= h($ip) ?>">
                        <input type="hidden" name="ip_to_reject"  value="<?= h($ip) ?>">
                        <button type="submit" name="approve_ip" class="ipa-action-btn acp-s-341d31cc"

                                onmouseover="this.style.borderColor='var(--green)'"
                                onmouseout="this.style.borderColor='rgba(106,170,112,0.3)'">
                            <i class="fas fa-check"></i> <?= t('btn_approve', [], 'Approve') ?>
                        </button>
                        <button type="submit" name="reject_ip" class="ipa-action-btn acp-s-cddb49fe"

                                onmouseover="this.style.borderColor='var(--red)'"
                                onmouseout="this.style.borderColor='rgba(184,80,80,0.3)'">
                            <i class="fas fa-ban"></i> <?= t('btn_reject', [], 'Reject') ?>
                        </button>
                    </form>
                    <?php endif; ?>
                    <a href="acp.php?s=admin_log&q=<?= urlencode($ip) ?>" class="ipa-logs-link">
                        <i class="fas fa-history acp-s-831b94f4"></i><?= t('btn_logs', [], 'Logs') ?>
                    </a>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>