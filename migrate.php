<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('IN_CMS', true);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/migration_manager.php';

$directory = __DIR__ . '/migrations';
$statusOnly = in_array('--status', $argv ?? [], true);

try {
    $current = CmsMigrationManager::currentVersion($db);
    $pending = CmsMigrationManager::pending($db, $directory);

    echo 'Current schema version: ' . $current . PHP_EOL;
    echo 'Pending migrations: ' . count($pending) . PHP_EOL;

    foreach ($pending as $migration) {
        echo '  - ' . basename($migration['path']) . PHP_EOL;
    }

    if ($statusOnly || !$pending) {
        exit(0);
    }

    $applied = CmsMigrationManager::run($db, $directory);
    foreach ($applied as $version) {
        echo 'Applied migration: ' . $version . PHP_EOL;
    }

    echo 'Database schema is up to date.' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
