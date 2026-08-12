<?php
// SPDX-License-Identifier: GPL-3.0-only
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/Installer.php';
require_once __DIR__ . '/../includes/Security.php';
require_once __DIR__ . '/../includes/Runner.php';

use DAoCCMS\Setup\Installer;
use DAoCCMS\Setup\Security;
use DAoCCMS\Setup\Runner;

// Installer's constructor starts the session, so the phase runners can
// reach the setup_* data collected by the earlier steps.
$installer = new Installer();

if ($installer->isInstalled()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'This installation is already sealed.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$security = new Security();
if (!$security->validateToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Security token validation failed. Reload the page and try again.']);
    exit;
}

// Refuses a second run within the same session (e.g. a stale tab replaying
// phase=config after 'settings' already succeeded) — see the matching guard
// in steps/install.php for why re-running is destructive, not just wasteful.
if (!empty($_SESSION['setup_install_done'])) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'Installation already completed in this session.']);
    exit;
}

$phase = (string) ($_POST['phase'] ?? '');
if (!array_key_exists($phase, Runner::PHASES)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown installation phase: ' . $phase]);
    exit;
}

try {
    $detail = Runner::run($phase);
    if ($phase === 'settings') {
        $_SESSION['setup_install_done'] = true;
    }
    echo json_encode(['ok' => true, 'detail' => $detail]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
