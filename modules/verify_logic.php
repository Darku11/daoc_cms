<?php
if (!defined('IN_CMS')) { exit; }

$verify_success = false;
$verify_error   = "";

$token = $_GET['token'] ?? $_GET['code'] ?? '';

if (empty($token)) {
    header("Location: index.php");
    exit;
}

$stmt_find = $db->prepare("SELECT id, username FROM users WHERE verify_code = ? AND is_verified = 0 LIMIT 1");
$stmt_find->execute([$token]);
$u = $stmt_find->fetch();

if ($u) {
    $uid   = (int)$u['id'];
    $uname = $u['username'];

    try {
        $db->beginTransaction();

        $req_admin = ($GLOBALS['cms_settings']['admin_approval_required'] ?? '0') === '1';
        $new_status = $req_admin ? 0 : 1;

        $stmt_upd_cms = $db->prepare("UPDATE users SET is_verified = ?, verify_code = NULL WHERE id = ?");
        $stmt_upd_cms->execute([$new_status, $uid]);

        $stmt_upd_dol = $db->prepare("UPDATE account SET Status = 1 WHERE Name = ?");
        $stmt_upd_dol->execute([$uname]);

        aldhran_log('USER_VERIFIED', "User '$uname' verified via email code.", $uid, $uid);

        $db->commit();
        $verify_success = true;

        if (isset($_SESSION['user_id']) && $_SESSION['user_id'] === $uid) {
            $_SESSION['is_verified'] = $new_status;
        }

    } catch (Exception $e) {
        $db->rollBack();
        error_log("Verification Error: " . $e->getMessage());
        $verify_error = t('verify.error_failed', [], 'The ritual of verification failed. Please contact the administrators.');
    }
} else {
    $verify_error = t('verify.error_invalid_link', [], 'The verification link is invalid or the account has already been activated.');
}

$is_logged_in = isset($_SESSION['user_id']);
$redirect_url = $is_logged_in ? 'index.php?msg=verified_success' : 'index.php?p=login&verified=1';
?>

<div class="verify-container">
    <img src="assets/img/logo.png" alt="DAoC CMS Logo" class="verify-logo">
    <h2 class="verify-title"><?= t('verify.title', [], 'Account Verification') ?></h2>
    <div class="verify-status">
        <?php if ($verify_success): ?>
            <div class="verify-status-box verify-status-box--success">
                <i class="fas fa-check-circle"></i><br><br>
                <?= t('verify.success', [], 'Thank you! Your account has been successfully verified.') ?><br>
                <?= t('verify.redirecting', [], 'You will be redirected shortly.') ?>
            </div>
            <script>setTimeout(function(){ window.location.href = '<?= $redirect_url ?>'; }, 3500);</script>
        <?php else: ?>
            <div class="verify-status-box verify-status-box--error">
                <i class="fas fa-exclamation-triangle"></i><br><br>
                <?php echo h($verify_error); ?>
            </div>
        <?php endif; ?>
    </div>
    <a href="<?= $redirect_url ?>" class="btn-verify">
        <?php echo (!$verify_success ? t('verify.btn_return', [], 'Return to Main') : t('verify.btn_continue', [], 'Continue')); ?>
    </a>
</div>