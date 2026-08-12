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

// ============================================================
// PLUGININTERFACE FALLBACK
// Defined here so it is already known to the hook loader in db.php,
// regardless of whether plugin_manager_logic.php
// has already been loaded or not.
// ============================================================
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

// Internal hook registry
$GLOBALS['_cms_hooks'] = [];

// Allowed hookpoints - anything else is silently ignored
define('CMS_ALLOWED_HOOKS', [
    // Frontend
    'hook_head',
    'hook_after_header',
    'hook_sidebar_nav',
    'hook_footer',
    // ACP Output
    'hook_acp_head',
    'hook_acp_sidebar_nav',
    'hook_acp_dashboard_top',
    'hook_acp_dashboard_modules',
    // ACP register (return arrays, not HTML)
    'hook_acp_register_section',
    'hook_acp_register_view',
]);

/**
 * Register a hook.
 *
 * @param string   $point    Hookpoint name (must be in CMS_ALLOWED_HOOKS)
 * @param callable $callback Returns a string that gets inserted into the DOM
 */
function cms_register_hook(string $point, callable $callback): void
{
    if (!in_array($point, CMS_ALLOWED_HOOKS, true)) {
        error_log("CMS Hook: Invalid hookpoint '$point' was ignored.");
        return;
    }
    $GLOBALS['_cms_hooks'][$point][] = $callback;
}

/**
 * Run all callbacks of a hookpoint.
 *
 * For output hooks (HTML): returns a filtered string.
 * For register hooks (hook_acp_register_*): returns an array.
 *
 * @param string $point  Hookpoint name
 * @param string $filter 'purify' | 'raw' | 'script'
 * @return string|array
 */
function cms_run_hook(string $point, string $filter = 'purify')
{
    if (empty($GLOBALS['_cms_hooks'][$point])) {
        return str_starts_with($point, 'hook_acp_register_') ? [] : '';
    }

    // Register hooks: collect and return arrays
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

    // Output hooks: collect and filter HTML
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

/**
 * Security filter for hook_head.
 * Only allows <link rel="stylesheet"> and <meta> tags.
 * Prevents plugins from injecting <script> tags into the head.
 */
function _cms_filter_head(string $html): string
{
    $allowed = [
        '/<link\s+rel=["\']stylesheet["\']\s+href=["\']https?:\/\/[^"\'<>]+["\']\s*\/?>/i',
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

/**
 * Security filter for hook_after_header.
 * Only allows inline <script> tags without a src attribute.
 * No external JS loadable, no event-handler abuse via src.
 */
function _cms_filter_script(string $html): string
{
    // Only allow <script> without src (inline JS only)
    if (preg_match_all('/<script(?![^>]*\bsrc\b)[^>]*>(.*?)<\/script>/is', $html, $matches)) {
        $output = '';
        foreach ($matches[0] as $tag) {
            // No document.cookie, no fetch to external URLs
            if (stripos($tag, 'document.cookie') !== false) continue;
            if (preg_match('/fetch\s*\(\s*["\'](https?:)?\/\//i', $tag))  continue;
            $output .= $tag . "\n";
        }
        return $output;
    }
    return '';
}

/**
 * Security filter for hook_acp_dashboard_* hooks.
 *
 * Allows structured HTML for ACP cards and notifications:
 *   Allowed tags: div, a, span, i, p, strong, em, small, h2-h6, ul, ol, li, br, hr
 *   Allowed attributes: class, style, href, title, id, data-*
 *
 * Always blocks:
 *   - <script>, <iframe>, <form>, <input>, <button>, <object>, <embed>
 *   - Event-Handler Attribute (on*)
 *   - href="javascript:..."
 *   - style attributes with url(), expression(), javascript:
 */
function _cms_filter_acp_card(string $html): string
{
    // Remove forbidden tags entirely (incl. content for script/iframe)
    $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
    $html = preg_replace('/<iframe\b[^>]*>.*?<\/iframe>/is',  '', $html);
    $html = preg_replace('/<(form|input|button|select|textarea|object|embed|applet)\b[^>]*>/i', '', $html);

    // Remove event-handler attributes (onclick, onload, onmouseover, ...)
    $html = preg_replace('/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $html);

    // Block javascript: in href/src/action
    $html = preg_replace('/\b(href|src|action)\s*=\s*["\']?\s*javascript:[^"\'>\s]*/i', 'href="#"', $html);

    // Block dangerous CSS values: url(), expression(), javascript:
    $html = preg_replace_callback('/style\s*=\s*"([^"]*)"/i', function($m) {
        if (preg_match('/(url\s*\(|expression\s*\(|javascript:)/i', $m[1])) {
            return '';
        }
        return $m[0];
    }, $html);

    // Remove forbidden tags but KEEP attributes of allowed tags
    // strip_tags() would remove all attributes - hence the regex approach
    $forbidden = 'script|iframe|form|input|button|select|textarea|object|embed|applet|meta|link|base|svg|math';
    $html = preg_replace('/<\/?(' . $forbidden . ')\b[^>]*>/i', '', $html);

    return $html;
}

if (defined('IN_ACP')) {
    cms_register_hook('hook_acp_dashboard_top', static function (): string {
        ob_start();
        include __DIR__ . '/../modules/acp_update_status_widget.php';
        return (string) ob_get_clean();
    });
}
