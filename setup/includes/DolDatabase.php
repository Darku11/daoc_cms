<?php
// SPDX-License-Identifier: GPL-3.0-only
declare(strict_types=1);

namespace DAoCCMS\Setup;

use PDO;
use PDOException;
use RuntimeException;

class DolDatabase
{
    public static function testConnection(string $host, int $port, string $dbName, string $user, string $password): array
    {
        $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";

        try {
            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            return ['success' => true, 'pdo' => $pdo];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function verifyExistingStructure(PDO $pdo): array
    {
        return self::verifyTables($pdo, ['account', 'dolcharacters']);
    }

    public static function verifyWorldStructure(PDO $pdo): array
    {
        return self::verifyTables($pdo, [
            'account',
            'ability',
            'dolcharacters',
            'guild',
            'itemtemplate',
            'keep',
            'merchantitem',
            'mob',
            'path',
            'pathpoints',
            'relic',
        ]);
    }

    private static function verifyTables(PDO $pdo, array $requiredTables): array
    {
        $missing = [];

        foreach ($requiredTables as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
            if ($stmt->rowCount() === 0) {
                $missing[] = $table;
            }
        }

        if (!empty($missing)) {
            return [
                'valid'   => false,
                'message' => 'Missing required game server tables: ' . implode(', ', $missing),
            ];
        }

        return ['valid' => true];
    }

    /** Count tables in the target database before destructive imports. */
    public static function countTables(PDO $pdo): int
    {
        return (int) $pdo->query('SHOW TABLES')->rowCount();
    }

    /** Return the account count when the table exists, otherwise null. */
    public static function countAccounts(PDO $pdo): ?int
    {
        try {
            if ($pdo->query("SHOW TABLES LIKE 'account'")->rowCount() === 0) {
                return null;
            }
            return (int) $pdo->query('SELECT COUNT(*) FROM `account`')->fetchColumn();
        } catch (PDOException) {
            return null;
        }
    }

    /**
     * Import an SQL file, optionally compressed with gzip.
     *
     * Failed statements do not stop game database imports because optional
     * DROP TABLE IF EXISTS may fail in restricted environments. Unlike before,
     * the failure is returned instead of being written only to the error log.
     *
     * @return array{executed:int, failed:int, errors:list<string>}
     */
    public static function importSqlFile(PDO $pdo, string $filePath): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new RuntimeException("SQL file not found or not readable: {$filePath}");
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec("SET NAMES 'utf8mb4'");

        $gzip = str_ends_with(strtolower($filePath), '.gz');
        if ($gzip && !function_exists('gzopen')) {
            throw new RuntimeException('The bundled database requires the PHP zlib extension.');
        }

        $handle = $gzip ? gzopen($filePath, 'rb') : fopen($filePath, 'r');
        if ($handle === false) {
            throw new RuntimeException("Could not open {$filePath} for reading.");
        }

        $query      = '';
        $inString   = false;
        $stringChar = '';
        $executed   = 0;
        $failed     = 0;
        $errors     = [];

        try {
            while (($line = $gzip ? gzgets($handle) : fgets($handle)) !== false) {
                $trimmed = trim($line);

                // Comments only end the statement outside of an open quote —
                // a game text field containing "-- " would otherwise get cut off.
                if (!$inString && ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#'))) {
                    continue;
                }

                $query .= $line;

                // Track whether we're inside a quoted string so a semicolon in
                // item/NPC text (e.g. "Halt; who goes there?") isn't mistaken
                // for the end of the statement.
                $len = strlen($line);
                for ($i = 0; $i < $len; $i++) {
                    $char = $line[$i];

                    if ($char === '\\') {
                        $i++;
                        continue;
                    }

                    if ($char === "'" || $char === '"') {
                        if (!$inString) {
                            $inString   = true;
                            $stringChar = $char;
                        } elseif ($char === $stringChar) {
                            $inString = false;
                        }
                    }
                }

                if (!$inString && substr(trim($query), -1) === ';') {
                    $statement = trim($query);

                    // Always stay inside the database selected in the setup.
                    // Dumps may contain their original schema name.
                    if (preg_match('/^(?:(?:CREATE|DROP)\s+(?:DATABASE|SCHEMA)\b|USE\s+)/i', $statement) === 1) {
                        $query = '';
                        continue;
                    }

                    try {
                        $pdo->exec($statement);
                        $executed++;
                    } catch (PDOException $e) {
                        $failed++;
                        if (count($errors) < 5) {
                            $errors[] = $e->getMessage();
                        }
                        error_log('Game DB import: ' . $e->getMessage() . ' | ' . substr($statement, 0, 120));
                    }
                    $query = '';
                }
            }
        } finally {
            if ($gzip) {
                gzclose($handle);
            } else {
                fclose($handle);
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        return ['executed' => $executed, 'failed' => $failed, 'errors' => $errors];
    }
}
