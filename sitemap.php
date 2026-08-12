<?php
// SPDX-License-Identifier: GPL-3.0-only

define('IN_CMS', true);
require_once __DIR__ . '/includes/db.php';

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=900');

$siteUrl = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : '';
if (!preg_match('~^https?://~i', $siteUrl)) {
    http_response_code(503);
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
    exit;
}

try {
    $stmt = $db->query("
        SELECT slug
        FROM pages
        WHERE status = 'published'
          AND (published_at IS NULL OR published_at <= NOW())
          AND min_priv <= 0
        ORDER BY slug ASC
    ");
    $slugs = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    error_log('Sitemap generation failed: ' . $e->getMessage());
    $slugs = [];
}

$urls = [];
foreach ($slugs as $slug) {
    $slug = preg_replace('/[^a-z0-9_\-]/i', '', (string)$slug);
    if ($slug === '') {
        continue;
    }
    $urls[] = $siteUrl . '/index.php?p=' . rawurlencode($slug);
}

$urls = array_values(array_unique($urls));

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($urls as $url): ?>
    <url><loc><?= htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></loc></url>
<?php endforeach; ?>
</urlset>
