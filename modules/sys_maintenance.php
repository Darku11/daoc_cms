<?php
// IN_CMS guard + absolute path instead of relative '../includes/db.php'
if (!defined('IN_CMS')) {
    define('IN_CMS', true);
    require_once __DIR__ . '/../includes/db.php';
} // otherwise db.php is already loaded via index.php/acp.php
if (($_SESSION['priv_level'] ?? 0) < 3) {
    aldhran_log("SECURITY_ALERT", "Unauthorized maintenance toggle attempt", $_SESSION['user_id'] ?? 0);
    die("Access Denied");
}

if (isset($_POST['toggle_maint'])) {
    // Validate the CSRF token.
    checkToken($_POST['csrf_token'] ?? '');

    // Absolute path - a relative path here depends on the CWD the script
    // was invoked from and can silently point to the wrong location.
    $lock_file = __DIR__ . '/../maintenance.lock';
    $admin_id = (int)($_SESSION['user_id'] ?? 0);
    $action_status = "";

    try {
        if (file_exists($lock_file)) {
            // --- DISABLE MAINTENANCE ---
            if (@unlink($lock_file)) {
                $action_status = "DEACTIVATED";
            }
        } else {
            // --- ENABLE MAINTENANCE ---
            $content = "MAINTENANCE ACTIVE\nStarted by: " . ($_SESSION['username'] ?? 'Unknown Admin') . "\nID: $admin_id\nTime: " . date('Y-m-d H:i:s');
            
            if (file_put_contents($lock_file, $content)) {
                $action_status = "ACTIVATED";
            }
        }

        // Log the action
        if (!empty($action_status)) {
            aldhran_log("MAINTENANCE_TOGGLE", "Global maintenance mode $action_status", $admin_id);
        }

        header("Location: ../index.php?p=maintenance_text&msg=toggled");
        exit;

    } catch (Exception $e) {
        error_log("Maintenance Toggle Error: " . $e->getMessage());
        die("Something went wrong. Check the logs.");
    }
}
