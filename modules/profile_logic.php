<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!isset($_SESSION['user_id'])) return;

$uid  = (int)$_SESSION['user_id'];

$stmt_username = $db->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
$stmt_username->execute([$uid]);
$user = $stmt_username->fetchColumn();

if (!$user) return;

// 1. STANDING & VERIFICATION CHECK
$stmt_std = $db->prepare("SELECT standing, is_verified, priv_level FROM users WHERE id = ?");
$stmt_std->execute([$uid]);
$userData = $stmt_std->fetch();

$myStanding    = (int)($userData['standing'] ?? 0);
$is_verified   = (int)($userData['is_verified'] ?? 0);
$myPriv        = (int)($userData['priv_level'] ?? 0);
// The user is set to read-only in the profile if banned OR unverified.
$is_restricted = ($myStanding >= 3 || $is_verified === 0);

// 2. IN-GAME CHARS
$my_chars = daoc_game_characters_for_account($db, $user);

// 3. AVATAR UPLOAD
if (isset($_FILES['avatar']) && !$is_restricted) {
    checkToken($_POST['csrf_token'] ?? '');

    if ($_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        if ($_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
            header("Location: index.php?p=profile&msg=upload_error_" . $_FILES['avatar']['error']);
            exit;
        }
    } else {
        $fileExtension = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (in_array($fileExtension, $allowed)) {
            $mimeType = '';
            if (function_exists('finfo_open')) {
                $finfo = @finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $mimeType = @finfo_file($finfo, $_FILES['avatar']['tmp_name']);
                    @finfo_close($finfo);
                }
            }
            if (empty($mimeType) && function_exists('mime_content_type')) {
                $mimeType = @mime_content_type($_FILES['avatar']['tmp_name']);
            }
            if (empty($mimeType)) {
                $info = @getimagesize($_FILES['avatar']['tmp_name']);
                $mimeType = $info['mime'] ?? '';
            }

            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($mimeType, $allowedMimes)) {
                header("Location: index.php?p=profile&msg=invalid_file");
                exit;
            }

            $uploadDir = 'assets/img/avatars/';
            if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0777, true); }

            $newFile = 'avatar_' . $uid . '_' . time() . '.' . $fileExtension;
            $dest    = $uploadDir . $newFile;

            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $dest)) {
                $stmt_old = $db->prepare("SELECT avatar_url FROM users WHERE id = ?");
                $stmt_old->execute([$uid]);
                $old = $stmt_old->fetch();
                if (!empty($old['avatar_url']) && file_exists($old['avatar_url'])) { @unlink($old['avatar_url']); }

                $db->prepare("UPDATE users SET avatar_url = ? WHERE id = ?")->execute([$dest, $uid]);
                aldhran_log("AVATAR_CHANGE", "User updated avatar", $uid);
                header("Location: index.php?p=profile&msg=success"); exit;
            } else {
                header("Location: index.php?p=profile&msg=move_failed"); exit;
            }
        } else {
            header("Location: index.php?p=profile&msg=invalid_file"); exit;
        }
    }
}

// 4. PROFILE UPDATE
if (isset($_POST['update_profile']) && !$is_restricted) {
    checkToken($_POST['csrf_token'] ?? '');

    $langs  = trim($_POST['u_langs'] ?? '');
    $desc   = trim($_POST['u_desc']  ?? '');
    $sig    = trim($_POST['u_sig']   ?? '');
    $new_pw = $_POST['new_pw'] ?? '';

    try {
        $db->beginTransaction();

        if ($myPriv >= 5) {
            $u_title = trim($_POST['u_title'] ?? '');
            $u_ingame_priv = (int)($_POST['u_ingame_priv'] ?? 1);
            $db->prepare("UPDATE users SET languages = ?, description = ?, forum_signature = ?, user_title = ? WHERE id = ?")
               ->execute([$langs, $desc, $sig, $u_title, $uid]);
            $db->prepare("UPDATE account SET PrivLevel = ? WHERE Name = ?")
               ->execute([$u_ingame_priv, $user]);
        } else {
            $db->prepare("UPDATE users SET languages = ?, description = ?, forum_signature = ? WHERE id = ?")
               ->execute([$langs, $desc, $sig, $uid]);
        }

        if (!empty($new_pw)) {
            if (strlen($new_pw) >= 8) {
                // CMS Hash
                $db->prepare("UPDATE users SET password = ? WHERE id = ?")
                   ->execute([aldhran_hash($new_pw), $uid]);

                // Game account hash: official workaround logic from the C# emulator
                $len = strlen($new_pw);
                $res = "";
                for ($i = 0; $i < $len; $i++) {
                    $res .= chr(ord(substr($new_pw, $i, 1)) >> 8);
                    $res .= chr(ord(substr($new_pw, $i, 1)));
                }

                $hash = strtoupper(md5($res));
                $hashLen = strlen($hash);
                
                for ($i = ($hashLen - 1) & ~1; $i >= 0; $i -= 2) {
                    if (substr($hash, $i, 1) == "0") {
                        $hash = substr($hash, 0, $i) . substr($hash, $i + 1, $hashLen);
                    }
                }

                $dol_final_hash = "##" . $hash;

                $db->prepare("UPDATE account SET Password = ?, Status = 0 WHERE Name = ?")
                   ->execute([$dol_final_hash, $user]);

                aldhran_log("PASSWORD_CHANGE", "User changed account password", $uid);
            } else {
                $db->rollBack();
                header("Location: index.php?p=profile&msg=pw_too_short");
                exit;
            }
        }

        $db->commit();
        header("Location: index.php?p=profile&msg=success"); exit;

    } catch (Exception $e) {
        $db->rollBack();
        error_log("Profile Update Error: " . $e->getMessage());
        header("Location: index.php?p=profile&msg=error"); exit;
    }
}

// 5. AVATAR DELETE
if (isset($_POST['delete_my_avatar']) && !$is_restricted) {
    checkToken($_POST['csrf_token'] ?? '');

    $stmt_old = $db->prepare("SELECT avatar_url FROM users WHERE id = ?");
    $stmt_old->execute([$uid]);
    $old = $stmt_old->fetch();
    if (!empty($old['avatar_url']) && file_exists($old['avatar_url'])) { @unlink($old['avatar_url']); }

    $db->prepare("UPDATE users SET avatar_url = NULL WHERE id = ?")->execute([$uid]);

    aldhran_log("AVATAR_DELETE", "User deleted avatar", $uid);
    header("Location: index.php?p=profile&msg=success"); exit;
}

// 6. 2FA ENABLE / DISABLE LOGIC
require_once(__DIR__ . '/../includes/TOTP.php');

if (isset($_POST['enable_2fa']) && !$is_restricted) {
    checkToken($_POST['csrf_token'] ?? '');
    $code   = trim($_POST['totp_code'] ?? '');
    $secret = $_SESSION['totp_setup_secret'] ?? '';

    if (!empty($secret) && TOTP::verifyCode($secret, $code)) {
        $db->prepare("UPDATE users SET totp_secret = ?, is_2fa_enabled = 1 WHERE id = ?")
           ->execute([$secret, $uid]);
        unset($_SESSION['totp_setup_secret']);
        aldhran_log("2FA_ENABLE", "User enabled 2FA", $uid);
        header("Location: index.php?p=profile&msg=2fa_enabled"); exit;
    } else {
        header("Location: index.php?p=profile&msg=2fa_invalid"); exit;
    }
}

if (isset($_POST['disable_2fa']) && !$is_restricted) {
    checkToken($_POST['csrf_token'] ?? '');
    $confirm_pw = $_POST['confirm_pw'] ?? '';

    $stmt_pw = $db->prepare("SELECT password FROM users WHERE id = ?");
    $stmt_pw->execute([$uid]);
    $user_pw = $stmt_pw->fetchColumn();

    if ($user_pw && aldhran_verify($confirm_pw, $user_pw)) {
        $db->prepare("UPDATE users SET totp_secret = NULL, is_2fa_enabled = 0 WHERE id = ?")->execute([$uid]);
        aldhran_log("2FA_DISABLE", "User disabled 2FA", $uid);
        header("Location: index.php?p=profile&msg=2fa_disabled"); exit;
    } else {
        header("Location: index.php?p=profile&msg=2fa_pw_invalid"); exit;
    }
}

// 7. GDPR ACCOUNT DELETION & LOGGING
if (isset($_POST['delete_my_account']) && !$is_restricted) {
    checkToken($_POST['csrf_token'] ?? '');
    
    if ($myPriv >= 5) {
        aldhran_log("SECURITY_ALERT", "SuperAdmin attempted self-deletion. Blocked.", $uid);
        header("Location: index.php?p=profile&msg=admin_delete_blocked");
        exit;
    }

    $del_pw = $_POST['del_pw'] ?? '';

    // Load current user data for password check and logging
    $stmt_user = $db->prepare("SELECT username, email, password FROM users WHERE id = ?");
    $stmt_user->execute([$uid]);
    $uData = $stmt_user->fetch();

    if ($uData && aldhran_verify($del_pw, $uData['password'])) {
        
        // Collect metadata for the audit log
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $sess_id = session_id();
        $timestamp = date('Y-m-d H:i:s');
        
        $audit_details = sprintf(
            "User: %s | Email: %s | IP: %s | Session: %s | UA: %s | File: profile_logic.php",
            $uData['username'],
            $uData['email'],
            $ip,
            $sess_id,
            $ua
        );

        // LOG: deletion was requested (before execution!)
        aldhran_log("SELF_DELETE_REQUESTED", $audit_details, $uid);

        try {
            $db->beginTransaction();

            // 1. Anonymize forum posts when present.
            $db->prepare("UPDATE spike_posts SET author_id = 0 WHERE author_id = ?")
               ->execute([$uid]);

            // 2. Anonymize characters in the game server database.
            $db->prepare("UPDATE dolcharacters SET AccountName = 'DELETED_USER' WHERE AccountName = ?")
               ->execute([$uData['username']]);

            // 3. Delete game server account
            $db->prepare("DELETE FROM account WHERE Name = ?")
               ->execute([$uData['username']]);

            // 4. Physically delete avatar file, if present
            $stmt_av = $db->prepare("SELECT avatar_url FROM users WHERE id = ?");
            $stmt_av->execute([$uid]);
            $av = $stmt_av->fetchColumn();
            if (!empty($av) && file_exists($av)) {
                @unlink($av);
            }

            // 5. Permanently delete CMS account
            $db->prepare("DELETE FROM users WHERE id = ?")
               ->execute([$uid]);

            $db->commit();

            // LOG: deletion completed successfully
            // (since the user ID no longer exists, log it as null / system-wide)
            aldhran_log("SELF_DELETE_COMPLETED", "Success. " . $audit_details, null, $uid);

            // Destroy session and redirect
            session_unset();
            session_destroy();
            header("Location: login.php?deleted=1");
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            error_log("CRITICAL ERROR during self-deletion for UID $uid: " . $e->getMessage());
            aldhran_log("GDPR_DELETE_FAIL", "Error: " . $e->getMessage() . " | " . $audit_details, $uid);
            header("Location: index.php?p=profile&msg=delete_failed");
            exit;
        }

    } else {
        // Password was incorrect
        aldhran_log("SECURITY_ALERT", "Failed self-deletion attempt (Wrong PW)", $uid);
        header("Location: index.php?p=profile&msg=del_pw_invalid");
        exit;
    }
}
?>
