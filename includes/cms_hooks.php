<?php
/**
 * DAoC CMS - Hook System
 *
 * Secure hookpoint system for the plugin manager.
 *
 * Available hookpoints:
 *
 * FRONTEND:
 *   hook_head                → In <head> (CSS links, meta tags)         Filter: raw
 *   hook_after_header        → Directly after the <header>              Filter: script
 *   hook_sidebar_nav         → At the end of the sidebar navigation     Filter: purify
 *   hook_footer              → In the footer before </body>             Filter: purify
 *
 * ACP OUTPUT:
 *   hook_acp_head            → In the ACP <head> (CSS links, meta tags) Filter: raw
 *   hook_acp_sidebar_nav     → Additional nav links in ACP sidebar      Filter: purify
 *   hook_acp_dashboard_top     → At the top of the ACP dashboard       Filter: acp_card
 *   hook_acp_dashboard_modules → Cards in the ACP dashboard grid       Filter: acp_card
 *
 * ACP REGISTER (return arrays, not HTML):
 *   hook_acp_register_section → Plugin registers itself in allowed_sections
 *                               Callback returns:
 *                               ['my_slug' => ['min_priv' => 4, 'label' => '...', 'icon' => 'fa-...']]
 *
 *   hook_acp_register_view    → Plugin registers its view callable
 *                               Callback returns:
 *                               ['my_slug' => fn() => 'HTML...']
 */

if (defined('ALDHRAN_HOOKS_LOADED')) return;
define('ALDHRAN_HOOKS_LOADED', true);

if (!interface_exists('PluginInterface')) {
    interface PluginInterface {
        public static function getMetadata(): array;
        public function __construct(\PDO $db, int $userPriv, int $currentUserId);
        public function initialize(): void;
        public function registerHooks(): void;
        public function uninstall(): bool;
        public function render(): string;
    }
}

$GLOBALS['_cms_hooks'] = [];

define('CMS_ALLOWED_HOOKS', [
    'hook_head',
    'hook_after_header',
    'hook_sidebar_nav',
    'hook_footer',
    'hook_acp_head',
    'hook_acp_sidebar_nav',
    'hook_acp_dashboard_top',
    'hook_acp_dashboard_modules',
    'hook_acp_register_section',
    'hook_acp_register_view',
]);

function cms_register_hook(string $point, callable $callback): void
{
    if (!in_array($point, CMS_ALLOWED_HOOKS, true)) {
        error_log("CMS Hook: Invalid hookpoint '$point' was ignored.");
        return;
    }
    $GLOBALS['_cms_hooks'][$point][] = $callback;
}

function cms_run_hook(string $point, string $filter = 'purify')
{
    if (empty($GLOBALS['_cms_hooks'][$point])) {
        return str_starts_with($point, 'hook_acp_register_') ? [] : '';
    }

    if (str_starts_with($point, 'hook_acp_register_')) {
        $collected = [];
        foreach ($GLOBALS['_cms_hooks'][$point] as $cb) {
            try {
                $result = $cb();
                if (is_array($result)) {
                    $collected[] = $result;
                }
            } catch (\Throwable $e) {
                error_log("CMS Hook Error in '$point': " . $e->getMessage());
            }
        }
        return $collected;
    }

    $output = '';
    foreach ($GLOBALS['_cms_hooks'][$point] as $cb) {
        try {
            $result = $cb();
            if (!is_string($result)) continue;

            if ($filter === 'raw') {
                $output .= _cms_filter_head($result);
            } elseif ($filter === 'script') {
                $output .= _cms_filter_script($result);
            } elseif ($filter === 'acp_card') {
                $output .= _cms_filter_acp_card($result);
            } else {
                $output .= purify($result);
            }
        } catch (\Throwable $e) {
            error_log("CMS Hook Error in '$point': " . $e->getMessage());
        }
    }

    return $output;
}

function _cms_filter_head(string $html): string
{
    $allowed = [
        '/<link\s+rel=["\']stylesheet["\']\s+href=["\']https?:\/\/[^"\'<>]+["\']\s*\/?>/i',
        '/<link\s+rel=["\']canonical["\']\s+href=["\']https?:\/\/[^"\'<>]+["\']\s*\/?>/i',
        '/<meta\s+[^>]+>/i',
    ];

    $output = '';
    foreach ($allowed as $pattern) {
        if (preg_match_all($pattern, $html, $matches)) {
            foreach ($matches[0] as $tag) {
                if (stripos($tag, 'javascript') !== false) continue;
                if (stripos($tag, 'data:')       !== false) continue;
                if (stripos($tag, 'http-equiv')  !== false) continue;
                $output .= $tag . "\n    ";
            }
        }
    }

    return $output;
}

function _cms_filter_script(string $html): string
{
    if (preg_match_all('/<script(?![^>]*\bsrc\b)[^>]*>(.*?)<\/script>/is', $html, $matches)) {
        $output = '';
        foreach ($matches[0] as $tag) {
            if (stripos($tag, 'document.cookie') !== false) continue;
            if (preg_match('/fetch\s*\(\s*["\'](https?:)?\/\//i', $tag))  continue;
            $output .= $tag . "\n";
        }
        return $output;
    }
    return '';
}

function _cms_filter_acp_card(string $html): string
{
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
    $html = preg_replace('/<iframe\b[^>]*>.*?<\/iframe>/is',  '', $html);
    $html = preg_replace('/<(form|input|button|select|textarea|object|embed|applet)\b[^>]*>/i', '', $html);
    $html = preg_replace('/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $html);
    $html = preg_replace('/\b(href|src|action)\s*=\s*["\']?\s*javascript:[^"\'>\s]*/i', 'href="#"', $html);
    $html = preg_replace_callback('/style\s*=\s*"([^"]*)"/i', function($m) {
        if (preg_match('/(url\s*\(|expression\s*\(|javascript:)/i', $m[1])) {
            return '';
        }
        return $m[0];
    }, $html);

    $forbidden = 'script|iframe|form|input|button|select|textarea|object|embed|applet|meta|link|base|svg|math';
    $html = preg_replace('/<\/?(' . $forbidden . ')\b[^>]*>/i', '', $html);

    return $html;
}

cms_register_hook('hook_head', static function (): string {
    if (!defined('SITE_URL') || !preg_match('~^https?://~i', SITE_URL)) {
        return '';
    }

    $params = $_GET;
    $page = preg_replace('/[^a-z0-9_\-]/i', '', (string)($params['p'] ?? 'home')) ?: 'home';
    $params['p'] = $page;

    $drop = [
        'msg', 'timeout', 'ref', 'edit_mode', 'csrf_token',
        'token', 'code', 'key', 'secret', 'password', 'fbclid', 'gclid'
    ];

    foreach (array_keys($params) as $key) {
        $lower = strtolower((string)$key);
        if (in_array($lower, $drop, true) || str_starts_with($lower, 'utm_')) {
            unset($params[$key]);
        }
    }

    ksort($params);
    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $url = rtrim(SITE_URL, '/') . '/index.php' . ($query !== '' ? '?' . $query : '');

    return '<link rel="canonical" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
});

if (defined('IN_ACP')) {
    cms_register_hook('hook_acp_dashboard_top', static function (): string {
        ob_start();
        include __DIR__ . '/../modules/acp_update_status_widget.php';
        return (string) ob_get_clean();
    });
}