<?php
// SPDX-License-Identifier: GPL-3.0-only
require_once(__DIR__ . '/includes/db.php');

header('Content-Type: text/plain');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "error_method";
    exit;
}

checkToken($_POST['csrf_token'] ?? '');

$myId    = (int)($_SESSION['user_id'] ?? 0);
$userPriv= (int)($_SESSION['priv_level'] ?? 0);
$post_id = (int)($_POST['post_id'] ?? 0);
$content = trim($_POST['content'] ?? '');

// 2. Login-Check
if ($myId <= 0) {
    echo "error_unauthorized";
    exit;
}

// 3. Input Check
if ($post_id <= 0 || empty($content)) {
    echo "error_input";
    exit;
}

// 4. Check permission
$stmt = $db->prepare("SELECT author_id FROM spike_posts WHERE id = ?");
$stmt->execute([$post_id]);
$post = $stmt->fetch();

if (!$post) {
    echo "error_not_found";
    exit;
}

if ((int)$post['author_id'] !== $myId && $userPriv < 3) {
    aldhran_log("SECURITY_ALERT", "Unauthorized post edit attempt on #$post_id", $myId);
    echo "error_unauthorized";
    exit;
}

// 5. Perform Update
try {
    $update = $db->prepare("UPDATE spike_posts SET content = ?, last_edit_at = NOW() WHERE id = ?");
    if ($update->execute([$content, $post_id])) {
        aldhran_log("POST_EDIT", "Post #$post_id edited", $myId, $post_id);
        echo "success";
    } else {
        echo "error_db";
    }
} catch (Exception $e) {
    error_log("AJAX Edit Error: " . $e->getMessage());
    echo "error_db";
}
exit;