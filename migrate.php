<?php
// SPDX-License-Identifier: GPL-3.0-only

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/migration_manager.php';

$directory = __DIR__ . '/migrations';
$statusOnly = in_array('--status', $argv ?? [], true);

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $db = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

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