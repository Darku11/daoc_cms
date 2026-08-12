<?php
// SPDX-License-Identifier: GPL-3.0-only
require_once('includes/db.php');
require_once('includes/TOTP.php');

function aldhran_check_new_device($user_id, $username, $email) {
    global $db;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $ua_hash = hash('sha256', $ua);

    $stmt = $db->prepare("SELECT id FROM user_known_devices WHERE user_id = ? AND ip_address = ? AND user_agent_hash = ?");
    $stmt->execute([$user_id, $ip, $ua_hash]);
    
    if (!$stmt->fetch()) {
        $db->prepare("INSERT INTO user_known_devices (user_id, ip_address, user_agent_hash) VALUES (?, ?, ?)")
           ->execute([$user_id, $ip, $ua_hash]);
        
        $subject = t(
    'security_alert_new_login_subject',
    [],
    'Security Alert: New Login Detected'
);

$message = sprintf(
    t(
        'security_alert_new_login_message',
        [],
        'Hello %s,<br><br>A new login to your DAoC CMS account has been detected.<br><br><b>IP:</b> %s<br><b>Browser/Device:</b> %s<br><b>Time:</b> %s<br><br>If this was not you, please change your password immediately.'
    ),
    h($username),
    h($ip),
    h($ua),
    date('Y-m-d H:i:s')
);
        aldhran_api_mail($email, $subject, $message);
        
        aldhran_log("NEW_DEVICE_LOGIN", "New device/IP logged: $ip", $user_id);
    } else {
        $db->prepare("UPDATE user_known_devices SET last_login = NOW() WHERE user_id = ? AND ip_address = ? AND user_agent_hash = ?")
           ->execute([$user_id, $ip, $ua_hash]);
    }
}

$error = "";
$info  = "";

if (isset($_GET['pending']))       $info = t('login.info_pending');
if (isset($_GET['verified']))      $info = t('login.info_verified');
if (isset($_GET['reset_sent']))    $info = t('login.info_reset_sent');
if (isset($_GET['reset_success'])) $info = t('login.info_reset_success');

if (isset($_GET['cancel_2fa'])) {
    unset($_SESSION['2fa_pending_uid']);
    unset($_SESSION['2fa_pending_user']);
    unset($_SESSION['2fa_pending_email']);
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkToken($_POST['csrf_token'] ?? '');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (isset($_POST['verify_2fa'])) {
        $pending_uid = (int)($_SESSION['2fa_pending_uid'] ?? 0);
        $totp_code   = trim($_POST['totp_code'] ?? '');

        if ($pending_uid <= 0) {
            $error = t('login.error_invalid');
        } else {
            aldhran_rate_limit('2fa_' . $ip, 5, 300);

            $stmt = $db->prepare("SELECT id, username, priv_level, standing, is_verified, email, totp_secret FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$pending_uid]);
            $u = $stmt->fetch();

            if ($u && TOTP::verifyCode($u['totp_secret'], $totp_code)) {
                aldhran_session_regenerate();

                $_SESSION['user_id']       = (int)$u['id'];
                $_SESSION['username']      = $u['username'];
                $_SESSION['priv_level']    = (int)$u['priv_level'];
                $_SESSION['user_standing'] = (int)$u['standing'];
                $_SESSION['is_verified']   = (int)$u['is_verified'];

                unset($_SESSION['2fa_pending_uid']);
                unset($_SESSION['2fa_pending_user']);
                unset($_SESSION['2fa_pending_email']);

                aldhran_rate_limit_clear('2fa_' . $ip);
                aldhran_rate_limit_clear('login_' . $ip);

                $db->prepare("UPDATE users SET last_ip = ?, last_activity = ? WHERE id = ?")->execute([$ip, time(), $u['id']]);

                aldhran_check_new_device((int)$u['id'], $u['username'], $u['email']);

                aldhran_log("LOGIN_SUCCESS", "User logged in with 2FA", (int)$u['id']);
                header("Location: index.php");
                exit;
            } else {
                aldhran_log("LOGIN_FAILED", "Invalid 2FA code for UID: " . $pending_uid);
                $error = t('login.error_2fa_invalid', [], 'Invalid 2FA authentication code.');
            }
        }
    } 
    else {
        $user_name = trim($_POST['username'] ?? '');
        $pass_raw  = $_POST['password']      ?? '';

        aldhran_rate_limit('login_' . $ip, 5, 600);

        $stmt = $db->prepare("SELECT id, username, password, priv_level, standing, is_verified, is_2fa_enabled, last_ip, last_activity, email FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$user_name]);
        $u = $stmt->fetch();

        if ($u && aldhran_verify($pass_raw, $u['password'])) {
            
            if ((int)$u['standing'] >= 5) {
                $error = t('login.error_suspended', [], 'Your account has been suspended. Contact staff.');
            } else {
                if ((int)$u['is_2fa_enabled'] === 1) {
                    $_SESSION['2fa_pending_uid']  = (int)$u['id'];
                    $_SESSION['2fa_pending_user'] = $u['username'];
                    $_SESSION['2fa_pending_email'] = $u['email'];
                } else {
                    aldhran_session_regenerate();

                    $_SESSION['user_id']       = (int)$u['id'];
                    $_SESSION['username']      = $u['username'];
                    $_SESSION['priv_level']    = (int)$u['priv_level'];
                    $_SESSION['user_standing'] = (int)$u['standing'];
                    $_SESSION['is_verified']   = (int)$u['is_verified'];

                    aldhran_rate_limit_clear('login_' . $ip);

                    $db->prepare("UPDATE users SET last_ip = ?, last_activity = ? WHERE id = ?")->execute([$ip, time(), $u['id']]);

                    aldhran_check_new_device((int)$u['id'], $u['username'], $u['email']);

                    aldhran_log("LOGIN_SUCCESS", "User logged in", (int)$u['id']);
                    header("Location: index.php");
                    exit;
                }
            }
        } else {
            aldhran_log("LOGIN_FAILED", "Failed login for: " . $user_name);
            $error = t('login.error_invalid');
        }
    }
}

$is_2fa_step = !empty($_SESSION['2fa_pending_uid']);
?>

<div class="um-nexus-wrapper" style="max-width:450px; margin:10vh auto;">
    <?php if (!empty($error)): ?>
        <div style="background:rgba(255,0,0,0.1); border:1px solid #ff4444; color:#fff; padding:15px; border-radius:4px; margin-bottom:20px; font-size:0.85em; text-align:center;">
            <i class="fas fa-exclamation-triangle"></i> <?php echo h($error); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($info)): ?>
        <div style="background:rgba(0,212,255,0.1); border:1px solid var(--glow-blue); color:#fff; padding:15px; border-radius:4px; margin-bottom:20px; font-size:0.85em; text-align:center;">
            <i class="fas fa-info-circle"></i> <?php echo h($info); ?>
        </div>
    <?php endif; ?>

    <div class="admin-box" style="border:1px solid rgba(197,160,89,0.1); background:rgba(5,5,5,0.98); padding:40px; border-top:3px solid var(--glow-gold); box-shadow:0 10px 30px rgba(0,0,0,0.5);">
        
        <?php if ($is_2fa_step): ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateToken(); ?>">
                <input type="hidden" name="verify_2fa" value="1">

                <div style="text-align:center; margin-bottom:25px;">
                    <i class="fas fa-shield-alt" style="font-size:2.5em; color:var(--glow-gold); margin-bottom:10px;"></i>
                    <h3 style="color:#fff; font-family:'Cinzel'; margin:0;"><?= t('login.2fa_title', [], 'Two-Factor Authentication') ?></h3>
                    <p style="color:#777; font-size:0.8em; margin-top:5px;">
                        <?= t('login.2fa_desc', [], 'Enter the 6-digit code from your authenticator app for') ?> <strong><?php echo h($_SESSION['2fa_pending_user']); ?></strong>.
                    </p>
                </div>

                <div style="margin-bottom:30px;">
                    <input type="text" name="totp_code" class="um-input-search-glow"
                           style="width:100%; padding:12px; background:#000; color:#fff; border:1px solid #1a1a1a; text-align:center; font-size:1.3em; letter-spacing:5px;"
                           maxlength="6" pattern="\d{6}" required autofocus autocomplete="one-time-code">
                </div>

                <button type="submit" class="btn-gold"
                        style="width:100%; padding:12px; font-family:'Cinzel'; letter-spacing:2px; cursor:pointer;">
                    <?= t('login.btn_verify_2fa', [], 'Verify Code') ?>
                </button>

                <div style="text-align:center; margin-top:15px;">
                    <a href="login.php?cancel_2fa=1"
                       style="color:#555; font-size:0.7em; text-decoration:none; text-transform:uppercase; letter-spacing:1px;">
                        <?= t('login.btn_cancel', [], 'Cancel Login') ?>
                    </a>
                </div>
            </form>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateToken(); ?>">

                <div style="margin-bottom:25px;">
                    <label style="color:#555; font-size:0.65em; letter-spacing:2px; text-transform:uppercase; display:block; margin-bottom:8px;">
                        <?= t('login.label_username') ?>
                    </label>
                    <input type="text" name="username" class="um-input-search-glow"
                           style="width:100%; padding:12px; background:#000; color:#fff; border:1px solid #1a1a1a;" required autofocus>
                </div>

                <div style="margin-bottom:30px;">
                    <label style="color:#555; font-size:0.65em; letter-spacing:2px; text-transform:uppercase; display:block; margin-bottom:8px;">
                        <?= t('login.label_password') ?>
                    </label>
                    <input type="password" name="password" class="um-input-search-glow"
                           style="width:100%; padding:12px; background:#000; color:#fff; border:1px solid #1a1a1a;" required>
                </div>

                <button type="submit" class="btn-gold"
                        style="width:100%; padding:12px; font-family:'Cinzel'; letter-spacing:2px; cursor:pointer;">
                    <?= t('login.btn_submit') ?>
                </button>

                <div style="text-align:right; margin-top:15px;">
                    <a href="forgot_password.php"
                       style="color:#555; font-size:0.7em; text-decoration:none; text-transform:uppercase; letter-spacing:1px; transition:color 0.2s;"
                       onmouseover="this.style.color='var(--glow-gold)'"
                       onmouseout="this.style.color='#555'">
                        <?= t('forgot_password.title') ?>?
                    </a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
