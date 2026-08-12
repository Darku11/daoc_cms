<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) { exit; }

/**
 * Parses mentions from HTML content and creates notifications.
 * Expects: <span class="spk-mention" data-uid="X">@Username</span>
 */
function spike_process_mentions(PDO $db, int $post_id, int $thread_id, int $author_id, string $content_html): void {
    if ($post_id <= 0 || $author_id <= 0) return;

    // Load settings
    $max_per_post = 5;
    try {
        $s = $db->prepare("SELECT setting_value FROM spike_settings WHERE setting_key IN ('mention_notify_enabled','notify_max_per_post')");
        $s->execute();
        $settings = $s->fetchAll(PDO::FETCH_KEY_PAIR);
        if (($settings['mention_notify_enabled'] ?? '1') === '0') return;
        $max_per_post = (int)($settings['notify_max_per_post'] ?? 5);
    } catch (\Throwable $e) {}

    // Extract UIDs from data-uid attributes
    preg_match_all('/data-uid=["\'](\d+)["\']/', $content_html, $matches);
    if (empty($matches[1])) return;

    $mentioned_ids = array_unique(array_map('intval', $matches[1]));
    $count = 0;

    foreach ($mentioned_ids as $mentioned_id) {
        if ($mentioned_id <= 0) continue;
        if ($mentioned_id === $author_id) continue; // don't notify the author about their own mention
        if (++$count > $max_per_post) break;

        try {
            // Duplicate check: don't notify the same mention twice in the same post
            $check = $db->prepare("SELECT id FROM spike_notifications WHERE user_id=? AND post_id=? AND type='mention' LIMIT 1");
            $check->execute([$mentioned_id, $post_id]);
            if ($check->fetch()) continue;

            $db->prepare("
                INSERT INTO spike_notifications (user_id, type, actor_id, thread_id, post_id)
                VALUES (?, 'mention', ?, ?, ?)
            ")->execute([$mentioned_id, $author_id, $thread_id, $post_id]);

        } catch (\Throwable $e) {
            error_log("[spike_notify] mention insert failed: " . $e->getMessage());
        }
    }
}

/**
 * Create a notification (generic, for other types).
 */
function spike_create_notification(PDO $db, int $user_id, string $type, int $actor_id, int $thread_id = 0, int $post_id = 0, string $message = ''): void {
    if ($user_id <= 0 || $user_id === $actor_id) return;
    try {
        $db->prepare("
            INSERT INTO spike_notifications (user_id, type, actor_id, thread_id, post_id, message)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$user_id, $type, $actor_id, $thread_id ?: null, $post_id ?: null, $message ?: null]);
    } catch (\Throwable $e) {
        error_log("[spike_notify] create failed: " . $e->getMessage());
    }
}

/**
 * Unread notification count for a user.
 */
function spike_get_unread_count(PDO $db, int $user_id): int {
    if ($user_id <= 0) return 0;
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM spike_notifications WHERE user_id=? AND is_read=0");
        $stmt->execute([$user_id]);
        return (int)$stmt->fetchColumn();
    } catch (\Throwable $e) { return 0; }
}

/**
 * Load notifications (for the bell dropdown).
 */
function spike_get_notifications(PDO $db, int $user_id, int $limit = 15): array {
    if ($user_id <= 0) return [];
    try {
        $stmt = $db->prepare("
            SELECT
                n.*,
                actor.username  AS actor_name,
                actor.avatar    AS actor_avatar,
                t.title         AS thread_title,
                t.slug          AS thread_slug
            FROM spike_notifications n
            LEFT JOIN users actor ON n.actor_id = actor.id
            LEFT JOIN spike_threads t ON n.thread_id = t.id
            WHERE n.user_id = ?
            ORDER BY n.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$user_id, $limit]);
        return $stmt->fetchAll();
    } catch (\Throwable $e) { return []; }
}

/**
 * Mark notifications as read.
 * $ids = [] → mark all
 */
function spike_mark_notifications_read(PDO $db, int $user_id, array $ids = []): void {
    if ($user_id <= 0) return;
    try {
        if (empty($ids)) {
            $db->prepare("UPDATE spike_notifications SET is_read=1 WHERE user_id=?")->execute([$user_id]);
        } else {
            $pl = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("UPDATE spike_notifications SET is_read=1 WHERE user_id=? AND id IN ($pl)")
               ->execute(array_merge([$user_id], array_map('intval', $ids)));
        }
    } catch (\Throwable $e) {
        error_log("[spike_notify] mark_read failed: " . $e->getMessage());
    }
}

/**
 * Clean up old notifications (> 90 days).
 */
function spike_cleanup_notifications(PDO $db): int {
    try {
        $stmt = $db->query("DELETE FROM spike_notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
        return $stmt->rowCount();
    } catch (\Throwable $e) { return 0; }
}
