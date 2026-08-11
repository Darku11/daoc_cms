<?php
/**
 * restart_launcher.php
 * Deployment: /includes/restart_launcher.php
 *
 * Started in the background by ajax_restart.php.
 * Waits for $argv[1] seconds, then starts the $argv[2] batch file.
 * Runs as a separate PHP CLI process - no web request needed.
 */

if (php_sapi_name() !== 'cli') exit;

$wait    = max(0, (int)($argv[1] ?? 30));
$bat     = $argv[2] ?? '';

if ($bat === '' || !file_exists($bat)) {
    file_put_contents(__DIR__ . '/../php_errors.log',
        date('[Y-m-d H:i:s]') . " restart_launcher: bat not found: $bat\n",
        FILE_APPEND
    );
    exit(1);
}

sleep($wait);

// Start the server process.
if (PHP_OS_FAMILY === 'Windows') {
    // cmd /C start launches the batch file in a new window.
    pclose(popen('cmd /C start "" ' . escapeshellarg($bat), 'r'));
} else {
    // Linux or Wine environment.
    exec('nohup ' . escapeshellarg($bat) . ' > /dev/null 2>&1 &');
}

file_put_contents(__DIR__ . '/../php_errors.log',
    date('[Y-m-d H:i:s]') . " restart_launcher: started $bat after {$wait}s\n",
    FILE_APPEND
);
