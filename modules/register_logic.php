<?php
if (!defined('IN_CMS')) exit;

if (!function_exists('isPasswordStrong')) {
    function isPasswordStrong(string $pw): bool {
        return strlen($pw) >= 8
            && preg_match('/[A-Z]/', $pw)
            && preg_match('/[0-9]/', $pw);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_submit'])) {
    checkToken($_POST['csrf_token'] ?? '');

    if (!empty($_POST['website'])) {
        header("Location: index.php?p=register&msg=success"); exit;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    aldhran_rate_limit('register_' . $ip, 3, 3600);

    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password']      ?? '';
    $confirm  = $_POST['confirm_pw']    ?? '';
    $errors   = [];

    $reg_start = (int)($_SESSION['reg_start'] ?? 0);
    if (time() - $reg_start < 3) {
        $errors[] = t('register.error_too_fast', [], 'You filled out the form too fast. Are you a bot?');
    }

    $turnstile_enabled = ($GLOBALS['cms_settings']['turnstile_enabled'] ?? '0') === '1';
    
    if ($turnstile_enabled) {
        $turnstile_response = $_POST['cf-turnstile-response'] ?? '';
        if (empty($turnstile_response)) {
            $errors[] = t('register.error_captcha_missing', [], 'Please complete the security check.');
        } else {
            $turnstile_secret = $GLOBALS['cms_settings']['turnstile_secret'] ?? '';
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://challenges.cloudflare.com/turnstile/v0/siteverify");
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'secret'   => $turnstile_secret,
                'response' => $turnstile_response,
                'remoteip' => $ip
            ]));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $verify_response = curl_exec($ch);
            curl_close($ch);
            
            $turnstile_data = json_decode($verify_response);
            if (!$turnstile_data || !$turnstile_data->success) {
                $errors[] = t('register.error_captcha_failed', [], 'Security check failed. Please try again.');
            }
        }
    }

    $disposable_domains = ['tempmail.com', '10minutemail.com', 'throwawaymail.com', 'mailinator.com', 'guerrillamail.com'];
    $email_domain = strtolower(substr(strrchr($email, "@") ?: '', 1));
    if (in_array($email_domain, $disposable_domains)) {
        $errors[] = t('register.error_disposable_email', [], 'Disposable email addresses are not allowed.');
    }

    if (empty($_POST['privacy_accepted'])) { $errors[] = t('register.error_privacy_required', [], 'You must accept the Privacy Policy to register.'); }
    if (strlen($username) < 3 || strlen($username) > 20)  { $errors[] = t('register.error_username_length', [], 'Username must be 3-20 characters.'); }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))        { $errors[] = t('register.error_invalid_email', [], 'Invalid email address.'); }
    if ($password !== $confirm)                             { $errors[] = t('register.error_password_mismatch', [], 'Passwords do not match.'); }
    if (!isPasswordStrong($password))                      { $errors[] = t('register.error_password_weak', [], 'Password needs 8+ chars, one uppercase and one number.'); }

    if (empty($errors)) {
        $stmt_check = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt_check->execute([$username, $email]);
        if ($stmt_check->fetch()) { $errors[] = t('register.error_taken', [], 'Username or email already taken.'); }
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            $req_email = ($GLOBALS['cms_settings']['email_verification_required'] ?? '0') === '1';
            $req_admin = ($GLOBALS['cms_settings']['admin_approval_required'] ?? '0') === '1';
            $initial_verified = ($req_email || $req_admin) ? 0 : 1;
            
            $verify_code = bin2hex(random_bytes(32));
            $db->prepare("INSERT INTO users (username, email, password, verify_code, priv_level, standing, is_verified, last_ip) VALUES (?, ?, ?, ?, 1, 0, ?, ?)")
               ->execute([$username, $email, aldhran_hash($password), $verify_code, $initial_verified, $ip]);
            
            $new_user_id = (int)$db->lastInsertId();

            if ($req_email) {
                $verifyLink = SITE_URL . "/index.php?p=verify&token=" . $verify_code;
                $subject   = "Welcome to DAoC CMS - Please verify your account";
                $message   = "
                <html><body style='background:#0a0a0a; color:#ccc; font-family:serif; padding:40px;'>
                <div style='max-width:600px; margin:auto; background:#111; border-top:3px solid #d4af37; padding:40px; text-align:center;'>
                    <h2 style='color:#d4af37; font-family:serif;'>Welcome to DAoC CMS, {$username}!</h2>
                    <p>Your account has been created. Please verify your email address.</p>
                    <div style='margin-top:30px;'>
                        <a href='{$verifyLink}' style='background:#d4af37; color:#000; padding:12px 30px; text-decoration:none; font-weight:bold; text-transform:uppercase;'>
                            Verify Email
                        </a>
                    </div>
                </div>
                </body></html>";
                
                if (($GLOBALS['cms_settings']['use_resend_api'] ?? '0') === '1') {
                    aldhran_api_mail($email, $subject, $message);
                } else {
                    aldhran_send_mail($email, $subject, $message);
                }
            }

            $db->commit();

            aldhran_session_regenerate();
            $_SESSION['user_id']       = $new_user_id;
            $_SESSION['username']      = $username;
            $_SESSION['priv_level']    = 1;
            $_SESSION['user_standing'] = 0;
            $_SESSION['is_verified']   = $initial_verified;

            aldhran_log("REGISTER", "New registration & Auto-Login: $username", $new_user_id);

            try {
                $stmt_household = $db->prepare("SELECT 1 FROM household_registrations WHERE ip_address = ? LIMIT 1");
                $stmt_household->execute([$ip]);

                if (!$stmt_household->fetchColumn()) {
                    $stmt_reg_count = $db->prepare("
                        SELECT COUNT(*)
                        FROM users
                        WHERE last_ip = ?
                          AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    ");
                    $stmt_reg_count->execute([$ip]);
                    $registrations_from_ip = (int)$stmt_reg_count->fetchColumn();

                    // Alert once when the threshold is reached. Further registrations
                    // remain visible in the dashboard without generating email floods.
                    if ($registrations_from_ip === 3) {
                        aldhran_log(
                            'REGISTRATION_IP_THRESHOLD',
                            'Three accounts were registered from the same IP within 24 hours',
                            $new_user_id
                        );
                    }
                }
            } catch (Throwable $security_monitor_error) {
                // Account creation has already succeeded; monitoring must not turn
                // that successful registration into a misleading form error.
                error_log('Registration security monitoring failed: ' . $security_monitor_error->getMessage());
            }
            
            header("Location: index.php?msg=welcome_verify"); 
            exit;

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("Register error: " . $e->getMessage());
            $errors[] = t('register.error_generic', [], 'Registration failed. Please try again.');
        }
    }

    $register_errors = $errors;
} else {
    $_SESSION['reg_start'] = time();
}
