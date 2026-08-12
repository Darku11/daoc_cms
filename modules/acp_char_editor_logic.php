<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_ACP')) exit;

if (!isset($userPriv) || $userPriv < 3) {
    header("Location: index.php");
    exit;
}

// ── Helper functions for the visualization ───────────────────────────────
function getRealmData($realmId) {
    switch ((int)$realmId) {
        case 1: return ['name' => 'Albion', 'color' => '#b85050', 'bg' => 'rgba(184,80,80,0.1)', 'icon' => 'fa-chess-rook'];
        case 2: return ['name' => 'Midgard', 'color' => '#5088b8', 'bg' => 'rgba(80,136,184,0.1)', 'icon' => 'fa-hammer'];
        case 3: return ['name' => 'Hibernia', 'color' => '#50b86a', 'bg' => 'rgba(80,184,106,0.1)', 'icon' => 'fa-leaf'];
        default: return ['name' => 'Unknown', 'color' => '#888888', 'bg' => 'rgba(136,136,136,0.1)', 'icon' => 'fa-question'];
    }
}

// ── Actions (POST) ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkToken($_POST['csrf_token'] ?? '');
    $charId = $_POST['char_id'] ?? '';

    // Quick Actions (Teleport, Heal, etc.)
    if (isset($_POST['quick_action'])) {
        $action = $_POST['quick_action'];
        $qa_val = trim($_POST['qa_value'] ?? '');
        
        // Hook in game server database or socket/ASP bridge logic here
        $actionMsg = "Quick Action executed: " . h($action) . (!empty($qa_val) ? " (Value: " . h($qa_val) . ")" : "");
        
        header("Location: acp.php?s=char_editor&edit=" . urlencode($charId) . "&msg=qa_done&qa_msg=" . urlencode($actionMsg));
        exit;
    }

    // Normal saving of the character sheet
    if (isset($_POST['update_char'])) {
        $fields = [
            'Level' => (int)$_POST['level'],
            'Experience' => (int)$_POST['experience'],
            'RealmPoints' => (int)$_POST['realm_points'],
            'BountyPoints' => (int)$_POST['bounty_points'],
            
            // Stats
            'Strength' => (int)$_POST['stat_str'],
            'Constitution' => (int)$_POST['stat_con'],
            'Dexterity' => (int)$_POST['stat_dex'],
            'Quickness' => (int)$_POST['stat_qui'],
            'Intelligence' => (int)$_POST['stat_int'],
            'Piety' => (int)$_POST['stat_pie'],
            'Empathy' => (int)$_POST['stat_emp'],
            'Charisma' => (int)$_POST['stat_cha'],
            
            // Wealth
            'Mithril' => (int)$_POST['money_mithril'],
            'Platinum' => (int)$_POST['money_plat'],
            'Gold' => (int)$_POST['money_gold'],
            'Silver' => (int)$_POST['money_silver'],
            'Copper' => (int)$_POST['money_copper']
        ];
        
        if (!empty($charId)) {
            $setParts = [];
            $params = [];
            foreach ($fields as $key => $val) {
                $setParts[] = "`$key` = ?";
                $params[] = $val;
            }
            $params[] = $charId; 
            
            $sql = "UPDATE dolcharacters SET " . implode(', ', $setParts) . " WHERE DOLCharacters_ID = ?";
            
            try {
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                header("Location: acp.php?s=char_editor&edit=" . urlencode($charId) . "&msg=saved");
                exit;
            } catch (\PDOException $e) {
                $error_msg = "Database Error: " . $e->getMessage();
            }
        }
    }
}

// ── Data retrieval (GET) ───────────────────────────────────────────────────
$charList = [];
$editChar = null;

if (isset($_GET['edit'])) {
    $charId = $_GET['edit'];
    try {
        $stmt = $db->prepare("SELECT * FROM dolcharacters WHERE DOLCharacters_ID = ? LIMIT 1");
        $stmt->execute([$charId]);
        $editChar = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {}
    
} else {
    $search = trim($_GET['q'] ?? '');
    $query = "SELECT DOLCharacters_ID, Name, AccountName, Level, Class, Realm, LastPlayed 
              FROM dolcharacters ";
    $params = [];
    
    if ($search !== '') {
        $query .= "WHERE Name LIKE ? OR AccountName LIKE ? ";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    $query .= "ORDER BY LastPlayed DESC LIMIT 50";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $charList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {}
}