<?php
/**
 * TEMPLATE ENGINE - DAoC CMS
 * Native PHP Templating with Secure Theme Override Support
 */
if (!defined('IN_CMS')) { exit; }

function cms_render_template(string $template_name, array $variables = []) {
    // 1. Filesystem Security
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $template_name)) {
        echo '<div class="info-msg">Security Violation: Invalid Template Request.</div>';
        return;
    }

    // 2. Determine the active theme
    $active_theme = $GLOBALS['cms_settings']['active_theme'] ?? 'default';
    $active_theme = preg_replace('/[^a-zA-Z0-9_\-]/', '', $active_theme);

    // 3. Resolve absolute paths from htdocs/includes to the project root.
    $root_dir = realpath(__DIR__ . '/..');

    // Search the active theme first, then the default template directory.
    $theme_file    = $root_dir . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $active_theme . DIRECTORY_SEPARATOR . $template_name . '.php';
    $fallback_file = $root_dir . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $template_name . '.php';

    $file_to_load = '';

    // 4. Apply theme override logic.
    if (file_exists($theme_file)) {
        $file_to_load = $theme_file;
    } elseif (file_exists($fallback_file)) {
        $file_to_load = $fallback_file;
    } else {
        // Fatal error with exact path info, so we never have to guess blindly
        die("<div style='background:#fff; color:#000; padding:20px; border-left:5px solid red; font-family:sans-serif; z-index:9999; position:relative;'>
            <strong>Architecture error: template not found!</strong><br><br>
            The CMS looked for: <code>{$template_name}.php</code><br>
            Paths searched:<br>
            1. Theme path: <code>{$theme_file}</code><br>
            2. Fallback path: <code>{$fallback_file}</code><br><br>
            Please create the file at one of these paths.
        </div>");
    }

    // 5. Isolate and unpack the template variable scope.
    extract($variables, EXTR_SKIP);

    // 6. Load the template
    require $file_to_load;
}
