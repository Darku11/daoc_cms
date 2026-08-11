<?php
require_once('includes/db.php');

// Checking server status directly — without including header
$server_ip   = $GLOBALS['cms_settings']['game_server_ip'] ?? '127.0.0.1';
$server_port = (int)($GLOBALS['cms_settings']['game_server_port'] ?? 10300);
$fp          = @fsockopen($server_ip, $server_port, $errno, $errstr, 1);
$is_online   = (bool)$fp;
if ($fp) fclose($fp);

// Online-User from DB
$online_count = 0;
try {
    $online_count = (int)$db->query(
        "SELECT COUNT(*) FROM users WHERE last_activity > UNIX_TIMESTAMP() - 300"
    )->fetchColumn();
} catch (PDOException $e) {}

header('Content-Type: application/json');
echo json_encode([
    'online' => $is_online,
    'count'  => $online_count,
]);
