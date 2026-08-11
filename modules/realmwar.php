<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../includes/db.php';

// ── Module guard ──────────────────────────────────────────────
try {
    $mod_check = $db->prepare("SELECT value FROM settings WHERE setting_key = 'mod_rvr_map' LIMIT 1");
    $mod_check->execute();
    $mod_val = $mod_check->fetchColumn();
    if ($mod_val === '0') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Module disabled']);
        exit;
    }
} catch (Exception $e) {
    // Error during check → continue anyway (fail open for the API)
}

const REALM_MAP = [0 => 'neutral', 1 => 'alb', 2 => 'mid', 3 => 'hib'];

const KEEP_ID_MAP = [
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
    $placeholders = implode(',', array_fill(0, count(KEEP_ID_MAP), '?'));
    $ids = array_values(KEEP_ID_MAP);
    $stmt = $db->prepare("SELECT KeepID, Realm FROM keep WHERE KeepID IN ($placeholders)");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $keepStatus = [];
    foreach (KEEP_ID_MAP as $slug => $keepId) {
        $realmId = isset($rows[$keepId]) ? (int)$rows[$keepId] : 0;
        $keepStatus[$slug] = REALM_MAP[$realmId] ?? 'neutral';
    }

    $counts = array_count_values(array_values($keepStatus));
    unset($counts['neutral']);
    arsort($counts);
    $dfOwner = !empty($counts) ? array_key_first($counts) : 'neutral';

    echo json_encode([
        'success'  => true,
        'keeps'    => $keepStatus,
        'df_owner' => $dfOwner,
        'counts'   => ['alb' => $counts['alb'] ?? 0, 'mid' => $counts['mid'] ?? 0, 'hib' => $counts['hib'] ?? 0],
        'updated'  => time(),
    ], JSON_PRETTY_PRINT);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}