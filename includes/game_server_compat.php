<?php
declare(strict_types=1);

if (defined('DAOC_GAME_SERVER_COMPAT_LOADED')) {
    return;
}
define('DAOC_GAME_SERVER_COMPAT_LOADED', true);

/**
 * Return the configured emulator family.
 *
 * Existing RC1 installations have no setting yet and therefore remain on the
 * legacy DOL behavior until an administrator deliberately selects OpenDAoC.
 */
function daoc_game_server_core(): string
{
    $core = strtolower(trim((string)($GLOBALS['cms_settings']['game_server_core'] ?? 'dol')));

    return in_array($core, ['opendaoc', 'dol'], true) ? $core : 'dol';
}

function daoc_game_server_is_opendaoc(): bool
{
    return daoc_game_server_core() === 'opendaoc';
}

/**
 * Character fields shared by current DOL and OpenDAoC databases.
 *
 * Keeping the query here gives profile, Herald and later integrations one
 * stable boundary without duplicating complete OpenDAoC module files.
 *
 * @return list<array{Name:string, Class:int|string, Level:int|string, Realm:int|string}>
 */
function daoc_game_characters_for_account(PDO $db, string $accountName): array
{
    $stmt = $db->prepare(
        'SELECT Name, Class, Level, Realm
           FROM dolcharacters
          WHERE AccountName = ?
          ORDER BY Level DESC, Name ASC'
    );
    $stmt->execute([$accountName]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Return the in-game privilege level, or null when no game account exists. */
function daoc_game_account_privilege(PDO $db, string $accountName): ?int
{
    $stmt = $db->prepare('SELECT PrivLevel FROM account WHERE Name = ? LIMIT 1');
    $stmt->execute([$accountName]);
    $value = $stmt->fetchColumn();

    return $value === false ? null : (int)$value;
}

/** Check a game database column once per request and PDO connection. */
function daoc_game_column_exists(PDO $db, string $table, string $column): bool
{
    static $cache = [];

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
        return false;
    }

    $key = spl_object_id($db) . ':' . strtolower($table) . '.' . strtolower($column);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $db->prepare(
        'SELECT 1
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND LOWER(TABLE_NAME) = LOWER(?)
            AND LOWER(COLUMN_NAME) = LOWER(?)
          LIMIT 1'
    );
    $stmt->execute([$table, $column]);

    return $cache[$key] = ($stmt->fetchColumn() !== false);
}

/**
 * Return the physical columns of a game table, preserving their database names.
 *
 * OpenDAoC and DOL share most item fields, but forks can add or omit optional
 * columns. Writers use this list to persist only fields supported by the active
 * schema.
 *
 * @return list<string>
 */
function daoc_game_table_columns(PDO $db, string $table): array
{
    static $cache = [];

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return [];
    }

    $key = spl_object_id($db) . ':' . strtolower($table);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = $db->prepare(
        'SELECT COLUMN_NAME
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND LOWER(TABLE_NAME) = LOWER(?)
          ORDER BY ORDINAL_POSITION'
    );
    $stmt->execute([$table]);

    return $cache[$key] = array_values(array_map(
        'strval',
        $stmt->fetchAll(PDO::FETCH_COLUMN)
    ));
}

/**
 * Remove fields that do not exist in the connected OpenDAoC/DOL table and
 * normalize each key to the physical column name.
 */
function daoc_game_filter_table_row(PDO $db, string $table, array $row): array
{
    $supported = [];
    foreach (daoc_game_table_columns($db, $table) as $column) {
        $supported[strtolower($column)] = $column;
    }

    $filtered = [];
    foreach ($row as $column => $value) {
        $actual = $supported[strtolower((string)$column)] ?? null;
        if ($actual !== null) {
            $filtered[$actual] = $value;
        }
    }

    return $filtered;
}

/**
 * Return normalized keep ownership for the RvR map.
 *
 * @param list<int> $keepIds
 * @return array<int,array{KeepID:int,Realm:int,GuildName:string}>
 */
function daoc_game_realm_war_keeps(PDO $db, array $keepIds): array
{
    $keepIds = array_values(array_unique(array_filter(
        array_map('intval', $keepIds),
        static fn(int $id): bool => $id > 0
    )));
    if ($keepIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
    $hasClaimedGuildName = daoc_game_column_exists($db, 'keep', 'ClaimedGuildName');
    $hasGuildId = daoc_game_column_exists($db, 'keep', 'GuildID');

    if (daoc_game_server_is_opendaoc() && $hasClaimedGuildName) {
        $sql = 'SELECT k.KeepID, k.Realm, COALESCE(k.ClaimedGuildName, \'\') AS GuildName
                  FROM keep k
                 WHERE k.KeepID IN (' . $placeholders . ')';
    } elseif ($hasGuildId) {
        $sql = 'SELECT k.KeepID, k.Realm, COALESCE(g.GuildName, \'\') AS GuildName
                  FROM keep k
                  LEFT JOIN guild g ON k.GuildID = g.GuildID
                 WHERE k.KeepID IN (' . $placeholders . ')';
    } elseif ($hasClaimedGuildName) {
        $sql = 'SELECT k.KeepID, k.Realm, COALESCE(k.ClaimedGuildName, \'\') AS GuildName
                  FROM keep k
                 WHERE k.KeepID IN (' . $placeholders . ')';
    } else {
        $sql = 'SELECT k.KeepID, k.Realm, \'\' AS GuildName
                  FROM keep k
                 WHERE k.KeepID IN (' . $placeholders . ')';
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($keepIds);

    $keeps = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $keepId = (int)$row['KeepID'];
        $keeps[$keepId] = [
            'KeepID' => $keepId,
            'Realm' => (int)$row['Realm'],
            'GuildName' => (string)($row['GuildName'] ?? ''),
        ];
    }

    return $keeps;
}

/**
 * Normalize relic identity. OpenDAoC uses RelicID, while legacy schemas may
 * additionally expose the generated Relic_ID string.
 *
 * @return list<array{RelicID:int,Realm:int,OriginalRealm:int,RelicName:string}>
 */
function daoc_game_realm_war_relics(PDO $db): array
{
    $nameExpression = daoc_game_column_exists($db, 'relic', 'Relic_ID')
        ? "COALESCE(NULLIF(Relic_ID, ''), CONCAT('Relic #', RelicID))"
        : "CONCAT('Relic #', RelicID)";

    $rows = $db->query(
        'SELECT RelicID, Realm, OriginalRealm, ' . $nameExpression . ' AS RelicName
           FROM relic
          ORDER BY RelicID ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    return array_map(
        static fn(array $row): array => [
            'RelicID' => (int)$row['RelicID'],
            'Realm' => (int)$row['Realm'],
            'OriginalRealm' => (int)$row['OriginalRealm'],
            'RelicName' => (string)$row['RelicName'],
        ],
        $rows
    );
}

/**
 * Normalize keep ownership for both emulator families.
 *
 * OpenDAoC stores ClaimedGuildName directly. Legacy DOL schemas commonly
 * store GuildID and require a join to guild.
 *
 * @return list<array{Name:string, Realm:int|string, GuildID:mixed, GuildName:mixed}>
 */
function daoc_game_herald_keeps(PDO $db): array
{
    $hasClaimedGuildName = daoc_game_column_exists($db, 'keep', 'ClaimedGuildName');
    $hasGuildId = daoc_game_column_exists($db, 'keep', 'GuildID');

    if (daoc_game_server_is_opendaoc() && $hasClaimedGuildName) {
        $stmt = $db->prepare(
            'SELECT k.Name, k.Realm, NULL AS GuildID, k.ClaimedGuildName AS GuildName
               FROM keep k
              ORDER BY k.Realm ASC, k.Name ASC'
        );
    } elseif ($hasGuildId) {
        $stmt = $db->prepare(
            'SELECT k.Name, k.Realm, k.GuildID, g.GuildName
               FROM keep k
               LEFT JOIN guild g ON k.GuildID = g.GuildID
              ORDER BY k.Realm ASC, k.Name ASC'
        );
    } elseif ($hasClaimedGuildName) {
        $stmt = $db->prepare(
            'SELECT k.Name, k.Realm, NULL AS GuildID, k.ClaimedGuildName AS GuildName
               FROM keep k
              ORDER BY k.Realm ASC, k.Name ASC'
        );
    } else {
        $stmt = $db->prepare(
            'SELECT k.Name, k.Realm, NULL AS GuildID, NULL AS GuildName
               FROM keep k
              ORDER BY k.Realm ASC, k.Name ASC'
        );
    }

    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array<int,int> */
function daoc_game_herald_population(PDO $db): array
{
    $population = [1 => 0, 2 => 0, 3 => 0];
    $stmt = $db->query(
        'SELECT Realm, COUNT(*) AS CharacterCount
           FROM dolcharacters
          WHERE Level > 0
          GROUP BY Realm'
    );

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $realm = (int)$row['Realm'];
        if (isset($population[$realm])) {
            $population[$realm] = (int)$row['CharacterCount'];
        }
    }

    return $population;
}

/** Return public leaderboard characters, respecting OpenDAoC stat privacy. */
function daoc_game_herald_top_characters(PDO $db, int $limit = 15): array
{
    $limit = max(1, min(100, $limit));
    $privacy = '';
    if (daoc_game_column_exists($db, 'dolcharacters', 'IgnoreStatistics')) {
        $privacy .= ' AND COALESCE(IgnoreStatistics, 0) = 0';
    }
    if (daoc_game_column_exists($db, 'dolcharacters', 'IsAnonymous')) {
        $privacy .= ' AND COALESCE(IsAnonymous, 0) = 0';
    }

    $stmt = $db->prepare(
        'SELECT Name, Class, Level, Realm, RealmPoints
           FROM dolcharacters
          WHERE Level > 0' . $privacy . '
          ORDER BY RealmPoints DESC, Name ASC
          LIMIT ' . $limit
    );
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function daoc_game_herald_character(PDO $db, string $characterName): ?array
{
    $deathsColumn = daoc_game_column_exists($db, 'dolcharacters', 'DeathsPvP')
        ? 'c.DeathsPvP'
        : 'c.DeathCount';

    $stmt = $db->prepare(
        'SELECT c.*,
                (c.KillsAlbionPlayers + c.KillsMidgardPlayers + c.KillsHiberniaPlayers) AS TotalKills,
                ' . $deathsColumn . ' AS HeraldDeaths,
                g.GuildName
           FROM dolcharacters c
           LEFT JOIN guild g ON c.GuildID = g.GuildID
          WHERE c.Name = ?
          LIMIT 1'
    );
    $stmt->execute([$characterName]);
    $character = $stmt->fetch(PDO::FETCH_ASSOC);

    return $character === false ? null : $character;
}

function daoc_game_herald_guild(PDO $db, string $guildId): ?array
{
    $stmt = $db->prepare('SELECT * FROM guild WHERE GuildID = ? LIMIT 1');
    $stmt->execute([$guildId]);
    $guild = $stmt->fetch(PDO::FETCH_ASSOC);

    return $guild === false ? null : $guild;
}

/** Return guild members while retaining the OpenDAoC statistics privacy flag. */
function daoc_game_herald_guild_members(PDO $db, string $guildId): array
{
    $hasStatsPrivacy = daoc_game_column_exists($db, 'dolcharacters', 'IgnoreStatistics');
    $hasAnonymous = daoc_game_column_exists($db, 'dolcharacters', 'IsAnonymous');
    $statsPrivacyColumn = $hasStatsPrivacy ? 'IgnoreStatistics' : '0 AS IgnoreStatistics';
    $anonymousColumn = $hasAnonymous ? 'IsAnonymous' : '0 AS IsAnonymous';
    $privacyChecks = [];
    if ($hasStatsPrivacy) $privacyChecks[] = 'COALESCE(IgnoreStatistics, 0)';
    if ($hasAnonymous) $privacyChecks[] = 'COALESCE(IsAnonymous, 0)';
    $privacyExpression = count($privacyChecks) > 1
        ? 'GREATEST(' . implode(', ', $privacyChecks) . ')'
        : ($privacyChecks[0] ?? '');
    $privacyOrder = $privacyExpression !== '' ? $privacyExpression . ' ASC, ' : '';

    $stmt = $db->prepare(
        'SELECT Name, Class, Level, Realm, RealmPoints, ' . $statsPrivacyColumn . ', ' . $anonymousColumn . '
           FROM dolcharacters
          WHERE GuildID = ?
          ORDER BY ' . $privacyOrder . 'RealmPoints DESC, Name ASC'
    );
    $stmt->execute([$guildId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array{mobs:int,items:int,quests:int} */
function daoc_game_pve_counts(PDO $db): array
{
    return [
        'mobs' => (int)$db->query('SELECT COUNT(*) FROM mob')->fetchColumn(),
        'items' => (int)$db->query('SELECT COUNT(*) FROM itemtemplate')->fetchColumn(),
        'quests' => (int)$db->query('SELECT COUNT(*) FROM dataquest')->fetchColumn(),
    ];
}

/**
 * OpenDAoC and current DOL both map mob names to loot templates, then to item
 * templates. DISTINCT removes duplicate rows caused by repeated mob spawns.
 */
function daoc_game_mob_loot(PDO $db, string $mobName, int $limit = 0): array
{
    $limitSql = $limit > 0 ? ' LIMIT ' . max(1, min(500, $limit)) : '';
    $stmt = $db->prepare(
        'SELECT DISTINCT
                lt.ItemTemplateID,
                it.Name AS ItemName,
                it.Quality,
                lt.Chance,
                it.Model
           FROM mobxloottemplate mxl
           JOIN loottemplate lt ON mxl.LootTemplateName = lt.TemplateName
           LEFT JOIN itemtemplate it ON lt.ItemTemplateID = it.Id_nb
          WHERE mxl.MobName = ?
          ORDER BY lt.Chance ASC, it.Name ASC' . $limitSql
    );
    $stmt->execute([$mobName]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function daoc_game_mob_by_id(PDO $db, string $mobId): ?array
{
    $stmt = $db->prepare('SELECT * FROM mob WHERE Mob_ID = ? LIMIT 1');
    $stmt->execute([$mobId]);
    $mob = $stmt->fetch(PDO::FETCH_ASSOC);

    return $mob === false ? null : $mob;
}

function daoc_game_dataquest(PDO $db, int $questId): ?array
{
    $stmt = $db->prepare('SELECT * FROM dataquest WHERE ID = ? LIMIT 1');
    $stmt->execute([$questId]);
    $quest = $stmt->fetch(PDO::FETCH_ASSOC);

    return $quest === false ? null : $quest;
}

/** Sum OpenDAoC's pipe-separated per-step XP or money rewards. */
function daoc_game_serialized_reward_total(mixed $value): int
{
    if ($value === null || $value === '') return 0;

    $total = 0;
    foreach (explode('|', (string)$value) as $part) {
        $part = trim($part);
        if ($part !== '' && is_numeric($part)) {
            $total += (int)$part;
        }
    }

    return $total;
}

/** @return list<string> */
function daoc_game_dataquest_reward_ids(array $quest): array
{
    $ids = [];

    $optional = trim((string)($quest['OptionalRewardItemTemplates'] ?? ''));
    if ($optional !== '') {
        // OpenDAoC stores the number of optional choices in the first byte.
        $optional = substr($optional, 1);
        $ids = array_merge($ids, explode('|', $optional));
    }

    $final = trim((string)($quest['FinalRewardItemTemplates'] ?? ''));
    if ($final !== '') {
        $ids = array_merge($ids, explode('|', $final));
    }

    $ids = array_values(array_unique(array_filter(
        array_map('trim', $ids),
        static fn(string $id): bool => $id !== ''
    )));

    return $ids;
}

/** @return array<string,string> item template ID => display name */
function daoc_game_item_names(PDO $db, array $itemIds): array
{
    if ($itemIds === []) return [];

    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $stmt = $db->prepare(
        'SELECT Id_nb, Name FROM itemtemplate WHERE Id_nb IN (' . $placeholders . ')'
    );
    $stmt->execute(array_values($itemIds));

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $items[(string)$item['Id_nb']] = (string)$item['Name'];
    }

    return $items;
}

function daoc_game_region_label(PDO $db, int $regionId): string
{
    $stmt = $db->prepare(
        "SELECT COALESCE(NULLIF(Description, ''), Name)
           FROM regions
          WHERE RegionID = ?
          LIMIT 1"
    );
    $stmt->execute([$regionId]);
    $label = $stmt->fetchColumn();

    return $label === false || trim((string)$label) === ''
        ? 'Region ' . $regionId
        : (string)$label;
}
