<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) { exit; }

$finalID   = (int)($_SESSION['user_id']   ?? 0);
$finalPriv = (int)($_SESSION['priv_level'] ?? 0);
$post_id   = (int)($_GET['id'] ?? 0);

if ($post_id <= 0) {
    header("Location: index.php?p=spike");
    exit;
}

// Spike-Settings for Edit-History & Polls
$spike_cfg = [];
try {
    foreach ($db->query("SELECT setting_key, setting_value FROM spike_settings")->fetchAll() as $row) {
        $spike_cfg[$row['setting_key']] = $row['setting_value'];
    }
} catch (\Throwable $e) {}

$polls_enabled = ($spike_cfg['polls_enabled'] ?? '1') === '1';

// Forbidden Words
$forbidden_words = [];
try {
    $s = $db->prepare("SELECT word, action, replacement FROM spike_forbidden_words WHERE scope IN ('forum','both') ORDER BY LENGTH(word) DESC");
    $s->execute();
    $forbidden_words = $s->fetchAll();
} catch (\Throwable $e) {}

// Load Smilies
$smilies = [];
try {
    $smilies = $db->query("SELECT code, image_url, emoji, title FROM spike_smilies WHERE is_active=1 ORDER BY pos ASC")->fetchAll();
} catch (\Throwable $e) {}

// 1. Load Posts (incl. Thread-Slug for Redirects)
$stmt = $db->prepare("
    SELECT p.*, t.id as thread_id, t.slug as thread_slug
    FROM spike_posts p
    JOIN spike_threads t ON p.thread_id = t.id
    WHERE p.id = ?
");
$stmt->execute([$post_id]);
$post_data = $stmt->fetch();

if (!$post_data) {
    header("Location: index.php?p=spike&err=post_not_found"); exit;
}

// Slug-Fallback
$thread_slug = $post_data['thread_slug'] ?? '';
if (empty($thread_slug)) {
    $thread_slug = 'thread-' . $post_data['thread_id'];
}

// 2. Check Permissions
$is_author = ($finalID > 0 && $finalID === (int)$post_data['author_id']);
$is_admin  = ($finalPriv >= 4);

if (!$is_author && !$is_admin) {
    aldhran_log("SECURITY_ALERT", "Unauthorized edit attempt on Post #$post_id", $finalID);
    header("Location: index.php?p=spike&err=unauthorized"); exit;
}

// ── Poll for first post ────
$fp_stmt = $db->prepare("SELECT MIN(id) FROM spike_posts WHERE thread_id = ?");
$fp_stmt->execute([$post_data['thread_id']]);
$is_first_post = ((int)$fp_stmt->fetchColumn() === $post_id);

$poll = null; $poll_options = []; $poll_total_votes = 0;
if ($polls_enabled && $is_first_post) {
    try {
        $ps = $db->prepare("SELECT * FROM spike_polls WHERE thread_id = ? LIMIT 1");
        $ps->execute([$post_data['thread_id']]);
        $poll = $ps->fetch();
        if ($poll) {
            $po = $db->prepare("SELECT * FROM spike_poll_options WHERE poll_id = ? ORDER BY pos ASC");
            $po->execute([$poll['id']]);
            $poll_options = $po->fetchAll();

            $vt = $db->prepare("SELECT COUNT(*) FROM spike_poll_votes WHERE poll_id = ?");
            $vt->execute([$poll['id']]);
            $poll_total_votes = (int)$vt->fetchColumn();
        }
    } catch (\Throwable $e) {}
}
$poll_locked         = $poll_total_votes > 0;
$can_force_edit_poll = $is_admin;

// Cleans option texts by removing HTML tags, limiting the length (VARCHAR(120)), removing duplicates, and keeping a maximum of 10 entries.
function ep_clean_poll_options(array $raw): array {
    $out = [];
    foreach ($raw as $opt) {
        $opt = trim(strip_tags((string)$opt));
        if ($opt === '') continue;
        $opt = mb_substr($opt, 0, 120);
        if (!in_array($opt, $out, true)) $out[] = $opt;
    }
    return array_slice($out, 0, 10);
}

// Checks poll text for forbidden words using the same logic as the content filter below.
function ep_poll_text_blocked(string $text, array $words): bool {
    foreach ($words as $w) {
        if ($w['action'] === 'block' && mb_stripos($text, $w['word']) !== false) return true;
    }
    return false;
}

// 3. Save
if (isset($_POST['save_edit'])) {
    checkToken($_POST['csrf_token'] ?? '');

    $new_content = trim($_POST['content'] ?? '');
    $edit_reason = trim(substr($_POST['edit_reason'] ?? '', 0, 255));

    // Intercept empty content (Quill sends '<p><br></p>' sometimes)
    $stripped = strip_tags($new_content);
    if (empty($new_content) || empty($stripped)) {
        header("Location: index.php?p=editpost&id=$post_id&err=empty"); exit;
    }

    // Check Forbidden words
    $blocked = false;
    foreach ($forbidden_words as $w) {
        if ($w['action'] === 'block' && mb_stripos($new_content, $w['word']) !== false) {
            $blocked = true; break;
        }
    }
    if ($blocked) {
        header("Location: index.php?p=editpost&id=$post_id&err=forbidden_word"); exit;
    }
    foreach ($forbidden_words as $w) {
        if ($w['action'] === 'replace')
            $new_content = str_ireplace($w['word'], $w['replacement'] ?? '***', $new_content);
    }

    $poll_action = 'keep'; // keep|create|update|delete
    if ($polls_enabled && $is_first_post) {
        if (isset($_POST['poll_remove'])) {
            $poll_action = 'delete';
        } elseif (trim($_POST['poll_question'] ?? '') !== '') {
            $poll_action = $poll ? 'update' : 'create';
        }
    }

    // Prevent changes if votes already exist and no admin override is enabled.
    if ($poll_action !== 'keep' && $poll_locked && !$can_force_edit_poll) {
        $poll_action = 'keep';
    }

    $poll_question      = '';
    $poll_options_clean = [];
    $poll_multi         = 0;
    $poll_ends_at       = null;

    if (in_array($poll_action, ['create', 'update'], true)) {
        $poll_question      = mb_substr(trim(strip_tags($_POST['poll_question'] ?? '')), 0, 255);
        $poll_options_clean = ep_clean_poll_options((array)($_POST['poll_options'] ?? []));
        $poll_multi         = isset($_POST['poll_multi']) ? 1 : 0;

        if (!empty($_POST['poll_ends_at'])) {
            $ts = strtotime(str_replace('T', ' ', trim($_POST['poll_ends_at'])));
            if ($ts && $ts > time()) $poll_ends_at = date('Y-m-d H:i:s', $ts);
        }

        if (count($poll_options_clean) < 2) {
            header("Location: index.php?p=editpost&id=$post_id&err=poll_min_options"); exit;
        }
        if (ep_poll_text_blocked($poll_question . ' ' . implode(' ', $poll_options_clean), $forbidden_words)) {
            header("Location: index.php?p=editpost&id=$post_id&err=forbidden_word"); exit;
        }
        // Confirmation required when an administrator overrides a poll that already has recorded votes.
        if ($poll_action === 'update' && $poll_locked && empty($_POST['poll_confirm_reset'])) {
            header("Location: index.php?p=editpost&id=$post_id&err=poll_confirm_required"); exit;
        }
    }
    // Confirmation required when an administrator deletes a poll that already has recorded votes.
    if ($poll_action === 'delete' && $poll_locked && empty($_POST['poll_confirm_reset'])) {
        header("Location: index.php?p=editpost&id=$post_id&err=poll_confirm_required"); exit;
    }

    try {
        $db->beginTransaction();

        if (($spike_cfg['edit_history_enabled'] ?? '1') === '1') {
            $db->prepare(
                "INSERT INTO spike_post_edits (post_id, editor_id, old_content, edit_reason) VALUES (?, ?, ?, ?)"
            )->execute([$post_id, $finalID, $post_data['content'], $edit_reason]);
        }

        $db->prepare(
            "UPDATE spike_posts
             SET content = ?, edited_at = NOW(), edited_by = ?, edit_count = edit_count + 1
             WHERE id = ?"
        )->execute([$new_content, $finalID, $post_id]);

        if ($poll_action === 'delete' && $poll) {
            $db->prepare("DELETE FROM spike_poll_votes WHERE poll_id = ?")->execute([$poll['id']]);
            $db->prepare("DELETE FROM spike_poll_options WHERE poll_id = ?")->execute([$poll['id']]);
            $db->prepare("DELETE FROM spike_polls WHERE id = ?")->execute([$poll['id']]);
            aldhran_log("POLL_DELETED", "Poll removed from thread #{$post_data['thread_id']}", $finalID, $post_id);

        } elseif ($poll_action === 'create' && !$poll) {
            $db->prepare("INSERT INTO spike_polls (thread_id, question, multi, ends_at, created_by) VALUES (?, ?, ?, ?, ?)")
               ->execute([$post_data['thread_id'], $poll_question, $poll_multi, $poll_ends_at, $finalID]);
            $new_poll_id = (int)$db->lastInsertId();

            $insop = $db->prepare("INSERT INTO spike_poll_options (poll_id, label, pos) VALUES (?, ?, ?)");
            foreach ($poll_options_clean as $i => $label) $insop->execute([$new_poll_id, $label, $i + 1]);

            aldhran_log("POLL_CREATED", "Poll added to thread #{$post_data['thread_id']}", $finalID, $post_id);

        } elseif ($poll_action === 'update' && $poll) {
            $db->prepare("UPDATE spike_polls SET question = ?, multi = ?, ends_at = ? WHERE id = ?")
               ->execute([$poll_question, $poll_multi, $poll_ends_at, $poll['id']]);

            // Rewrite all poll options. This code path is only reached when the poll is
			// unlocked (no votes exist) or when an administrator has confirmed an override.
			// Any loss of existing votes is therefore expected and confirmed.
            $db->prepare("DELETE FROM spike_poll_votes WHERE poll_id = ?")->execute([$poll['id']]);
            $db->prepare("DELETE FROM spike_poll_options WHERE poll_id = ?")->execute([$poll['id']]);

            $insop = $db->prepare("INSERT INTO spike_poll_options (poll_id, label, pos) VALUES (?, ?, ?)");
            foreach ($poll_options_clean as $i => $label) $insop->execute([$poll['id'], $label, $i + 1]);

            aldhran_log("POLL_UPDATED", "Poll updated on thread #{$post_data['thread_id']}", $finalID, $post_id);
        }

        $log_msg = ($is_admin && !$is_author) ? "Admin edit by $finalID" : "User edit";
        aldhran_log("POST_EDITED", $log_msg, $finalID, $post_id);

        $db->commit();

        header("Location: index.php?p=viewthread&slug=" . urlencode($thread_slug) . "&msg=edited#post-$post_id");
        exit;

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("Edit Post Error: " . $e->getMessage());
        header("Location: index.php?p=editpost&id=$post_id&err=db_error"); exit;
    }
}
