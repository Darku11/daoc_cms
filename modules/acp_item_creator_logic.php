<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_ACP')) exit;

// Fallback: pull $userPriv/$currentUserId from session if not already in scope
if (!isset($userPriv))      $userPriv      = (int)($_SESSION['priv_level'] ?? 0);
if (!isset($currentUserId)) $currentUserId = (int)($_SESSION['user_id']    ?? 0);


define('ITEM_STAT_WEIGHTS', [
    1=>0.6667,2=>0.6667,3=>0.6667,4=>0.6667,5=>0.6667,6=>0.6667,7=>0.6667,8=>0.6667,
    10=>0.25,11=>2.0,12=>2.0,
    13=>2.0,14=>2.0,15=>2.0,16=>2.0,17=>2.0,18=>2.0,19=>2.0,20=>2.0,21=>2.0,22=>2.0,
]);

function ie_calc_utility(array $bonuses): float {
    $utility = 0.0;
    foreach ($bonuses as $type => $value) {
        $type  = (int)$type; $value = (float)$value;
        if ($value <= 0) continue;
        $utility += $value * (ITEM_STAT_WEIGHTS[$type] ?? 1.0);
    }
    return round($utility, 2);
}

function ie_utility_cap(int $level): float {
    return round(($level / 50) * 100, 1);
}

/** Resolve the physical bonus-type column used by the connected schema. */
function ie_bonus_type_column(PDO $db, int $slot): ?string {
    $officialName = "Bonus{$slot}Type";
    if (daoc_game_column_exists($db, 'itemtemplate', $officialName)) return $officialName;

    $legacyName = "BonusType{$slot}";
    return daoc_game_column_exists($db, 'itemtemplate', $legacyName) ? $legacyName : null;
}

// ── AI helper: collect bonuses from POST ────────────────────────
function ie_collect_bonuses_from_post(): array {
    $bonuses = [];
    for ($i = 1; $i <= 10; $i++) {
        $type  = (int)($_POST["BonusType{$i}"] ?? 0);
        $value = (float)($_POST["Bonus{$i}"]   ?? 0);
        if ($type > 0 && $value > 0) $bonuses[$type] = ($bonuses[$type] ?? 0) + $value;
    }
    $et = (int)($_POST['ExtraBonusType'] ?? 0);
    $ev = (float)($_POST['ExtraBonus']   ?? 0);
    if ($et > 0 && $ev > 0) $bonuses[$et] = ($bonuses[$et] ?? 0) + $ev;
    return $bonuses;
}

// ── AJAX Handler ───────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $action = $_GET['ajax'] ?? '';

    // ── Calculate utility ──────────────────────────────────────
    if ($action === 'calc_utility') {
        $bonuses = ie_collect_bonuses_from_post();
        $utility = ie_calc_utility($bonuses);
        $level   = (int)($_POST['Level'] ?? 50);
        $cap     = ie_utility_cap($level);
        echo json_encode([
            'utility' => $utility,
            'cap'     => $cap,
            'overpow' => $utility > $cap,
            'pct'     => $cap > 0 ? round(($utility / $cap) * 100, 1) : 0,
        ]);
        exit;
    }

    // ── Model suggestions ──────────────────────────────────────
    if ($action === 'model_suggest') {
        $name = '%' . trim($_GET['name'] ?? '') . '%';
        $stmt = $db->prepare("SELECT Model, Name, COUNT(*) as cnt FROM itemtemplate WHERE Name LIKE ? AND Model > 0 GROUP BY Model ORDER BY cnt DESC LIMIT 12");
        $stmt->execute([$name]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // ── Search item ────────────────────────────────────────────
    if ($action === 'search') {
        $q    = '%' . trim($_GET['q'] ?? '') . '%';
        $stmt = $db->prepare("SELECT Id_nb, Name, Level, Object_Type, Model FROM itemtemplate WHERE Name LIKE ? OR Id_nb LIKE ? ORDER BY Name ASC LIMIT 40");
        $stmt->execute([$q, $q]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // ── Load item ──────────────────────────────────────────────
    if ($action === 'load') {
        $id   = trim($_GET['id'] ?? '');
        $stmt = $db->prepare("SELECT * FROM itemtemplate WHERE Id_nb = ? LIMIT 1");
        $stmt->execute([$id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($item) {
            for ($i = 1; $i <= 10; $i++) {
                $typeColumn = ie_bonus_type_column($db, $i);
                $item["BonusType{$i}"] = $typeColumn === null ? 0 : (int)($item[$typeColumn] ?? 0);
            }
        }
        echo json_encode($item ?: ['error' => 'not_found']);
        exit;
    }

    // ── Delete item ────────────────────────────────────────────
    if ($action === 'delete') {
        if ($userPriv < 4) { echo json_encode(['error' => 'permission_denied']); exit; }
        checkToken($_POST['csrf_token'] ?? '');
        $id = trim($_POST['id'] ?? '');
        if (!$id) { echo json_encode(['error' => 'no_id']); exit; }
        $used = $db->prepare("SELECT COUNT(*) FROM merchantitem WHERE ItemTemplateID = ?");
        $used->execute([$id]);
        if ((int)$used->fetchColumn() > 0) {
            echo json_encode(['error' => 'in_use', 'msg' => 'Item is used in merchants. Remove from merchants first.']);
            exit;
        }
        $db->prepare("DELETE FROM itemtemplate WHERE Id_nb = ?")->execute([$id]);
        aldhran_log("ITEM_DELETE", "Deleted item: {$id}", $currentUserId);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Save item ──────────────────────────────────────────────
    if ($action === 'save') {
        if ($userPriv < 4) { echo json_encode(['error' => 'permission_denied']); exit; }
        checkToken($_POST['csrf_token'] ?? '');
        $id_nb  = trim($_POST['Id_nb'] ?? '');
        $is_new = (bool)($_POST['is_new'] ?? false);
        if (!$id_nb) { echo json_encode(['error' => 'no_id']); exit; }

        $fields = [
            'TranslationId'=>trim($_POST['TranslationId']??''),'Name'=>trim($_POST['Name']??''),
            'ExamineArticle'=>trim($_POST['ExamineArticle']??''),'MessageArticle'=>trim($_POST['MessageArticle']??''),
            'Level'=>(int)($_POST['Level']??0),'Durability'=>(int)($_POST['Durability']??100),
            'MaxDurability'=>(int)($_POST['MaxDurability']??100),'Condition'=>(int)($_POST['Condition']??100),
            'MaxCondition'=>(int)($_POST['MaxCondition']??100),'Quality'=>(int)($_POST['Quality']??100),
            'DPS_AF'=>(int)($_POST['DPS_AF']??0),'SPD_ABS'=>(int)($_POST['SPD_ABS']??0),
            'Hand'=>(int)($_POST['Hand']??0),'Type_Damage'=>(int)($_POST['Type_Damage']??0),
            'Object_Type'=>(int)($_POST['Object_Type']??0),'Item_Type'=>(int)($_POST['Item_Type']??0),
            'Color'=>(int)($_POST['Color']??0),'Emblem'=>(int)($_POST['Emblem']??0),
            'Effect'=>(int)($_POST['Effect']??0),'Weight'=>(int)($_POST['Weight']??0),
            'Model'=>(int)($_POST['Model']??0),'Extension'=>(int)($_POST['Extension']??0),
            'Price'=>(int)($_POST['Price']??0),'IsPickable'=>(int)($_POST['IsPickable']??1),
            'IsDropable'=>(int)($_POST['IsDropable']??1),'IsTradable'=>(int)($_POST['IsTradable']??1),
            'IsIndestructible'=>(int)($_POST['IsIndestructible']??0),'IsNotLosingDur'=>(int)($_POST['IsNotLosingDur']??0),
            'MaxCount'=>(int)($_POST['MaxCount']??1),'PackSize'=>(int)($_POST['PackSize']??1),
            'Charges'=>(int)($_POST['Charges']??0),'MaxCharges'=>(int)($_POST['MaxCharges']??0),
            'SpellID'=>(int)($_POST['SpellID']??0),'ProcSpellID'=>(int)($_POST['ProcSpellID']??0),
            'ProcChance'=>(int)($_POST['ProcChance']??0),'Realm'=>(int)($_POST['Realm']??0),
            'BonusLevel'=>(int)($_POST['BonusLevel']??0),'LevelRequirement'=>(int)($_POST['LevelRequirement']??0),
            'AllowedClasses'=>trim($_POST['AllowedClasses']??''),'CanUseEvery'=>(int)($_POST['CanUseEvery']??0),
            'Flags'=>(int)($_POST['Flags']??0),'Description'=>trim($_POST['Description']??''),
            'PackageID'=>trim($_POST['PackageID']??''),
        ];
        for ($i = 1; $i <= 10; $i++) {
            $fields["Bonus{$i}"] = (int)($_POST["Bonus{$i}"] ?? 0);
            $typeColumn = ie_bonus_type_column($db, $i);
            if ($typeColumn !== null) {
                $fields[$typeColumn] = (int)($_POST["BonusType{$i}"] ?? 0);
            }
        }
        $fields['ExtraBonus']     = (int)($_POST['ExtraBonus']     ?? 0);
        $fields['ExtraBonusType'] = (int)($_POST['ExtraBonusType'] ?? 0);

        // OpenDAoC, DOL and custom forks do not always expose the exact same
        // optional item columns. Persist only fields supported by this schema.
        $fields = daoc_game_filter_table_row($db, 'itemtemplate', $fields);
        if ($fields === [] || !daoc_game_column_exists($db, 'itemtemplate', 'Id_nb')) {
            echo json_encode(['error'=>'unsupported_schema','msg'=>'The itemtemplate schema is not compatible.']);
            exit;
        }

        if ($is_new) {
            $check = $db->prepare("SELECT Id_nb FROM itemtemplate WHERE Id_nb = ?");
            $check->execute([$id_nb]);
            if ($check->fetch()) { echo json_encode(['error'=>'duplicate_id','msg'=>"ID '{$id_nb}' already exists."]); exit; }
            $fields['Id_nb'] = $id_nb;
            $cols = implode(', ', array_map(fn($k) => "`{$k}`", array_keys($fields)));
            $vals = implode(', ', array_fill(0, count($fields), '?'));
            $db->prepare("INSERT INTO itemtemplate ({$cols}) VALUES ({$vals})")->execute(array_values($fields));
            aldhran_log("ITEM_CREATE", "Created item: {$id_nb}", $currentUserId);
        } else {
            $sets = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($fields)));
            $vals = array_values($fields);
            $vals[] = $id_nb;
            $db->prepare("UPDATE itemtemplate SET {$sets} WHERE Id_nb = ?")->execute($vals);
            aldhran_log("ITEM_UPDATE", "Updated item: {$id_nb}", $currentUserId);
        }

        // ── AI detect set: check if similar items could form a set ──
        global $botSettings;
        if (isset($botSettings) && $botSettings->isActive() && class_exists('AiManager')) {
            try {
                $itemName    = $fields['Name'] ?? $id_nb;
                $objectType  = (int)($fields['Object_Type'] ?? 0);
                $level       = (int)($fields['Level'] ?? 50);
                $realm       = (int)($fields['Realm'] ?? 0);

                // Find similar items in DB (same level ±5, same realm)
                $similar = $db->prepare("
                    SELECT Id_nb, Name, Object_Type, Level FROM itemtemplate
                    WHERE Level BETWEEN ? AND ? AND Realm = ? AND Id_nb != ?
                    ORDER BY Name LIMIT 20
                ");
                $similar->execute([$level - 5, $level + 5, $realm, $id_nb]);
                $similarItems = $similar->fetchAll(PDO::FETCH_ASSOC);

                if (count($similarItems) >= 3) {
                    $ai = new AiManager($db, $botSettings, $currentUserId, $userPriv);
                    $ai->request('item_creator', 'detect_set', [
                        'current_item' => ['id' => $id_nb, 'name' => $itemName, 'level' => $level, 'object_type' => $objectType],
                        'similar_items' => $similarItems,
                        'instruction'  => 'Analyze if these items could form a gear set. If yes, suggest a set name and which items belong together. Also check if important armor slots are missing.',
                    ], ['save_suggestion' => true, 'target_id' => 0]);
                }
            } catch (\Throwable $e) {
                error_log("Item Creator AI detect_set failed: " . $e->getMessage());
            }
        }

        echo json_encode(['ok' => true, 'id' => $id_nb]);
        exit;
    }

    // ── Clone item ─────────────────────────────────────────────
    if ($action === 'clone') {
        if ($userPriv < 4) { echo json_encode(['error' => 'permission_denied']); exit; }
        checkToken($_POST['csrf_token'] ?? '');
        $src_id   = trim($_POST['src_id']   ?? '');
        $new_id   = trim($_POST['new_id']   ?? '');
        $new_name = trim($_POST['new_name'] ?? '');
        if (!$src_id || !$new_id) { echo json_encode(['error' => 'missing']); exit; }
        $src = $db->prepare("SELECT * FROM itemtemplate WHERE Id_nb = ?");
        $src->execute([$src_id]);
        $item = $src->fetch(PDO::FETCH_ASSOC);
        if (!$item) { echo json_encode(['error' => 'src_not_found']); exit; }
        $check = $db->prepare("SELECT Id_nb FROM itemtemplate WHERE Id_nb = ?");
        $check->execute([$new_id]);
        if ($check->fetch()) { echo json_encode(['error' => 'duplicate_id']); exit; }
        unset(
            $item['LastTimeRowUpdated'],
            $item['ItemTemplate_ID'],
            $item['ItemUnique_ID']
        );
        $item['Id_nb'] = $new_id;
        if ($new_name) $item['Name'] = $new_name;
        $item = daoc_game_filter_table_row($db, 'itemtemplate', $item);
        $cols = implode(', ', array_map(fn($k) => "`{$k}`", array_keys($item)));
        $vals = implode(', ', array_fill(0, count($item), '?'));
        $db->prepare("INSERT INTO itemtemplate ({$cols}) VALUES ({$vals})")->execute(array_values($item));
        aldhran_log("ITEM_CLONE", "Cloned {$src_id} → {$new_id}", $currentUserId);
        echo json_encode(['ok' => true, 'id' => $new_id]);
        exit;
    }

    // ════════════════════════════════════════════════════════
    // AI ACTIONS
    // ════════════════════════════════════════════════════════

    // ── AI: balance check ──────────────────────────────────────
    if ($action === 'ai_balance_check') {
        if ($userPriv < 4) { echo json_encode(['error' => 'permission_denied']); exit; }
        checkToken($_POST['csrf_token'] ?? '');
        if (!class_exists('AiManager')) { echo json_encode(['error' => 'AiManager not available']); exit; }

        $level   = (int)($_POST['Level']       ?? 50);
        $name    = trim($_POST['Name']          ?? '');
        $objType = (int)($_POST['Object_Type']  ?? 0);
        $bonuses = ie_collect_bonuses_from_post();
        $utility = ie_calc_utility($bonuses);
        $cap     = ie_utility_cap($level);

        global $botSettings;
        $ai = new AiManager($db, $botSettings, $currentUserId, $userPriv);
        $result = $ai->request('item_creator', 'suggest_stats', [
            'item_name'    => $name,
            'level'        => $level,
            'object_type'  => $objType,
            'current_utility' => $utility,
            'utility_cap'  => $cap,
            'over_cap'     => $utility > $cap,
            'bonuses'      => $bonuses,
            'instruction'  => 'Analyze this item\'s balance for a DAoC private server. Check if stats are appropriate for its level. Suggest specific improvements if needed. Be concise.',
        ], ['save_suggestion' => true, 'target_id' => 0]);

        echo json_encode($result);
        exit;
    }

    // ── AI: generate lore / description ────────────────────────
    if ($action === 'ai_generate_lore') {
        if ($userPriv < 4) { echo json_encode(['error' => 'permission_denied']); exit; }
        checkToken($_POST['csrf_token'] ?? '');
        if (!class_exists('AiManager')) { echo json_encode(['error' => 'AiManager not available']); exit; }

        $name    = trim($_POST['Name']         ?? '');
        $level   = (int)($_POST['Level']       ?? 50);
        $objType = (int)($_POST['Object_Type'] ?? 0);
        $realm   = (int)($_POST['Realm']       ?? 0);

        $realmNames  = [0=>'Any',1=>'Albion',2=>'Midgard',3=>'Hibernia'];
        $objectNames = [13=>'Armor',21=>'1H Weapon',22=>'2H Weapon',41=>'Cloak',42=>'Jewelry',45=>'Ring',46=>'Necklace'];

        global $botSettings;
        $ai = new AiManager($db, $botSettings, $currentUserId, $userPriv);
        $result = $ai->request('item_creator', 'generate_lore', [
            'item_name'   => $name,
            'level'       => $level,
            'object_type' => $objectNames[$objType] ?? "Type $objType",
            'realm'       => $realmNames[$realm]    ?? 'Any',
            'instruction' => 'Write a short, lore-appropriate item description (2-3 sentences) for this Dark Age of Camelot item. Focus on its origin, material, and legendary history. Keep it immersive and concise.',
        ], ['save_suggestion' => true, 'target_id' => 0]);

        echo json_encode($result);
        exit;
    }

    // ── AI: suggest stats (full rebuild) ───────────────────────
    if ($action === 'ai_suggest_stats') {
        if ($userPriv < 4) { echo json_encode(['error' => 'permission_denied']); exit; }
        checkToken($_POST['csrf_token'] ?? '');
        if (!class_exists('AiManager')) { echo json_encode(['error' => 'AiManager not available']); exit; }

        $name    = trim($_POST['Name']         ?? '');
        $level   = (int)($_POST['Level']       ?? 50);
        $objType = (int)($_POST['Object_Type'] ?? 0);
        $realm   = (int)($_POST['Realm']       ?? 0);
        $classes = trim($_POST['AllowedClasses'] ?? '');

        global $botSettings;
        $ai = new AiManager($db, $botSettings, $currentUserId, $userPriv);
        $result = $ai->request('item_creator', 'suggest_stats', [
            'item_name'       => $name,
            'level'           => $level,
            'object_type'     => $objType,
            'realm'           => $realm,
            'allowed_classes' => $classes ?: 'all',
            'utility_cap'     => ie_utility_cap($level),
            'instruction'     => 'Suggest a balanced set of bonus stats (BonusType + BonusValue pairs) for this item. Stay within the utility cap. Return JSON with array "bonuses": [{type: int, value: int}]. Max 6 bonuses. Use DAoC bonus type IDs (1=STR,2=DEX,3=CON,4=QUI,10=Hits,13-22=Resists).',
        ], ['save_suggestion' => true, 'target_id' => 0]);

        echo json_encode($result);
        exit;
    }

    echo json_encode(['error' => 'unknown_action']);
    exit;
}
