<?php
if (!defined('IN_ACP')) exit;
if ((int)($_SESSION['priv_level'] ?? 0) < 3) return;

// ── AJAX-Handler ──────────────────────────────────────────────
if (isset($_GET['ajax']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    checkToken($_POST['csrf_token'] ?? '');

    $action  = $_POST['action'] ?? '';
    $results = [];
    if (in_array($action, ['clear_css', 'clear_all'])) {
        try {
            $db->prepare(
                "INSERT INTO settings (setting_key, value)
                 VALUES ('css_version', '2')
                 ON DUPLICATE KEY UPDATE value = CAST(value AS UNSIGNED) + 1"
            )->execute();

            $db->prepare(
                "INSERT INTO settings (setting_key, value)
                 VALUES ('cache_css_cleared_at', ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)"
            )->execute([date('Y-m-d H:i:s')]);

            $results['css'] = ['ok' => true, 'msg' => 'CSS cache cleared – new version active.'];
        } catch (\Throwable $e) {
            $results['css'] = ['ok' => false, 'msg' => 'DB error: ' . $e->getMessage()];
        }
    }

    if (in_array($action, ['clear_opcache', 'clear_all'])) {
        if (function_exists('opcache_reset')) {
            $ok = opcache_reset();
            $results['opcache'] = [
                'ok'  => $ok,
                'msg' => $ok ? 'OPcache cleared successfully.' : 'opcache_reset() returned false.',
            ];
        } else {
            $results['opcache'] = ['ok' => false, 'msg' => 'OPcache extension not available on this server.'];
        }

        try {
            $db->prepare(
                "INSERT INTO settings (setting_key, value)
                 VALUES ('cache_opcache_cleared_at', ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value)"
            )->execute([date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {}
    }

    aldhran_log('CACHE_CLEAR', 'Cache cleared: ' . $action, (int)($_SESSION['user_id'] ?? 0));
    echo json_encode(['ok' => true, 'results' => $results]);
    exit;
}

try {
    $cache_settings = $db->query(
        "SELECT setting_key, value FROM settings
          WHERE setting_key IN (
              'css_version',
              'cache_css_cleared_at',
              'cache_opcache_cleared_at'
          )"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (\Throwable $e) {
    $cache_settings = [];
}

$cache_css_version    = $cache_settings['css_version']              ?? '1';
$cache_css_cleared_at = $cache_settings['cache_css_cleared_at']     ?? null;
$cache_opc_cleared_at = $cache_settings['cache_opcache_cleared_at'] ?? null;
$opcache_available    = function_exists('opcache_reset');
$opcache_status       = [];

if (function_exists('opcache_get_status')) {
    $raw = @opcache_get_status(false);
    if ($raw) {
        $opcache_status = [
            'enabled'        => $raw['opcache_enabled']                        ?? false,
            'cached_scripts' => $raw['opcache_statistics']['num_cached_scripts'] ?? 0,
            'hits'           => $raw['opcache_statistics']['hits']               ?? 0,
            'misses'         => $raw['opcache_statistics']['misses']             ?? 0,
            'memory_used'    => round(($raw['memory_usage']['used_memory']       ?? 0) / 1024 / 1024, 1),
            'memory_free'    => round(($raw['memory_usage']['free_memory']       ?? 0) / 1024 / 1024, 1),
        ];
    }
}
