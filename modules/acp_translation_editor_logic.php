<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_ACP')) exit;

$userPriv = (int)($_SESSION['priv_level'] ?? 0);
if ($userPriv < 4) return;

// ── Available languages ────────────────────────────────────
$available_languages = [];
try {
    $available_languages = $db->query(
        "SELECT DISTINCT lang_code FROM cms_translations ORDER BY lang_code"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $e) {
    $available_languages = ['en', 'de'];
}


// ── AJAX Handler ───────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'] ?? '';

    // ── Load translations ──────────────────────────────────
    if ($action === 'load_translations') {
        checkToken($_POST['csrf_token'] ?? '');

        $lang   = preg_replace('/[^a-z]/i', '', $_POST['lang']   ?? 'en');
        $search = trim($_POST['search'] ?? '');

        $where  = "WHERE lang_code = ?";
        $params = [$lang];

        if ($search !== '') {
            $where  .= " AND (var_key LIKE ? OR var_value LIKE ? OR var_context LIKE ?)";
            $s       = '%' . $search . '%';
            $params  = array_merge($params, [$s, $s, $s]);
        }

        $stmt = $db->prepare(
            "SELECT id, var_key, var_value, var_context
               FROM cms_translations
              {$where}
           ORDER BY var_context, var_key"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['ok' => true, 'data' => $rows]);
        exit;
    }

    // ── Save value only (legacy, kept for compatibility) ───
    if ($action === 'save_translation') {
        checkToken($_POST['csrf_token'] ?? '');

        $id    = (int)($_POST['id']    ?? 0);
        $value = trim($_POST['value'] ?? '');

        if (!$id) { echo json_encode(['ok' => false, 'error' => 'No ID']); exit; }

        $stmt = $db->prepare("UPDATE cms_translations SET var_value = ? WHERE id = ?");
        $stmt->execute([$value, $id]);
        aldhran_bump_settings_version();

        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Save full card (key + context + value) ─────────────
    if ($action === 'save_translation_full') {
        checkToken($_POST['csrf_token'] ?? '');

        $id      = (int)($_POST['id']          ?? 0);
        $key     = trim($_POST['var_key']       ?? '');
        $context = trim($_POST['var_context']   ?? 'core');
        $value   = trim($_POST['value']         ?? '');

        if (!$id || !$key) {
            echo json_encode(['ok' => false, 'error' => 'Missing id or key']);
            exit;
        }
        if ($context === '') $context = 'core';

        try {
            $db->prepare(
                "UPDATE cms_translations
                    SET var_key = ?, var_context = ?, var_value = ?
                  WHERE id = ?"
            )->execute([$key, $context, $value, $id]);

            aldhran_bump_settings_version();
            aldhran_log('TRANSLATION_UPDATE', "Updated key {$key} (id:{$id})", $currentUserId);

            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            error_log('[TLE] save_translation_full: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'Database error – key may already exist.']);
        }
        exit;
    }

    // ── Create new key ─────────────────────────────────────
    if ($action === 'create_translation') {
        checkToken($_POST['csrf_token'] ?? '');

        $lang    = preg_replace('/[^a-z]/i', '', $_POST['lang']       ?? 'en');
        $key     = trim($_POST['var_key']                              ?? '');
        $value   = trim($_POST['var_value']                            ?? '');
        $context = trim($_POST['var_context']                          ?? 'core');

        if (!$key || !$lang) {
            echo json_encode(['ok' => false, 'error' => 'Missing key or language']);
            exit;
        }
        if (!$value) {
            echo json_encode(['ok' => false, 'error' => 'Missing value']);
            exit;
        }
        if ($context === '') $context = 'core';

        try {
            $db->prepare(
                "INSERT INTO cms_translations (lang_code, var_key, var_value, var_context)
                 VALUES (?, ?, ?, ?)"
            )->execute([$lang, $key, $value, $context]);

            aldhran_bump_settings_version();
            aldhran_log('TRANSLATION_CREATE', "Created key {$key} for {$lang}", $currentUserId);

            echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Key already exists or a database error occurred.']);
        }
        exit;
    }

    // ── Delete key ─────────────────────────────────────────
    if ($action === 'delete_translation') {
        checkToken($_POST['csrf_token'] ?? '');

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok' => false]); exit; }

        $db->prepare("DELETE FROM cms_translations WHERE id = ?")->execute([$id]);
        aldhran_bump_settings_version();
        aldhran_log('TRANSLATION_DELETE', "Deleted translation #{$id}", $currentUserId);

        echo json_encode(['ok' => true]);
        exit;
    }


    // ════════════════════════════════════════════════════════
    // AI ACTIONS
    // ════════════════════════════════════════════════════════

    if (
        str_starts_with($action, 'ai_') &&
        isset($botSettings) &&
        $botSettings->isActive() &&
        class_exists('AiManager')
    ) {
        checkToken($_POST['csrf_token'] ?? '');
        $ai = new AiManager($db, $botSettings, $currentUserId, $userPriv);

        // ── Detect missing keys (vs. English base) ─────────
        if ($action === 'ai_detect_missing') {
            $lang      = preg_replace('/[^a-z]/i', '', $_POST['lang'] ?? 'de');
            $base_lang = 'en';

            $base = $db->prepare(
                "SELECT var_key FROM cms_translations WHERE lang_code = ? LIMIT 500"
            );
            $base->execute([$base_lang]);
            $all_base = $base->fetchAll(PDO::FETCH_COLUMN);

            $target = $db->prepare(
                "SELECT var_key FROM cms_translations WHERE lang_code = ?"
            );
            $target->execute([$lang]);
            $all_target = array_flip($target->fetchAll(PDO::FETCH_COLUMN));

            $missing = array_values(
                array_filter($all_base, fn($k) => !isset($all_target[$k]))
            );

            echo json_encode(['ok' => true, 'missing' => $missing, 'count' => count($missing)]);
            exit;
        }

        // ── Improve a single translation string ────────────
        if ($action === 'ai_improve_single') {
            $var_key   = trim($_POST['var_key']   ?? '');
            $var_value = trim($_POST['var_value'] ?? '');
            $lang      = preg_replace('/[^a-z]/i', '', $_POST['lang'] ?? 'en');

            $result = $ai->request('translation_editor', 'improve_text', [
                'translation_key' => $var_key,
                'current_value'   => $var_value,
                'language'        => $lang,
                'instruction'     =>
                    "Improve this UI translation string. Language: {$lang}. " .
                    "Keep it concise and suitable for a UI label. " .
                    "Return only the improved translation.",
            ], ['save_suggestion' => true]);

            echo json_encode($result);
            exit;
        }

        // ── Suggest tone/style guide for a language ────────
        if ($action === 'ai_suggest_tone') {
            $lang = preg_replace('/[^a-z]/i', '', $_POST['lang'] ?? 'en');

            $sample = $db->prepare(
                "SELECT var_key, var_value FROM cms_translations WHERE lang_code = ? LIMIT 20"
            );
            $sample->execute([$lang]);

            $result = $ai->request('translation_editor', 'suggest_text', [
                'sample_strings' => $sample->fetchAll(PDO::FETCH_ASSOC),
                'language'       => $lang,
                'instruction'    =>
                    'Analyze tone consistency of these UI strings. ' .
                    'Flag inconsistencies and suggest a style guide (2–3 sentences).',
            ]);

            echo json_encode($result);
            exit;
        }
    }

    // Fallback
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}