<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) exit;

function daoc_pve_pick_column(array $columns, array $candidates): ?string
{
    $lookup = [];
    foreach ($columns as $column) {
        $lookup[strtolower((string)$column)] = (string)$column;
    }
    foreach ($candidates as $candidate) {
        $match = $lookup[strtolower($candidate)] ?? null;
        if ($match !== null) return $match;
    }
    return null;
}

function daoc_pve_class_map(): array
{
    return [
        2=>'Armsman',13=>'Cabalist',6=>'Cleric',10=>'Friar',33=>'Heretic',9=>'Infiltrator',11=>'Mercenary',4=>'Minstrel',12=>'Necromancer',1=>'Paladin',19=>'Reaver',3=>'Scout',8=>'Sorcerer',5=>'Theurgist',7=>'Wizard',60=>'Mauler',
        31=>'Berserker',30=>'Bonedancer',26=>'Healer',25=>'Hunter',29=>'Runemaster',32=>'Savage',23=>'Shadowblade',28=>'Shaman',24=>'Skald',27=>'Spiritmaster',21=>'Thane',34=>'Valkyrie',59=>'Warlock',22=>'Warrior',61=>'Mauler',
        55=>'Animist',39=>'Bainshee',48=>'Bard',43=>'Blademaster',45=>'Champion',47=>'Druid',40=>'Eldritch',41=>'Enchanter',44=>'Hero',42=>'Mentalist',49=>'Nightshade',50=>'Ranger',56=>'Valewalker',58=>'Vampiir',46=>'Warden',62=>'Mauler',
    ];
}

function daoc_pve_realm_class_ids(int $realm): array
{
    $realms = [
        1 => [2,13,6,10,33,9,11,4,12,1,19,3,8,5,7,60],
        2 => [31,30,26,25,29,32,23,28,24,27,21,34,59,22,61],
        3 => [55,39,48,43,45,47,40,41,44,42,49,50,56,58,46,62],
    ];
    if (isset($realms[$realm])) return $realms[$realm];
    return array_merge($realms[1], $realms[2], $realms[3]);
}

function daoc_pve_class_restrictions(string $allowedClasses, int $realm): array
{
    preg_match_all('/\d+/', $allowedClasses, $matches);
    $allowedIds = array_values(array_unique(array_map('intval', $matches[0] ?? [])));
    $allowedIds = array_values(array_filter($allowedIds, static fn(int $id): bool => $id > 0));
    if ($allowedIds === []) {
        return ['allowed' => [], 'excluded' => [], 'restricted' => false];
    }

    $map = daoc_pve_class_map();
    $scope = daoc_pve_realm_class_ids($realm);
    $allowed = [];
    foreach ($allowedIds as $id) {
        $allowed[] = $map[$id] ?? ('Class #' . $id);
    }

    $excluded = [];
    foreach ($scope as $id) {
        if (!in_array($id, $allowedIds, true)) {
            $excluded[] = $map[$id] ?? ('Class #' . $id);
        }
    }

    return [
        'allowed' => array_values(array_unique($allowed)),
        'excluded' => array_values(array_unique($excluded)),
        'restricted' => true,
    ];
}

function daoc_pve_object_type_label(int $type): string
{
    $labels = [
        0=>'Misc',1=>'Generic Item',2=>'Inventory Item',3=>'Poison',4=>'Dye',5=>'Alchemy Tincture',6=>'Spellcraft Gem',7=>'Scroll',8=>'Arrow',9=>'Bolt',10=>'Thrown',11=>'Instrument',12=>'Shield',13=>'Armor',14=>'Cloth',15=>'Leather',16=>'Studded',17=>'Chain',18=>'Plate',19=>'Reinforced',20=>'Scale',21=>'One-Handed Weapon',22=>'Two-Handed Weapon',23=>'Polearm',24=>'Staff',25=>'Longbow',26=>'Crossbow',27=>'Sword',28=>'Knife',29=>'Axe',30=>'Spear',31=>'Composite Bow',32=>'Blunt',33=>'Jousting',34=>'Fired Weapon',35=>'Magical Item',36=>'Magical',41=>'Cloak',42=>'Jewelry',43=>'Belt',44=>'Bracer',45=>'Ring',46=>'Neck',47=>'Waist',48=>'Mythirian',
    ];
    return $labels[$type] ?? ('Type #' . $type);
}

function daoc_pve_zone_map_path(string $zoneName): ?string
{
    $slug = trim((string)preg_replace('/[^A-Za-z0-9]+/', '_', $zoneName), '_');
    if ($slug === '') return null;

    foreach (['albion', 'midgard', 'hibernia'] as $realmDir) {
        $relative = 'assets/img/zones/' . $realmDir . '/' . $slug . '_map.webp';
        if (is_file(__DIR__ . '/../' . $relative)) return $relative;
    }
    return null;
}

function daoc_pve_item_merchants(PDO $db, string $itemId): array
{
    static $cache = [];
    $cacheKey = spl_object_id($db) . ':' . $itemId;
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];

    $merchantTableName = daoc_game_table_name($db, 'merchantitem');
    $mobTableName = daoc_game_table_name($db, 'mob');
    if ($merchantTableName === null || $mobTableName === null) return $cache[$cacheKey] = [];

    $merchantColumns = daoc_game_table_columns($db, 'merchantitem');
    $mobColumns = daoc_game_table_columns($db, 'mob');
    $itemColumn = daoc_pve_pick_column($merchantColumns, ['ItemTemplateID','ItemTemplateId','ItemTemplate_Id','TemplateID']);
    $listColumn = daoc_pve_pick_column($merchantColumns, ['ItemListID','ItemListId','ItemList_ID','ItemsListTemplateID']);
    $mobListColumn = daoc_pve_pick_column($mobColumns, ['ItemsListTemplateID','ItemsListTemplateId','ItemListID','ItemListId']);
    $nameColumn = daoc_pve_pick_column($mobColumns, ['Name']);
    $xColumn = daoc_pve_pick_column($mobColumns, ['X']);
    $yColumn = daoc_pve_pick_column($mobColumns, ['Y']);
    $zColumn = daoc_pve_pick_column($mobColumns, ['Z']);
    $regionColumn = daoc_pve_pick_column($mobColumns, ['Region','RegionID']);
    $realmColumn = daoc_pve_pick_column($mobColumns, ['Realm']);

    if (!$itemColumn || !$listColumn || !$mobListColumn || !$nameColumn || !$xColumn || !$yColumn || !$regionColumn) {
        return $cache[$cacheKey] = [];
    }

    $merchantTable = daoc_game_table_sql($db, 'merchantitem');
    $mobTable = daoc_game_table_sql($db, 'mob');
    $stmt = $db->prepare("SELECT DISTINCT `{$listColumn}` FROM {$merchantTable} WHERE `{$itemColumn}` = ?");
    $stmt->execute([$itemId]);
    $listIds = array_values(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN), static fn($v): bool => $v !== null && $v !== ''));
    if ($listIds === []) return $cache[$cacheKey] = [];

    $placeholders = implode(',', array_fill(0, count($listIds), '?'));
    $select = [
        "`{$nameColumn}` AS MerchantName",
        "`{$xColumn}` AS X",
        "`{$yColumn}` AS Y",
        $zColumn ? "`{$zColumn}` AS Z" : '0 AS Z',
        "`{$regionColumn}` AS Region",
        $realmColumn ? "`{$realmColumn}` AS Realm" : '0 AS Realm',
        "`{$mobListColumn}` AS ItemListID",
    ];
    $stmt = $db->prepare('SELECT ' . implode(', ', $select) . " FROM {$mobTable} WHERE `{$mobListColumn}` IN ({$placeholders}) ORDER BY `{$nameColumn}` ASC");
    $stmt->execute($listIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $zoneTableName = daoc_game_table_name($db, 'zones');
    $zoneStmt = null;
    $zoneFallbackStmt = null;
    if ($zoneTableName !== null) {
        $zoneTable = daoc_game_table_sql($db, 'zones');
        $zoneStmt = $db->prepare("SELECT ZoneID, Name, OffsetX, OffsetY, Width, Height FROM {$zoneTable} WHERE RegionID = ? AND (? / 8192) BETWEEN OffsetX AND (OffsetX + Width) AND (? / 8192) BETWEEN OffsetY AND (OffsetY + Height) LIMIT 1");
        $zoneFallbackStmt = $db->prepare("SELECT ZoneID, Name, OffsetX, OffsetY, Width, Height FROM {$zoneTable} WHERE RegionID = ? ORDER BY ZoneID ASC LIMIT 1");
    }

    $merchants = [];
    foreach ($rows as $row) {
        $x = (float)$row['X'];
        $y = (float)$row['Y'];
        $region = (int)$row['Region'];
        $zone = null;
        if ($zoneStmt) {
            $zoneStmt->execute([$region, $x, $y]);
            $zone = $zoneStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$zone && $zoneFallbackStmt) {
                $zoneFallbackStmt->execute([$region]);
                $zone = $zoneFallbackStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }

        $zoneName = (string)($zone['Name'] ?? ('Region ' . $region));
        $xPct = null;
        $yPct = null;
        if ($zone && (float)$zone['Width'] > 0 && (float)$zone['Height'] > 0) {
            $xPct = max(0.0, min(100.0, ((($x / 8192) - (float)$zone['OffsetX']) / (float)$zone['Width']) * 100));
            $yPct = max(0.0, min(100.0, ((($y / 8192) - (float)$zone['OffsetY']) / (float)$zone['Height']) * 100));
        }

        $merchants[] = [
            'name' => (string)$row['MerchantName'],
            'x' => (int)round($x),
            'y' => (int)round($y),
            'z' => (int)round((float)$row['Z']),
            'region' => $region,
            'realm' => (int)$row['Realm'],
            'zone_name' => $zoneName,
            'map_image' => daoc_pve_zone_map_path($zoneName),
            'map_x' => $xPct,
            'map_y' => $yPct,
            'item_list_id' => (string)$row['ItemListID'],
        ];
    }

    return $cache[$cacheKey] = $merchants;
}

function daoc_pve_item_detail(PDO $db, string $itemId): ?array
{
    static $cache = [];
    $cacheKey = spl_object_id($db) . ':' . $itemId;
    if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];

    $columns = daoc_game_table_columns($db, 'itemtemplate');
    if ($columns === []) return $cache[$cacheKey] = null;

    $wanted = ['Id_nb','Name','Level','Quality','DPS_AF','SPD_ABS','Object_Type','Item_Type','Bonus','BonusLevel','AllowedClasses','Realm','Description','Model'];
    $select = [];
    foreach ($wanted as $candidate) {
        $actual = daoc_pve_pick_column($columns, [$candidate]);
        if ($actual !== null) $select[] = "`{$actual}` AS `{$candidate}`";
    }
    if (!in_array('`Id_nb` AS `Id_nb`', $select, true)) {
        $idColumn = daoc_pve_pick_column($columns, ['Id_nb']);
        if ($idColumn === null) return $cache[$cacheKey] = null;
    }

    $table = daoc_game_table_sql($db, 'itemtemplate');
    $idColumn = daoc_pve_pick_column($columns, ['Id_nb']);
    $stmt = $db->prepare('SELECT ' . implode(', ', $select) . " FROM {$table} WHERE `{$idColumn}` = ? LIMIT 1");
    $stmt->execute([$itemId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return $cache[$cacheKey] = null;

    $objectType = (int)($row['Object_Type'] ?? 0);
    $realm = (int)($row['Realm'] ?? 0);
    $restrictions = daoc_pve_class_restrictions((string)($row['AllowedClasses'] ?? ''), $realm);
    $isWeapon = $objectType >= 21 && $objectType <= 34;
    $isArmor = $objectType >= 13 && $objectType <= 20;
    $merchants = daoc_pve_item_merchants($db, $itemId);

    return $cache[$cacheKey] = [
        'id' => (string)($row['Id_nb'] ?? $itemId),
        'name' => (string)($row['Name'] ?? $itemId),
        'level' => (int)($row['Level'] ?? 0),
        'quality' => isset($row['Quality']) ? (int)$row['Quality'] : null,
        'bonus' => isset($row['Bonus']) ? (int)$row['Bonus'] : (isset($row['BonusLevel']) ? (int)$row['BonusLevel'] : null),
        'object_type' => $objectType,
        'type_label' => daoc_pve_object_type_label($objectType),
        'realm' => $realm,
        'description' => (string)($row['Description'] ?? ''),
        'model' => isset($row['Model']) ? (int)$row['Model'] : 0,
        'dps' => $isWeapon && isset($row['DPS_AF']) ? (float)$row['DPS_AF'] : null,
        'speed' => $isWeapon && isset($row['SPD_ABS']) ? (float)$row['SPD_ABS'] : null,
        'af' => $isArmor && isset($row['DPS_AF']) ? (float)$row['DPS_AF'] : null,
        'abs' => $isArmor && isset($row['SPD_ABS']) ? (float)$row['SPD_ABS'] : null,
        'allowed_classes' => $restrictions['allowed'],
        'excluded_classes' => $restrictions['excluded'],
        'class_restricted' => $restrictions['restricted'],
        'merchants' => $merchants,
        'merchant_count' => count($merchants),
    ];
}
