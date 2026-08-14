<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) {
    define('IN_CMS', true);
    require_once __DIR__ . '/../includes/db.php';
}

if (($_SESSION['priv_level'] ?? 0) < 5) {
    aldhran_log('SECURITY_ALERT', 'Unauthorized maintenance toggle attempt', $_SESSION['user_id'] ?? 0);
    http_response_code(403);
    exit('Access Denied');
}

if (!isset($_POST['toggle_maint'])) {
    http_response_code(405);
    exit('Method Not Allowed');
}

checkToken($_POST['csrf_token'] ?? '');

$adminId = (int)($_SESSION['user_id'] ?? 0);
$lockFile = __DIR__ . '/../maintenance.lock';
clearstatcache(true, $lockFile);
$current = file_exists($lockFile);
$newState = $current ? '0' : '1';

try {
    if ($newState === '1') {
        $lockContent = "MAINTENANCE ACTIVE\nStarted by: " . ($_SESSION['username'] ?? 'Unknown Admin')
            . "\nID: {$adminId}\nTime: " . date('Y-m-d H:i:s');
        if (file_put_contents($lockFile, $lockContent, LOCK_EX) === false) {
            throw new RuntimeException('Could not create maintenance lock file.');
        }
    } elseif (file_exists($lockFile) && !unlink($lockFile)) {
        throw new RuntimeException('Could not remove maintenance lock file.');
    }

    $stmt = $db->prepare(
        "INSERT INTO settings (setting_key, value) VALUES ('maintenance_mode', ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)"
    );
    $stmt->execute([$newState]);
    $GLOBALS['cms_settings']['maintenance_mode'] = $newState;

    aldhran_log(
        'MAINTENANCE_TOGGLE',
        'Global maintenance mode ' . ($newState === '1' ? 'ACTIVATED' : 'DEACTIVATED'),
        $adminId
    );

    if (isset($GLOBALS['botDispatcher'])) {
        try {
            $GLOBALS['botDispatcher']->onMaintenanceToggle($newState === '1', $adminId);
        } catch (Throwable $e) {
            error_log('BotDispatcher maintenance trigger failed: ' . $e->getMessage());
        }
    }

    header('Location: ../index.php?p=maintenance_text&msg=toggled');
    exit;
} catch (Throwable $e) {
    if ($current && !file_exists($lockFile)) {
        @file_put_contents($lockFile, "MAINTENANCE ACTIVE\n", LOCK_EX);
    } elseif (!$current && file_exists($lockFile)) {
        @unlink($lockFile);
    }
    error_log('Maintenance Toggle Error: ' . $e->getMessage());
    http_response_code(500);
    exit('Something went wrong. Check the logs.');
}
