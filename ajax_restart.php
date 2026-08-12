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
$sender        = h($_SESSION['username'] ?? 'Admin');

// ── Config from DB ───────────────────────────────────────────
try {
    $cfg = $db->query(
        "SELECT setting_key, value FROM settings
          WHERE setting_key IN (
              'game_server_console_host',
              'game_server_console_port',
              'game_server_console_secret',
              'game_server_bat_path'
          )"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (\Throwable $e) {
    $cfg = [];
}

$console_host   = $cfg['game_server_console_host']   ?? '127.0.0.1';
$console_port   = (int)($cfg['game_server_console_port']   ?? 5100);
$console_secret = $cfg['game_server_console_secret'] ?? 'CHANGE_ME_IN_APPSETTINGS';
$startup_path       = $cfg['game_server_bat_path']        ?? '';

// ── POST to AldhranConsole /restart ───────────────────────────
$payload = json_encode([
    'delay_minutes' => $delay_minutes,
    'announcement'  => $announcement,
    'sender'        => $sender,
]);

$url     = "http://{$console_host}:{$console_port}/restart";
$context = stream_context_create([
    'http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", [
            'Content-Type: application/json',
            'X-Aldhran-Secret: ' . $console_secret,
            'Content-Length: ' . strlen($payload),
        ]),
        'content'       => $payload,
        'timeout'       => 8,
        'ignore_errors' => true,
    ],
]);

$raw = @file_get_contents($url, false, $context);

if ($raw === false || $raw === '') {
    echo json_encode(['ok' => false, 'error' => "Cannot reach AldhranConsole at {$url}"]); exit;
}

// Extract JSON
$start = strpos($raw, '{');
$end   = strrpos($raw, '}');
if ($start !== false && $end !== false) {
    $raw = substr($raw, $start, $end - $start + 1);
}

$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid console response: ' . substr($raw, 0, 100)]); exit;
}

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
