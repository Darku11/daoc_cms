<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) exit;

$checkPriv = (int)($_SESSION['priv_level'] ?? 0);
if ($checkPriv < 5) {
    die("Unauthorized access to nexus core.");
}

// Sensitive account administration must never be reachable with only the
// normal CMS session. Require the same fresh ACP re-authentication as acp.php.
$acpAuthedAt = (int)($_SESSION['acp_authed_at'] ?? 0);
if (!defined('IN_ACP') || $acpAuthedAt <= 0 || (time() - $acpAuthedAt) >= 1800) {
    http_response_code(403);
    die('ACP re-authentication required.');
}
$_SESSION['acp_authed_at'] = time();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    checkToken($_POST['csrf_token'] ?? '');

    $target_user_id = (int)($_POST['target_user_id'] ?? 0);
    $action         = $_POST['action'];
    $admin_id       = (int)$_SESSION['user_id'];

    $stmt_user = $db->prepare("SELECT username, email FROM users WHERE id = ?");
    $stmt_user->execute([$target_user_id]);
    $user_data = $stmt_user->fetch();

    if (!$user_data) {
        header("Location: index.php?p=permissions&err=user_not_found");
        exit;
    }

    $username = $user_data['username'];

    switch ($action) {
        case 'update_standing':
            $allowed_standings = ['Active', 'Suspended', 'Warning', 'Restricted'];
            $new_standing = in_array($_POST['standing'] ?? '', $allowed_standings, true)
                ? $_POST['standing']
                : null;

            if ($new_standing === null) {
                header("Location: index.php?p=permissions&err=invalid_standing");
                exit;
            }

            try {
                $db->beginTransaction();
                $db->prepare("UPDATE users SET standing = ? WHERE id = ?")
                   ->execute([$new_standing, $target_user_id]);

                if ($new_standing === 'Suspended') {
                    $reason = "Suspended by Administrator via CMS Management Hub.";
                    $db->prepare("UPDATE account SET Banned = 1, BanReason = ? WHERE Name = ?")
                       ->execute([$reason, $username]);
                } else {
                    $db->prepare("UPDATE account SET Banned = 0, BanReason = '' WHERE Name = ?")
                       ->execute([$username]);
                }

                aldhran_log("STANDING_CHANGE", "Changed standing to $new_standing for user #$target_user_id (game account sync)", $admin_id, $target_user_id);
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Standing change error: " . $e->getMessage());
                header("Location: index.php?p=permissions&err=db_error");
                exit;
            }

            header("Location: index.php?p=permissions&msg=sync_success");
            break;

        case 'change_privilege':
            $new_priv = (int)($_POST['priv_level'] ?? -1);
            if ($new_priv < 0 || $new_priv > $checkPriv) {
                header("Location: index.php?p=permissions&err=invalid_priv");
                exit;
            }

            try {
                $db->beginTransaction();
                $db->prepare("UPDATE users SET priv_level = ? WHERE id = ?")
                   ->execute([$new_priv, $target_user_id]);
                $db->prepare("UPDATE account SET PrivLevel = ? WHERE Name = ?")
                   ->execute([$new_priv, $username]);
                aldhran_log("PRIV_CHANGE", "Set PrivLevel to $new_priv for user #$target_user_id (game account sync)", $admin_id, $target_user_id);
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Privilege change error: " . $e->getMessage());
                header("Location: index.php?p=permissions&err=db_error");
                exit;
            }

            header("Location: index.php?p=permissions&msg=priv_success");
            break;

        case 'delete_account':
            if ($target_user_id === (int)$admin_id) {
                header("Location: index.php?p=permissions&err=cannot_delete_self");
                exit;
            }

            try {
                $db->beginTransaction();
                $db->prepare("DELETE FROM users WHERE id = ?")
                   ->execute([$target_user_id]);
                $db->prepare("UPDATE account SET Banned = 1, BanReason = 'Account deleted via CMS' WHERE Name = ?")
                   ->execute([$username]);
                aldhran_log("ACCOUNT_DELETED", "Deleted CMS account #$target_user_id ($username)", $admin_id, $target_user_id);
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Account delete error: " . $e->getMessage());
                header("Location: index.php?p=permissions&err=db_error");
                exit;
            }

            header("Location: index.php?p=permissions&msg=delete_success");
            break;

        default:
            header("Location: index.php?p=permissions&err=unknown_action");
            exit;
    }
    exit;
}
