<?php
// SPDX-License-Identifier: GPL-3.0-only
require_once('includes/db.php');

header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthenticated']); exit;
}
$userPriv = (int)($_SESSION['priv_level'] ?? 0);
if ($userPriv < 4) {
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']); exit;
}
checkToken($_GET['csrf_token'] ?? '');

// ── Already running? Don't spawn a second instance. ────────────
$ping = $botSettings->sendCommand('ping', [], 2);
if (($ping['status'] ?? '') === 'ok') {
    echo json_encode(['status' => 'error', 'message' => 'Bot is already running.']);
    exit;
}

// ── Locate the script (relative to the CMS root, e.g. "../bot/bot.js") ──
$relativePath = trim($botSettings->data['bot_script_path'] ?? '');
if ($relativePath === '') {
    echo json_encode(['status' => 'error', 'message' => 'Bot script path not configured. Set it under "Bot Script Path" and save first.']);
    exit;
}

$scriptPath = realpath(__DIR__ . '/' . $relativePath);
if ($scriptPath === false || !file_exists($scriptPath)) {
    echo json_encode(['status' => 'error', 'message' => 'Bot script not found at "' . $relativePath . '" (relative to the CMS root).']);
    exit;
}

$dir  = dirname($scriptPath);
$file = basename($scriptPath);

if (!defined('BOT_BOOTSTRAP_SECRET') || BOT_BOOTSTRAP_SECRET === '') {
    echo json_encode(['status' => 'error', 'message' => 'BOT_BOOTSTRAP_SECRET is missing from includes/config.php.']);
    exit;
}

$botEnvironment = [
    'DAOC_CMS_CONFIG_URL'          => rtrim(SITE_URL, '/') . '/api_bot_config.php',
    'DAOC_CMS_BOOTSTRAP_SECRET'    => BOT_BOOTSTRAP_SECRET,
    'DAOC_CMS_WEBHOOK_URL'         => rtrim(SITE_URL, '/') . '/bot_webhook.php',
];

foreach ($botEnvironment as $name => $value) {
    putenv($name . '=' . $value);
}

$startError = null;
try {
    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = 'cmd /C start "" /D ' . escapeshellarg($dir) . ' node ' . escapeshellarg($file);
        pclose(popen($cmd, 'r'));
    } else {
        $cmd = 'cd ' . escapeshellarg($dir) . ' && nohup node ' . escapeshellarg($file)
             . ' > ' . escapeshellarg($dir . '/bot_output.log') . ' 2>&1 &';
        exec($cmd);
    }
} catch (\Throwable $e) {
    $startError = $e->getMessage();
} finally {
    foreach (array_keys($botEnvironment) as $name) {
        putenv($name);
    }
}

if ($startError !== null) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to start: ' . $startError]);
    exit;
}

aldhran_log('BOT_START', 'Bot process started via ACP by ' . ($_SESSION['username'] ?? 'Admin'), (int)($_SESSION['user_id'] ?? 0));

echo json_encode(['status' => 'ok', 'message' => 'Start command sent. It may take a few seconds for the bot to come online.']);
