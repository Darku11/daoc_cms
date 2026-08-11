<?php
if (!defined('IN_ACP')) exit;

if ($userPriv < 4) {
    header("Location: acp.php");
    exit;
}

$zones_msg = '';

if (isset($_POST['save_zone'])) {
    checkToken($_POST['csrf_token'] ?? '');

    $zone_id = (int)$_POST['zone_id'];
    $is_lava = isset($_POST['is_lava']) ? 1 : 0;
    $diving_flag = (int)$_POST['diving_flag'];
    $water_level = (int)$_POST['water_level'];
    $experience = (int)$_POST['experience'];
    $realmpoints = (int)$_POST['realmpoints'];
    $bountypoints = (int)$_POST['bountypoints'];
    $coin = (int)$_POST['coin'];
    $realm = (int)$_POST['realm'];

    $stmt = $db->prepare("UPDATE zones SET IsLava = ?, DivingFlag = ?, WaterLevel = ?, Experience = ?, Realmpoints = ?, Bountypoints = ?, Coin = ?, Realm = ? WHERE ZoneID = ?");
    if ($stmt->execute([$is_lava, $diving_flag, $water_level, $experience, $realmpoints, $bountypoints, $coin, $realm, $zone_id])) {
        $zones_msg = 'saved';
    } else {
        $zones_msg = 'error';
    }
}

$edit_zone = null;
if (isset($_GET['id'])) {
    $stmt = $db->prepare("SELECT * FROM zones WHERE ZoneID = ?");
    $stmt->execute([(int)$_GET['id']]);
    $edit_zone = $stmt->fetch();
}

$zones_list = [];
$search_q = '';
$page = 1;
$per_page = 5;
$total_zones = 0;
$total_pages = 1;

if (!$edit_zone) {
    $search_q = trim($_GET['q'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    
    $where = "";
    $params = [];
    
    if ($search_q !== '') {
        if (is_numeric($search_q)) {
            $where = "WHERE ZoneID = ?";
            $params[] = (int)$search_q;
        } else {
            $where = "WHERE Name LIKE ?";
            $params[] = "%" . $search_q . "%";
        }
    }
    
    $stmt_count = $db->prepare("SELECT COUNT(*) FROM zones $where");
    $stmt_count->execute($params);
    $total_zones = (int)$stmt_count->fetchColumn();
    
    $total_pages = max(1, (int)ceil($total_zones / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;
    
    $sql = "SELECT ZoneID, RegionID, Name, IsLava, Realm FROM zones $where ORDER BY Name ASC LIMIT $per_page OFFSET $offset";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $zones_list = $stmt->fetchAll();
}
?>