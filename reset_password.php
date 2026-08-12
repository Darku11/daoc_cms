<?php
// SPDX-License-Identifier: GPL-3.0-only
require_once('includes/db.php');

$error   = "";
$success = false;

// Sanitize the token: allow hexadecimal characters only
$token = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? '');
if (empty($token)) { header("Location: index.php?p=login"); exit; }

// Validate the token: single-use with expiration
$stmt = $db->prepare("SELECT id, username FROM users WHERE reset_token = ? AND reset_expiry > NOW() LIMIT 1");
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    $error = t('reset_password.error_invalid_link', [], 'This reset link is invalid or has expired.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    checkToken($_POST['csrf_token'] ?? '');

    // Rate limit: max 5 attempts per token
    aldhran_rate_limit('pw_reset_submit_' . $token, 5, 3600);

    $new_pass  = $_POST['password']         ?? '';
    $conf_pass = $_POST['confirm_password'] ?? '';

    if (strlen($new_pass) < 8) {
        $error = t('reset_password.error_too_short', [], 'Password too short (min. 8 characters).');
    } elseif ($new_pass !== $conf_pass) {
        $error = t('reset_password.error_mismatch', [], 'The passwords do not match.');
    } else {
        $cms_hash = aldhran_hash($new_pass);

        // Game account sync hash
        $res = "";
        for ($i = 0; $i < strlen($new_pass); $i++) { $res .= $new_pass[$i] . chr(0); }
        $dol_final_hash = "##" . strtoupper(md5($res));

        try {
            $db->beginTransaction();
            $db->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expiry = NULL WHERE id = ?")
               ->execute([$cms_hash, $user['id']]);

            // Game account sync
            $db->prepare("UPDATE account SET Password = ?, Status = 1 WHERE Name = ?")
               ->execute([$dol_final_hash, $user['username']]);

            aldhran_log("PW_RESET_SUCCESS", "Password reset finalized", $user['id']);
            $db->commit();
            $success = true;

        } catch (Exception $e) {
            $db->rollBack();
            error_log("Reset Error: " . $e->getMessage());
            $error = t('reset_password.error_sync_failed', [], 'Synchronization failed. Please contact staff.');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= t('reset_password.heading') ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="container">
    <h2 style="color:#d4af37; text-transform:uppercase; letter-spacing:3px; font-size:1.4em; margin-bottom:30px;">
        <?= t('reset_password.heading') ?>
    </h2>

    <?php if ($success): ?>
        <div style="color:#00ff00; background:rgba(0,255,0,0.05); padding:15px; border:1px solid #060; margin-bottom:20px; font-size:0.9em;">
            <?= t('reset_password.success') ?>
        </div>
        <a href="index.php?p=login" class="btn" style="display:block; text-decoration:none; text-align:center;">
            <?= t('reset_password.btn_login') ?>
        </a>

    <?php elseif ($error && !$user): ?>
        <div style="color:#ff4444; background:rgba(255,0,0,0.05); padding:15px; border:1px solid #600; margin-bottom:20px; font-size:0.8em;">
            <?php echo h($error); ?>
        </div>
        <a href="forgot_password.php" class="btn" style="display:block; text-decoration:none; text-align:center;">
            <?= t('reset_password.btn_new_link') ?>
        </a>

    <?php else: ?>
        <?php if ($error): ?>
            <div style="color:#ff4444; font-size:0.8em; margin-bottom:15px; text-align:left;">
                <?php echo h($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="?token=<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo generateToken(); ?>">

            <div style="text-align:left; margin-bottom:20px;">
                <label style="display:block; font-size:10px; color:#666; text-transform:uppercase; margin-bottom:8px;">
                    <?= t('reset_password.label_password') ?>
                </label>
                <input type="password" name="password" class="reg-input" required
                       placeholder="Min. 8 chars" minlength="8">
            </div>

            <div style="text-align:left; margin-bottom:30px;">
                <label style="display:block; font-size:10px; color:#666; text-transform:uppercase; margin-bottom:8px;">
                    <?= t('reset_password.label_confirm') ?>
                </label>
                <input type="password" name="confirm_password" class="reg-input" required
                       placeholder="Repeat password">
            </div>

            <button type="submit" class="btn"><?= t('reset_password.btn_submit') ?></button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
