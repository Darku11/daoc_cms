<?php
if (!defined('IN_CMS')) { exit; }

/**
 * Marks a thread as read (up to the last loaded post).
 * Called in viewthread_logic.php after posts have been loaded.
 */
function spike_mark_thread_read(PDO $db, int $user_id, int $thread_id, array $posts): void {
    if ($user_id <= 0 || $thread_id <= 0 || empty($posts)) return;

    $last_post_id = 0;
    foreach ($posts as $p) {
        if ((int)$p['id'] > $last_post_id) $last_post_id = (int)$p['id'];
    }
    if ($last_post_id <= 0) return;

    try {
        $db->prepare("
            INSERT INTO spike_read_markers (user_id, thread_id, last_post_id)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                last_post_id = GREATEST(last_post_id, VALUES(last_post_id)),
                marked_at    = NOW()
        ")->execute([$user_id, $thread_id, $last_post_id]);
    } catch (\Throwable $e) {
        error_log("[spike_unread] mark_thread_read: " . $e->getMessage());
    }
}

/**
 * Returns an array of thread IDs the user has NOT fully read yet.
 * "Unread" = no marker present OR last marker post < current last post in thread.
 *
 * @param PDO   $db
 * @param int   $user_id
 * @param array $thread_ids   Array of thread IDs to check
 * @return array              Array of unread thread IDs
 */
function spike_get_unread_thread_ids(PDO $db, int $user_id, array $thread_ids): array {
    if ($user_id <= 0 || empty($thread_ids)) return [];

    $pl = implode(',', array_fill(0, count($thread_ids), '?'));

    try {
        // For each thread: determine last post ID and compare against marker
        $stmt = $db->prepare("
            SELECT
                t.id AS thread_id,
                COALESCE(MAX(p.id), 0) AS last_post_id,
                COALESCE(rm.last_post_id, 0) AS read_up_to
            FROM spike_threads t
            LEFT JOIN spike_posts p ON p.thread_id = t.id
            LEFT JOIN spike_read_markers rm ON rm.thread_id = t.id AND rm.user_id = ?
            WHERE t.id IN ($pl)
            GROUP BY t.id, rm.last_post_id
        ");
        $stmt->execute(array_merge([$user_id], array_values($thread_ids)));
        $rows = $stmt->fetchAll();

        $unread = [];
        foreach ($rows as $row) {
            // Unread if: no marker (read_up_to=0) OR last post > marker
            if ((int)$row['last_post_id'] > (int)$row['read_up_to']) {
                $unread[] = (int)$row['thread_id'];
            }
        }
        return $unread;

    } catch (\Throwable $e) {
        error_log("[spike_unread] get_unread_thread_ids: " . $e->getMessage());
        return [];
    }
}

/**
 * Returns unread counts per board (for the forum overview).
 * Shows: "Board has X unread threads".
 *
 * @param PDO   $db
 * @param int   $user_id
 * @param array $board_ids
 * @return array  [board_id => unread_count]
 */
function spike_get_unread_per_board(PDO $db, int $user_id, array $board_ids): array {
    if ($user_id <= 0 || empty($board_ids)) return [];

    $pl = implode(',', array_fill(0, count($board_ids), '?'));

    try {
        $stmt = $db->prepare("
            SELECT
                t.board_id,
                COUNT(DISTINCT t.id) AS unread_count
            FROM spike_threads t
            LEFT JOIN (
                SELECT sp.thread_id, MAX(sp.id) AS last_post_id
                FROM spike_posts sp
                GROUP BY sp.thread_id
            ) lp ON lp.thread_id = t.id
            LEFT JOIN spike_read_markers rm ON rm.thread_id = t.id AND rm.user_id = ?
            WHERE t.board_id IN ($pl)
              AND COALESCE(lp.last_post_id, 0) > COALESCE(rm.last_post_id, 0)
            GROUP BY t.board_id
        ");
        $stmt->execute(array_merge([$user_id], array_values($board_ids)));
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    } catch (\Throwable $e) {
        error_log("[spike_unread] get_unread_per_board: " . $e->getMessage());
        return [];
    }
}

/**
 * "Mark all as read" for a user (whole forum or a single board).
 */
function spike_mark_all_read(PDO $db, int $user_id, int $board_id = 0): void {
    if ($user_id <= 0) return;
    try {
        if ($board_id > 0) {
            // Mark all threads of the board as read
            $db->prepare("
                INSERT INTO spike_read_markers (user_id, thread_id, last_post_id)
                SELECT ?, t.id, COALESCE((SELECT MAX(id) FROM spike_posts WHERE thread_id = t.id), 0)
                FROM spike_threads t
                WHERE t.board_id = ?
                ON DUPLICATE KEY UPDATE
                    last_post_id = GREATEST(last_post_id, VALUES(last_post_id)),
                    marked_at    = NOW()
            ")->execute([$user_id, $board_id]);
        } else {
            // Whole forum
            $db->prepare("
                INSERT INTO spike_read_markers (user_id, thread_id, last_post_id)
                SELECT ?, t.id, COALESCE((SELECT MAX(id) FROM spike_posts WHERE thread_id = t.id), 0)
                FROM spike_threads t
                ON DUPLICATE KEY UPDATE
                    last_post_id = GREATEST(last_post_id, VALUES(last_post_id)),
                    marked_at    = NOW()
            ")->execute([$user_id]);
        }
    } catch (\Throwable $e) {
        error_log("[spike_unread] mark_all_read: " . $e->getMessage());
    }
}

/**
 * Read-marker statistics for the ACP (which threads are read the most).
 */
function spike_get_read_stats(PDO $db, int $limit = 20): array {
    try {
        $stmt = $db->prepare("
            SELECT
                t.id, t.title, t.slug,
                COUNT(rm.id) AS reader_count,
                MAX(rm.marked_at) AS last_read
            FROM spike_threads t
            JOIN spike_read_markers rm ON rm.thread_id = t.id
            GROUP BY t.id, t.title, t.slug
            ORDER BY reader_count DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    } catch (\Throwable $e) {
        return [];
    }
}
