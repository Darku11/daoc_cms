<?php
// SPDX-License-Identifier: GPL-3.0-only
if (defined('ALDHRAN_DB_LOADED')) return;
define('ALDHRAN_DB_LOADED', true);

require_once __DIR__ . '/config.php';

ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.cookie_secure', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.gc_maxlifetime', 3600);
ini_set('session.cookie_lifetime', 0);

$session_prefix = defined('INSTANCE_ID') ? preg_replace('/[^a-zA-Z0-9]+/', '', INSTANCE_ID) : 'DEFAULT';
session_name('DAOC_SESSID_' . $session_prefix);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

aldhran_send_security_headers();

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../php_errors.log');
error_reporting(E_ALL);

class AldhranDB {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        $charset = 'utf8mb4';
        $dsn     = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (\PDOException $e) {
            error_log("Connection failed: " . $e->getMessage());
            die("DAoC CMS Bridge Error: Contact Staff.");
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance->pdo;
    }
}

$db = AldhranDB::getInstance();

require_once(__DIR__ . '/cms_errortracker.php');
$errorTracker = new ErrorTracker($db);

require_once(__DIR__ . '/botsettings.php');
$botSettings = new BotSettings($db);

if (file_exists(__DIR__ . '/AiManager.php')) {
    require_once(__DIR__ . '/AiManager.php');
}

if (file_exists(__DIR__ . '/BotEventDispatcher.php')) {
    require_once(__DIR__ . '/BotEventDispatcher.php');
    $GLOBALS['botDispatcher'] = new BotEventDispatcher($db, $botSettings);
}

try {
    $stmt_settings = $db->query("SELECT setting_key, value FROM settings");
    $GLOBALS['cms_settings'] = $stmt_settings->fetchAll(PDO::FETCH_KEY_PAIR);

    $stmt_langs = $db->query("SELECT lang_code FROM cms_languages WHERE is_active = 1");
    $GLOBALS['cms_allowed_languages'] = $stmt_langs->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Failed to load global CMS settings: " . $e->getMessage());
    $GLOBALS['cms_settings'] = [];
    $GLOBALS['cms_allowed_languages'] = ['en'];
}

require_once __DIR__ . '/game_server_compat.php';

$_current_settings_version = $GLOBALS['cms_settings']['settings_version'] ?? '1';

if (($_SESSION['cms_cache_version'] ?? '') !== $_current_settings_version) {
    unset($_SESSION['cms_cache']);
    unset($_SESSION['lang']);
    $_SESSION['cms_cache_version'] = $_current_settings_version;
}

$last_cleanup = (int)($GLOBALS['cms_settings']['log_cleanup_last_run'] ?? 0);
if (time() - $last_cleanup > 86400) {
    try {
        $user_actions = ['LOGIN_SUCCESS', 'LOGIN_FAIL', 'REGISTER', 'PASSWORD_CHANGE', 'AVATAR_CHANGE', 'AVATAR_DELETE', 'GDPR_EXPORT', 'PROFILE_UPDATE', 'GDPR_DELETE_FAIL'];
        $placeholders = implode(',', array_fill(0, count($user_actions), '?'));
        
        $stmt = $db->prepare("DELETE FROM aldhran_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) AND action_type IN ($placeholders)");
        $stmt->execute($user_actions);

        $db->exec("DELETE FROM users WHERE is_verified = 0 AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        
        $db->query("INSERT INTO settings (setting_key, value) VALUES ('log_cleanup_last_run', '" . time() . "') ON DUPLICATE KEY UPDATE value = VALUES(value)");
    } catch (Exception $e) {
        error_log("DSGVO log cleanup failed: " . $e->getMessage());
    }
}

function aldhran_log($action, $details = "", $user_id = null, $target_id = null) {
    global $db;
    static $in_critical_log = false;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    
    if (strpos($ip, '.') !== false) {
        $ip = preg_replace('/\.\d+$/', '.0', $ip);
    } elseif (strpos($ip, ':') !== false) {
        $ip = preg_replace('/:[0-9a-fA-F]+$/', ':0', $ip);
    }

    try {
        $stmt = $db->prepare("INSERT INTO aldhran_logs (user_id, target_id, action_type, details, ip_address) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $target_id, $action, $details, $ip]);

        // A critical alert must describe a confirmed high-impact condition, not a
        // routine rejected request or a single authentication failure. Generic
        // SECURITY_ALERT, CSRF and ACP re-authentication events remain available in
        // the Audit Trail without triggering the site-wide banner or admin email.
        $critical_actions = ['AUDIT_LOG_TAMPERING', 'REGISTRATION_IP_THRESHOLD'];
        if (!$in_critical_log && in_array($action, $critical_actions, true)) {
            $in_critical_log = true;

            $stmt_flag = $db->prepare("SELECT value FROM settings WHERE setting_key = 'has_critical_error'");
            $stmt_flag->execute();
            $already_flagged = ($stmt_flag->fetchColumn() ?: '0') === '1';

            $db->exec("INSERT INTO settings (setting_key, value) VALUES ('has_critical_error', '1') ON DUPLICATE KEY UPDATE value = '1'");

            // Only email admins for a NEW incident, not on every single error while
            // an existing one is still unacknowledged (see admin_log Acknowledge button).
            if (!$already_flagged) {
                $stmt_sa = $db->prepare("SELECT email FROM users WHERE priv_level >= 5");
                $stmt_sa->execute();
                $sa_emails = $stmt_sa->fetchAll(PDO::FETCH_COLUMN);

                $subject = "DAoC CMS Alert: Critical Log Event (" . $action . ")";
                $message = "A critical event was logged in Audit Log:<br><br><b>Action:</b> " . h($action) . "<br><b>Details:</b> " . h($details) . "<br><b>IP:</b> " . h($ip) . "<br><b>Timestamp:</b> " . date('Y-m-d H:i:s');

                foreach ($sa_emails as $sa_email) {
                    if (!empty($sa_email)) {
                        if (($GLOBALS['cms_settings']['use_resend_api'] ?? '0') === '1') {
                            aldhran_api_mail($sa_email, $subject, $message);
                        } elseif (function_exists('aldhran_send_mail')) {
                            aldhran_send_mail($sa_email, $subject, $message);
                        } else {
                            aldhran_api_mail($sa_email, $subject, $message);
                        }
                    }
                }
            }
            $in_critical_log = false;
        }
    } catch (Exception $e) {
        error_log("Logging failed: " . $e->getMessage());
    }
}

function logAction($conn, $admin_id, $target_id, $action_type, $details) {
    return aldhran_log($action_type, $details, $admin_id, $target_id);
}

require_once __DIR__ . '/htmlpurifier/library/HTMLPurifier.auto.php';

function aldhran_hash($password) {
    $peppered = hash_hmac("sha256", $password, ALDRAN_PEPPER);
    return password_hash($peppered, PASSWORD_BCRYPT);
}

function aldhran_verify($password, $hash) {
    $peppered = hash_hmac("sha256", $password, ALDRAN_PEPPER);
    return password_verify($peppered, $hash);
}

function aldhran_send_mail($to, $subject, $message) {
    if (defined('MAIL_DRIVER') && MAIL_DRIVER === 'smtp') {
        $host = defined('SMTP_HOST') ? SMTP_HOST : 'localhost';
        $port = defined('SMTP_PORT') ? SMTP_PORT : 1025;
        $user = defined('SMTP_USER') ? SMTP_USER : '';
        $pass = defined('SMTP_PASS') ? SMTP_PASS : '';
        $from = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'noreply@localhost';
        $name = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'DAoC CMS';

        $socket = @fsockopen($host, $port, $errno, $errstr, 10);
        if (!$socket) return false;

        fgets($socket, 515);
        fputs($socket, "EHLO " . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost') . "\r\n");
        fgets($socket, 515);

        if (!empty($user) && !empty($pass)) {
            fputs($socket, "AUTH LOGIN\r\n");
            fgets($socket, 515);
            fputs($socket, base64_encode($user) . "\r\n");
            fgets($socket, 515);
            fputs($socket, base64_encode($pass) . "\r\n");
            fgets($socket, 515);
        }

        fputs($socket, "MAIL FROM: <$from>\r\n");
        fgets($socket, 515);
        fputs($socket, "RCPT TO: <$to>\r\n");
        fgets($socket, 515);
        fputs($socket, "DATA\r\n");
        fgets($socket, 515);

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: =?UTF-8?B?" . base64_encode($name) . "?= <$from>\r\n";
        $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";

        fputs($socket, $headers . "\r\n" . $message . "\r\n.\r\n");
        fgets($socket, 515);

        fputs($socket, "QUIT\r\n");
        fclose($socket);
        return true;
    }

    $from = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'noreply@localhost';
    $name = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'DAoC CMS';
    $headers = "MIME-Version: 1.0\r\nContent-type:text/html;charset=UTF-8\r\nFrom: $name <$from>\r\n";
    return mail($to, $subject, $message, $headers);
}

function aldhran_api_mail($to, $subject, $html_message) {
    $api_key = defined('RESEND_API_KEY') ? RESEND_API_KEY : (defined('SMTP_PASS') ? SMTP_PASS : '');
    
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json'
    ]);
    
    $from_email = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'noreply@localhost';
    $from_name  = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'DAoC CMS';

    $payload = json_encode([
        'from'    => $from_name . ' <' . $from_email . '>',
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $html_message
    ]);
    
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return $response;
}

function h($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

function purify($html) {
    static $purifier = null;
    if ($purifier === null) {
        $config = HTMLPurifier_Config::createDefault();
        $purifier = new HTMLPurifier($config);
    }
    return $purifier->purify($html);
}

function generateToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function checkToken($token) {
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        aldhran_log("SECURITY_ALERT", "CSRF Token Validation Failed", $_SESSION['user_id'] ?? null);
        $isJson = false;
        foreach (headers_list() as $h) {
            if (stripos($h, 'Content-Type:') === 0 && stripos($h, 'application/json') !== false) { $isJson = true; break; }
        }
        if ($isJson) {
            die(json_encode(['ok' => false, 'success' => false, 'error' => 'csrf_invalid']));
        }
        die("CSRF token validation failed. Possible attack detected.");
    }
    return true;
}

function aldhran_session_regenerate() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

function aldhran_send_security_headers() {
    if (headers_sent()) return;
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()");
    header("Content-Security-Policy: " .
        "default-src 'self'; " .
        "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://code.jquery.com https://cdn.ckeditor.com https://cdnjs.cloudflare.com https://js.hcaptcha.com https://newassets.hcaptcha.com https://cdn.quilljs.com https://cdn.jsdelivr.net; " .
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://newassets.hcaptcha.com https://cdn.quilljs.com https://cdn.jsdelivr.net; " .
        "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; " .
        "img-src 'self' data: https:; " .
        "object-src 'none'; " .
        "frame-src https://hcaptcha.com https://*.hcaptcha.com; " .
        "frame-ancestors 'self'; " .
        "connect-src 'self' https://cdnjs.cloudflare.com https://hcaptcha.com https://*.hcaptcha.com https://newassets.hcaptcha.com;"
    );
}

define('ALDHRAN_RATE_LIMIT_DIR', sys_get_temp_dir() . '/aldhran_rl/');

function aldhran_rate_limit(string $key, int $max = 5, int $decay = 600): void {
    if (!is_dir(ALDHRAN_RATE_LIMIT_DIR)) {
        @mkdir(ALDHRAN_RATE_LIMIT_DIR, 0700, true);
    }
    $file = ALDHRAN_RATE_LIMIT_DIR . md5($key) . '.rl';
    $now  = time();

    $fp = @fopen($file, 'c+');
    if (!$fp) return; // fail open rather than blocking legitimate traffic on a filesystem error

    flock($fp, LOCK_EX);

    $raw = stream_get_contents($fp);
    $data = ['count' => 0, 'first' => $now];
    if ($raw) {
        $decoded = json_decode($raw, true);
        if ($decoded) $data = $decoded;
    }
    if (($now - $data['first']) > $decay) {
        $data = ['count' => 0, 'first' => $now];
    }
    $data['count']++;

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($data['count'] > $max) {
        $retry = $decay - ($now - $data['first']);
        http_response_code(429);
        die("Too many attempts. Please wait " . ceil($retry / 60) . " minute(s).");
    }
}

function aldhran_rate_limit_clear(string $key): void {
    $file = ALDHRAN_RATE_LIMIT_DIR . md5($key) . '.rl';
    if (file_exists($file)) {
        @unlink($file);
    }
}

function getRoleName($level) {
    $roles = [
        1 => '<span class="role-badge role-player">Player</span>',
        2 => '<span class="role-badge role-associate">Associate</span>',
        3 => '<span class="role-badge role-gm">GM</span>',
        4 => '<span class="role-badge role-admin">Admin</span>',
        5 => '<span class="role-badge role-superadmin">Super Admin</span>'
    ];
    return $roles[$level] ?? '<span class="role-badge role-guest">Guest</span>';
}

function isSuperAdmin($user_id) {
    global $db;
    $stmt = $db->prepare("SELECT priv_level FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();
    return ($row && (int)$row['priv_level'] === 5);
}

function aldhran_bump_settings_version(): void {
    global $db;
    $version = (string)time();
    try {
        $db->prepare("UPDATE settings SET value = ? WHERE setting_key = 'settings_version'")
           ->execute([$version]);
    } catch (Exception $e) {
        error_log("Failed to bump settings_version: " . $e->getMessage());
    }
}

function aldhran_bump_css_version(): void {
    global $db;
    $version = (string)time();
    try {
        $db->prepare("UPDATE settings SET value = ? WHERE setting_key = 'css_version'")
           ->execute([$version]);
    } catch (Exception $e) {
        error_log("Failed to bump css_version: " . $e->getMessage());
    }
}

require_once(__DIR__ . '/cms_lang.php');
require_once(__DIR__ . '/cms_assets.php');
require_once(__DIR__ . '/cms_hooks.php');

(function() use ($db) {
    $pluginDir = realpath(__DIR__ . '/../plugins/') . DIRECTORY_SEPARATOR;
    if (!$pluginDir) return;

    try {
        $active = $db->query("
            SELECT filename, slug, min_priv
            FROM   aldhran_plugins
            WHERE  is_active  = 1
              AND  slug       != ''
              AND  slug       IS NOT NULL
              AND  slug NOT LIKE 'theme_%'
            ORDER  BY installed_at ASC
        ")->fetchAll();
    } catch (\Exception $e) {
        error_log("Hook loader: Failed to load plugins: " . $e->getMessage());
        return;
    }

    $userPriv      = (int)($_SESSION['priv_level'] ?? 0);
    $currentUserId = (int)($_SESSION['user_id']    ?? 0);

    global $_cms_plugin_instances;
    $_cms_plugin_instances = [];

    foreach ($active as $row) {
        if (empty(trim($row['slug']))) continue;

        $file = $pluginDir . basename($row['filename']);
        $realFile = realpath($file);

        if (!$realFile || strpos($realFile, $pluginDir) !== 0 || !file_exists($realFile)) continue;

        if ($userPriv < (int)$row['min_priv']) continue;

        try {
            require_once $realFile;
            $className = pathinfo($row['filename'], PATHINFO_FILENAME);
            if (!class_exists($className)) continue;

            $plugin = new $className($db, $userPriv, $currentUserId);
            $plugin->initialize();

            if (method_exists($plugin, 'registerHooks')) {
                $plugin->registerHooks();
            }

            $_cms_plugin_instances[$row['slug']] = $plugin;

        } catch (\Throwable $e) {
            error_log("Hook loader: Plugin '{$row['slug']}' error: " . $e->getMessage());
        }
    }
})();
