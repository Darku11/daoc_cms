<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_ACP')) exit;
if ($userPriv < 4) return;

if (!isset($userPriv))      $userPriv      = (int)($_SESSION['priv_level'] ?? 0);
if (!isset($currentUserId)) $currentUserId = (int)($_SESSION['user_id']    ?? 0);

define('IGC_SERVICE_URL', $GLOBALS['cms_settings']['igc_service_url'] ?? 'http://127.0.0.1:5100');
define('IGC_SECRET',      $GLOBALS['cms_settings']['igc_api_secret']  ?? '');

function igc_call(string $endpoint, array $payload = [], string $method = 'POST'): array {
    $url = IGC_SERVICE_URL . '/' . ltrim($endpoint, '/');
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Aldhran-Secret: ' . IGC_SECRET,
        ],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    } elseif ($method === 'GET' && !empty($payload)) {
        curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($payload));
    }
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err || $raw === false)
        return ['ok' => false, 'error' => $err ?: 'Connection failed'];
    $data = json_decode($raw, true);
    return $data ?? ['ok' => false, 'error' => 'Invalid response'];
}

// ── AJAX handlers ──────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && isset($_POST['igc_action'])) {
    header('Content-Type: application/json');
    checkToken($_POST['csrf_token'] ?? '');

    $action = $_POST['igc_action'];

    // SuperAdmin-only actions
    $superonly = ['raw'];
    if (in_array($action, $superonly) && $userPriv < 5) {
        echo json_encode(['ok' => false, 'error' => 'SuperAdmin only']);
        exit;
    }

    $result = match($action) {
        'status'     => igc_call('status', [], 'GET'),
        'kick'       => igc_call('kick',      ['name' => trim($_POST['name'] ?? ''), 'reason' => trim($_POST['reason'] ?? 'Kicked by Admin')]),
        'privlevel'  => igc_call('privlevel', ['name' => trim($_POST['name'] ?? ''), 'level'  => (int)($_POST['level'] ?? 0)]),
        'teleport'   => igc_call('teleport',  ['name' => trim($_POST['name'] ?? ''), 'zone'   => trim($_POST['zone'] ?? ''), 'x' => (int)($_POST['x'] ?? 0), 'y' => (int)($_POST['y'] ?? 0), 'region' => (int)($_POST['region'] ?? 0)]),
        'giveitem'   => igc_call('giveitem',  ['name' => trim($_POST['name'] ?? ''), 'item_id'=> trim($_POST['item_id'] ?? ''), 'count' => (int)($_POST['count'] ?? 1)]),
        'setstats'   => igc_call('setstats',  ['name' => trim($_POST['name'] ?? ''), 'stat'   => trim($_POST['stat'] ?? ''), 'value' => (int)($_POST['value'] ?? 0)]),
        'broadcast'  => igc_call('broadcast', ['message' => trim($_POST['message'] ?? ''), 'sender' => $acp_username]),
        'raw'        => igc_call('raw',       ['command' => trim($_POST['command'] ?? ''), 'executor' => trim($_POST['executor'] ?? '')]),
        'heal'       => igc_call('heal',      ['name' => trim($_POST['name'] ?? '')]),
        'revive'     => igc_call('revive',    ['name' => trim($_POST['name'] ?? '')]),
        'freeze'     => igc_call('freeze',    ['name' => trim($_POST['name'] ?? ''), 'on' => ($_POST['on'] ?? '0') === '1']),
        'mute'       => igc_call('mute',      ['name' => trim($_POST['name'] ?? ''), 'on' => ($_POST['on'] ?? '0') === '1']),
        'item_search'=> igc_call('items/search', ['q' => trim($_POST['q'] ?? '')], 'GET'),
        default      => ['ok' => false, 'error' => 'Unknown action'],
    };

    // Log to admin log — strip csrf_token before logging so the token
    // itself never ends up in plaintext in the log table.
    $log_payload = $_POST;
    unset($log_payload['csrf_token']);
    aldhran_log('IGC_' . strtoupper($action), json_encode($log_payload, JSON_UNESCAPED_UNICODE), $currentUserId);

    echo json_encode($result);
    exit;
}
