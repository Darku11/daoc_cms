<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * restart_launcher.php
 * Deployment: /includes/restart_launcher.php
 *
 * Started in the background by ajax_restart.php.
 * Waits for $argv[1] seconds, then starts the configured $argv[2] game server startup target.
 * Runs as a separate PHP CLI process - no web request needed.
 */

if (php_sapi_name() !== 'cli') exit;

$wait    = max(0, (int)($argv[1] ?? 30));
$startup = $argv[2] ?? '';

if ($startup === '' || !file_exists($startup)) {
    file_put_contents(__DIR__ . '/../php_errors.log',
        date('[Y-m-d H:i:s]') . " restart_launcher: startup target not found: $startup\n",
        FILE_APPEND
    );
    exit(1);
}

sleep($wait);

// Start the server process.
if (PHP_OS_FAMILY === 'Windows') {
    // cmd /C start launches the configured Windows startup target in a new window.
    pclose(popen('cmd /C start "" ' . escapeshellarg($startup), 'r'));
} else {
    // Linux or Wine environment.
    exec('nohup ' . escapeshellarg($startup) . ' > /dev/null 2>&1 &');
}

file_put_contents(__DIR__ . '/../php_errors.log',
    date('[Y-m-d H:i:s]') . " restart_launcher: started $startup after {$wait}s\n",
    FILE_APPEND
);
