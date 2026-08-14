<?php
// SPDX-License-Identifier: GPL-3.0-only
declare(strict_types=1);

require_once __DIR__ . '/includes/Installer.php';
require_once __DIR__ . '/includes/Security.php';
require_once __DIR__ . '/../includes/bridge_config_file.php';

use DAoCCMS\Setup\Installer;
use DAoCCMS\Setup\Security;

$installer = new Installer();
$security = new Security();

if ($installer->isInstalled()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Setup is locked. Download the bridge configuration from the ACP.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$csrfToken = isset($_GET['csrf_token']) && is_string($_GET['csrf_token'])
    ? $_GET['csrf_token']
    : '';
if (!$security->validateToken($csrfToken)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Security token validation failed. Reload the setup page and try again.';
    exit;
}

$baseUrl = rtrim((string)($_SESSION['setup_config']['base_url'] ?? ''), '/');
$cmsApiUrl = (string)($_SESSION['setup_console']['cms_api_url'] ?? ($baseUrl . '/api_events.php'));
$sharedSecret = (string)(
    $_SESSION['setup_config']['asp_key']
    ?? $_SESSION['setup_crypto']['asp_key']
    ?? ''
);
$bridgePort = (int)($_SESSION['setup_console']['bridge_port'] ?? 2000);

try {
    $content = daoc_bridge_config_content($cmsApiUrl, $sharedSecret, $bridgePort);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $e->getMessage();
    exit;
}

header('Content-Type: text/plain; charset=UTF-8');
header('Content-Disposition: attachment; filename="daoc_cms_bridge.conf"');
header('Content-Length: ' . strlen($content));
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
echo $content;
