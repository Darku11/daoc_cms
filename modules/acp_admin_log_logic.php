<?php
// SPDX-License-Identifier: GPL-3.0-only
$_acp_auth = (defined('IN_ACP') && isset($userPriv) && $userPriv >= 4);
$_cms_auth = (isset($can_edit) && $can_edit);
if (!$_acp_auth && !$_cms_auth) return;

$success = "";
$error   = "";

// ── Critical Error Acknowledge ────────────────────────────────
if (isset($_GET['dismiss_critical_errors']) && $_acp_auth) {
    checkToken($_GET['csrf_token'] ?? '');
    $db->prepare("INSERT INTO settings (setting_key, value) VALUES ('has_critical_error', '0') ON DUPLICATE KEY UPDATE value = '0'")->execute();
    aldhran_log('CRITICAL_ERRORS_DISMISSED', 'Admin acknowledged critical errors', $_SESSION['user_id'] ?? 0);
    header("Location: " . (defined('IN_ACP') ? 'acp.php?s=admin_log' : 'index.php?p=admin_log'));
    exit;
}

$has_critical_error = ($GLOBALS['cms_settings']['has_critical_error'] ?? '0') === '1';
$log_csrf_token = generateToken();