<?php
// SPDX-License-Identifier: GPL-3.0-only
define('IN_CMS', true);
require_once('includes/db.php');

header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthenticated']); exit;
}
$userPriv = (int)($_SESSION['priv_level'] ?? 0);
if ($userPriv < 4) {
    echo json_encode(['ok' => false, 'error' => 'Forbidden']); exit;
}
checkToken($_POST['csrf_token'] ?? '');

// ── Input ─────────────────────────────────────────────────────
$delay_minutes = max(0, min(60, (int)($_POST['delay_minutes'] ?? 0)));
$announcement  = trim($_POST['announcement'] ?? '');
$sender        = trim((string)($_SESSION['username'] ?? 'Admin'));

$startup_path = (string)($GLOBALS['cms_settings']['game_server_bat_path'] ?? '');

// ── POST to AldhranConsole /restart ───────────────────────────
$decoded = aldhran_console_call('restart', [
    'delay_minutes' => $delay_minutes,
    'announcement'  => $announcement,
    'sender'        => $sender,
]);

if (!($decoded['ok'] ?? false)) {
    echo json_encode(['ok' => false, 'error' => $decoded['error'] ?? 'Console error']); exit;
}

// ── Startup launcher: countdown + restart buffer ─────────────
if ($startup_path !== '' && file_exists($startup_path)) {
    $wait_seconds = ($delay_minutes * 60) + 42;
    $php_bin      = PHP_BINARY;
    $launcher     = __DIR__ . '/includes/restart_launcher.php';
    $cmd = escapeshellcmd($php_bin)
         . ' ' . escapeshellarg($launcher)
         . ' ' . (int)$wait_seconds
         . ' ' . escapeshellarg($startup_path);

    if (PHP_OS_FAMILY === 'Windows') {
        pclose(popen('start /B "" ' . $cmd, 'r'));
    } else {
        exec($cmd . ' > /dev/null 2>&1 &');
    }
}

aldhran_log(
    'SERVER_RESTART',
    'Restart scheduled by ' . $sender
        . ' (delay: ' . $delay_minutes . 'min'
        . ', announcement: ' . ($announcement ?: '—') . ')',
    (int)($_SESSION['user_id'] ?? 0)
);

echo json_encode([
    'ok'     => true,
    'result' => $decoded['result'] ?? 'Restart initiated.',
    'delay'  => $delay_minutes,
]);
