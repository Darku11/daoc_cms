<?php
if (!defined('IN_CMS')) { exit; }

$userPriv = (int)($_SESSION['priv_level'] ?? $GLOBALS['userPriv'] ?? 0);
$myId     = (int)($_SESSION['user_id'] ?? $currentUserId ?? 0);

$search_settings = [];
try {
    $search_settings = $db->query("SELECT setting_key, setting_value FROM spike_settings")
                          ->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (\Throwable $e) {}

$search_enabled = ($search_settings['search_enabled'] ?? '1') === '1';
$min_length     = max(2, (int)($search_settings['search_min_length'] ?? 3));
$max_results    = min(100, (int)($search_settings['search_max_results'] ?? 30));

// ── Input ──────────────────────────────────────────────────────
$q          = trim(strip_tags($_GET['q']   ?? $_POST['q']   ?? ''));
$board_id   = (int)($_GET['board']         ?? 0);
$author     = trim($_GET['author']         ?? '');
$type       = in_array($_GET['type']??'', ['thread','post','both']) ? ($_GET['type']??'both') : 'both';
$date_from  = $_GET['from'] ?? '';
$date_to    = $_GET['to']   ?? '';
$page       = max(1, (int)($_GET['page']   ?? 1));
$per_page   = 20;
$is_ajax    = isset($_GET['ajax']) || isset($_POST['ajax']);

$results    = [];
$total      = 0;
$error      = '';
$did_search = false;

// ── Boards for filter dropdown ─────────────────────────────────
$boards_for_filter = [];
try {
    $boards_for_filter = $db->query("
        SELECT b.id, b.title, c.title AS cat_title
        FROM spike_boards b
        JOIN spike_categories c ON b.cat_id = c.id
        ORDER BY c.pos, b.pos
    ")->fetchAll();
} catch (\Throwable $e) {}

// ── Search ─────────────────────────────────────────────────────
if (!empty($q) && $search_enabled) {
    if (mb_strlen($q) < $min_length) {
        $error = t('spike_search.error_min_length', ['min' => $min_length], "Search query must be at least {$min_length} characters.");
    } else {
        $did_search = true;
        $q_safe     = preg_replace('/[+\-><\(\)~\*\"@]+/', ' ', $q);
        $q_safe     = trim($q_safe);
        $q_like     = '%' . $q . '%';

        try {
            // ── Build results ────────────────────────────────────
            // We build a UNION of thread hits and post hits

            $where_board  = $board_id > 0 ? "AND b.id = $board_id" : '';
            $where_author = '';
            $params_author = [];
            if (!empty($author)) {
                $where_author = "AND u.username LIKE ?";
                $params_author = ['%' . $author . '%'];
            }
            $where_date = '';
            $params_date = [];
            if (!empty($date_from)) { $where_date .= " AND created_date >= ?"; $params_date[] = $date_from; }
            if (!empty($date_to))   { $where_date .= " AND created_date <= ?"; $params_date[] = $date_to; }

            // Privilege check: which boards is this user allowed to see?
            $visible_boards_sql = "
                SELECT b.id FROM spike_boards b
                JOIN spike_categories c ON b.cat_id = c.id
                WHERE (b.min_priv = 0 OR b.min_priv <= {$userPriv})
                  AND (c.min_priv = 0 OR c.min_priv <= {$userPriv})
            ";

            $union_parts = [];
            $union_params = [];

            // ── Thread hits ──────────────────────────────────────
            if (in_array($type, ['thread','both'])) {
                $union_parts[] = "
                    SELECT
                        'thread' AS result_type,
                        t.id AS thread_id,
                        t.id AS source_id,
                        t.title AS title,
                        LEFT(p_first.content, 300) AS snippet,
                        t.created_at AS created_date,
                        u.username AS author,
                        b.id AS board_id,
                        b.title AS board_title,
                        t.slug AS thread_slug,
                        (SELECT COUNT(*) FROM spike_posts WHERE thread_id = t.id) AS reply_count
                    FROM spike_threads t
                    JOIN spike_boards b ON t.board_id = b.id
                    JOIN spike_categories c ON b.cat_id = c.id
                    JOIN users u ON t.author_id = u.id
                    LEFT JOIN spike_posts p_first ON p_first.id = (
                        SELECT MIN(id) FROM spike_posts WHERE thread_id = t.id
                    )
                    WHERE b.id IN ($visible_boards_sql)
                      AND (MATCH(t.title) AGAINST(? IN BOOLEAN MODE) OR t.title LIKE ?)
                      $where_board
                      $where_author
                ";
                $union_params[] = $q_safe . '*';
                $union_params[] = $q_like;
                $union_params   = array_merge($union_params, $params_author);
            }

            // ── Post hits ────────────────────────────────────────
            if (in_array($type, ['post','both'])) {
                $union_parts[] = "
                    SELECT
                        'post' AS result_type,
                        t.id AS thread_id,
                        p.id AS source_id,
                        t.title AS title,
                        LEFT(p.content, 300) AS snippet,
                        p.created_at AS created_date,
                        u.username AS author,
                        b.id AS board_id,
                        b.title AS board_title,
                        t.slug AS thread_slug,
                        (SELECT COUNT(*) FROM spike_posts WHERE thread_id = t.id) AS reply_count
                    FROM spike_posts p
                    JOIN spike_threads t ON p.thread_id = t.id
                    JOIN spike_boards b ON t.board_id = b.id
                    JOIN spike_categories c ON b.cat_id = c.id
                    JOIN users u ON p.author_id = u.id
                    WHERE b.id IN ($visible_boards_sql)
                      AND (MATCH(p.content) AGAINST(? IN BOOLEAN MODE) OR p.content LIKE ?)
                      $where_board
                      $where_author
                ";
                $union_params[] = $q_safe . '*';
                $union_params[] = $q_like;
                $union_params   = array_merge($union_params, $params_author);
            }

            if (empty($union_parts)) {
                $results = [];
            } else {
                $union_sql = implode(" UNION ALL ", $union_parts);
                $full_sql  = "
                    SELECT * FROM ($union_sql) AS search_results
                    WHERE 1=1 $where_date
                    ORDER BY created_date DESC
                    LIMIT ? OFFSET ?
                ";

                $count_sql = "SELECT COUNT(*) FROM ($union_sql) AS search_results WHERE 1=1 $where_date";

                $all_params = array_merge($union_params, $params_date);

                $count_stmt = $db->prepare($count_sql);
                $count_stmt->execute($all_params);
                $total = (int)$count_stmt->fetchColumn();

                $stmt = $db->prepare($full_sql);
                $stmt->execute(array_merge($all_params, [$per_page, ($page-1) * $per_page]));
                $results = $stmt->fetchAll();
            }

            // ── Search log ────────────────────────────────────────
            try {
                $db->prepare("INSERT INTO spike_search_log (user_id, query, results) VALUES (?, ?, ?)")
                   ->execute([$myId ?: null, mb_substr($q, 0, 200), $total]);
            } catch (\Throwable $e) {}

        } catch (\Throwable $e) {
            error_log("[spike_search] Error: " . $e->getMessage());
            $error = t('spike_search.error_generic', [], 'Search error. Please try again.');
        }
    }
}

// ── AJAX Response ──────────────────────────────────────────────
if ($is_ajax) {
    header('Content-Type: application/json');
    echo json_encode([
        'ok'      => empty($error),
        'error'   => $error,
        'total'   => $total,
        'results' => $results,
        'page'    => $page,
    ]);
    exit;
}