<?php
require_once('includes/db.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkToken($_POST['csrf_token'] ?? '');

    // Rate limit: max 3 reset requests per 24h
    aldhran_rate_limit('pw_reset_' . ($_SERVER['REMOTE_ADDR'] ?? '0'), 3, 86400);

    $email = trim($_POST['email'] ?? '');

    // Always return the same response to prevent user enumeration
    $stmt = $db->prepare("SELECT id, username FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        $token   = bin2hex(random_bytes(32));
        // Token expiration: 1 hour
		// Existing token is overwritten, preventing token stacking
        $db->prepare("UPDATE users SET reset_token = ?, reset_expiry = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?")
           ->execute([$token, $user['id']]);

        aldhran_log("PW_RESET_REQUESTED", "Reset link generated", $user['id']);

        $resetLink = SITE_URL . "/reset_password.php?token=" . $token;
        $subject   = "Password Reset - DAoC CMS";
        $message   = "
        <html><body style='background:#0a0a0a; color:#ccc; font-family:serif; padding:40px;'>
        <div style='max-width:600px; margin:auto; background:#111; border-top:3px solid #d4af37; padding:40px; text-align:center;'>
            <h2 style='color:#d4af37; font-family:serif;'>Password Reset</h2>
            <p>A password reset was requested for your account. Click the button below to reset your password.</p>
            <p style='color:#555; font-size:0.85em;'>This link expires in 1 hour. If you did not request this, ignore this email.</p>
            <div style='margin-top:30px;'>
                <a href='{$resetLink}' style='background:#d4af37; color:#000; padding:12px 30px; text-decoration:none; font-weight:bold; text-transform:uppercase;'>
                    Reset Password
                </a>
            </div>
        </div>
        </body></html>";

        if (($GLOBALS['cms_settings']['use_resend_api'] ?? '0') === '1') {
            aldhran_api_mail($email, $subject, $message);
        } else {
            aldhran_send_mail($email, $subject, $message);
        }
    } else {
        // Do not log unknown email addresses to prevent user enumeration
		// Short delay to mitigate timing attacks
        usleep(random_int(100000, 300000));
    }

    // Always redirect to the same page, regardless of whether the user exists
    header("Location: index.php?p=login&reset_sent=1");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forgot Password - DAoC CMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { background:#050505; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; font-family:'Cinzel',serif; }
        .container { max-width:400px; width:90%; background:rgba(10,10,10,0.95); border:1px solid rgba(212,175,55,0.3); padding:40px; text-align:center; border-top:3px solid #d4af37; }
        .reg-input { width:100%; padding:14px; background:rgba(0,0,0,0.6); border:1px solid #222; color:#fff; box-sizing:border-box; }
        .btn { width:100%; padding:16px; background:transparent; border:1px solid #d4af37; color:#d4af37; text-transform:uppercase; letter-spacing:3px; font-weight:bold; cursor:pointer; transition:0.3s; font-family:'Cinzel',serif; }
        .btn:hover { background:#d4af37; color:#000; }
    </style>
</head>
<body>
<div class="container">
    <h2 style="color:#d4af37; text-transform:uppercase; letter-spacing:3px; font-size:1.4em; margin-bottom:30px;">
        <?= t('forgot_password.heading') ?>
    </h2>
    <p style="color:#666; font-size:0.9em; margin-bottom:30px;"><?= t('forgot_password.description') ?></p>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo generateToken(); ?>">
        <div style="text-align:left; margin-bottom:25px;">
            <label style="display:block; font-size:10px; color:#666; text-transform:uppercase; margin-bottom:8px; letter-spacing:2px;">
                <?= t('forgot_password.label_email') ?>
            </label>
            <input type="email" name="email" class="reg-input" required placeholder="name@example.com">
        </div>
        <button type="submit" class="btn"><?= t('forgot_password.btn_submit') ?></button>
    </form>

    <div style="margin-top:30px; font-size:11px;">
        <a href="index.php?p=login" style="color:#444; text-decoration:none; text-transform:uppercase;">
            <?= t('forgot_password.back_to_login') ?>
        </a>
    </div>
</div>
</body>
</html>
