<?php
// SPDX-License-Identifier: GPL-3.0-only

final class CmsMigrationManager
{
    public const BASELINE_VERSION = '20260812000000';
    public const SETTING_KEY = 'cms_schema_version';

    public static function currentVersion(PDO $db): string
    {
        $stmt = $db->prepare("SELECT value FROM settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([self::SETTING_KEY]);
        $version = $stmt->fetchColumn();

        if ($version === false || !preg_match('/^\d{14}$/', (string)$version)) {
            return self::BASELINE_VERSION;
        }

        return (string)$version;
    }

    public static function pending(PDO $db, string $directory): array
    {
        $current = self::currentVersion($db);
        $migrations = self::discover($directory);

        return array_values(array_filter(
            $migrations,
            static fn(array $migration): bool => strcmp($migration['version'], $current) > 0
        ));
    }

    public static function run(PDO $db, string $directory): array
    {
        $applied = [];

        foreach (self::pending($db, $directory) as $migration) {
            $callback = require $migration['path'];
            if (!is_callable($callback)) {
                throw new RuntimeException('Migration must return a callable: ' . basename($migration['path']));
            }

            $callback($db);
            self::storeVersion($db, $migration['version']);
            $applied[] = $migration['version'];
        }

        return $applied;
    }

    private static function discover(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $migrations = [];
        $files = glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [];

        foreach ($files as $path) {
            $filename = basename($path);
            if (!preg_match('/^(\d{14})_[a-z0-9_]+\.php$/', $filename, $matches)) {
                continue;
            }

            $migrations[] = [
                'version' => $matches[1],
                'path' => $path,
            ];
        }

        usort($migrations, static function (array $a, array $b): int {
            return strcmp($a['version'], $b['version']);
        });

        return $migrations;
    }

    private static function storeVersion(PDO $db, string $version): void
    {
        $stmt = $db->prepare(
            "INSERT INTO settings (setting_key, value) VALUES (?, ?) " .
            "ON DUPLICATE KEY UPDATE value = VALUES(value)"
        );
        $stmt->execute([self::SETTING_KEY, $version]);
    }
}