<?php
require_once('includes/db.php');

$user_id = $_SESSION['user_id'] ?? null;

$_SESSION = array();

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

if ($user_id) {
    aldhran_log("LOGOUT", "User logged out", $user_id);
}

header("Location: index.php?msg=logged_out");
exit;