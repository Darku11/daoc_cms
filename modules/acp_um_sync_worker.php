<?php
// SPDX-License-Identifier: GPL-3.0-only
$db_path = dirname(__DIR__) . '/includes/db.php';
if (file_exists($db_path)) { require_once $db_path; }
else { http_response_code(500); exit('Nexus Bridge Error: database bootstrap not found.'); }

$userPriv  = (int)($_SESSION['priv_level'] ?? 0);
$myUserId  = (int)($_SESSION['user_id'] ?? 0);
$adminName = (string)($_SESSION['username'] ?? 'System');

if ($myUserId <= 0 || $userPriv < 3) {
    http_response_code(403);
    exit(t('sync_worker_err_unauthorized', [], 'Security Protocol: Unauthorized.'));
}

// The User Manager exposes AJAX calls directly to this worker. A normal CMS
// login is not enough for account administration: require a fresh ACP re-auth.
$acpAuthedAt = (int)($_SESSION['acp_authed_at'] ?? 0);
if ($acpAuthedAt <= 0 || (time() - $acpAuthedAt) >= 1800) {
    http_response_code(403);
    exit(t('acp.reauth_required', [], 'ACP re-authentication required.'));
}
$_SESSION['acp_authed_at'] = time();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}
checkToken($_POST['csrf_token'] ?? '');

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

function maxManageableStanding(int $userPriv): int {
    if ($userPriv >= 5) return 5;
    if ($userPriv >= 4) return 4;
    if ($userPriv === 3) return 3;
    return 0;
}

function canEditTarget(int $userPriv, int $myUserId, int $targetPriv, int $targetId): bool {
    return $targetPriv <= maxManageablePrivLevel($userPriv);
}

function canAssignPrivLevel(int $userPriv, int $newPriv): bool {
    return $newPriv >= 1 && $newPriv <= maxManageablePrivLevel($userPriv);
}

function _botTrigger(callable $fn): void {
    if (!isset($GLOBALS['botDispatcher'])) return;
    try { $fn($GLOBALS['botDispatcher']); }
    catch (\Throwable $e) { error_log('Bot event dispatch failed: ' . $e->getMessage()); }
}

function umAvatarPath(?string $relative): ?string {
    $relative = ltrim(str_replace('\\', '/', (string)$relative), '/');
    if ($relative === '' || !preg_match('#^assets/img/avatars/[A-Za-z0-9._-]+$#', $relative)) return null;
    return dirname(__DIR__) . '/' . $relative;
}

function umDeleteAvatarFile(?string $relative): void {
    $path = umAvatarPath($relative);
    if ($path && is_file($path)) @unlink($path);
}

function umStoreAvatar(array $file, int $targetId): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) return null;
    if ((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > 5 * 1024 * 1024) return null;

    $info = @getimagesize($file['tmp_name']);
    $type = $info[2] ?? 0;
    $extMap = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    if (!isset($extMap[$type])) return null;

    $dir = dirname(__DIR__) . '/assets/img/avatars';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return null;

    $name = 'avatar_' . $targetId . '_' . bin2hex(random_bytes(8)) . '.' . $extMap[$type];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) return null;
    return 'assets/img/avatars/' . $name;
}

if (isset($_POST['um_ajax_search'])) {
    $search = '%' . trim((string)$_POST['um_ajax_search']) . '%';
    $maxPriv = maxManageablePrivLevel($userPriv);
    $stmt = $db->prepare("SELECT id, username, standing FROM users WHERE username LIKE ? AND priv_level <= ? LIMIT 8");
    $stmt->execute([$search, $maxPriv]);
    while ($u = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sc = ((int)$u['standing'] >= 3) ? 'var(--red)' : (((int)$u['standing'] >= 1) ? 'var(--amber-warn)' : 'var(--parch-muted)');
        echo "<div class='um-sr-item' style='border-left:2px solid " . h($sc) . "' onclick='loadUserEditor(" . (int)$u['id'] . ")'>"
           . "<span class='um-sr-name'>" . h($u['username']) . "</span>"
           . "<span class='um-sr-stand'>" . h(getStandingText($u['standing'])) . "</span>"
           . "</div>";
    }
    exit;
}

if (isset($_POST['um_load_cat'])) {
    $cat = (string)$_POST['um_load_cat'];
    $where = '1=1';
    if ($cat === 'restricted') $where = 'standing >= 3';
    elseif ($cat === 'warned') $where = 'standing BETWEEN 1 AND 2';
    elseif ($cat === 'staff') $where = 'priv_level >= 3';

    $stmt = $db->prepare("SELECT id, username, standing, priv_level, forum_posts FROM users WHERE $where AND priv_level <= ? ORDER BY id DESC LIMIT 50");
    $stmt->execute([maxManageablePrivLevel($userPriv)]);
    echo "<table class='um-table'><thead><tr><th>" . t('sync_worker_th_user', [], 'User') . "</th><th>" . t('sync_worker_th_standing', [], 'Standing') . "</th><th>" . t('sync_worker_th_authlvl', [], 'AuthLvl') . "</th><th>" . t('sync_worker_th_posts', [], 'Posts') . "</th><th></th></tr></thead><tbody>";
    while ($u = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sc = ((int)$u['standing'] >= 3) ? 'um-status-restricted' : (((int)$u['standing'] >= 1) ? 'um-status-warn' : 'um-status-good');
        echo "<tr><td>" . h($u['username']) . "</td><td><span class='" . $sc . "'>" . h(getStandingText($u['standing'])) . "</span></td><td>" . (int)$u['priv_level'] . "</td><td>" . (int)($u['forum_posts'] ?? 0) . "</td><td><button onclick='loadUserEditor(" . (int)$u['id'] . ")' class='um-btn-action'>" . t('btn_edit', [], 'Edit') . "</button></td></tr>";
    }
    echo '</tbody></table>';
    exit;
}

if (isset($_POST['um_ajax_get_editor'])) {
    $id = (int)$_POST['um_ajax_get_editor'];
    $stmt = $db->prepare("SELECT u.*, a.PrivLevel AS ingame_priv FROM users u LEFT JOIN account a ON u.username = a.Name WHERE u.id = ?");
    $stmt->execute([$id]);
    $u_data = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($u_data) {
        if (!canEditTarget($userPriv, $myUserId, (int)$u_data['priv_level'], $id)) exit(t('sync_worker_err_access_denied', [], 'Access Denied.'));
        $can_edit = true;
        include __DIR__ . '/acp_um_editor_view.php';
    }
    exit;
}

if (isset($_POST['um_ajax_get_add_form'])) {
    if ($userPriv < 4) exit(t('sync_worker_err_restricted', [], 'Restricted.'));
    $can_edit = true;
    include __DIR__ . '/acp_um_add_user_view.php';
    exit;
}

$action = (string)($_POST['um_action'] ?? '');
if ($action === '') exit('UNKNOWN_ACTION');

if ($action === 'delete_avatar') {
    if ($userPriv < 4) exit(t('sync_worker_err_restricted', [], 'Restricted.'));
    $targetId = (int)($_POST['target_id'] ?? 0);
    $stmt = $db->prepare("SELECT avatar_url, priv_level FROM users WHERE id = ?");
    $stmt->execute([$targetId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) exit('USER_NOT_FOUND');
    if (!canEditTarget($userPriv, $myUserId, (int)$row['priv_level'], $targetId)) exit(t('sync_worker_err_access_denied', [], 'Access Denied.'));
    umDeleteAvatarFile($row['avatar_url'] ?? null);
    $db->prepare("UPDATE users SET avatar_url = NULL WHERE id = ?")->execute([$targetId]);
    aldhran_log('AVATAR_DELETE', "Admin removed avatar for user #$targetId", $myUserId, $targetId);
    exit('SUCCESS');
}

if ($action === 'toggle_verify') {
    if ($userPriv < 4) exit(t('sync_worker_err_restricted', [], 'Restricted.'));
    $targetId = (int)($_POST['target_id'] ?? 0);
    $status = ((int)($_POST['status'] ?? 0) === 1) ? 1 : 0;
    $stmt = $db->prepare("SELECT username, priv_level FROM users WHERE id = ?");
    $stmt->execute([$targetId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) exit('USER_NOT_FOUND');
    if (!canEditTarget($userPriv, $myUserId, (int)$u['priv_level'], $targetId)) exit(t('sync_worker_err_access_denied', [], 'Access Denied.'));
    $db->prepare("UPDATE users SET is_verified = ?, verify_code = NULL WHERE id = ?")->execute([$status, $targetId]);
    $db->prepare("UPDATE account SET Status = ? WHERE Name = ?")->execute([$status, $u['username']]);
    aldhran_log('USER_VERIFIED_TOGGLE', "Admin set is_verified = $status for {$u['username']}", $myUserId, $targetId);
    exit('SUCCESS');
}

if ($action === 'resend_verification') {
    if ($userPriv < 4) exit(t('sync_worker_err_restricted', [], 'Restricted.'));
    $targetId = (int)($_POST['target_id'] ?? 0);
    $stmt = $db->prepare("SELECT username, email, priv_level FROM users WHERE id = ?");
    $stmt->execute([$targetId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u || empty($u['email'])) exit('USER_NOT_FOUND');
    if (!canEditTarget($userPriv, $myUserId, (int)$u['priv_level'], $targetId)) exit(t('sync_worker_err_access_denied', [], 'Access Denied.'));
    $token = bin2hex(random_bytes(32));
    $db->prepare("UPDATE users SET verify_code = ?, is_verified = 0 WHERE id = ?")->execute([$token, $targetId]);
    $verifyUrl = SITE_URL . '/index.php?p=verify&code=' . $token;
    $subject = t('sync_worker_verify_email_subject', [], 'Verify your account');
    $message = t('sync_worker_verify_email_body', ['username' => $u['username'], 'verify_url' => $verifyUrl], "Hello {$u['username']},\r\n\r\nPlease verify your account:\r\n$verifyUrl\r\n\r\nDAoC CMS");
    exit(mail($u['email'], $subject, $message, 'From: noreply@localhost') ? 'SUCCESS' : 'MAIL_ERROR');
}

if ($action === 'delete_user') {
    if ($userPriv < 4) exit(t('sync_worker_err_restricted', [], 'Restricted.'));
    $targetId = (int)($_POST['target_id'] ?? 0);
    $stmt = $db->prepare("SELECT username, avatar_url, priv_level FROM users WHERE id = ?");
    $stmt->execute([$targetId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) exit('USER_NOT_FOUND');
    if (!canEditTarget($userPriv, $myUserId, (int)$u['priv_level'], $targetId)) exit(t('sync_worker_err_access_denied', [], 'Access Denied.'));
    if ($targetId === $myUserId) exit(t('sync_worker_err_superadmin_self_delete', [], 'Administrators cannot delete themselves via User Manager.'));

    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM account WHERE Name = ?")->execute([$u['username']]);
        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$targetId]);
        $db->commit();
        umDeleteAvatarFile($u['avatar_url'] ?? null);
        aldhran_log('USER_DELETED', "User {$u['username']} deleted by {$adminName}", $myUserId, $targetId);
        _botTrigger(fn($d) => $d->onUserBanned($targetId, 'Account deleted by ' . $adminName, $myUserId));
        exit('SUCCESS');
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('User delete failed: ' . $e->getMessage());
        http_response_code(500);
        exit('ERROR');
    }
}

if ($action === 'create_user') {
    if ($userPriv < 4) exit(t('sync_worker_err_restricted', [], 'Restricted.'));
    $name = trim((string)($_POST['u_name'] ?? ''));
    $email = trim((string)($_POST['u_email'] ?? ''));
    $password = (string)($_POST['u_pass'] ?? '');
    $newPriv = (int)($_POST['u_priv'] ?? 0);
    if ($name === '' || strlen($password) < 8 || !canAssignPrivLevel($userPriv, $newPriv)) exit(t('sync_worker_err_access_denied', [], 'Invalid account data.'));

    try {
        $db->beginTransaction();
        $db->prepare("INSERT INTO users (username, email, password, priv_level, standing, is_verified) VALUES (?, ?, ?, ?, 0, 1)")
           ->execute([$name, $email, aldhran_hash($password), $newPriv]);
        $res = '';
        for ($i = 0, $len = strlen($password); $i < $len; $i++) $res .= chr(0) . $password[$i];
        $gameHash = '##' . strtoupper(md5($res));
        $db->prepare("INSERT INTO account (Name, Password, Status, PrivLevel, CreationDate) VALUES (?, ?, 1, ?, NOW())")
           ->execute([$name, $gameHash, $newPriv]);
        $db->commit();
        aldhran_log('USER_CREATED', "New user $name created by {$adminName}", $myUserId);
        exit('SUCCESS');
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('User create failed: ' . $e->getMessage());
        http_response_code(500);
        exit('ERROR');
    }
}

if ($action === 'update_full') {
    $targetId = (int)($_POST['target_id'] ?? 0);
    $stmt = $db->prepare("SELECT username, avatar_url, priv_level, standing, description, user_title, forum_signature FROM users WHERE id = ?");
    $stmt->execute([$targetId]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$current) exit(t('sync_worker_err_user_not_found', [], 'User not found.'));

    $targetPriv = (int)$current['priv_level'];
    $oldStanding = (int)$current['standing'];
    if (!canEditTarget($userPriv, $myUserId, $targetPriv, $targetId)) exit(t('sync_worker_err_access_denied', [], 'Access Denied.'));
    if ($targetId === $myUserId && $targetPriv >= 5) exit(t('sync_worker_err_superadmin_self_edit', [], 'Super-Admins cannot edit themselves via UM.'));

    // Avatar uploads are sent as their own minimal AJAX request. Do not let
    // absent form fields reset privilege, standing, bio or title to zero/empty.
    if (isset($_FILES['u_avatar'])) {
        $newAvatar = umStoreAvatar($_FILES['u_avatar'], $targetId);
        if ($newAvatar === null) {
            http_response_code(400);
            exit('INVALID_AVATAR');
        }
        $db->prepare("UPDATE users SET avatar_url = ? WHERE id = ?")->execute([$newAvatar, $targetId]);
        umDeleteAvatarFile($current['avatar_url'] ?? null);
        aldhran_log('AVATAR_CHANGE', "Admin updated avatar for user #$targetId", $myUserId, $targetId);
        exit('SUCCESS');
    }

    $newCmsPriv = (int)($_POST['u_priv'] ?? $targetPriv);
    if ($userPriv < 4) $newCmsPriv = $targetPriv;
    elseif (!canAssignPrivLevel($userPriv, $newCmsPriv)) exit(t('sync_worker_err_access_denied', [], 'Access Denied.'));

    $newStanding = (int)($_POST['u_stand'] ?? $oldStanding);
    $newStanding = max(0, min(maxManageableStanding($userPriv), $newStanding));

    if ($userPriv === 3) {
        if ($targetPriv === 2) {
            $db->prepare("UPDATE users SET description = ?, forum_signature = ? WHERE id = ?")
               ->execute([(string)($_POST['u_bio'] ?? $current['description']), (string)($_POST['forum_signature'] ?? $current['forum_signature']), $targetId]);
        } else {
            $db->prepare("UPDATE users SET standing = ?, standing_reason = ?, description = ?, forum_signature = ? WHERE id = ?")
               ->execute([$newStanding, (string)($_POST['u_reason'] ?? ''), (string)($_POST['u_bio'] ?? $current['description']), (string)($_POST['forum_signature'] ?? $current['forum_signature']), $targetId]);
        }
        aldhran_log('USER_UPDATED', "User #$targetId updated by BS3 {$adminName}", $myUserId, $targetId);
        exit('SUCCESS');
    }

    try {
        $db->beginTransaction();

        if (!empty($_POST['u_new_password'])) {
            $newPassword = (string)$_POST['u_new_password'];
            if (strlen($newPassword) < 8) throw new RuntimeException('Password too short');
            $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([aldhran_hash($newPassword), $targetId]);
            $res = '';
            for ($i = 0, $len = strlen($newPassword); $i < $len; $i++) $res .= chr(0) . $newPassword[$i];
            $db->prepare("UPDATE account SET Password = ? WHERE Name = ?")->execute(['##' . strtoupper(md5($res)), $current['username']]);
        }

        $newIngamePriv = max(1, min(3, (int)($_POST['u_ingame_priv'] ?? 1)));
        $db->prepare("UPDATE account SET PrivLevel = ?, Banned = ?, BanReason = ? WHERE Name = ?")
           ->execute([$newIngamePriv, $newStanding >= 4 ? 1 : 0, $newStanding >= 4 ? (string)($_POST['u_reason'] ?? '') : '', $current['username']]);

        $db->prepare("UPDATE users SET priv_level = ?, standing = ?, standing_reason = ?, description = ?, user_title = ?, forum_signature = ? WHERE id = ?")
           ->execute([
               $newCmsPriv,
               $newStanding,
               (string)($_POST['u_reason'] ?? ''),
               (string)($_POST['u_bio'] ?? $current['description']),
               (string)($_POST['u_title'] ?? $current['user_title']),
               (string)($_POST['forum_signature'] ?? $current['forum_signature']),
               $targetId,
           ]);

        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('User update failed: ' . $e->getMessage());
        http_response_code(500);
        exit('ERROR');
    }

    aldhran_log('USER_UPDATED', "User #$targetId updated by {$adminName}", $myUserId, $targetId);
    if ($newStanding >= 4 && $oldStanding < 4) {
        _botTrigger(fn($d) => $d->onUserBanned($targetId, (string)($_POST['u_reason'] ?? 'No reason given'), $myUserId));
    } elseif ($newStanding < 4 && $oldStanding >= 4) {
        _botTrigger(fn($d) => $d->onUserUnbanned($targetId, $myUserId));
    }
    exit('SUCCESS');
}

http_response_code(400);
exit('UNKNOWN_ACTION');
