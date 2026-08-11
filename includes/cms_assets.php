<?php
if (defined('ALDHRAN_ASSETS_LOADED')) return;
define('ALDHRAN_ASSETS_LOADED', true);

class AldhranAssets
{
    private static array $external = [];
    private static array $inline = [];
    private static array $rendered = [];

    private const ALLOWED_TYPES  = ['css', 'js'];
    private const ALLOWED_SCOPES = ['frontend', 'acp'];

    // Whitelist cleared for GDPR compliance (intentionally empty, everything runs locally)
    private const ALLOWED_CDN_DOMAINS = [];

    /**
     * Initializes default assets needed everywhere.
     */
    public static function init(): void
    {
        // Font Awesome, local, for both areas
        self::register('css', 'assets/css/fontawesome.min.css', 1, 'acp', 'core-awesome');
        self::register('css', 'assets/css/fontawesome.min.css', 1, 'frontend', 'core-awesome');

        // ACP layout/component styles - moved out of aldhran_styles (DB) into
        // a static file, since none of it is themeable per-shard like the
        // frontend CSS is.
        self::register('css', 'assets/css/acp.css', 2, 'acp', 'core-acp');

        // Local fonts for the ACP (frontend already loads them automatically via style.php)
        self::register('css', 'style.php?module=local_fonts', 3, 'acp', 'core-font');
    }

    public static function register(string $type, string $path, int $priority = 10, string $scope = 'frontend', string $id = ''): void 
    {
        if (!self::validateType($type) || !self::validateScope($scope) || !self::validatePath($path)) return;
        $id = $id ?: md5($type . $path . $scope);
        if (isset(self::$rendered[$id])) return;
        self::$external[$scope][$type][] = ['path' => $path, 'priority' => $priority, 'id' => $id];
    }

    public static function registerInline(string $type, string $content, string $scope = 'frontend', string $id = ''): void 
    {
        if (!self::validateType($type) || !self::validateScope($scope)) return;
        $content = trim($content);
        if ($content === '') return;
        $id = $id ?: md5($type . $content . $scope);
        if (isset(self::$rendered[$id])) return;
        self::$inline[$scope][$type][] = ['content' => $content, 'id' => $id];
    }

    public static function render(string $scope = 'frontend'): string
    {
        if (!self::validateScope($scope)) return '';
        $out = "\n\n";

        // External CSS
        $cssList = self::$external[$scope]['css'] ?? [];
        usort($cssList, fn($a, $b) => $a['priority'] <=> $b['priority']);
        foreach ($cssList as $asset) {
            if (isset(self::$rendered[$asset['id']])) continue;
            $href = htmlspecialchars($asset['path'], ENT_QUOTES, 'UTF-8');
            $out .= "<link rel=\"stylesheet\" href=\"$href\">\n";
            self::$rendered[$asset['id']] = true;
        }

        // Inline CSS
        $inlineCss = self::$inline[$scope]['css'] ?? [];
        $inlineCss = array_filter($inlineCss, fn($b) => !isset(self::$rendered[$b['id']]));
        if (!empty($inlineCss)) {
            $out .= "<style>\n";
            foreach ($inlineCss as $block) {
                $out .= "/* asset:{$block['id']} */\n" . $block['content'] . "\n";
                self::$rendered[$block['id']] = true;
            }
            $out .= "</style>\n";
        }

        // External JS
        $jsList = self::$external[$scope]['js'] ?? [];
        usort($jsList, fn($a, $b) => $a['priority'] <=> $b['priority']);
        foreach ($jsList as $asset) {
            if (isset(self::$rendered[$asset['id']])) continue;
            $src = htmlspecialchars($asset['path'], ENT_QUOTES, 'UTF-8');
            $out .= "<script src=\"$src\"></script>\n";
            self::$rendered[$asset['id']] = true;
        }

        // Inline JS
        $inlineJs = self::$inline[$scope]['js'] ?? [];
        $inlineJs = array_filter($inlineJs, fn($b) => !isset(self::$rendered[$b['id']]));
        if (!empty($inlineJs)) {
            $out .= "<script>\n";
            foreach ($inlineJs as $block) {
                $out .= "/* asset:{$block['id']} */\n" . $block['content'] . "\n";
                self::$rendered[$block['id']] = true;
            }
            $out .= "</script>\n";
        }

        return $out . "\n";
    }

    public static function renderTokens(array $settings): string
    {
        $tokens = [];
        foreach ($settings as $key => $value) {
            if (strpos($key, 'token_') !== 0) continue;
            $varName  = '--ald-' . str_replace('_', '-', substr($key, 6));
            $varValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            $tokens[] = "    $varName: $varValue;";
        }
        return empty($tokens) ? '' : "<style>\n:root {\n" . implode("\n", $tokens) . "\n}\n</style>\n";
    }

    public static function pluginContainerOpen(string $pluginSlug): string {
        return "<div class=\"ald-plugin-container\" data-plugin=\"".htmlspecialchars($pluginSlug)."\">\n";
    }

    public static function pluginContainerClose(): string {
        return "</div>\n";
    }

    private static function validateType($t) { return in_array($t, self::ALLOWED_TYPES); }
    private static function validateScope($s) { return in_array($s, self::ALLOWED_SCOPES); }
    private static function validatePath($path) {
        if (!str_starts_with($path, 'http')) return !str_contains($path, '..');
        return in_array(parse_url($path, PHP_URL_HOST), self::ALLOWED_CDN_DOMAINS);
    }
}

// Kickstart
AldhranAssets::init();