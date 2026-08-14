<?php
// SPDX-License-Identifier: GPL-3.0-only
declare(strict_types=1);

namespace DAoCCMS\Setup;

use PDO;
use PDOException;
use RuntimeException;

require_once __DIR__ . '/Writer.php';
require_once __DIR__ . '/Database.php';

/**
 * Split installation into phases that can be invoked independently.
 * Keep installation behavior in one runner so both synchronous and asynchronous
 * setup flows use the same implementation.
 */
class Runner
{
    /** Phase order and labels. */
    public const PHASES = [
        'config'   => 'Writing configuration file',
        'connect'  => 'Verifying database connection',
        'schema'   => 'Creating database tables',
        'rename'   => 'Renaming CMS tables',
        'admin'    => 'Creating administrator account',
        'settings' => 'Saving settings',
    ];

    private const SCHEMA_BASELINE = '20260812000000';

    private const RENAMES = [
        'bot_settings'             => 'cms_bot_settings',
        'languages'                => 'cms_languages',
        'translations'             => 'cms_translations',
        'ai_settings'              => 'cms_ai_settings',
        'ai_logs'                  => 'cms_ai_logs',
        'ai_suggestions'           => 'cms_ai_suggestions',
        'ai_tasks'                 => 'cms_ai_tasks',
        'ai_provider_keys'         => 'cms_ai_provider_keys',
        'bot_commands'             => 'cms_bot_commands',
        'bot_command_permissions'  => 'cms_bot_command_permissions',
        'live_events'              => 'cms_live_events',
        'suits'                    => 'cms_suits',
        'suit_items'               => 'cms_suit_items',
    ];

    private static function root(): string
    {
        $root = realpath(__DIR__ . '/../../');
        if ($root === false) {
            throw new RuntimeException('Could not resolve the installation root directory.');
        }
        return $root;
    }

    /**
     * Verify that all required data from previous steps exists in the session.
     *
     * @return array{db:array,game:array,cms:array,crypto:array,admin:array,console:array}
     */
    public static function session(): array
    {
        $db     = $_SESSION['setup_db']     ?? [];
        $game   = $_SESSION['setup_dol']    ?? [];
        $cms    = $_SESSION['setup_config'] ?? [];
        $crypto = $_SESSION['setup_crypto'] ?? [];
        $admin  = $_SESSION['setup_admin']  ?? [];
        $console = $_SESSION['setup_console'] ?? [];

        if (empty($db) || empty($game) || empty($cms) || empty($crypto) || empty($admin)) {
            throw new RuntimeException('Session data is missing. Please restart the setup.');
        }

        if (!in_array(($game['core'] ?? ''), ['opendaoc', 'dol'], true)) {
            throw new RuntimeException('No game server core was selected. Return to The Realm Gate and choose OpenDAoC or Dawn of Light.');
        }

        return [
            'db' => $db,
            'game' => $game,
            'cms' => $cms,
            'crypto' => $crypto,
            'admin' => $admin,
            'console' => $console,
        ];
    }

    /** Recreate the PDO connection from session data. */
    public static function connect(): PDO
    {
        $s = self::session();
        $db = $s['db'];

        $result = Database::testConnection(
            $db['host'],
            (int) $db['port'],
            $db['name'],
            $db['user'],
            $db['pass']
        );

        if (!$result['success']) {
            throw new RuntimeException('Database connection failed: ' . $result['message']);
        }

        return $result['pdo'];
    }


    /** Check whether a table exists in the currently selected schema. */
    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
              LIMIT 1'
        );
        $stmt->execute([$table]);

        return $stmt->fetchColumn() !== false;
    }

    /* ------------------------------------------------------------------ */
    /* Phases                                                              */
    /* ------------------------------------------------------------------ */

    /** Write includes/config.php. */
    public static function config(): string
    {
        $s = self::session();

        $configPath = self::root() . '/includes/config.php';

        (new Writer())->writeConfig($configPath, [
            'base_url'     => $s['cms']['base_url'],
            'instance_id'  => $s['crypto']['instance_id'],
            'pepper'       => $s['crypto']['pepper'],
            'asp_key'      => $s['cms']['asp_key'] ?? $s['crypto']['asp_key'],
            'bot_bootstrap_secret' => $s['crypto']['bot_bootstrap_secret'],
            'db_host'      => $s['db']['host'],
            'db_user'      => $s['db']['user'],
            'db_pass'      => $s['db']['pass'],
            'db_name'      => $s['db']['name'],
            'db_prefix'    => '',
            'resend_key'   => $s['cms']['resend_key'],
            'sender_email' => $s['cms']['sender_email'],
            'sender_name'  => $s['cms']['sender_name'],
        ]);

        return 'includes/config.php written';
    }

    /** Test the connection and return the server name. */
    public static function verify(): string
    {
        $pdo = self::connect();
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

        return 'Connected · MySQL ' . $version;
    }

    /**
     * Importiert setup/sql/database.sql.
     * Track quoted strings and DELIMITER directives so semicolons inside
     * strings, triggers, and event bodies remain intact.
     * Import tables directly under their final cms_* names.
     */
    public static function schema(): string
    {
        $pdo = self::connect();

        $sqlFile = realpath(__DIR__ . '/../sql/database.sql');
        if ($sqlFile === false || !is_file($sqlFile)) {
            throw new RuntimeException('The file sql/database.sql is missing in setup/sql/.');
        }

        $handle = fopen($sqlFile, 'r');
        if ($handle === false) {
            throw new RuntimeException('Could not open sql/database.sql for reading.');
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $query      = '';
        $delimiter  = ';';
        $inString   = false;
        $stringChar = '';
        $executed   = 0;

        try {
        // Incomplete installations may leave selected cms_* tables without
        // their prefix. These staging tables can only originate from an
        // unfinished schema or rename phase and must be cleaned before
        // importing tables under their final names.
        foreach (array_keys(self::RENAMES) as $stagingTable) {
            if (self::tableExists($pdo, $stagingTable)) {
                $pdo->exec("DROP TABLE `{$stagingTable}`");
            }
        }

            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);

                if (!$inString && preg_match('/^DELIMITER\\s+(.+)$/i', $trimmed, $match)) {
                    if (trim($query) !== '') {
                        throw new RuntimeException('Unexpected DELIMITER directive inside an SQL statement.');
                    }
                    $delimiter = trim($match[1]);
                    continue;
                }

                if (!$inString && ($trimmed === '' || strpos($trimmed, '--') === 0)) {
                    continue;
                }

                $query .= $line;

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
                            if ($i + 1 < $len && $line[$i + 1] === $stringChar) {
                                $i++;
                            } else {
                                $inString = false;
                            }
                        }
                    }
                }

                $statement = rtrim($query);
                if (!$inString && str_ends_with($statement, $delimiter)) {
                    $statement = substr($statement, 0, -strlen($delimiter));
                    if ($delimiter === ';') {
                        $statement .= ';';
                    }

                    // The dump already contains the final cms_* names.
                    // Normalize only foreign-key references that still point to
                    // unprefixed staging names.
                    $executable = $statement;
                    foreach (self::RENAMES as $from => $to) {
                        $referencePattern = '/(REFERENCES\\s+`?)'
                            . preg_quote($from, '/')
                            . '(`?\\s)/i';
                        $executable = preg_replace(
                            $referencePattern,
                            '$1' . $to . '$2',
                            (string) $executable
                        );
                    }

                    // Explicit foreign-key names must be unique across a MariaDB schema.
                    // Generated names avoid collisions with objects left by a
                    // previous incomplete setup run.
                    $executable = preg_replace(
                        '/CONSTRAINT\\s+`[^`]+`\\s+(FOREIGN\\s+KEY)/i',
                        '$1',
                        (string) $executable
                    );

                    try {
                        $pdo->exec($executable);
                        $executed++;
                    } catch (PDOException $e) {
                        $message = $e->getMessage();

                        // A retry may encounter seed rows or normalized objects that
                        // were imported previously or already exist globally.
                        $duplicateRow = $e->getCode() === '23000'
                            && str_contains($message, '1062');
                        $existingTrigger = str_contains($message, '1359')
                            && stripos($message, 'trigger') !== false;
                        $existingEvent = str_contains($message, '1537')
                            && stripos($message, 'event') !== false;

                        if (!$duplicateRow && !$existingTrigger && !$existingEvent) {
                            throw new RuntimeException('SQL import error: ' . $message);
                        }
                    }

                    $query = '';
                }
            }

            if (trim($query) !== '') {
                throw new RuntimeException('The SQL dump ends with an incomplete statement.');
            }
        } finally {
            fclose($handle);
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $executed . ' statements executed';
    }

    /** Normalize tables left by incomplete installer runs. */

    public static function rename(): string
    {
        $pdo       = self::connect();
        $renamed   = 0;
        $preserved = 0;
        $removed   = 0;

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach (self::RENAMES as $from => $to) {
                $sourceExists = self::tableExists($pdo, $from);
                $targetExists = self::tableExists($pdo, $to);

                // After a partially successful run, the final target table may
                // already exist. The retry-created unprefixed staging table then
                // contains only dump data and can be discarded while preserving
                // the installed target table.
                if ($targetExists) {
                    if ($sourceExists) {
                        $pdo->exec("DROP TABLE `{$from}`");
                        $removed++;
                    }
                    $preserved++;
                    continue;
                }

                if (!$sourceExists) {
                    throw new RuntimeException(
                        "Neither staging table `{$from}` nor target table `{$to}` exists."
                    );
                }

                $pdo->exec("RENAME TABLE `{$from}` TO `{$to}`");
                $renamed++;
            }
        } catch (PDOException $e) {
            throw new RuntimeException('Could not normalize CMS table names: ' . $e->getMessage());
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        $detail = [$renamed . ' tables renamed'];
        if ($preserved > 0) {
            $detail[] = $preserved . ' existing tables preserved';
        }
        if ($removed > 0) {
            $detail[] = $removed . ' retry staging tables removed';
        }

        return implode(' · ', $detail);
    }

    /** Create the super-administrator account. */
    public static function admin(): string
    {
        $s   = self::session();
        $pdo = self::connect();

        $peppered = hash_hmac('sha256', $s['admin']['password'], $s['crypto']['pepper']);
        $hash     = password_hash($peppered, PASSWORD_BCRYPT);

        $stmt = $pdo->prepare(
            'INSERT INTO `users` (username, email, password, priv_level, is_verified, created_at)
             VALUES (?, ?, ?, 5, 1, NOW())'
        );
        $stmt->execute([
            $s['admin']['username'],
            $s['admin']['email'],
            $hash,
        ]);

        // The plaintext password is no longer needed after this point.
        unset($_SESSION['setup_admin']['password']);

        return 'Administrator "' . $s['admin']['username'] . '" created';
    }

    /** Write the base settings. */
    public static function settings(): string
    {
        $s   = self::session();
        $pdo = self::connect();

        $settings = [
            'cms_name'           => $s['cms']['cms_name'],
            'language'           => $s['cms']['language'],
            'timezone'           => $s['cms']['timezone'],
            'game_server_core'   => $s['game']['core'],
            'game_server_bridge_port' => (string)($s['console']['bridge_port'] ?? 2000),
            'game_server_cms_api_url' => (string)(
                $s['console']['cms_api_url']
                ?? (rtrim((string)$s['cms']['base_url'], '/') . '/api_events.php')
            ),
            'game_server_console_host' => (string)($s['console']['host'] ?? '127.0.0.1'),
            'game_server_console_port' => (string)($s['console']['port'] ?? 5100),
            'game_server_shared_secret' => $s['cms']['asp_key'] ?? $s['crypto']['asp_key'],
            'discord_bot_token'  => $s['cms']['discord_token'],
            'discord_guild_id'   => $s['cms']['discord_guild'],
            'settings_version'   => (string) time(),
            'cms_schema_version' => self::SCHEMA_BASELINE,
        ];

        $stmt = $pdo->prepare(
            'INSERT INTO `settings` (setting_key, value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );

        $written = 0;
        foreach ($settings as $key => $value) {
            if (!empty($value) || $key === 'settings_version') {
                $stmt->execute([$key, $value]);
                $written++;
            }
        }

        return $written . ' settings saved';
    }

    /** Run a named phase. */
    public static function run(string $phase): string
    {
        switch ($phase) {
            case 'config':   return self::config();
            case 'connect':  return self::verify();
            case 'schema':   return self::schema();
            case 'rename':   return self::rename();
            case 'admin':    return self::admin();
            case 'settings': return self::settings();
        }

        throw new RuntimeException('Unknown installation phase: ' . $phase);
    }
}
