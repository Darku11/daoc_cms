<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) exit;
if ($userPriv < 5) exit;

// AJAX: clear log
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    checkToken($_POST['csrf_token'] ?? '');
    $action = $_POST['ajax_action'];

    if ($action === 'clear_log') {
        if (empty($_POST['confirmed']) || $_POST['confirmed'] !== '1') {
            echo json_encode(['error' => 'confirmation_required']);
            exit;
        }
        $db->query("TRUNCATE TABLE sys_error_log");
        aldhran_log("SYS_LOG", "Error log cleared", $currentUserId);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── AI: Analyze Error ──────────────────────────────────────
    if ($action === 'ai_analyze_error') {
        if (!class_exists('AiManager')) { echo json_encode(['error'=>'AiManager not available']); exit; }

        $error_id = (int)($_POST['error_id'] ?? 0);
        $row = null;
        if ($error_id) {
            $stmt = $db->prepare("SELECT * FROM sys_error_log WHERE id = ? LIMIT 1");
            $stmt->execute([$error_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$row) { echo json_encode(['error'=>'Error entry not found']); exit; }

        global $botSettings;
        $ai = new AiManager($db, $botSettings, $currentUserId, $userPriv);
        $result = $ai->request('error_log', 'analyze_error', [
            'error_type'   => $row['errno']       ?? 'E_ERROR',
            'error_message'=> $row['errstr']       ?? '',
            'file'         => basename($row['errfile'] ?? ''),
            'line'         => $row['errline']      ?? 0,
            'stacktrace'   => $row['stacktrace']   ?? '',
            'request_url'  => $row['request_url']  ?? '',
            'instruction'  => 'Analyze this PHP error from a CMS. Explain: 1) What caused it (root cause), 2) Why it happens, 3) A specific code fix with example. Be concise and practical. If it\'s a common PHP error pattern, mention that.',
        ], ['save_suggestion' => true, 'target_id' => $error_id]);

        echo json_encode($result);
        exit;
    }

    // ── AI: Detect Pattern ────────────────────────────────────
    if ($action === 'ai_detect_pattern') {
        if (!class_exists('AiManager')) { echo json_encode(['error'=>'AiManager not available']); exit; }

        // Load the last 50 errors
        $recent = $db->query("SELECT errno, errstr, errfile, errline, created_at FROM sys_error_log ORDER BY created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($recent)) { echo json_encode(['status'=>'ok','result'=>['suggestion'=>'No errors in log to analyze.']]); exit; }

        global $botSettings;
        $ai = new AiManager($db, $botSettings, $currentUserId, $userPriv);
        $result = $ai->request('error_log', 'analyze_error', [
            'error_entries' => $recent,
            'instruction'   => 'Analyze these PHP error log entries for patterns. Find: 1) Recurring errors (same file/line), 2) Error spikes at certain times, 3) The most critical error to fix first. Summarize in 3-4 bullet points. Prioritize by impact.',
        ], ['save_suggestion' => true, 'target_id' => null]);

        echo json_encode($result);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// Load data for the view
$stmt = $db->query("SELECT * FROM sys_error_log ORDER BY created_at DESC LIMIT 500");
$error_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ai_active = isset($botSettings) && $botSettings->isActive() && $botSettings->hasAiConfigured();
