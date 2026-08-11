<?php
if (!defined('IN_CMS')) exit;

if (!isset($_GET['ajax']) || $_GET['ajax'] !== '1') return;

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'login_required']);
    exit;
}

header('Content-Type: application/json');

define('IGC_SERVICE_URL', $GLOBALS['cms_settings']['igc_service_url'] ?? 'http://127.0.0.1:5100');
define('IGC_SECRET',      $GLOBALS['cms_settings']['igc_api_secret']  ?? '');

$itemshop_enabled = (int)($GLOBALS['cms_settings']['itemshop_enabled'] ?? 1);
if (!$itemshop_enabled) {
    echo json_encode(['ok' => false, 'error' => 'The item shop is currently disabled.']);
    exit;
}

function igc_call(string $endpoint, array $payload = [], string $method = 'POST'): array {
    $url = IGC_SERVICE_URL . '/' . ltrim($endpoint, '/');
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Aldhran-Secret: ' . IGC_SECRET],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['ok' => false, 'error' => 'connection_failed'];
    $data = json_decode($raw, true);
    return $data ?? ['ok' => false, 'error' => 'invalid_response'];
}

function itemshop_get_chars_with_status(PDO $db, string $accountName): array {
    $stmt = $db->prepare("SELECT Name, Realm, Level, Class FROM dolcharacters WHERE AccountName = ? ORDER BY Level DESC");
    $stmt->execute([$accountName]);
    $chars = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $status       = igc_call('status', [], 'GET');
    $online_names = [];
    if ($status['ok'] ?? false) {
        foreach ($status['players'] ?? [] as $p) {
            $online_names[] = strtolower($p['Name']);
        }
    }

    foreach ($chars as &$c) {
        $c['online'] = in_array(strtolower($c['Name']), $online_names, true);
    }
    return $chars;
}

$action = $_POST['action'] ?? '';
checkToken($_POST['csrf_token'] ?? '');

$username = $_SESSION['username'] ?? '';

if ($action === 'status') {
    $chars = itemshop_get_chars_with_status($db, $username);
    $online_char = null;
    foreach ($chars as $c) {
        if ($c['online']) { $online_char = $c; break; }
    }
    echo json_encode([
        'ok'          => true,
        'characters'  => $chars,
        'online_char' => $online_char,
    ]);
    exit;
}

if ($action === 'system_list') {
    $page     = max(1, (int)($_POST['page'] ?? 1));
    $per_page = 20;
    $offset   = ($page - 1) * $per_page;
    $search   = trim($_POST['search'] ?? '');

    $where  = "ssi.active = 1";
    $params = [];
    if ($search !== '') {
        $where .= " AND it.Name LIKE ?";
        $params[] = '%' . $search . '%';
    }

    try {
        $stmtCount = $db->prepare("
            SELECT COUNT(*) FROM shop_system_items ssi
            JOIN itemtemplate it ON it.Id_nb = ssi.item_id
            WHERE $where
        ");
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        $stmt = $db->prepare("
            SELECT ssi.item_id, ssi.category, ssi.base_price, it.Name, it.Level
            FROM shop_system_items ssi
            JOIN itemtemplate it ON it.Id_nb = ssi.item_id
            WHERE $where
            ORDER BY it.Name ASC
            LIMIT $per_page OFFSET $offset
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = array_map(function($r) {
            $price = (int)round($r['base_price'] * 1.30);
            return [
                'item_id' => $r['item_id'],
                'name'    => $r['Name'],
                'level'   => (int)$r['Level'],
                'category'=> $r['category'],
                'price'   => $price,
                'price_formatted' => number_format($price) . 'c',
            ];
        }, $rows);

        echo json_encode([
            'ok'    => true,
            'items' => $items,
            'page'  => $page,
            'pages' => max(1, (int)ceil($total / $per_page)),
            'total' => $total,
        ]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => 'The item shop catalogue has not been initialized yet..']);
    }
    exit;
}

if ($action === 'search') {
    $term   = trim($_POST['term'] ?? '');
    $source = trim($_POST['source'] ?? 'system');

    if (mb_strlen($term) < 2) {
        echo json_encode(['ok' => true, 'results' => []]);
        exit;
    }

    if ($source === 'system') {
        $stmt = $db->prepare("
            SELECT ssi.item_id, it.Name, it.Level, ssi.base_price
            FROM shop_system_items ssi
            JOIN itemtemplate it ON it.Id_nb = ssi.item_id
            WHERE ssi.active = 1 AND it.Name LIKE ?
            ORDER BY it.Name ASC
            LIMIT 20
        ");
        $stmt->execute(['%' . $term . '%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = array_map(function($r) {
            $price = (int)round($r['base_price'] * 1.30);
            return [
                'item_id'         => $r['item_id'],
                'name'            => $r['Name'],
                'level'           => (int)$r['Level'],
                'price_formatted' => number_format($price) . 'c',
            ];
        }, $rows);
    } else {
        // Housing search
        $stmt = $db->prepare("
            SELECT DISTINCT it.Id_nb AS item_id, it.Name, it.Level
            FROM inventory i
            JOIN houseconsignmentmerchant hcm ON i.OwnerID = hcm.OwnerID
            JOIN itemtemplate it ON it.Id_nb = i.ITemplate_Id
            WHERE i.SellPrice > 0 AND it.Name LIKE ?
            ORDER BY it.Name ASC
            LIMIT 20
        ");
        $stmt->execute(['%' . $term . '%']);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($results as &$r) { $r['level'] = (int)$r['level']; $r['price_formatted'] = null; }
    }

    echo json_encode(['ok' => true, 'results' => $results]);
    exit;
}

if ($action === 'item_detail') {
    $itemId = trim($_POST['item_id'] ?? '');
    $source = trim($_POST['source'] ?? 'system');

    $stmt = $db->prepare("
        SELECT it.Id_nb, it.Name, it.Level, it.Description, it.SpellID, s.Name AS SpellName, s.Description AS SpellDesc
        FROM itemtemplate it
        LEFT JOIN spell s ON s.SpellID = it.SpellID
        WHERE it.Id_nb = ?
        LIMIT 1
    ");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        echo json_encode(['ok' => false, 'error' => 'item_not_found']);
        exit;
    }

    $effect = $item['SpellDesc'] ?: ($item['Description'] ?: 'No effect description available.');

    $listings = [];
    if ($source === 'system') {
        $stmtSys = $db->prepare("SELECT base_price FROM shop_system_items WHERE item_id = ? AND active = 1");
        $stmtSys->execute([$itemId]);
        $base = $stmtSys->fetchColumn();
        if ($base !== false) {
            $price = (int)round($base * 1.30);
            $listings[] = ['ref' => 'sys_' . $itemId, 'seller_label' => 'System Vendor', 'count' => 1, 'price' => $price, 'price_formatted' => number_format($price) . 'c'];
        }
    } else {
        $stmtHc = $db->prepare("
            SELECT i.Inventory_ID AS ref, i.SellPrice, i.Count
            FROM inventory i
            JOIN houseconsignmentmerchant hcm ON i.OwnerID = hcm.OwnerID
            WHERE i.ITemplate_Id = ? AND i.SellPrice > 0
            ORDER BY i.SellPrice ASC
        ");
        $stmtHc->execute([$itemId]);
        foreach ($stmtHc->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $listings[] = [
                'ref'             => $r['ref'],
                'seller_label'    => 'Housing Merchant',
                'count'           => (int)$r['Count'],
                'price'           => (int)$r['SellPrice'],
                'price_formatted' => number_format($r['SellPrice']) . 'c',
            ];
        }
    }

    echo json_encode([
        'ok' => true,
        'item' => [
            'item_id' => $item['Id_nb'],
            'name'    => $item['Name'],
            'level'   => (int)$item['Level'],
            'effect'  => $effect,
        ],
        'listings' => $listings,
    ]);
    exit;
}

if ($action === 'purchase') {
    $ref    = trim($_POST['item_ref'] ?? '');
    $source = trim($_POST['source']   ?? '');
    $count  = max(1, (int)($_POST['count'] ?? 1));

    $chars = itemshop_get_chars_with_status($db, $username);
    $charName = null;
    foreach ($chars as $c) {
        if ($c['online']) { $charName = $c['Name']; break; }
    }

    if (!$charName) {
        echo json_encode(['ok' => false, 'error' => 'No character of yours is currently online. Please log in to the game first.']);
        exit;
    }

    $result = igc_call('shop/purchase', [
        'buyer_name' => $charName,
        'source'     => $source,
        'item_ref'   => $ref,
        'count'      => $count,
    ]);

    if (!($result['ok'] ?? false) && ($result['gold_deducted'] ?? false)) {
        $itemTemplateId = $result['item_id'] ?? '';
        $ins = $db->prepare("INSERT INTO webshop_orders (player_name, item_template_id, count, delivered, created_at) VALUES (?, ?, ?, 0, NOW())");
        $ins->execute([$charName, $itemTemplateId, $count]);
        $result['fallback_queued'] = true;
        $result['error'] = 'Purchase succeeded, delivery delayed. Your item will be sent automatically shortly.';
    }

    aldhran_log('ITEMSHOP_PURCHASE', json_encode([
        'char' => $charName, 'source' => $source, 'ref' => $ref, 'count' => $count,
        'result_ok' => $result['ok'] ?? false
    ]), (int)$_SESSION['user_id']);

    echo json_encode($result);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'unknown_action']);
