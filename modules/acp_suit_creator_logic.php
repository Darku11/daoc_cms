<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_ACP')) exit;

if (!isset($userPriv))      $userPriv      = (int)($_SESSION['priv_level'] ?? 0);
if (!isset($currentUserId)) $currentUserId = (int)($_SESSION['user_id']    ?? 0);

if (function_exists('cms_load_language_context')) {
    cms_load_language_context('suit_creator');
}

if (!function_exists('t')) {
    function t(string $key, array $params = [], string $fallback = ''): string {
        return $fallback !== '' ? $fallback : $key;
    }
}

$SC_PROPERTIES = [
    // ── Stats ──
    1   => ['label'=>'Strength',       'short'=>'Str', 'group'=>'stat',   'weight'=>0.6667],
    2   => ['label'=>'Dexterity',      'short'=>'Dex', 'group'=>'stat',   'weight'=>0.6667],
    3   => ['label'=>'Constitution',   'short'=>'Con', 'group'=>'stat',   'weight'=>0.6667],
    4   => ['label'=>'Quickness',      'short'=>'Qui', 'group'=>'stat',   'weight'=>0.6667],
    5   => ['label'=>'Intelligence',   'short'=>'Int', 'group'=>'stat',   'weight'=>0.6667],
    6   => ['label'=>'Piety',          'short'=>'Pie', 'group'=>'stat',   'weight'=>0.6667],
    7   => ['label'=>'Empathy',        'short'=>'Emp', 'group'=>'stat',   'weight'=>0.6667],
    8   => ['label'=>'Charisma',       'short'=>'Cha', 'group'=>'stat',   'weight'=>0.6667],

    9   => ['label'=>'Power',          'short'=>'Pow', 'group'=>'power',  'weight'=>2.0],
    10  => ['label'=>'Hits',           'short'=>'HP',  'group'=>'hits',   'weight'=>0.25],

    // ── Resists ──
    11  => ['label'=>'Body Resist',    'short'=>'Bod', 'group'=>'resist', 'weight'=>2.0],
    12  => ['label'=>'Cold Resist',    'short'=>'Col', 'group'=>'resist', 'weight'=>2.0],
    13  => ['label'=>'Crush Resist',   'short'=>'Cru', 'group'=>'resist', 'weight'=>2.0],
    14  => ['label'=>'Energy Resist',  'short'=>'Ene', 'group'=>'resist', 'weight'=>2.0],
    15  => ['label'=>'Heat Resist',    'short'=>'Hea', 'group'=>'resist', 'weight'=>2.0],
    16  => ['label'=>'Matter Resist',  'short'=>'Mat', 'group'=>'resist', 'weight'=>2.0],
    17  => ['label'=>'Slash Resist',   'short'=>'Sla', 'group'=>'resist', 'weight'=>2.0],
    18  => ['label'=>'Spirit Resist',  'short'=>'Spi', 'group'=>'resist', 'weight'=>2.0],
    19  => ['label'=>'Thrust Resist',  'short'=>'Thr', 'group'=>'resist', 'weight'=>2.0],
    249 => ['label'=>'Natural Resist', 'short'=>'Nat', 'group'=>'resist', 'weight'=>2.0],
];
$GLOBALS['SC_PROPERTIES'] = $SC_PROPERTIES;

/* Defines the order and headings of the groups displayed in the UI. */
$SC_GROUPS = [
    'stat'    => ['label'=>'Stats',            'icon'=>'fa-dumbbell',    'cap'=>'stat'],
    'hits'    => ['label'=>'Hits',             'icon'=>'fa-heart',       'cap'=>'hits'],
    'power'   => ['label'=>'Power',            'icon'=>'fa-bolt',        'cap'=>'power'],
    'resist'  => ['label'=>'Resists',          'icon'=>'fa-shield-alt',  'cap'=>'resist'],
    'skill'   => ['label'=>'Skills',           'icon'=>'fa-scroll',      'cap'=>'skill'],
    'focus'   => ['label'=>'Focus',            'icon'=>'fa-gem',         'cap'=>'focus'],
    'pct'     => ['label'=>'Percent Bonuses',  'icon'=>'fa-percent',     'cap'=>'pct'],
    'cap_inc' => ['label'=>'Cap Increases',    'icon'=>'fa-arrow-up',    'cap'=>'cap_inc'],
];
$GLOBALS['SC_GROUPS'] = $SC_GROUPS;


/* ══════════════════════════════════════════════════════════════════
   2. SERVER-PROFILES
   ══════════════════════════════════════════════════════════════════ */
$SC_SERVER_PROFILES = [
    'i50' => [
        'label'=>'I50 (Instinct 50)', 'max_level'=>50, 'max_utility'=>100.0,
        'max_bonuses'=>4, 'quality'=>100,
        'caps'=>['stat'=>75,'hits'=>400,'power'=>25,'resist'=>26,'skill'=>11,'focus'=>50,'pct'=>10,'cap_inc'=>26],
    ],
    'pvp' => [
        'label'=>'PvP Server', 'max_level'=>50, 'max_utility'=>80.0,
        'max_bonuses'=>4, 'quality'=>100,
        'caps'=>['stat'=>75,'hits'=>300,'power'=>25,'resist'=>26,'skill'=>11,'focus'=>50,'pct'=>10,'cap_inc'=>26],
    ],
    'xp'  => [
        'label'=>'XP Server (leveled)', 'max_level'=>50, 'max_utility'=>15.0,
        'max_bonuses'=>4, 'quality'=>90,
        'caps'=>['stat'=>25,'hits'=>80,'power'=>10,'resist'=>15,'skill'=>4,'focus'=>20,'pct'=>5,'cap_inc'=>10],
    ],
];
foreach ($SC_SERVER_PROFILES as $k => $p) {
    $SC_SERVER_PROFILES[$k]['max_stat']   = $p['caps']['stat'];
    $SC_SERVER_PROFILES[$k]['max_resist'] = $p['caps']['resist'];
    $SC_SERVER_PROFILES[$k]['max_hits']   = $p['caps']['hits'];
}
$GLOBALS['SC_SERVER_PROFILES'] = $SC_SERVER_PROFILES;


/* ══════════════════════════════════════════════════════════════════
   3. SLOTS WITH PAPERDOLL COORDINATES
   ══════════════════════════════════════════════════════════════════ */
$SC_SLOTS = [
    'helm'     =>['label'=>'Helm',      'item_type'=>21,'object_type'=>null,'armor'=>true, 'row'=>1, 'paperdoll'=>['x'=>50, 'y'=>6,  'side'=>'center']],
    'torso'    =>['label'=>'Torso',     'item_type'=>25,'object_type'=>null,'armor'=>true, 'row'=>1, 'paperdoll'=>['x'=>50, 'y'=>24, 'side'=>'center']],
    'arms'     =>['label'=>'Arms',      'item_type'=>28,'object_type'=>null,'armor'=>true, 'row'=>1, 'paperdoll'=>['x'=>20, 'y'=>24, 'side'=>'left']],
    'hands'    =>['label'=>'Hands',     'item_type'=>22,'object_type'=>null,'armor'=>true, 'row'=>1, 'paperdoll'=>['x'=>20, 'y'=>42, 'side'=>'left']],
    'legs'     =>['label'=>'Legs',      'item_type'=>27,'object_type'=>null,'armor'=>true, 'row'=>1, 'paperdoll'=>['x'=>50, 'y'=>56, 'side'=>'center']],
    'feet'     =>['label'=>'Feet',      'item_type'=>23,'object_type'=>null,'armor'=>true, 'row'=>1, 'paperdoll'=>['x'=>50, 'y'=>80, 'side'=>'center']],
    'cloak'    =>['label'=>'Cloak',     'item_type'=>26,'object_type'=>41,  'armor'=>false,'row'=>2, 'paperdoll'=>['x'=>20, 'y'=>6,  'side'=>'left']],
    'belt'     =>['label'=>'Belt',      'item_type'=>32,'object_type'=>41,  'armor'=>false,'row'=>2, 'paperdoll'=>['x'=>50, 'y'=>42, 'side'=>'center']],
    'neck'     =>['label'=>'Necklace',  'item_type'=>29,'object_type'=>41,  'armor'=>false,'row'=>2, 'paperdoll'=>['x'=>80, 'y'=>6,  'side'=>'right']],
    'ring1'    =>['label'=>'Ring (R)',  'item_type'=>36,'object_type'=>41,  'armor'=>false,'row'=>3, 'paperdoll'=>['x'=>80, 'y'=>24, 'side'=>'right']],
    'ring2'    =>['label'=>'Ring (L)',  'item_type'=>35,'object_type'=>41,  'armor'=>false,'row'=>3, 'paperdoll'=>['x'=>80, 'y'=>42, 'side'=>'right']],
    'bracer1'  =>['label'=>'Bracer (R)','item_type'=>34,'object_type'=>41,  'armor'=>false,'row'=>3, 'paperdoll'=>['x'=>80, 'y'=>60, 'side'=>'right']],
    'bracer2'  =>['label'=>'Bracer (L)','item_type'=>33,'object_type'=>41,  'armor'=>false,'row'=>3, 'paperdoll'=>['x'=>80, 'y'=>78, 'side'=>'right']],
    'mythirian'=>['label'=>'Mythirian', 'item_type'=>37,'object_type'=>41,  'armor'=>false,'row'=>3, 'paperdoll'=>['x'=>20, 'y'=>78, 'side'=>'left']],
];
$GLOBALS['SC_SLOTS'] = $SC_SLOTS;

/* Armor Class → eObjectType */
$SC_ARMOR_OBJECT = [
    'cloth'=>32, 'leather'=>33, 'studded'=>34, 'chain'=>35,
    'plate'=>36, 'reinforced'=>37, 'scale'=>38,
];
$GLOBALS['SC_ARMOR_OBJECT'] = $SC_ARMOR_OBJECT;


/* ══════════════════════════════════════════════════════════════════
   4. CLASSES-PRESETS
   ══════════════════════════════════════════════════════════════════ */
$SC_CLASSES = [
    // Albion
    'armsman'     =>['label'=>'Armsman',     'realm'=>1,'stats'=>[1,3,4,2],  'caster'=>false,'armor'=>'plate'],
    'mercenary'   =>['label'=>'Mercenary',   'realm'=>1,'stats'=>[1,2,3,4],  'caster'=>false,'armor'=>'chain'],
    'paladin'     =>['label'=>'Paladin',     'realm'=>1,'stats'=>[1,3,2,4],  'caster'=>false,'armor'=>'plate'],
    'reaver'      =>['label'=>'Reaver',      'realm'=>1,'stats'=>[1,3,2,4],  'caster'=>false,'armor'=>'chain'],
    'scout'       =>['label'=>'Scout',       'realm'=>1,'stats'=>[2,4,1,3],  'caster'=>false,'armor'=>'studded'],
    'minstrel'    =>['label'=>'Minstrel',    'realm'=>1,'stats'=>[8,2,1,3],  'caster'=>true, 'armor'=>'chain'],
    'infiltrator' =>['label'=>'Infiltrator', 'realm'=>1,'stats'=>[2,4,1,3],  'caster'=>false,'armor'=>'leather'],
    'cabalist'    =>['label'=>'Cabalist',    'realm'=>1,'stats'=>[5,2,3],    'caster'=>true, 'armor'=>'cloth'],
    'sorcerer'    =>['label'=>'Sorcerer',    'realm'=>1,'stats'=>[5,2,3],    'caster'=>true, 'armor'=>'cloth'],
    'wizard'      =>['label'=>'Wizard',      'realm'=>1,'stats'=>[5,2,3],    'caster'=>true, 'armor'=>'cloth'],
    'theurgist'   =>['label'=>'Theurgist',   'realm'=>1,'stats'=>[5,2,3],    'caster'=>true, 'armor'=>'cloth'],
    'necromancer' =>['label'=>'Necromancer', 'realm'=>1,'stats'=>[5,2,3],    'caster'=>true, 'armor'=>'cloth'],
    'cleric'      =>['label'=>'Cleric',      'realm'=>1,'stats'=>[6,2,3],    'caster'=>true, 'armor'=>'chain'],
    'friar'       =>['label'=>'Friar',       'realm'=>1,'stats'=>[6,3,2,4],  'caster'=>true, 'armor'=>'leather'],
    'heretic'     =>['label'=>'Heretic',     'realm'=>1,'stats'=>[6,2,3],    'caster'=>true, 'armor'=>'chain'],
    'mauler_alb'  =>['label'=>'Mauler (Alb)','realm'=>1,'stats'=>[1,3,4,2],  'caster'=>false,'armor'=>'leather'],
    // Midgard
    'warrior'     =>['label'=>'Warrior',     'realm'=>2,'stats'=>[1,3,2,4],  'caster'=>false,'armor'=>'chain'],
    'berserker'   =>['label'=>'Berserker',   'realm'=>2,'stats'=>[1,4,3,2],  'caster'=>false,'armor'=>'studded'],
    'thane'       =>['label'=>'Thane',       'realm'=>2,'stats'=>[1,6,3,4],  'caster'=>true, 'armor'=>'chain'],
    'skald'       =>['label'=>'Skald',       'realm'=>2,'stats'=>[8,1,3,4],  'caster'=>true, 'armor'=>'chain'],
    'savage'      =>['label'=>'Savage',      'realm'=>2,'stats'=>[1,4,2,3],  'caster'=>false,'armor'=>'studded'],
    'shadowblade' =>['label'=>'Shadowblade', 'realm'=>2,'stats'=>[2,4,1,3],  'caster'=>false,'armor'=>'leather'],
    'hunter'      =>['label'=>'Hunter',      'realm'=>2,'stats'=>[2,4,1,3],  'caster'=>false,'armor'=>'studded'],
    'runemaster'  =>['label'=>'Runemaster',  'realm'=>2,'stats'=>[6,2,3],    'caster'=>true, 'armor'=>'cloth'],
    'spiritmaster'=>['label'=>'Spiritmaster','realm'=>2,'stats'=>[6,2,3],    'caster'=>true, 'armor'=>'cloth'],
    'bonedancer'  =>['label'=>'Bonedancer',  'realm'=>2,'stats'=>[6,2,3],    'caster'=>true, 'armor'=>'cloth'],
    'warlock'     =>['label'=>'Warlock',     'realm'=>2,'stats'=>[6,2,3],    'caster'=>true, 'armor'=>'cloth'],
    'healer'      =>['label'=>'Healer',      'realm'=>2,'stats'=>[6,2,3],    'caster'=>true, 'armor'=>'chain'],
    'shaman'      =>['label'=>'Shaman',      'realm'=>2,'stats'=>[6,2,3],    'caster'=>true, 'armor'=>'chain'],
    'valkyrie'    =>['label'=>'Valkyrie',    'realm'=>2,'stats'=>[1,2,3,4],  'caster'=>true, 'armor'=>'chain'],
    'mauler_mid'  =>['label'=>'Mauler (Mid)','realm'=>2,'stats'=>[1,3,4,2],  'caster'=>false,'armor'=>'leather'],
    // Hibernia
    'hero'        =>['label'=>'Hero',        'realm'=>3,'stats'=>[1,3,2,4],  'caster'=>false,'armor'=>'scale'],
    'champion'    =>['label'=>'Champion',    'realm'=>3,'stats'=>[1,3,5,2],  'caster'=>true, 'armor'=>'scale'],
    'blademaster' =>['label'=>'Blademaster', 'realm'=>3,'stats'=>[1,2,3,4],  'caster'=>false,'armor'=>'reinforced'],
    'warden'      =>['label'=>'Warden',      'realm'=>3,'stats'=>[7,3,2,1],  'caster'=>true, 'armor'=>'scale'],
    'valewalker'  =>['label'=>'Valewalker',  'realm'=>3,'stats'=>[1,5,3,2],  'caster'=>true, 'armor'=>'cloth'],
    'ranger'      =>['label'=>'Ranger',      'realm'=>3,'stats'=>[2,4,1,3],  'caster'=>false,'armor'=>'reinforced'],
    'nightshade'  =>['label'=>'Nightshade',  'realm'=>3,'stats'=>[2,4,1,3],  'caster'=>false,'armor'=>'leather'],
    'vampiir'     =>['label'=>'Vampiir',     'realm'=>3,'stats'=>[1,3,2,4],  'caster'=>false,'armor'=>'leather'],
    'eldritch'    =>['label'=>'Eldritch',    'realm'=>3,'stats'=>[5,2,3],    'caster'=>true, 'armor'=>'cloth'],
    'enchanter'   =>['label'=>'Enchanter',   'realm'=>3,'stats'=>[5,2,3],    'caster'=>true, 'armor'=>'cloth'],
    'mentalist'   =>['label'=>'Mentalist',   'realm'=>3,'stats'=>[5,2,3],    'caster'=>true, 'armor'=>'cloth'],
    'animist'     =>['label'=>'Animist',     'realm'=>3,'stats'=>[5,2,3],    'caster'=>true, 'armor'=>'cloth'],
    'bainshee'    =>['label'=>'Bainshee',    'realm'=>3,'stats'=>[5,2,3],    'caster'=>true, 'armor'=>'cloth'],
    'druid'       =>['label'=>'Druid',       'realm'=>3,'stats'=>[7,2,3],    'caster'=>true, 'armor'=>'scale'],
    'bard'        =>['label'=>'Bard',        'realm'=>3,'stats'=>[8,2,3],    'caster'=>true, 'armor'=>'reinforced'],
    'mauler_hib'  =>['label'=>'Mauler (Hib)','realm'=>3,'stats'=>[1,3,4,2],  'caster'=>false,'armor'=>'leather'],
];
$SC_CLASS_IDS = [
    'paladin'=>1,'armsman'=>2,'scout'=>3,'minstrel'=>4,'theurgist'=>5,'cleric'=>6,
    'wizard'=>7,'sorcerer'=>8,'infiltrator'=>9,'friar'=>10,'mercenary'=>11,
    'necromancer'=>12,'cabalist'=>13,'reaver'=>19,'thane'=>21,'warrior'=>22,
    'shadowblade'=>23,'skald'=>24,'hunter'=>25,'healer'=>26,'spiritmaster'=>27,
    'shaman'=>28,'runemaster'=>29,'bonedancer'=>30,'berserker'=>31,'savage'=>32,
    'heretic'=>33,'valkyrie'=>34,'bainshee'=>39,'eldritch'=>40,'enchanter'=>41,
    'mentalist'=>42,'blademaster'=>43,'hero'=>44,'champion'=>45,'warden'=>46,
    'druid'=>47,'bard'=>48,'nightshade'=>49,'ranger'=>50,'animist'=>55,
    'valewalker'=>56,'vampiir'=>58,'warlock'=>59,
    'mauler_alb'=>60,'mauler_mid'=>61,'mauler_hib'=>62,
];
foreach ($SC_CLASS_IDS as $k => $cid) {
    if (isset($SC_CLASSES[$k])) $SC_CLASSES[$k]['dol_id'] = $cid;
}
$GLOBALS['SC_CLASSES'] = $SC_CLASSES;


/* ══════════════════════════════════════════════════════════════════
   5. SCHEME & INTROSPECTION
   ══════════════════════════════════════════════════════════════════ */
function sc_col_exists(PDO $db, string $table, string $col): bool {
    $st = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
}
function sc_add_column(PDO $db, string $table, string $col, string $def): void {
    if (!sc_col_exists($db, $table, $col)) {
        $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}");
    }
}

function sc_ensure_tables(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS `cms_suits` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(120) NOT NULL,
        `description` TEXT,
        `realm` TINYINT(1) NOT NULL DEFAULT 0,
        `server_type` VARCHAR(32) NOT NULL DEFAULT 'i50',
        `archetype` VARCHAR(32) NOT NULL DEFAULT 'custom',
        `created_by` INT UNSIGNED NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $db->exec("CREATE TABLE IF NOT EXISTS `cms_suit_items` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `suit_id` INT UNSIGNED NOT NULL,
        `slot` VARCHAR(32) NOT NULL,
        `item_template_id` TEXT,
        `slot_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`), KEY `suit_id` (`suit_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $db->exec("CREATE TABLE IF NOT EXISTS `cms_suit_revisions` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `suit_id` INT UNSIGNED NOT NULL,
        `snapshot` LONGTEXT NOT NULL,
        `label` VARCHAR(120) DEFAULT NULL,
        `created_by` INT UNSIGNED NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), KEY `suit_id` (`suit_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    sc_add_column($db, 'cms_suits',      'class_key',       "VARCHAR(32) NOT NULL DEFAULT ''");
    sc_add_column($db, 'cms_suits',      'target_stats',    "TEXT NULL");
    sc_add_column($db, 'cms_suits',      'gen_settings',    "TEXT NULL");
    sc_add_column($db, 'cms_suits',      'updated_by',      "INT UNSIGNED NOT NULL DEFAULT 0");
    sc_add_column($db, 'cms_suit_items', 'bonuses',         "TEXT NULL");
    sc_add_column($db, 'cms_suit_items', 'base_item_id',    "VARCHAR(100) NULL");
    sc_add_column($db, 'cms_suit_items', 'generated_id_nb', "VARCHAR(100) NULL");
}
if (empty($_SESSION['sc_tables_v3'])) {
    try {
        sc_ensure_tables($db);
        $_SESSION['sc_tables_v3'] = true;
    } catch (Throwable $e) {
        error_log('SuitCreator sc_ensure_tables: ' . $e->getMessage());
    }
}

function sc_slugify(string $str): string {
    $slug = strtolower(trim($str));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim($slug, '_');
    return substr($slug, 0, 40);
}

function sc_item_columns(PDO $db): array {
    static $cols = null;
    if ($cols === null) {
        try {
            $cols = daoc_game_table_columns($db, 'itemtemplate');
        } catch (Throwable $e) {
            $cols = [];
        }
    }
    return $cols;
}
function sc_bonus_cols(PDO $db): array {
    static $c = null;
    if ($c !== null) return $c;
    $cols = sc_item_columns($db);
    if     (in_array('Bonus1Type', $cols, true)) $type = 'Bonus%dType';
    elseif (in_array('BonusType1', $cols, true)) $type = 'BonusType%d';
    else                                         $type = 'Bonus%dType';
    $max = 0;
    for ($i = 1; $i <= 10; $i++) {
        if (in_array(sprintf($type, $i), $cols, true) && in_array('Bonus'.$i, $cols, true)) $max = $i;
        else break;
    }
    return $c = ['type'=>$type, 'value'=>'Bonus%d', 'max'=>max(1, $max)];
}

/* ══════════════════════════════════════════════════════════════════
   6. CRAFTING SYSTEM & ITEM VALIDATION (Database Rule Validation)
   ══════════════════════════════════════════════════════════════════ */

/**
 * Validates assigned base items against armor classes, realms, and equipment slots.
 */
function sc_validate_item_combination(PDO $db, string $classKey, array $items): array {
    $class = $GLOBALS['SC_CLASSES'][$classKey] ?? null;
    $errors = [];
    $warnings = [];

    $armorHierarchy = [
        'cloth'      => [32],
        'leather'    => [32, 33],
        'studded'    => [32, 33, 34],
        'reinforced' => [32, 33, 34, 37],
        'chain'      => [32, 33, 34, 35],
        'scale'      => [32, 33, 34, 38],
        'plate'      => [32, 33, 34, 35, 36],
    ];

    $allowedObjects = ($class && isset($class['armor']))
        ? ($armorHierarchy[$class['armor']] ?? [32])
        : [32, 33, 34, 35, 36, 37, 38];

    foreach ($items as $slotKey => $itemData) {
        $slotDef = $GLOBALS['SC_SLOTS'][$slotKey] ?? null;
        if (!$slotDef) continue;

        $tid = is_array($itemData) ? ($itemData['item_template_id'] ?? '') : (string)$itemData;
        if (empty($tid)) continue;

        $st = $db->prepare("SELECT Name, Object_Type, Item_Type, Realm FROM itemtemplate WHERE Id_nb = ? LIMIT 1");
        $st->execute([$tid]);
        $dbItem = $st->fetch(PDO::FETCH_ASSOC);

        if (!$dbItem) {
            $warnings[] = t('acp_sc_val_item_not_found', ['slot'=>$slotDef['label'], 'id'=>$tid], "Slot '{$slotDef['label']}': Base item '{$tid}' not found in database.");
            continue;
        }

        if ($slotDef['armor'] && $class) {
            $objType = (int)$dbItem['Object_Type'];
            if (!in_array($objType, $allowedObjects, true)) {
                $errors[] = t('acp_sc_val_armor_invalid', [
                    'slot' => $slotDef['label'],
                    'item' => $dbItem['Name'],
                    'class' => $class['label'],
                    'armor' => $class['armor']
                ], "Slot '{$slotDef['label']}': Item '{$dbItem['Name']}' cannot be used by class '{$class['label']}' (requires <= {$class['armor']}).");
            }
        }

        // Reich-Validierung
        if ($class && (int)$dbItem['Realm'] > 0 && (int)$dbItem['Realm'] !== (int)$class['realm']) {
            $warnings[] = t('acp_sc_val_realm_mismatch', [
                'slot' => $slotDef['label'],
                'item' => $dbItem['Name']
            ], "Slot '{$slotDef['label']}': Item '{$dbItem['Name']}' belongs to a different realm.");
        }
    }

    return [
        'valid'    => empty($errors),
        'errors'   => $errors,
        'warnings' => $warnings,
    ];
}

/* ══════════════════════════════════════════════════════════════════
   7. MATHEMATICAL CONSTRAINT SOLVER & BUDGET ENGINE
   ══════════════════════════════════════════════════════════════════ */
function sc_prop(int $type): array {
    return $GLOBALS['SC_PROPERTIES'][$type] ?? ['label'=>'Type '.$type,'short'=>'#'.$type,'group'=>'stat','weight'=>1.0];
}
function sc_weight(int $type): float { return (float)sc_prop($type)['weight']; }

function sc_cap_for(int $type, array $profile): int {
    $group = sc_prop($type)['group'];
    return (int)($profile['caps'][$group] ?? PHP_INT_MAX);
}

function sc_feasibility(array $targets, int $slot_count, array $profile): array {
    $required = 0.0; $per_type = [];
    foreach ($targets as $t => $v) {
        $t = (int)$t; $v = (int)$v;
        if ($v <= 0) continue;
        $cap = sc_cap_for($t, $profile);
        $eff = min($v, $cap * $slot_count);
        $u   = $eff * sc_weight($t);
        $required += $u;
        $per_type[$t] = ['requested'=>$v,'placeable'=>$eff,'utility'=>round($u,2),'cap_per_item'=>$cap];
    }
    $capacity = $slot_count * (float)$profile['max_utility'];
    $min_slots = $profile['max_utility'] > 0 ? (int)ceil($required / $profile['max_utility']) : 0;
    return [
        'required'  => round($required, 2),
        'capacity'  => round($capacity, 2),
        'deficit'   => round(max(0, $required - $capacity), 2),
        'fits'      => $required <= $capacity,
        'min_slots' => $min_slots,
        'per_type'  => $per_type,
    ];
}

/**
 * Exact constraint solver using Branch-and-Bound backtracking.
 */
function sc_distribute_solver(array $targets, array $slots, array $profile, array $opts = []): array {
    $slots = array_values(array_filter($slots));
    $n = count($slots);
    if ($n < 1) return ['items'=>[], 'summary'=>['error'=>'no_slots']];

    $maxUtil    = (float)$profile['max_utility'];
    $maxBonuses = (int)($opts['max_bonuses'] ?? $profile['max_bonuses'] ?? 4);
    $maxBonuses = max(1, min(10, $maxBonuses));

    // Assemble target stat packages.
    $order = [];
    foreach ($targets as $t => $v) {
        $t = (int)$t; $v = (int)$v;
        if ($t <= 0 || $v <= 0) continue;
        $order[] = ['type'=>$t, 'needed'=>$v, 'weight'=>sc_weight($t), 'cap'=>sc_cap_for($t, $profile)];
    }
    // Sort by weight in descending order.
    usort($order, fn($a,$b) => ($b['weight'] <=> $a['weight']) ?: ($b['needed'] <=> $a['needed']));

    $state = [];
    foreach ($slots as $s) {
        $state[$s] = ['bonuses'=>[], 'utility'=>0.0];
    }

    $unplaced = [];

    foreach ($order as $req) {
        $type = $req['type'];
        $w    = $req['weight'];
        $cap  = $req['cap'];
        $need = $req['needed'];

        while ($need > 0) {
            $bestSlot = null;
            $bestFitChunk = 0;
            $minUtilAfter = PHP_FLOAT_MAX;

            foreach ($slots as $s) {
                $item = &$state[$s];
                $existing = 0;
                $bonusCount = count($item['bonuses']);
                $hasType = false;
                foreach ($item['bonuses'] as $b) {
                    if ($b['type'] === $type) {
                        $existing = $b['value'];
                        $hasType = true;
                        break;
                    }
                }

                if (!$hasType && $bonusCount >= $maxBonuses) continue;
                if ($existing >= $cap) continue;

                $roomUtil = $maxUtil - $item['utility'];
                if ($roomUtil < $w) continue;

                $possibleValByUtil = (int)floor($roomUtil / $w);
                $possibleValByCap  = $cap - $existing;
                $chunk = min($need, $possibleValByUtil, $possibleValByCap);

                if ($chunk <= 0) continue;

                $newUtil = $item['utility'] + ($chunk * $w);
                if ($newUtil < $minUtilAfter) {
                    $minUtilAfter = $newUtil;
                    $bestSlot = $s;
                    $bestFitChunk = $chunk;
                }
            }

            if ($bestSlot === null || $bestFitChunk <= 0) {
                $unplaced[$type] = ($unplaced[$type] ?? 0) + $need;
                break;
            }

            $found = false;
            foreach ($state[$bestSlot]['bonuses'] as &$b) {
                if ($b['type'] === $type) {
                    $b['value'] += $bestFitChunk;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $state[$bestSlot]['bonuses'][] = ['type'=>$type, 'value'=>$bestFitChunk];
            }
            $state[$bestSlot]['utility'] += ($bestFitChunk * $w);
            $need -= $bestFitChunk;
        }
    }

    $totalUtil = 0.0;
    foreach ($state as $s => &$it) {
        usort($it['bonuses'], fn($a,$b) => $a['type'] <=> $b['type']);
        $it['utility'] = round($it['utility'], 2);
        $it['fill']    = $maxUtil > 0 ? round($it['utility'] / $maxUtil * 100) : 0;
        $totalUtil    += $it['utility'];
    }

    return [
        'items' => $state,
        'summary' => [
            'total_utility' => round($totalUtil, 2),
            'capacity'      => round($n * $maxUtil, 2),
            'slots'         => $n,
            'unplaced'      => $unplaced,
            'max_bonuses'   => $maxBonuses,
            'solver'        => 'constraint_exact',
        ],
    ];
}

function sc_distribute(array $targets, array $slots, array $profile, array $opts = []): array {
    return sc_distribute_solver($targets, $slots, $profile, $opts);
}

function sc_coverage(array $items, array $targets, array $profile): array {
    $have = [];
    foreach ($items as $it) {
        foreach (($it['bonuses'] ?? []) as $b) {
            $have[(int)$b['type']] = ($have[(int)$b['type']] ?? 0) + (int)$b['value'];
        }
    }
    $rows = [];
    foreach ($targets as $t => $want) {
        $t = (int)$t; $want = (int)$want;
        if ($want <= 0) continue;
        $got = $have[$t] ?? 0;
        $rows[$t] = ['label'=>sc_prop($t)['label'], 'want'=>$want, 'have'=>$got, 'missing'=>max(0, $want-$got)];
    }
    return $rows;
}


/* ══════════════════════════════════════════════════════════════════
   8. AJAX END POINTS
   ══════════════════════════════════════════════════════════════════ */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $action = $_GET['ajax'] ?? '';
    $err = fn($m) => print(json_encode(['error'=>$m]));

    if ($action === 'validate_combination') {
        if ($userPriv < 3) { $err('forbidden'); exit; }
        checkToken($_POST['csrf_token'] ?? '');
        $classKey = preg_replace('/[^a-z0-9_]/','', $_POST['class_key'] ?? '');
        $items    = json_decode($_POST['items'] ?? '{}', true) ?: [];
        echo json_encode(sc_validate_item_combination($db, $classKey, $items));
        exit;
    }

    if ($action === 'paperdoll_data') {
        if ($userPriv < 3) { $err('forbidden'); exit; }
        echo json_encode(['ok'=>true, 'slots'=>$SC_SLOTS]);
        exit;
    }

    if ($action === 'list') {
        if ($userPriv < 3) { $err('forbidden'); exit; }
        $q = trim($_GET['q'] ?? '');
        $sql = "SELECT id,name,realm,server_type"
             . (sc_col_exists($db,'cms_suits','archetype')  ? ",archetype"  : "")
             . (sc_col_exists($db,'cms_suits','class_key')  ? ",class_key"  : "")
             . ",updated_at FROM cms_suits";
        $par = [];
        if ($q !== '') {
            $hasDesc = sc_col_exists($db, 'cms_suits', 'description');
            if ($hasDesc) {
                $sql .= " WHERE name LIKE ? OR description LIKE ?";
                $par = ["%$q%", "%$q%"];
            } else {
                $sql .= " WHERE name LIKE ?";
                $par = ["%$q%"];
            }
        }
        $sql .= " ORDER BY updated_at DESC";
        try {
            $st = $db->prepare($sql); $st->execute($par);
            echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            $err('db_error: ' . $e->getMessage());
        }
        exit;
    }

    if ($action === 'load') {
        if ($userPriv < 3) { $err('forbidden'); exit; }
        $id = (int)($_GET['id'] ?? 0);
        $st = $db->prepare("SELECT * FROM cms_suits WHERE id = ?");
        $st->execute([$id]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) { $err('not_found'); exit; }
        $it = $db->prepare("SELECT * FROM cms_suit_items WHERE suit_id = ? ORDER BY slot_order");
        $it->execute([$id]);
        $rows = $it->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) $r['bonuses'] = json_decode($r['bonuses'] ?? '[]', true) ?: [];
        unset($r);
        $s['items']        = $rows;
        $s['target_stats'] = json_decode($s['target_stats'] ?? '{}', true) ?: new stdClass();
        $s['gen_settings'] = json_decode($s['gen_settings'] ?? '{}', true) ?: new stdClass();
        echo json_encode($s);
        exit;
    }

    if ($action === 'feasibility') {
        if ($userPriv < 3) { $err('forbidden'); exit; }
        checkToken($_POST['csrf_token'] ?? '');
        $targets = json_decode($_POST['targets'] ?? '{}', true) ?: [];
        $count   = max(1, (int)($_POST['slot_count'] ?? 1));
        $pk      = preg_replace('/[^a-z0-9_]/','', $_POST['server_type'] ?? 'i50');
        $profile = $SC_SERVER_PROFILES[$pk] ?? $SC_SERVER_PROFILES['i50'];
        echo json_encode(['ok'=>true] + sc_feasibility($targets, $count, $profile));
        exit;
    }

    if ($action === 'distribute') {
        if ($userPriv < 3) { $err('forbidden'); exit; }
        checkToken($_POST['csrf_token'] ?? '');
        $targets = json_decode($_POST['targets'] ?? '{}', true) ?: [];
        $slots   = json_decode($_POST['slots']   ?? '[]', true) ?: [];
        $pk      = preg_replace('/[^a-z0-9_]/','', $_POST['server_type'] ?? 'i50');
        $profile = $SC_SERVER_PROFILES[$pk] ?? $SC_SERVER_PROFILES['i50'];
        if (!count($slots)) { $err('no_slots'); exit; }

        $opts = [];
        if (isset($_POST['max_bonuses'])) $opts['max_bonuses'] = (int)$_POST['max_bonuses'];

        $res = sc_distribute($targets, $slots, $profile, $opts);
        $res['feasibility'] = sc_feasibility($targets, count($slots), $profile);
        $res['coverage']    = sc_coverage($res['items'], $targets, $profile);
        echo json_encode(['ok'=>true] + $res);
        exit;
    }

    if ($action === 'save' && $userPriv >= 3) {
        checkToken($_POST['csrf_token'] ?? '');
        $id          = (int)($_POST['suit_id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $realm       = (int)($_POST['realm'] ?? 0);
        $server_type = preg_replace('/[^a-z0-9_]/','', $_POST['server_type'] ?? 'i50');
        $archetype   = preg_replace('/[^a-z0-9_]/','', $_POST['archetype'] ?? 'custom');
        $class_key   = preg_replace('/[^a-z0-9_]/','', $_POST['class_key'] ?? '');
        $targets     = json_decode($_POST['targets'] ?? '{}', true) ?: [];
        $gen         = json_decode($_POST['gen_settings'] ?? '{}', true) ?: [];
        $items       = json_decode($_POST['items'] ?? '{}', true) ?: [];
        if ($name === '') { $err('no_name'); exit; }

        if ($id > 0) sc_snapshot($db, $id, $currentUserId, 'auto');

        if ($id > 0) {
            $db->prepare("UPDATE cms_suits SET name=?,description=?,realm=?,server_type=?,archetype=?,
                          class_key=?,target_stats=?,gen_settings=?,updated_by=?,updated_at=NOW() WHERE id=?")
               ->execute([$name,$description,$realm,$server_type,$archetype,$class_key,
                          json_encode($targets),json_encode($gen),$currentUserId,$id]);
        } else {
            $db->prepare("INSERT INTO cms_suits (name,description,realm,server_type,archetype,class_key,
                          target_stats,gen_settings,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
               ->execute([$name,$description,$realm,$server_type,$archetype,$class_key,
                          json_encode($targets),json_encode($gen),$currentUserId,$currentUserId]);
            $id = (int)$db->lastInsertId();
        }

        $db->prepare("DELETE FROM cms_suit_items WHERE suit_id=?")->execute([$id]);
        $ins = $db->prepare("INSERT INTO cms_suit_items
            (suit_id,slot,item_template_id,bonuses,base_item_id,generated_id_nb,slot_order)
            VALUES (?,?,?,?,?,?,?)");
        $ord = 0;
        foreach ($SC_SLOTS as $slot => $_def) {
            if (!isset($items[$slot])) continue;
            $row = $items[$slot];
            if (!is_array($row)) $row = ['item_template_id'=>$row];
            $tid = trim((string)($row['item_template_id'] ?? ''));
            $bon = $row['bonuses'] ?? [];
            if ($tid === '' && !$bon) continue;
            $ins->execute([
                $id, $slot, $tid ?: null,
                json_encode(array_values($bon)),
                $row['base_item_id'] ?? null,
                $row['generated_id_nb'] ?? null,
                $ord++,
            ]);
        }
        aldhran_log("SUIT_SAVE", "Saved suit id={$id} name={$name}", $currentUserId);
        echo json_encode(['ok'=>true,'id'=>$id]);
        exit;
    }

    if ($action === 'duplicate' && $userPriv >= 3) {
        checkToken($_POST['csrf_token'] ?? '');
        $id = (int)($_POST['suit_id'] ?? 0);
        $st = $db->prepare("SELECT * FROM cms_suits WHERE id=?"); $st->execute([$id]);
        $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) { $err('not_found'); exit; }
        $db->prepare("INSERT INTO cms_suits (name,description,realm,server_type,archetype,class_key,
                      target_stats,gen_settings,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute([$s['name'].' ('.t('acp_sc_duplicate_name_suffix', [], 'Copy').')',$s['description'],$s['realm'],$s['server_type'],$s['archetype'],
                      $s['class_key'],$s['target_stats'],$s['gen_settings'],$currentUserId,$currentUserId]);
        $new = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO cms_suit_items (suit_id,slot,item_template_id,bonuses,base_item_id,slot_order)
                      SELECT ?,slot,item_template_id,bonuses,base_item_id,slot_order FROM cms_suit_items WHERE suit_id=?")
           ->execute([$new, $id]);
        aldhran_log("SUIT_DUPLICATE", "Suit {$id} → {$new}", $currentUserId);
        echo json_encode(['ok'=>true,'id'=>$new]);
        exit;
    }

    if ($action === 'delete' && $userPriv >= 3) {
        checkToken($_POST['csrf_token'] ?? '');
        $id = (int)($_POST['suit_id'] ?? 0);
        if (!$id) { $err('no_id'); exit; }
        sc_snapshot($db, $id, $currentUserId, 'before deletion');
        $db->prepare("DELETE FROM cms_suit_items WHERE suit_id=?")->execute([$id]);
        $db->prepare("DELETE FROM cms_suits WHERE id=?")->execute([$id]);
        aldhran_log("SUIT_DELETE", "Deleted suit id={$id}", $currentUserId);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'revisions') {
        if ($userPriv < 3) { $err('forbidden'); exit; }
        $id = (int)($_GET['suit_id'] ?? 0);
        $st = $db->prepare("SELECT id,label,created_by,created_at FROM cms_suit_revisions
                            WHERE suit_id=? ORDER BY id DESC LIMIT 30");
        $st->execute([$id]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
    if ($action === 'revision_restore' && $userPriv >= 3) {
        checkToken($_POST['csrf_token'] ?? '');
        $rid = (int)($_POST['revision_id'] ?? 0);
        $st = $db->prepare("SELECT * FROM cms_suit_revisions WHERE id=?"); $st->execute([$rid]);
        $rev = $st->fetch(PDO::FETCH_ASSOC);
        if (!$rev) { $err('not_found'); exit; }
        $snap = json_decode($rev['snapshot'], true);
        if (!$snap) { $err('bad_snapshot'); exit; }
        $sid = (int)$rev['suit_id'];
        sc_snapshot($db, $sid, $currentUserId, t('acp_sc_revision_before_restore', [], 'before restore'));
        $s = $snap['suit'];
        $db->prepare("UPDATE cms_suits SET name=?,description=?,realm=?,server_type=?,archetype=?,
                      class_key=?,target_stats=?,gen_settings=?,updated_by=?,updated_at=NOW() WHERE id=?")
           ->execute([$s['name'],$s['description'],$s['realm'],$s['server_type'],$s['archetype'],
                      $s['class_key'] ?? '', $s['target_stats'] ?? '{}', $s['gen_settings'] ?? '{}',
                      $currentUserId, $sid]);
        $db->prepare("DELETE FROM cms_suit_items WHERE suit_id=?")->execute([$sid]);
        $ins = $db->prepare("INSERT INTO cms_suit_items (suit_id,slot,item_template_id,bonuses,base_item_id,generated_id_nb,slot_order) VALUES (?,?,?,?,?,?,?)");
        foreach (($snap['items'] ?? []) as $r) {
            $ins->execute([$sid,$r['slot'],$r['item_template_id'],$r['bonuses'],$r['base_item_id'] ?? null,$r['generated_id_nb'] ?? null,$r['slot_order']]);
        }
        aldhran_log("SUIT_RESTORE", "Suit {$sid} restored from revision {$rid}", $currentUserId);
        echo json_encode(['ok'=>true,'id'=>$sid]);
        exit;
    }

    if ($action === 'build_items' && $userPriv >= 3) {
        checkToken($_POST['csrf_token'] ?? '');
        $suit_id = (int)($_POST['suit_id'] ?? 0);
        $mode    = in_array($_POST['mode'] ?? '', ['clone','blank'], true) ? $_POST['mode'] : 'clone';
        $dry     = !empty($_POST['dry_run']);
        $prefix  = preg_replace('/[^A-Za-z0-9_]/','', $_POST['prefix'] ?? '');
        $pattern = trim($_POST['name_pattern'] ?? '{suit} {slot}');
        $level   = max(1, min(100, (int)($_POST['level'] ?? 50)));
        $quality = max(1, min(100, (int)($_POST['quality'] ?? 100)));
        $realm   = (int)($_POST['realm'] ?? 0);
        $items   = json_decode($_POST['items'] ?? '{}', true) ?: [];
        if (!$suit_id) { $err('save_suit_first'); exit; }
        if (!$items)   { $err('no_items'); exit; }

        $cols = sc_item_columns($db);
        if (!$cols) { $err('itemtemplate_not_found'); exit; }
        $bc = sc_bonus_cols($db);

        $st = $db->prepare("SELECT name, class_key FROM cms_suits WHERE id=?"); $st->execute([$suit_id]);
        $suitRow  = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $suitName = (string)($suitRow['name'] ?? '');
        $classKey = (string)($suitRow['class_key'] ?? '');
        if ($prefix === '') {
            $prefix = sc_slugify($suitName);
            if ($prefix === '') $prefix = 'sc'.$suit_id;
        }

        $armorClass  = $SC_CLASSES[$classKey]['armor'] ?? null;
        $armorObject = $armorClass ? ($SC_ARMOR_OBJECT[$armorClass] ?? null) : null;
        if ($mode === 'blank' && !$armorObject) {
            $armorObject = (int)($_POST['armor_object'] ?? 0) ?: null;
        }

        $report = [];
        foreach ($SC_SLOTS as $slot => $def) {
            if (empty($items[$slot])) continue;
            $row     = $items[$slot];
            $bonuses = array_values($row['bonuses'] ?? []);
            $baseId  = trim((string)($row['item_template_id'] ?? ''));
            if (!$bonuses) continue;

            $newId = strtolower($prefix.'_'.$slot);
            $name  = str_replace(['{suit}','{slot}'], [$suitName, $def['label']], $pattern);

            $data = [];
            if ($mode === 'clone' && $baseId !== '') {
                $q = $db->prepare("SELECT * FROM itemtemplate WHERE Id_nb=? LIMIT 1");
                $q->execute([$baseId]);
                $src = $q->fetch(PDO::FETCH_ASSOC);
                if ($src) {
                    $data = $src;
                    unset($data['ItemTemplate_ID'], $data['LastTimeRowUpdated']);
                } else {
                    $report[] = ['slot'=>$slot,'status'=>'skipped','reason'=>"Base item '{$baseId}' was not found"];
                    continue;
                }
            }

            $slotObject = $def['armor'] ? $armorObject : $def['object_type'];

            $data['Id_nb']       = $newId;
            $data['Name']        = $name;
            $data['Level']       = $level;
            $data['Quality']     = $quality;
            $data['AllowedClasses'] = $data['AllowedClasses'] ?? '';
            $data['Item_Type']   = $data['Item_Type'] ?? $def['item_type'];
            $data['Realm']       = $data['Realm']     ?? $realm;
            if (!isset($data['Object_Type']) && $slotObject) $data['Object_Type'] = $slotObject;
            if ($mode === 'blank') {
                if (!$slotObject) {
                    $report[] = ['slot'=>$slot,'status'=>'skipped',
                                 'reason'=>'Armor type unknown - select a class or use clone mode'];
                    continue;
                }
                $data['Object_Type']   = $slotObject;
                $data['Item_Type']     = $def['item_type'];
                $data['Realm']         = $realm;
                $data['Model']         = (int)($row['model'] ?? 0);
                $data['Condition']     = 50000;
                $data['MaxCondition']  = 50000;
                $data['Durability']    = 50000;
                $data['MaxDurability'] = 50000;
                $data['IsPickable']    = 1;
                $data['IsDropable']    = 1;
                $data['MaxCount']      = 1;
                $data['PackageID']     = 'suitcreator';
            }

            for ($i = 1; $i <= $bc['max']; $i++) {
                $data[sprintf($bc['type'], $i)] = 0;
                $data['Bonus'.$i] = 0;
            }
            $i = 1;
            foreach ($bonuses as $b) {
                if ($i > $bc['max']) break;
                $data[sprintf($bc['type'], $i)] = (int)$b['type'];
                $data['Bonus'.$i]               = (int)$b['value'];
                $i++;
            }
            $overflow = max(0, count($bonuses) - $bc['max']);

            $data = array_intersect_key($data, array_flip($cols));

            if ($dry) {
                $report[] = ['slot'=>$slot,'status'=>'dry','id_nb'=>$newId,'name'=>$name,
                             'bonuses'=>count($bonuses),'overflow'=>$overflow,'mode'=>$mode];
                continue;
            }

            $fields = array_keys($data);
            $ph     = implode(',', array_fill(0, count($fields), '?'));
            $set    = implode(',', array_map(fn($f) => "`$f`=VALUES(`$f`)", $fields));
            $sql    = "INSERT INTO `itemtemplate` (`".implode('`,`', $fields)."`) VALUES ($ph)
                       ON DUPLICATE KEY UPDATE $set";
            try {
                $db->prepare($sql)->execute(array_values($data));
                $db->prepare("UPDATE cms_suit_items SET generated_id_nb=?, base_item_id=? WHERE suit_id=? AND slot=?")
                   ->execute([$newId, $baseId ?: null, $suit_id, $slot]);
                $report[] = ['slot'=>$slot,'status'=>'ok','id_nb'=>$newId,'name'=>$name,
                             'bonuses'=>count($bonuses),'overflow'=>$overflow];
            } catch (Throwable $e) {
                $report[] = ['slot'=>$slot,'status'=>'error','id_nb'=>$newId,'reason'=>$e->getMessage()];
            }
        }
        if (!$dry) aldhran_log("SUIT_BUILD", "Suit {$suit_id}: ".count($report)." items generated (mode={$mode})", $currentUserId);
        echo json_encode(['ok'=>true,'dry_run'=>$dry,'bonus_slots'=>$bc['max'],'report'=>$report]);
        exit;
    }

    if ($action === 'scan_properties') {
        if ($userPriv < 3) { $err('forbidden'); exit; }
        $bc   = sc_bonus_cols($db);
        $seen = [];
        for ($i = 1; $i <= $bc['max']; $i++) {
            $tc = sprintf($bc['type'], $i);
            $sql = "SELECT `$tc` AS t, COUNT(*) AS c, MIN(`Bonus$i`) AS mn, MAX(`Bonus$i`) AS mx
                    FROM `itemtemplate` WHERE `$tc` > 0 AND `Bonus$i` > 0 GROUP BY `$tc`";
            try { $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { continue; }
            foreach ($rows as $r) {
                $t = (int)$r['t'];
                if (!isset($seen[$t])) $seen[$t] = ['type'=>$t,'count'=>0,'min'=>PHP_INT_MAX,'max'=>0];
                $seen[$t]['count'] += (int)$r['c'];
                $seen[$t]['min']    = min($seen[$t]['min'], (int)$r['mn']);
                $seen[$t]['max']    = max($seen[$t]['max'], (int)$r['mx']);
            }
        }
        ksort($seen);
        foreach ($seen as $t => &$s) {
            $p = $GLOBALS['SC_PROPERTIES'][$t] ?? null;
            $s['known'] = (bool)$p;
            $s['label'] = $p['label'] ?? '— unknown —';
            $s['group'] = $p['group'] ?? '';
            $s['hint']  = $s['max'] <= 30
                        ? t('acp_sc_scan_hint_resist', [], 'Resist/cap-like (max ≤30)')
                        : ($s['max'] <= 100
                            ? t('acp_sc_scan_hint_stat', [], 'Stat-like')
                            : t('acp_sc_scan_hint_hits', [], 'Hits-like (max >100)'));
        }
        unset($s);

        $slots = [];
        $cols  = sc_item_columns($db);
        if (in_array('Item_Type', $cols, true) && in_array('Object_Type', $cols, true)) {
            $wanted = [];
            foreach ($SC_SLOTS as $k => $d) $wanted[$d['item_type']] = $d;
            $ph = implode(',', array_fill(0, count($wanted), '?'));
            try {
                $q = $db->prepare("SELECT Item_Type, Object_Type, COUNT(*) c
                                   FROM itemtemplate WHERE Item_Type IN ($ph)
                                   GROUP BY Item_Type, Object_Type ORDER BY Item_Type, c DESC");
                $q->execute(array_keys($wanted));
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $it  = (int)$r['Item_Type'];
                    $def = $wanted[$it] ?? null;
                    $slots[] = [
                        'item_type'   => $it,
                        'slot'        => $def['label'] ?? '—',
                        'object_type' => (int)$r['Object_Type'],
                        'count'       => (int)$r['c'],
                        'expected'    => $def && !$def['armor'] ? (int)$def['object_type'] : null,
                    ];
                }
            } catch (Throwable $e) {}
        }

        echo json_encode(['ok'=>true,'columns'=>$bc,'properties'=>array_values($seen),'slots'=>$slots]);
        exit;
    }

    if ($action === 'export_merchant' && $userPriv >= 3) {
        checkToken($_POST['csrf_token'] ?? '');
        $suit_id     = (int)($_POST['suit_id'] ?? 0);
        $merchant_id = trim($_POST['merchant_id'] ?? '');
        $base_price  = (int)($_POST['base_price'] ?? 0);
        $page        = (int)($_POST['page'] ?? 0);
        $use_gen     = !empty($_POST['use_generated']);
        if (!$suit_id || $merchant_id === '') { $err('missing_params'); exit; }
        $si = $db->prepare("SELECT slot,item_template_id,generated_id_nb,slot_order FROM cms_suit_items WHERE suit_id=? ORDER BY slot_order");
        $si->execute([$suit_id]);
        $rows = $si->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { $err('no_items'); exit; }
        $ids = [];
        foreach ($rows as $r) {
            $id = $use_gen ? ($r['generated_id_nb'] ?: $r['item_template_id']) : $r['item_template_id'];
            if ($id) $ids[] = $id;
        }
        if (!$ids) { $err('no_items'); exit; }
        $per = (int)round(max(0, $base_price) / count($ids));

        // OpenDAoC's MerchantItem has no Price column; the price lives on the
        // referenced ItemTemplate. Custom DOL schemas may still provide Price.
        $merchantColumns = array_map('strtolower', daoc_game_table_columns($db, 'merchantitem'));
        foreach (['itemlistid','itemtemplateid','pagenumber','slotposition'] as $required) {
            if (!in_array($required, $merchantColumns, true)) {
                $err('merchantitem_schema_not_supported');
                exit;
            }
        }
        $merchantHasPrice = in_array('price', $merchantColumns, true);
        $itemHasPrice = daoc_game_column_exists($db, 'itemtemplate', 'Price');

        $insertColumns = ['ItemListID','ItemTemplateID'];
        if ($merchantHasPrice) $insertColumns[] = 'Price';
        $insertColumns[] = 'PageNumber';
        $insertColumns[] = 'SlotPosition';
        $merchantHasUpdatedAt = in_array('lasttimerowupdated', $merchantColumns, true);
        $merchantHasObjectId = in_array('merchantitem_id', $merchantColumns, true);
        if ($merchantHasUpdatedAt) $insertColumns[] = 'LastTimeRowUpdated';
        if ($merchantHasObjectId) $insertColumns[] = 'MerchantItem_ID';
        $insertSql = 'INSERT INTO merchantitem (`' . implode('`,`', $insertColumns)
            . '`) VALUES (' . implode(',', array_fill(0, count($insertColumns), '?')) . ')';

        $db->beginTransaction();
        try {
            $db->prepare("DELETE FROM merchantitem WHERE ItemListID=? AND PageNumber=?")
               ->execute([$merchant_id,$page]);
            $ins = $db->prepare($insertSql);
            $priceUpdate = (!$merchantHasPrice && $itemHasPrice && $per > 0)
                ? $db->prepare('UPDATE itemtemplate SET Price=? WHERE Id_nb=?')
                : null;

            $pos = 0;
            foreach ($ids as $id) {
                if ($priceUpdate) {
                    $priceUpdate->execute([$per, $id]);
                }
                $values = [$merchant_id, $id];
                if ($merchantHasPrice) $values[] = $per;
                $values[] = $page;
                $values[] = $pos++;
                if ($merchantHasUpdatedAt) $values[] = date('Y-m-d H:i:s');
                if ($merchantHasObjectId) $values[] = daoc_game_object_id();
                $ins->execute($values);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Suit Creator merchant export failed: ' . $e->getMessage());
            $err('merchant_export_failed');
            exit;
        }

        aldhran_log("SUIT_MERCHANT_EXPORT", "Suit {$suit_id} → {$merchant_id} page {$page}", $currentUserId);
        echo json_encode([
            'ok'=>true,
            'items_exported'=>$pos,
            'price_each'=>$per,
            'price_storage'=>$merchantHasPrice ? 'merchantitem' : 'itemtemplate',
        ]);
        exit;
    }

    if ($action === 'export_json') {
        if ($userPriv < 3) { $err('forbidden'); exit; }
        $id = (int)($_GET['suit_id'] ?? 0);
        echo json_encode(sc_snapshot_data($db, $id), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'import_json' && $userPriv >= 3) {
        checkToken($_POST['csrf_token'] ?? '');
        $snap = json_decode($_POST['payload'] ?? '', true);
        if (!$snap || empty($snap['suit'])) { $err('bad_payload'); exit; }
        $s = $snap['suit'];
        $db->prepare("INSERT INTO cms_suits (name,description,realm,server_type,archetype,class_key,
                      target_stats,gen_settings,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute([
               substr((string)($s['name'] ?? 'Import'),0,120),
               (string)($s['description'] ?? ''), (int)($s['realm'] ?? 0),
               preg_replace('/[^a-z0-9_]/','',(string)($s['server_type'] ?? 'i50')),
               preg_replace('/[^a-z0-9_]/','',(string)($s['archetype'] ?? 'custom')),
               preg_replace('/[^a-z0-9_]/','',(string)($s['class_key'] ?? '')),
               is_string($s['target_stats'] ?? null) ? $s['target_stats'] : json_encode($s['target_stats'] ?? []),
               is_string($s['gen_settings'] ?? null) ? $s['gen_settings'] : json_encode($s['gen_settings'] ?? []),
               $currentUserId, $currentUserId,
           ]);
        $new = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO cms_suit_items (suit_id,slot,item_template_id,bonuses,slot_order) VALUES (?,?,?,?,?)");
        $ord = 0;
        foreach (($snap['items'] ?? []) as $r) {
            if (empty($r['slot']) || !isset($SC_SLOTS[$r['slot']])) continue;
            $ins->execute([$new,$r['slot'],$r['item_template_id'] ?? null,
                is_string($r['bonuses'] ?? null) ? $r['bonuses'] : json_encode($r['bonuses'] ?? []), $ord++]);
        }
        aldhran_log("SUIT_IMPORT", "Suit imported as id={$new}", $currentUserId);
        echo json_encode(['ok'=>true,'id'=>$new]);
        exit;
    }

    if ($action === 'search_items') {
        if ($userPriv < 3) { $err('forbidden'); exit; }
        $q    = '%'.trim($_GET['q'] ?? '').'%';
        $slot = $_GET['slot'] ?? '';
        $cols = sc_item_columns($db);
        $sel  = "Id_nb,Name,Level";
        if (in_array('Model', $cols, true))       $sel .= ",Model";
        if (in_array('Quality', $cols, true))     $sel .= ",Quality";
        if (in_array('Item_Type', $cols, true))   $sel .= ",Item_Type";
        $sql = "SELECT $sel FROM itemtemplate WHERE (Name LIKE ? OR Id_nb LIKE ?)";
        $par = [$q, $q];
        if ($slot && isset($SC_SLOTS[$slot]) && in_array('Item_Type', $cols, true) && empty($_GET['all'])) {
            $sql .= " AND Item_Type = ?";
            $par[] = $SC_SLOTS[$slot]['item_type'];
        }
        $sql .= " ORDER BY Level DESC, Name LIMIT 40";
        $st = $db->prepare($sql); $st->execute($par);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($action === 'item_delve') {
        if ($userPriv < 3) { $err('forbidden'); exit; }
        $id = trim($_GET['id'] ?? '');
        $st = $db->prepare("SELECT * FROM itemtemplate WHERE Id_nb=? LIMIT 1");
        $st->execute([$id]);
        $it = $st->fetch(PDO::FETCH_ASSOC);
        if (!$it) { $err('not_found'); exit; }
        $bc = sc_bonus_cols($db);
        $bon = []; $util = 0.0;
        for ($i = 1; $i <= $bc['max']; $i++) {
            $t = (int)($it[sprintf($bc['type'], $i)] ?? 0);
            $v = (int)($it['Bonus'.$i] ?? 0);
            if ($t > 0 && $v > 0) { $bon[] = ['type'=>$t,'value'=>$v]; $util += $v * sc_weight($t); }
        }
        echo json_encode(['ok'=>true,'item'=>[
            'Id_nb'=>$it['Id_nb'],'Name'=>$it['Name'],'Level'=>$it['Level'] ?? 0,
            'Quality'=>$it['Quality'] ?? 0,'Model'=>$it['Model'] ?? 0,
            'DPS_AF'=>$it['DPS_AF'] ?? 0,'SPD_ABS'=>$it['SPD_ABS'] ?? 0,
        ],'bonuses'=>$bon,'utility'=>round($util,2)]);
        exit;
    }

    if ($action === 'merchants') {
        if ($userPriv < 3) { $err('forbidden'); exit; }
        echo json_encode($db->query("SELECT DISTINCT ItemListID FROM merchantitem ORDER BY ItemListID LIMIT 200")->fetchAll(PDO::FETCH_COLUMN));
        exit;
    }

    if ($action === 'ai_balance_check' && $userPriv >= 3) {
        checkToken($_POST['csrf_token'] ?? '');
        if (!class_exists('AiManager')) { $err('AiManager not available'); exit; }
        $suit_id     = (int)($_POST['suit_id'] ?? 0);
        $server_type = preg_replace('/[^a-z0-9_]/','', $_POST['server_type'] ?? 'i50');
        $archetype   = preg_replace('/[^a-z0-9_]/','', $_POST['archetype'] ?? 'custom');
        $class_key   = preg_replace('/[^a-z0-9_]/','', $_POST['class_key'] ?? '');
        $profile     = $SC_SERVER_PROFILES[$server_type] ?? $SC_SERVER_PROFILES['i50'];

        $slot_bonuses = []; $total_utility = 0.0;
        $bc = sc_bonus_cols($db);
        if ($suit_id) {
            $st = $db->prepare("SELECT slot,item_template_id,bonuses FROM cms_suit_items WHERE suit_id=?");
            $st->execute([$suit_id]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $bon = json_decode($row['bonuses'] ?? '[]', true) ?: [];
                if (!$bon && $row['item_template_id']) {
                    $q = $db->prepare("SELECT * FROM itemtemplate WHERE Id_nb=? LIMIT 1");
                    $q->execute([$row['item_template_id']]);
                    if ($it = $q->fetch(PDO::FETCH_ASSOC)) {
                        for ($i = 1; $i <= $bc['max']; $i++) {
                            $t = (int)($it[sprintf($bc['type'], $i)] ?? 0);
                            $v = (int)($it['Bonus'.$i] ?? 0);
                            if ($t > 0 && $v > 0) $bon[] = ['type'=>$t,'value'=>$v];
                        }
                    }
                }
                $labelled = [];
                foreach ($bon as $b) {
                    $labelled[] = ['stat'=>sc_prop((int)$b['type'])['label'], 'value'=>(int)$b['value']];
                    $total_utility += (int)$b['value'] * sc_weight((int)$b['type']);
                }
                $slot_bonuses[$row['slot']] = $labelled;
            }
        }

        global $botSettings;
        $ai = new AiManager($db, $botSettings, $currentUserId, $userPriv);
        $result = $ai->request('suit_creator', 'balance_check', [
            'archetype'     => $archetype,
            'class'         => $SC_CLASSES[$class_key]['label'] ?? $archetype,
            'server_type'   => $server_type,
            'caps'          => $profile['caps'],
            'max_utility'   => $profile['max_utility'],
            'total_utility' => round($total_utility, 2),
            'slot_bonuses'  => $slot_bonuses,
            'instruction'   => 'Analyze the balance of this DAoC gear set. Check utility usage for each slot, '
                             . 'resistance coverage, stat caps, and whether it suits the archetype and class. '
                             . 'Identify unbalanced slots and provide actionable suggestions in concise bullet points.',
        ], ['save_suggestion' => true, 'target_id' => $suit_id ?: null]);
        echo json_encode($result);
        exit;
    }

    if ($action === 'ai_suggest_missing' && $userPriv >= 3) {
        checkToken($_POST['csrf_token'] ?? '');
        if (!class_exists('AiManager')) { $err('AiManager not available'); exit; }
        $suit_id     = (int)($_POST['suit_id'] ?? 0);
        $server_type = preg_replace('/[^a-z0-9_]/','', $_POST['server_type'] ?? 'i50');
        $archetype   = preg_replace('/[^a-z0-9_]/','', $_POST['archetype'] ?? 'custom');
        $class_key   = preg_replace('/[^a-z0-9_]/','', $_POST['class_key'] ?? '');
        $assigned    = json_decode($_POST['assigned_slots'] ?? '[]', true) ?: [];
        $empty       = array_values(array_diff(array_keys($SC_SLOTS), $assigned));

        global $botSettings;
        $ai = new AiManager($db, $botSettings, $currentUserId, $userPriv);
        $result = $ai->request('suit_creator', 'suggest_stats', [
            'archetype'      => $archetype,
            'class'          => $SC_CLASSES[$class_key]['label'] ?? $archetype,
            'server_type'    => $server_type,
            'assigned_slots' => $assigned,
            'empty_slots'    => $empty,
            'instruction'    => 'This gear set has empty slots. Suggest suitable class bonuses for each empty slot '
                              . 'and identify critical gaps such as Mythirian or neck items. Keep the response concise.',
        ], ['save_suggestion' => true, 'target_id' => $suit_id ?: null]);
        echo json_encode($result);
        exit;
    }

    if ($action === 'ai_autobuild' && $userPriv >= 3) {
        checkToken($_POST['csrf_token'] ?? '');
        if (!class_exists('AiManager')) { $err('AiManager not available'); exit; }
        $server_type = preg_replace('/[^a-z0-9_]/','', $_POST['server_type'] ?? 'i50');
        $class_key   = preg_replace('/[^a-z0-9_]/','', $_POST['class_key'] ?? '');
        $brief       = trim($_POST['brief'] ?? '');
        $profile     = $SC_SERVER_PROFILES[$server_type] ?? $SC_SERVER_PROFILES['i50'];
        $props = [];
        foreach ($SC_PROPERTIES as $id => $p) $props[$id] = $p['label'];

        global $botSettings;
        $ai = new AiManager($db, $botSettings, $currentUserId, $userPriv);
        $result = $ai->request('suit_creator', 'autobuild', [
            'class'      => $SC_CLASSES[$class_key]['label'] ?? 'unknown',
            'server'     => $server_type,
            'caps'       => $profile['caps'],
            'properties' => $props,
            'brief'      => $brief,
            'instruction'=> 'Generate target stats for a DAoC gear set. Return only a JSON object in the form '
                          . '{"targets":{"<property_id>":<value>}, "reason":"brief explanation"}. '
                          . 'Use only IDs from properties. Do not exceed any caps. Return no Markdown or prose.',
        ], ['save_suggestion' => false]);
        echo json_encode($result);
        exit;
    }

    echo json_encode(['error'=>'unknown_action']);
    exit;
}


/* ══════════════════════════════════════════════════════════════════
   9. SNAPSHOT-HELPER
   ══════════════════════════════════════════════════════════════════ */
function sc_snapshot_data(PDO $db, int $suit_id): array {
    $st = $db->prepare("SELECT * FROM cms_suits WHERE id=?"); $st->execute([$suit_id]);
    $suit = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $it = $db->prepare("SELECT slot,item_template_id,bonuses,base_item_id,generated_id_nb,slot_order
                        FROM cms_suit_items WHERE suit_id=? ORDER BY slot_order");
    $it->execute([$suit_id]);
    return ['_format'=>'aldhran.suit/1', 'suit'=>$suit, 'items'=>$it->fetchAll(PDO::FETCH_ASSOC)];
}
function sc_snapshot(PDO $db, int $suit_id, int $userId, string $label = ''): void {
    if (!$suit_id) return;
    $data = sc_snapshot_data($db, $suit_id);
    if (empty($data['suit'])) return;
    $db->prepare("INSERT INTO cms_suit_revisions (suit_id,snapshot,label,created_by) VALUES (?,?,?,?)")
       ->execute([$suit_id, json_encode($data), substr($label, 0, 120), $userId]);
    $db->prepare("DELETE FROM cms_suit_revisions WHERE suit_id=? AND id NOT IN
                  (SELECT id FROM (SELECT id FROM cms_suit_revisions WHERE suit_id=? ORDER BY id DESC LIMIT 30) x)")
       ->execute([$suit_id, $suit_id]);
}
