<?php
// SPDX-License-Identifier: GPL-3.0-only
if ((int)($_SESSION['priv_level'] ?? 0) < 3) {
    die("Nexus Logic: Access Denied.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_um_call = isset($_POST['um_action'])              ||
                  isset($_POST['um_ajax_search'])         ||
                  isset($_POST['um_load_cat'])            ||
                  isset($_POST['um_ajax_get_editor'])     ||
                  isset($_POST['um_ajax_get_add_form']);
    if ($is_um_call) {
        checkToken($_POST['csrf_token'] ?? '');
        require_once('modules/acp_um_sync_worker.php');
    }
}

$u_data = null;

if (!function_exists('getStandingText')) {
    function getStandingText($level) {
        $texts = [0 => "Good", 1 => "Warning I", 2 => "Warning II", 3 => "Restricted", 4 => "Suspended", 5 => "Banned"];
        return $texts[(int)$level] ?? "Unknown";
    }
}