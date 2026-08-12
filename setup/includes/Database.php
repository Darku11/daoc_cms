<?php
// SPDX-License-Identifier: GPL-3.0-only
declare(strict_types=1);

namespace DAoCCMS\Setup;

use PDO;
use PDOException;

class Database
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

            // Test CREATE TABLE privileges
            $dummyTable = 'setup_test_' . time();
            $pdo->exec("CREATE TABLE `{$dummyTable}` (id INT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("DROP TABLE `{$dummyTable}`");

            return ['success' => true, 'pdo' => $pdo];

        } catch (PDOException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public static function checkExistingTables(PDO $pdo): bool
    {
        $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
        
        return $stmt->rowCount() > 0;
    }
}