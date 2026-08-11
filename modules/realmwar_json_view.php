<?php
if (!defined('IN_CMS')) exit;

// ── Module guard ───────────────────────────────────────────────
if (($GLOBALS['cms_settings']['mod_rvr_map'] ?? '1') === '0') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Module disabled']);
    exit;
}

// ── Clear all output buffers early & set a clean JSON header ──
// The CMS router has already called ob_start() – we clear everything
// (header, sidebar, etc.) and return only JSON.
while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── Keep map: slug → DOL KeepID ──────────────────────────────
const RW_REALM_MAP = [0 => 'neutral', 1 => 'alb', 2 => 'mid', 3 => 'hib'];

const RW_KEEP_ID_MAP = [
    'dun_crauchon'    => 100,
    'dun_crimthain'   => 101,
    'dun_bolg'        => 102,
    'dun_nged'        => 103,
    'dun_da_behnn'    => 104,
    'dun_scathaig'    => 105,
    'dun_ailinne'     => 106,
    'bledmeer'        => 75,
    'nottmoor'        => 76,
    'hlidskialf'      => 77,
    'blendrake'       => 78,
    'glenlock'        => 79,
    'fensalir'        => 80,
    'arvakr'          => 81,
    'caer_benowyc'    => 50,
    'caer_berkstead'  => 51,
    'caer_erasleigh'  => 52,
    'caer_boldiam'    => 53,
    'caer_sursbrooke' => 54,
    'caer_hurbury'    => 55,
    'caer_renaris'    => 56,
];

try {
    $rows = daoc_game_realm_war_keeps($db, array_values(RW_KEEP_ID_MAP));

    $keepStatus = [];
    foreach (RW_KEEP_ID_MAP as $slug => $keepId) {
        $row = $rows[$keepId] ?? null;
        $realmId = $row ? (int)$row['Realm'] : 0;
        $keepStatus[$slug] = [
            'owner' => RW_REALM_MAP[$realmId] ?? 'neutral',
            'guild' => $row ? (string)$row['GuildName'] : '',
        ];
    }

    $counts = array_count_values(array_column($keepStatus, 'owner'));
    unset($counts['neutral']);
    arsort($counts);
    $dfOwner = !empty($counts) ? array_key_first($counts) : 'neutral';

    echo json_encode([
        'success'  => true,
        'keeps'    => $keepStatus,
        'df_owner' => $dfOwner,
        'counts'   => [
            'alb' => $counts['alb'] ?? 0,
            'mid' => $counts['mid'] ?? 0,
            'hib' => $counts['hib'] ?? 0,
        ],
        'updated'  => time(),
    ]);

} catch (Throwable $e) {
    error_log('Realmwar API query failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
exit;
