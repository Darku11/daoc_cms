<?php
// SPDX-License-Identifier: GPL-3.0-only
$db_path = dirname(__DIR__) . '/includes/db.php';
if (file_exists($db_path)) { require_once($db_path); }
else { die(t('sync_worker_err_file_not_found', [], "Nexus Bridge Error: File not found at $db_path")); }

$userPriv  = (int)($_SESSION['priv_level'] ?? 0);
$myUserId  = (int)($_SESSION['user_id']    ?? 0);
$adminName = $_SESSION['username'] ?? 'System';

if ($userPriv < 3) { die(t('sync_worker_err_unauthorized', [], 'Security Protocol: Unauthorized.')); }

if (!function_exists('getStandingText')) {
    function getStandingText($level) {
        $texts = [
            0 => t('acp_um_standing_0', [], 'Good'),
            1 => t('acp_um_standing_1', [], 'Warning I'),
            2 => t('acp_um_standing_2', [], 'Warning II'),
            3 => t('acp_um_standing_3', [], 'Restricted'),
            4 => t('acp_um_standing_4', [], 'Suspended'),
            5 => t('acp_um_standing_5', [], 'Banned'),
        ];
        return $texts[(int)$level] ?? t('sync_worker_unknown', [], 'Unknown');
    }
}

function maxManageablePrivLevel(int $userPriv): int {
    if ($userPriv >= 5) return 5;
    if ($userPriv === 4) return 3;
    if ($userPriv === 3) return 2;
    return 0;
}

function canEditTarget(int $userPriv, int $myUserId, int $targetPriv, int $targetId): bool {
    return $targetPriv <= maxManageablePrivLevel($userPriv);
}

function canAssignPrivLevel(int $userPriv, int $newPriv): bool {
    if ($newPriv < 1) return false;
    return $newPriv <= maxManageablePrivLevel($userPriv);
}

function _botTrigger(callable $fn): void {
    if (!isset($GLOBALS['botDispatcher'])) return;
    try { $fn($GLOBALS['botDispatcher']); }
    catch (\Throwable $e) {}
}

if (isset($_POST['um_ajax_search'])) {
    $search = "%" . $_POST['um_ajax_search'] . "%";
    $filter = " AND priv_level <= " . maxManageablePrivLevel($userPriv);
    $stmt = $db->prepare("SELECT id, username, standing FROM users WHERE username LIKE ? $filter LIMIT 8");
    $stmt->execute([$search]);
    while ($u = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sc = ($u['standing'] >= 3) ? 'var(--red)' : ($u['standing'] >= 1 ? 'var(--amber-warn)' : 'var(--parch-muted)');
        echo "<div class='um-sr-item' style='border-left:2px solid ".$sc."' onclick='loadUserEditor(".(int)$u['id'].")'>"
           . "<span class='um-sr-name'>" . htmlspecialchars($u['username']) . "</span>"
           . "<span class='um-sr-stand'>" . getStandingText($u['standing']) . "</span>"
           . "</div>";
    }
    exit;
}

if (isset($_POST['um_load_cat'])) {
    $cat   = $_POST['um_load_cat'];
    $where = "1=1";
    if ($cat == 'restricted') $where = "standing >= 3";
    elseif ($cat == 'warned') $where = "standing BETWEEN 1 AND 2";
    elseif ($cat == 'staff')  $where = "priv_level >= 3";
    $where .= " AND priv_level <= " . maxManageablePrivLevel($userPriv);

    $stmt = $db->query("SELECT id, username, standing, priv_level, forum_posts FROM users WHERE $where ORDER BY id DESC LIMIT 50");
    echo "<table class='um-table'><thead><tr><th>" . t('sync_worker_th_user', [], 'User') . "</th><th>" . t('sync_worker_th_standing', [], 'Standing') . "</th><th>" . t('sync_worker_th_authlvl', [], 'AuthLvl') . "</th><th>" . t('sync_worker_th_posts', [], 'Posts') . "</th><th></th></tr></thead><tbody>";
    while ($u = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sc = ($u['standing'] >= 3) ? 'um-status-restricted' : ($u['standing'] >= 1 ? 'um-status-warn' : 'um-status-good');
        echo "<tr>"
           . "<td>" . htmlspecialchars($u['username']) . "</td>"
           . "<td><span class='" . $sc . "'>" . getStandingText($u['standing']) . "</span></td>"
           . "<td>" . (int)$u['priv_level'] . "</td>"
           . "<td>" . (int)($u['forum_posts'] ?? 0) . "</td>"
           . "<td><button onclick='loadUserEditor(" . (int)$u['id'] . ")' class='um-btn-action'>" . t('btn_edit', [], 'Edit') . "</button></td>"
           . "</tr>";
    }
    echo "</tbody></table>";
    exit;
}

if (isset($_POST['um_ajax_get_editor'])) {
    $id   = (int)$_POST['um_ajax_get_editor'];
    $stmt = $db->prepare("SELECT u.*, a.PrivLevel as ingame_priv FROM users u LEFT JOIN account a ON u.username = a.Name WHERE u.id = ?");
    $stmt->execute([$id]);
    $u_data = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($u_data) {
        if (!canEditTarget($userPriv, $myUserId, (int)$u_data['priv_level'], $id)) {
            die(t('sync_worker_err_access_denied', [], 'Access Denied.'));
        }
        $can_edit = true;
        include(__DIR__ . '/acp_um_editor_view.php');
    }
    exit;
}

if (isset($_POST['um_ajax_get_add_form'])) {
    if ($userPriv < 4) { die(t('sync_worker_err_restricted', [], 'Restricted.')); }
    $can_edit = true;
    $add_view = __DIR__ . '/acp_um_add_user_view.php';
    if (file_exists($add_view)) { include($add_view); }
    else { echo t('sync_worker_err_template_missing', [], 'Template missing: acp_um_add_user_view.php'); }
    exit;
}

if (isset($_POST['um_action']) && $_POST['um_action'] === 'delete_avatar') {
    if ($userPriv < 4) { die(t('sync_worker_err_restricted', [], 'Restricted.')); }
    $target_id = (int)$_POST['target_id'];
    $stmt = $db->prepare("SELECT avatar_url, priv_level FROM users WHERE id = ?");
    $stmt->execute([$target_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !canEditTarget($userPriv, $myUserId, (int)$row['priv_level'], $target_id)) {
        die(t('sync_worker_err_access_denied', [], 'Access Denied.'));
    }
    if ($row && !empty($row['avatar_url'])) {
        $file_path = dirname(__DIR__) . '/' . $row['avatar_url'];
        if (file_exists($file_path)) { unlink($file_path); }
        $db->prepare("UPDATE users SET avatar_url = NULL WHERE id = ?")->execute([$target_id]);
    }
    echo "SUCCESS"; exit;
}

if (isset($_POST['um_action']) && $_POST['um_action'] === 'toggle_verify') {
    if ($userPriv < 4) { die(t('sync_worker_err_restricted', [], 'Restricted.')); }
    $target_id = (int)$_POST['target_id'];
    $status = (int)$_POST['status'];
    
    $stmt = $db->prepare("SELECT username, priv_level FROM users WHERE id = ?");
    $stmt->execute([$target_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($u && !canEditTarget($userPriv, $myUserId, (int)$u['priv_level'], $target_id)) {
        die(t('sync_worker_err_access_denied', [], 'Access Denied.'));
    }
    
    if ($u) {
        $db->prepare("UPDATE users SET is_verified = ?, verify_code = NULL WHERE id = ?")->execute([$status, $target_id]);
        $dol_status = ($status === 1) ? 1 : 0;
        $db->prepare("UPDATE account SET Status = ? WHERE Name = ?")->execute([$dol_status, $u['username']]);
        aldhran_log("USER_VERIFIED_TOGGLE", "Admin set is_verified = $status for {$u['username']}", $myUserId, $target_id);
        echo "SUCCESS";
    } else {
        echo "USER_NOT_FOUND";
    }
    exit;
}

if (isset($_POST['um_action']) && $_POST['um_action'] === 'resend_verification') {
    $target_id = (int)$_POST['target_id'];
    $stmt = $db->prepare("SELECT username, email, priv_level FROM users WHERE id = ?");
    $stmt->execute([$target_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($u && !canEditTarget($userPriv, $myUserId, (int)$u['priv_level'], $target_id)) {
        die(t('sync_worker_err_access_denied', [], 'Access Denied.'));
    }
    if ($u && !empty($u['email'])) {
        $token = bin2hex(random_bytes(32));
        $db->prepare("UPDATE users SET verify_code = ?, is_verified = 0 WHERE id = ?")->execute([$token, $target_id]);
        $verify_url = SITE_URL . '/index.php?p=verify&code=' . $token;
        $subject    = t('sync_worker_verify_email_subject', [], 'Verify your account');
        $message    = t('sync_worker_verify_email_body', ['username' => $u['username'], 'verify_url' => $verify_url], "Hello " . $u['username'] . ",\r\n\r\nPlease verify your account:\r\n" . $verify_url . "\r\n\r\nDAoC CMS");
        $headers    = 'From: noreply@localhost';
        echo mail($u['email'], $subject, $message, $headers) ? "SUCCESS" : "MAIL_ERROR";
    } else { echo "USER_NOT_FOUND"; }
    exit;
}

if (isset($_POST['um_action']) && $_POST['um_action'] === 'delete_user') {
    if ($userPriv < 4) { die(t('sync_worker_err_restricted', [], 'Restricted.')); }
    $target_id = (int)$_POST['target_id'];
    $stmt = $db->prepare("SELECT username, priv_level FROM users WHERE id = ?");
    $stmt->execute([$target_id]);
    $u_data = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($u_data) {
        $targetPriv = (int)$u_data['priv_level'];
        if (!canEditTarget($userPriv, $myUserId, $targetPriv, $target_id)) { die(t('sync_worker_err_access_denied', [], 'Access Denied.')); }
        if ($userPriv >= 5 && $target_id === $myUserId) { die(t('sync_worker_err_superadmin_self_delete', [], 'Super-Admins cannot delete themselves.')); }
        $db->beginTransaction();
        $db->prepare("DELETE FROM account WHERE Name = ?")->execute([$u_data['username']]);
        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$target_id]);
        $db->commit();
        aldhran_log("USER_DELETED", "User {$u_data['username']} deleted by {$adminName}", $myUserId, $target_id);
        _botTrigger(fn($d) => $d->onUserBanned($target_id, 'Account deleted by ' . $adminName, $myUserId));
        echo "SUCCESS";
    }
    exit;
}

if (isset($_POST['um_action']) && $_POST['um_action'] === 'create_user') {
    if ($userPriv < 4) { die(t('sync_worker_err_restricted', [], 'Restricted.')); }
    $u_name = trim($_POST['u_name']);
    $u_pass = $_POST['u_pass'];
    $new_priv = (int)($_POST['u_priv'] ?? 0);
    if (!canAssignPrivLevel($userPriv, $new_priv)) {
        aldhran_log('PRIVILEGE_CHANGE_DENIED', "Blocked new user with PrivLevel $new_priv by {$adminName}", $myUserId);
        die(t('sync_worker_err_access_denied', [], 'Access Denied.'));
    }
    $hash = aldhran_hash($u_pass);
    $db->prepare("INSERT INTO users (username, email, password, priv_level, standing, is_verified) VALUES (?, ?, ?, ?, 0, 1)")
       ->execute([$u_name, trim($_POST['u_email']), $hash, $new_priv]);
    $res = "";
    for ($i = 0; $i < strlen($u_pass); $i++) { $res .= chr(0) . $u_pass[$i]; }
    $game_hash = "##" . strtoupper(md5($res));
    $db->prepare("INSERT INTO account (Name, Password, Status, PrivLevel, CreationDate) VALUES (?, ?, 1, ?, NOW())")
       ->execute([$u_name, $game_hash, $new_priv]);
    aldhran_log("USER_CREATED", "New user $u_name created by {$adminName}", $myUserId);
    echo "SUCCESS"; exit;
}

if (isset($_POST['um_action']) && $_POST['um_action'] === 'update_full') {
    $target_id = (int)$_POST['target_id'];
    $stmt = $db->prepare("SELECT username, avatar_url, priv_level, standing FROM users WHERE id = ?");
    $stmt->execute([$target_id]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$current) { die(t('sync_worker_err_user_not_found', [], 'User not found.')); }
    $targetPriv   = (int)$current['priv_level'];
    $old_standing = (int)$current['standing'];
    if (!canEditTarget($userPriv, $myUserId, $targetPriv, $target_id)) { die(t('sync_worker_err_access_denied', [], 'Access Denied.')); }
    if ($targetPriv >= 5 && $target_id === $myUserId) { die(t('sync_worker_err_superadmin_self_edit', [], 'Super-Admins cannot edit themselves via UM.')); }

    $new_cms_priv = (int)($_POST['u_priv'] ?? $targetPriv);
    if ($userPriv >= 4 && !canAssignPrivLevel($userPriv, $new_cms_priv)) {
        aldhran_log('PRIVILEGE_CHANGE_DENIED', "Blocked PrivLevel change from $targetPriv to $new_cms_priv by {$adminName}", $myUserId, $target_id);
        die(t('sync_worker_err_access_denied', [], 'Access Denied.'));
    }

    if ($userPriv === 3) {
        $new_standing = min(3, max(0, (int)$_POST['u_stand']));
        if ($targetPriv === 2) {
            $db->prepare("UPDATE users SET description = ?, forum_signature = ? WHERE id = ?")
               ->execute([$_POST['u_bio'], $_POST['forum_signature'], $target_id]);
        } else {
            $db->prepare("UPDATE users SET standing = ?, standing_reason = ?, description = ?, forum_signature = ? WHERE id = ?")
               ->execute([$new_standing, $_POST['u_reason'] ?? '', $_POST['u_bio'], $_POST['forum_signature'], $target_id]);
        }
        aldhran_log("USER_UPDATED", "User #$target_id updated by BS3 {$adminName}", $myUserId, $target_id);
        echo "SUCCESS"; exit;
    }

    if (!empty($_POST['u_new_password'])) {
        $new_pw = $_POST['u_new_password'];
        $hash   = aldhran_hash($new_pw);
        $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $target_id]);
        $res = "";
        for ($i = 0; $i < strlen($new_pw); $i++) { $res .= chr(0) . $new_pw[$i]; }
        $game_hash = "##" . strtoupper(md5($res));
        $db->prepare("UPDATE account SET Password = ? WHERE Name = ?")->execute([$game_hash, $current['username']]);
    }

    $new_ingame_priv = (int)$_POST['u_ingame_priv'];
    $db->prepare("UPDATE account SET PrivLevel = ? WHERE Name = ?")->execute([$new_ingame_priv, $current['username']]);

    $avatar_url = $current['avatar_url'];
    if (isset($_FILES['u_avatar']) && $_FILES['u_avatar']['error'] === UPLOAD_ERR_OK) {
        $ext     = pathinfo($_FILES['u_avatar']['name'], PATHINFO_EXTENSION);
        $newFile = 'avatar_' . $target_id . '_' . time() . '.' . $ext;
        if (move_uploaded_file($_FILES['u_avatar']['tmp_name'], '../assets/img/avatars/' . $newFile)) {
            $avatar_url = 'assets/img/avatars/' . $newFile;
        }
    }

    $new_standing = (int)$_POST['u_stand'];
    $db->prepare("UPDATE users SET priv_level = ?, standing = ?, standing_reason = ?, description = ?, user_title = ?, forum_signature = ?, avatar_url = ? WHERE id = ?")
       ->execute([$new_cms_priv, $new_standing, $_POST['u_reason'], $_POST['u_bio'], $_POST['u_title'], $_POST['forum_signature'], $avatar_url, $target_id]);

    aldhran_log("USER_UPDATED", "User #$target_id updated by {$adminName}", $myUserId, $target_id);

    if ($new_standing >= 4 && $old_standing < 4) {
        _botTrigger(fn($d) => $d->onUserBanned($target_id, $_POST['u_reason'] ?? 'No reason given', $myUserId));
    } elseif ($new_standing < 4 && $old_standing >= 4) {
        _botTrigger(fn($d) => $d->onUserUnbanned($target_id, $myUserId));
    }

    echo "SUCCESS"; exit;
}
