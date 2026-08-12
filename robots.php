<?php
// SPDX-License-Identifier: GPL-3.0-only

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

$siteUrl = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : '';
if (!defined('SITE_URL')) {
    $config = __DIR__ . '/includes/config.php';
    if (is_file($config)) {
        require_once $config;
        $siteUrl = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : '';
    }
}

$basePath = '/';
if ($siteUrl !== '') {
    $path = (string)(parse_url($siteUrl, PHP_URL_PATH) ?? '');
    $path = '/' . trim($path, '/');
    $basePath = $path === '/' ? '/' : $path . '/';
}

$prefix = $basePath === '/' ? '/' : $basePath;

echo "User-agent: *\n";
echo "Allow: /\n";
echo 'Disallow: ' . $prefix . "acp.php\n";
echo 'Disallow: ' . $prefix . "setup/\n";
echo 'Disallow: ' . $prefix . "migrate.php\n";

if (preg_match('~^https?://~i', $siteUrl)) {
    echo 'Sitemap: ' . $siteUrl . "/sitemap.php\n";
}
