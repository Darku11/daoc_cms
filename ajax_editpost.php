<?php
// SPDX-License-Identifier: GPL-3.0-only
require_once(__DIR__ . '/includes/db.php');
header('Content-Type: text/plain');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo "error_method"; exit; }

checkToken($_POST['csrf_token'] ?? '');

$myId    = (int)($_SESSION['user_id']   ?? 0);
$myPriv  = (int)($_SESSION['priv_level'] ?? 0);
$post_id = (int)($_POST['post_id']       ?? 0);
$content = trim($_POST['content']        ?? '');
$reason  = trim(substr($_POST['edit_reason'] ?? '', 0, 255));

if ($myId <= 0)                      { echo "error_unauthorized"; exit; }
if ($post_id <= 0 || empty($content)){ echo "error_input";        exit; }

// ── load post  ────────────────────────────────────────────────
$stmt = $db->prepare("SELECT author_id, content FROM spike_posts WHERE id = ? LIMIT 1");
$stmt->execute([$post_id]);
$post = $stmt->fetch();

if (!$post)                                                     { echo "error_not_found";    exit; }
if ((int)$post['author_id'] !== $myId && $myPriv < 3) {
    aldhran_log("SECURITY_ALERT", "Unauthorized post edit attempt on #$post_id", $myId);
    echo "error_unauthorized"; exit;
}

// ── Check Forbidden Words  ────────────────────────────────────
try {
    $fw_rows = $db->prepare("SELECT word, action, replacement FROM spike_forbidden_words WHERE scope IN ('forum','both') ORDER BY LENGTH(word) DESC");
    $fw_rows->execute();
    $fw = $fw_rows->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fw as $w) {
        if (mb_stripos(strip_tags($content), $w['word']) !== false) {
            if ($w['action'] === 'block') { echo "error_forbidden_word"; exit; }
            if ($w['action'] === 'replace') {
                $content = str_ireplace($w['word'], $w['replacement'] ?? '***', $content);
            }
        }
    }
} catch (\Throwable $e) {
    error_log("Forbidden words check failed: " . $e->getMessage());
}

// ── Is edit history enabled? ───────────────────────────────────
$history_enabled = true;
try {
    $cfg = $db->prepare("SELECT setting_value FROM spike_settings WHERE setting_key = 'edit_history_enabled' LIMIT 1");
    $cfg->execute();
    $val = $cfg->fetchColumn();
    if ($val === '0') $history_enabled = false;
} catch (\Throwable $e) {}

// ── Conduct update ────────────────────────────────────────
try {
    $db->beginTransaction();

    // Archive old version
    if ($history_enabled) {
        $db->prepare("INSERT INTO spike_post_edits (post_id, editor_id, old_content, edit_reason) VALUES (?, ?, ?, ?)")
           ->execute([$post_id, $myId, $post['content'], $reason]);
    }

    // Updating Post — edited_at / edited_by / edit_count
    $db->prepare("UPDATE spike_posts SET content = ?, edited_at = NOW(), edited_by = ?, edit_count = edit_count + 1 WHERE id = ?")
       ->execute([$content, $myId, $post_id]);

    $db->commit();
    aldhran_log("POST_EDIT", "Post #$post_id edited (reason: " . ($reason ?: 'none') . ")", $myId, $post_id);
    echo "success";

} catch (Exception $e) {
    $db->rollBack();
    error_log("AJAX Edit Error: " . $e->getMessage());
    echo "error_db";
}
exit;
