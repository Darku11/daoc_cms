<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_ACP')) exit;
if (!isset($userPriv))      $userPriv      = (int)($_SESSION['priv_level'] ?? 0);
if (!isset($currentUserId)) $currentUserId = (int)($_SESSION['user_id']    ?? 0);
if ($userPriv < 4) return;

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
        'status'     => aldhran_console_call('status', [], 'GET'),
        'kick'       => aldhran_console_call('kick',      ['name' => trim($_POST['name'] ?? ''), 'reason' => trim($_POST['reason'] ?? 'Kicked by Admin')]),
        'privlevel'  => aldhran_console_call('privlevel', ['name' => trim($_POST['name'] ?? ''), 'level'  => (int)($_POST['level'] ?? 0)]),
        'teleport'   => aldhran_console_call('teleport',  ['name' => trim($_POST['name'] ?? ''), 'zone'   => trim($_POST['zone'] ?? ''), 'x' => (int)($_POST['x'] ?? 0), 'y' => (int)($_POST['y'] ?? 0), 'region' => (int)($_POST['region'] ?? 0)]),
        'giveitem'   => aldhran_console_call('giveitem',  ['name' => trim($_POST['name'] ?? ''), 'item_id'=> trim($_POST['item_id'] ?? ''), 'count' => (int)($_POST['count'] ?? 1)]),
        'setstats'   => aldhran_console_call('setstats',  ['name' => trim($_POST['name'] ?? ''), 'stat'   => trim($_POST['stat'] ?? ''), 'value' => (int)($_POST['value'] ?? 0)]),
        'broadcast'  => aldhran_console_call('broadcast', ['message' => trim($_POST['message'] ?? ''), 'sender' => $acp_username]),
        'raw'        => aldhran_console_call('raw',       ['command' => trim($_POST['command'] ?? ''), 'executor' => trim($_POST['executor'] ?? '')]),
        'heal'       => aldhran_console_call('heal',      ['name' => trim($_POST['name'] ?? '')]),
        'revive'     => aldhran_console_call('revive',    ['name' => trim($_POST['name'] ?? '')]),
        'freeze'     => aldhran_console_call('freeze',    ['name' => trim($_POST['name'] ?? ''), 'on' => ($_POST['on'] ?? '0') === '1']),
        'mute'       => aldhran_console_call('mute',      ['name' => trim($_POST['name'] ?? ''), 'on' => ($_POST['on'] ?? '0') === '1']),
        'item_search'=> aldhran_console_call('items/search', ['q' => trim($_POST['q'] ?? '')], 'GET'),
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
