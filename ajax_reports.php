<?php
/**
 * ajax_reports.php - DAoC CMS
 *
 * Standalone frontend endpoint for the "Reported Posts" popup.
 * Lets priv 2-3 moderators review and action forum reports without
 * needing ACP access (spike_admin itself stays priv >= 4).
 */
define('IN_CMS', true);
require_once __DIR__ . '/includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}

$userPriv = (int)($_SESSION['priv_level'] ?? 0);
if ($userPriv < 2 || $userPriv > 3) {
    // priv < 2 has no business here; priv >= 4 uses the full ACP spike_admin instead
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

if ($action === 'list') {
    try {
        $reports = $db->query("
            SELECT r.id, r.status, r.reason, r.details, r.created_at, r.post_id, r.thread_id,
                   p.content AS post_content, t.title AS thread_title, t.slug AS thread_slug,
                   u.username AS reporter_name, a.username AS post_author
            FROM spike_reports r
            JOIN spike_posts p ON r.post_id = p.id
            JOIN spike_threads t ON r.thread_id = t.id
            JOIN users u ON r.reporter_id = u.id
            LEFT JOIN users a ON p.author_id = a.id
            WHERE r.status IN ('open', 'reviewing')
            ORDER BY FIELD(r.status, 'open', 'reviewing'), r.created_at ASC
            LIMIT 50
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($reports as &$r) {
            $r['post_content'] = mb_substr(strip_tags($r['post_content'] ?? ''), 0, 400);
        }
        unset($r);

        echo json_encode(['ok' => true, 'reports' => $reports]);
    } catch (\Throwable $e) {
        error_log("ajax_reports.php list error: " . $e->getMessage());
        echo json_encode(['error' => 'db_error']);
    }
    exit;
}

if ($action === 'set_status') {
    checkToken($_POST['csrf_token'] ?? '');

    $report_id  = (int)($_POST['report_id'] ?? 0);
    $new_status = in_array($_POST['new_status'] ?? '', ['reviewing', 'resolved', 'dismissed'], true)
        ? $_POST['new_status']
        : null;
    $note       = trim(substr($_POST['handler_note'] ?? '', 0, 500));
    $handler_id = (int)($_SESSION['user_id'] ?? 0);

    if (!$report_id || !$new_status) {
        echo json_encode(['error' => 'invalid_params']);
        exit;
    }

    try {
        $stmt = $db->prepare("UPDATE spike_reports SET status = ?, handled_by = ?, handled_at = NOW(), handler_note = ? WHERE id = ?");
        $stmt->execute([$new_status, $handler_id, $note, $report_id]);

        aldhran_log('HANDLE_REPORT', "Report #$report_id → $new_status (via frontend popup)", $handler_id);

        // Remaining open+reviewing count, so the popup/badge can refresh itself
        $remaining = (int)$db->query("SELECT COUNT(*) FROM spike_reports WHERE status IN ('open','reviewing')")->fetchColumn();

        echo json_encode(['ok' => true, 'remaining' => $remaining]);
    } catch (\Throwable $e) {
        error_log("ajax_reports.php set_status error: " . $e->getMessage());
        echo json_encode(['error' => 'db_error']);
    }
    exit;
}

echo json_encode(['error' => 'unknown_action']);
