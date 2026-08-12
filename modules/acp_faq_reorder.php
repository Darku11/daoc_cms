<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) exit;

if (!isset($userPriv))      $userPriv      = (int)($_SESSION['priv_level'] ?? 0);
if (!isset($currentUserId)) $currentUserId = (int)($_SESSION['user_id']    ?? 0);


// Auth: only admins (4+) may change the order
if ((int)($_SESSION['priv_level'] ?? 0) < 4) {
    http_response_code(403);
    die("Unauthorized");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order'])) {
    // Check CSRF - even this quiet endpoint needs protection
    checkToken($_POST['csrf_token'] ?? '');

    $order = $_POST['order'];
    if (!is_array($order)) { die("Invalid input"); }

    // Prepared statement instead of string interpolation in the loop
    $stmt = $db->prepare("UPDATE faq SET sort_order = ? WHERE id = ?");

    foreach ($order as $index => $id) {
        $stmt->execute([(int)$index, (int)$id]);
    }

    echo "Success";
    exit;
}
