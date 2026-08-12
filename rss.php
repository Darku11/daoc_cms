<?php

if (!defined('IN_CMS')) {
    define('IN_CMS', true);
    require_once __DIR__ . '/includes/db.php';
}

$site_name = $GLOBALS['cms_settings']['site_name'] ?? 'DAoC CMS';
$site_url  = defined('SITE_URL') ? rtrim((string)SITE_URL, '/') : '';
$feed_desc = 'Latest public forum posts on ' . $site_name;
$limit     = 20;

try {
    $r = $db->prepare("SELECT value FROM settings WHERE setting_key='site_name' LIMIT 1");
    $r->execute();
    $site_name = $r->fetchColumn() ?: $site_name;
} catch (\Throwable $e) {}

$board_id = isset($_GET['board']) ? (int)$_GET['board'] : 0;

try {
    $sql = "
        SELECT p.id as post_id, p.content, p.created_at,
               t.id as thread_id, t.title as thread_title, t.slug as thread_slug,
               b.title as board_title,
               u.username
        FROM spike_posts p
        JOIN spike_threads t ON p.thread_id = t.id
        JOIN spike_boards b ON t.board_id = b.id
        JOIN spike_categories c ON b.cat_id = c.id
        JOIN users u ON p.author_id = u.id
        WHERE t.is_approved = 1
          AND 0 >= (CASE WHEN b.min_priv > 0 THEN b.min_priv ELSE c.min_priv END)
          AND u.is_shadow_banned = 0
    ";

    $params = [];
    if ($board_id > 0) {
        $sql .= " AND t.board_id = ?";
        $params[] = $board_id;
    }

    $sql .= " ORDER BY p.created_at DESC LIMIT ?";
    $params[] = $limit;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log('RSS feed generation failed: ' . $e->getMessage());
    $posts = [];
}

header('Content-Type: application/rss+xml; charset=UTF-8');
header('Cache-Control: public, max-age=900');

$feed_url = $site_url . '/rss.php' . ($board_id > 0 ? '?board=' . $board_id : '');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/">
    <channel>
        <title><?= htmlspecialchars($site_name . ' – Forum') ?></title>
        <link><?= htmlspecialchars($site_url . '/index.php?p=spike') ?></link>
        <description><?= htmlspecialchars($feed_desc) ?></description>
        <language>en</language>
        <lastBuildDate><?= date('r') ?></lastBuildDate>
        <atom:link href="<?= htmlspecialchars($feed_url) ?>" rel="self" type="application/rss+xml"/>

        <?php foreach ($posts as $p):
            $thread_url = !empty($p['thread_slug'])
                ? $site_url . '/index.php?p=viewthread&slug=' . urlencode($p['thread_slug'])
                : $site_url . '/index.php?p=viewthread&id=' . (int)$p['thread_id'];
            $thread_url .= '#post-' . (int)$p['post_id'];

            $desc = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($p['content']))), 0, 300);
            if (mb_strlen(strip_tags($p['content'])) > 300) $desc .= '…';
        ?>
        <item>
            <title><?= htmlspecialchars($p['thread_title'] . ' — ' . $p['board_title']) ?></title>
            <link><?= htmlspecialchars($thread_url) ?></link>
            <guid isPermaLink="true"><?= htmlspecialchars($thread_url) ?></guid>
            <description><?= htmlspecialchars($desc) ?></description>
            <dc:creator><?= htmlspecialchars($p['username']) ?></dc:creator>
            <pubDate><?= date('r', strtotime($p['created_at'])) ?></pubDate>
        </item>
        <?php endforeach; ?>

    </channel>
</rss>