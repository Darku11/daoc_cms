<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) exit;

if (isset($_POST['get_spawns'])) {
    if (ob_get_level()) ob_clean();

    checkToken($_POST['csrf_token'] ?? '');

    $name   = $_POST['mob_name'] ?? '';
    $region = $_POST['region']   ?? '';
    $level  = (int)($_POST['level'] ?? 0);

    $stmt_spawns = $db->prepare("
        SELECT m.X, m.Y, m.Z,
        COALESCE(
            (SELECT Name FROM zones z
             WHERE z.RegionID = m.Region
             AND (m.X / 8192) >= z.OffsetX AND (m.X / 8192) <= (z.OffsetX + z.Width)
             AND (m.Y / 8192) >= z.OffsetY AND (m.Y / 8192) <= (z.OffsetY + z.Height) LIMIT 1),
            (SELECT Name FROM zones z WHERE z.RegionID = m.Region LIMIT 1),
            'Unknown Zone'
        ) as ZoneName
        FROM mob m
        WHERE m.Name = ? AND m.Region = ? AND m.Level = ?
        LIMIT 50
    ");
    $stmt_spawns->execute([$name, $region, $level]);
    $spawns = $stmt_spawns->fetchAll();

    $loot = daoc_game_mob_loot($db, $name);

    echo '<div class="bestiary-ajax-wrap">';

        echo '<div class="bestiary-ajax-col">';
            echo '<h4 class="bestiary-ajax-title">' . t('pve_bestiary.ajax.locations', [], 'LOCATIONS') . '</h4>';
            echo '<table class="spawn-table"><thead><tr><th>' . t('pve_bestiary.ajax.x', [], 'X') . '</th><th>' . t('pve_bestiary.ajax.y', [], 'Y') . '</th><th>' . t('pve_bestiary.ajax.z', [], 'Z') . '</th><th>Zone</th></tr></thead><tbody>';
            if ($spawns) {
                foreach ($spawns as $s) {
                    $zName = h($s['ZoneName'] ?: 'Unknown Zone');
                    echo "<tr class='loc-row' style='display:none;'><td>" . (int)round($s['X']) . "</td><td>" . (int)round($s['Y']) . "</td><td>" . (int)round($s['Z']) . "</td><td class='bestiary-zone-name'>" . $zName . "</td></tr>";
                }
            } else {
                echo "<tr><td colspan='4'>" . t('pve_bestiary.ajax.no_locations', [], 'No locations found.') . "</td></tr>";
            }
            echo '</tbody></table>';

            if (count($spawns) > 8) {
                echo '<div class="bestiary-page-nav">';
                echo '<button onclick="changeLocPage(-1)" class="pg-link bestiary-page-btn">&laquo; Prev</button>';
                echo '<span id="loc-page-info" class="bestiary-page-info"></span>';
                echo '<button onclick="changeLocPage(1)" class="pg-link bestiary-page-btn">Next &raquo;</button>';
                echo '</div>';
            }

        echo '</div>';

        echo '<div class="bestiary-ajax-col bestiary-ajax-col--wide">';
            echo '<h4 class="bestiary-ajax-title">' . t('pve_bestiary.ajax.potential_drops', [], 'POTENTIAL DROPS') . '</h4>';
            echo '<table class="spawn-table"><thead><tr><th colspan="2">' . t('pve_bestiary.ajax.item', [], 'Item') . '</th><th>' . t('pve_bestiary.ajax.chance', [], 'Chance') . '</th></tr></thead><tbody>';
            if ($loot) {
                $iconBase = "assets/img/icons/items/";
                foreach ($loot as $l) {
                    $itemName    = h($l['ItemName'] ?: $l['ItemTemplateID']);
                    $iconFile    = (!empty($l['Model'])) ? ((int)$l['Model']) . ".png" : "default.png";
                    $fullPath    = h($iconBase . $iconFile);
                    $defaultPath = h($iconBase . "default.png");
                    $modelLabel  = h((string)($l['Model'] ?? ''));
                    echo "<tr class='loot-row' style='display:none;'>";
                    echo "<td class='bestiary-icon-cell'>";
                    echo "<img src='$fullPath' title='Model: $modelLabel' onerror=\"this.src='$defaultPath';\" class='bestiary-item-icon'>";
                    echo "</td>";
                    echo "<td><span class='bestiary-item-name'>$itemName</span></td>";
                    echo "<td class='bestiary-loot-chance'>" . (int)$l['Chance'] . "%</td>";
                    echo "</tr>";
                }
            } else {
                echo "<tr><td colspan='3' class='bestiary-no-loot'>" . t('pve_bestiary.ajax.no_loot', [], 'No loot assigned.') . "</td></tr>";
            }
            echo '</tbody></table>';

            if (count($loot) > 8) {
                echo '<div class="bestiary-page-nav">';
                echo '<button onclick="changeLootPage(-1)" class="pg-link bestiary-page-btn">&laquo; Prev</button>';
                echo '<span id="loot-page-info" class="bestiary-page-info"></span>';
                echo '<button onclick="changeLootPage(1)" class="pg-link bestiary-page-btn">Next &raquo;</button>';
                echo '</div>';
            }

        echo '</div>';

    echo '</div>';
    exit();
}

$search  = $_GET['search'] ?? '';
$realm   = (int)($_GET['realm'] ?? 0);
$sort    = $_GET['sort'] ?? 'lvl_desc';
$min_lvl = (int)($_GET['min_lvl'] ?? 1);
$max_lvl = (int)($_GET['max_lvl'] ?? 150);
$zone_id = (int)($_GET['zone'] ?? 0);

$order_map = [
    'name_asc'  => "Name ASC",
    'lvl_asc'   => "Level ASC, Name ASC",
    'region'    => "Region ASC, Level DESC",
    'lvl_desc'  => "Level DESC, Name ASC",
];
$order_by = $order_map[$sort] ?? $order_map['lvl_desc'];

$where_clauses = ["Name LIKE ?", "Level BETWEEN ? AND ?"];
$params = ["%$search%", $min_lvl, $max_lvl];

if ($realm > 0) {
    $where_clauses[] = "Realm = ?";
    $params[] = $realm;
}

$zone_info = null;
if ($zone_id > 0) {
    $stmt_zi = $db->prepare("SELECT ZoneID, Name, RegionID, OffsetX, OffsetY, Width, Height FROM zones WHERE ZoneID = ?");
    $stmt_zi->execute([$zone_id]);
    $zone_info = $stmt_zi->fetch();
    if ($zone_info) {
        $where_clauses[] = "Region = ? AND (X / 8192) BETWEEN ? AND ? AND (Y / 8192) BETWEEN ? AND ?";
        $params[] = (int)$zone_info['RegionID'];
        $params[] = (float)$zone_info['OffsetX'];
        $params[] = (float)$zone_info['OffsetX'] + (float)$zone_info['Width'];
        $params[] = (float)$zone_info['OffsetY'];
        $params[] = (float)$zone_info['OffsetY'] + (float)$zone_info['Height'];
    } else {
        $zone_id = 0;
    }
}

$zones_list = $db->query("SELECT ZoneID, Name FROM zones ORDER BY Name ASC")->fetchAll();

$where_str = "WHERE " . implode(" AND ", $where_clauses);

$limit  = 30;
$page   = max(1, (int)($_GET['pg'] ?? 1));
$offset = ($page - 1) * $limit;

$stmt_count = $db->prepare("SELECT COUNT(*) FROM (SELECT Name FROM mob $where_str GROUP BY Name, Region, Level) AS grouped");
$stmt_count->execute($params);
$total_mobs  = (int)$stmt_count->fetchColumn();
$total_pages = (int)ceil($total_mobs / $limit);

$stmt_mobs = $db->prepare("SELECT Name, Level, Realm, Region, COUNT(*) as spawn_count, MAX(X) as sx, MAX(Y) as sy
                            FROM mob $where_str
                            GROUP BY Name, Region, Level, Realm
                            ORDER BY $order_by
                            LIMIT $limit OFFSET $offset");
$stmt_mobs->execute($params);
$mobs = $stmt_mobs->fetchAll();

$stmt_zone = $db->prepare("
    SELECT Name FROM zones
    WHERE RegionID = ?
      AND (? / 8192) >= OffsetX AND (? / 8192) <= (OffsetX + Width)
      AND (? / 8192) >= OffsetY AND (? / 8192) <= (OffsetY + Height)
    LIMIT 1
");
$stmt_zone_fallback = $db->prepare("SELECT Name FROM zones WHERE RegionID = ? LIMIT 1");

$bestiary_token = generateToken();

if (!function_exists('getRealmName')) {
    function getRealmName($id) {
        switch ((int)$id) {
            case 1: return t('pve_bestiary.realm.albion', [], 'Albion');
            case 2: return t('pve_bestiary.realm.midgard', [], 'Midgard');
            case 3: return t('pve_bestiary.realm.hibernia', [], 'Hibernia');
            default: return t('pve_bestiary.realm.neutral', [], 'Neutral');
        }
    }
}
?>
<div class="admin-container">
    <div class="bestiary-header">
        <h2 class="bestiary-main-title"><?= t('pve_bestiary.title', [], 'Bestiary'); ?></h2>
        <span class="bestiary-entity-count"><?php echo $total_mobs; ?> <?= t('pve_bestiary.entities', [], 'ENTITIES'); ?></span>
    </div>

    <form method="GET" class="filter-bar bestiary-filter-bar">
        <input type="hidden" name="p" value="<?php echo h($_GET['p'] ?? ''); ?>">
        <div class="bestiary-filter-name">
            <label class="um-label"><?= t('pve_bestiary.filter.name', [], 'Name'); ?></label>
            <input type="text" name="search" value="<?php echo h($search); ?>" class="um-input">
        </div>
        <div class="bestiary-filter-realm">
            <label class="um-label"><?= t('pve_bestiary.filter.realm', [], 'Realm'); ?></label>
            <select name="realm" class="um-input">
                <option value="0"><?= t('pve_bestiary.filter.all', [], 'All'); ?></option>
                <option value="1" <?php if ($realm == 1) echo 'selected'; ?>><?= t('pve_bestiary.realm.albion', [], 'Albion'); ?></option>
                <option value="2" <?php if ($realm == 2) echo 'selected'; ?>><?= t('pve_bestiary.realm.midgard', [], 'Midgard'); ?></option>
                <option value="3" <?php if ($realm == 3) echo 'selected'; ?>><?= t('pve_bestiary.realm.hibernia', [], 'Hibernia'); ?></option>
            </select>
        </div>
        <div class="bestiary-filter-zone">
            <label class="um-label"><?= t('pve_bestiary.filter.zone', [], 'Zone'); ?></label>
            <select name="zone" class="um-input">
                <option value="0"><?= t('pve_bestiary.filter.all', [], 'All'); ?></option>
                <?php foreach ($zones_list as $z): ?>
                    <option value="<?= (int)$z['ZoneID'] ?>" <?php if ($zone_id == (int)$z['ZoneID']) echo 'selected'; ?>><?= h($z['Name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="bestiary-filter-sort">
            <label class="um-label"><?= t('pve_bestiary.filter.sort', [], 'Sort'); ?></label>
            <select name="sort" class="um-input">
                <option value="lvl_desc" <?php if ($sort == 'lvl_desc') echo 'selected'; ?>><?= t('pve_bestiary.filter.sort.lvl_high', [], 'Lvl High'); ?></option>
                <option value="lvl_asc"  <?php if ($sort == 'lvl_asc')  echo 'selected'; ?>><?= t('pve_bestiary.filter.sort.lvl_low', [], 'Lvl Low'); ?></option>
                <option value="name_asc" <?php if ($sort == 'name_asc') echo 'selected'; ?>><?= t('pve_bestiary.filter.sort.name_az', [], 'Name A-Z'); ?></option>
                <option value="region"   <?php if ($sort == 'region')   echo 'selected'; ?>><?= t('pve_bestiary.filter.sort.region', [], 'Region'); ?></option>
            </select>
        </div>
        <button type="submit" class="btn-gold bestiary-filter-btn"><?= t('pve_bestiary.filter.btn', [], 'FILTER'); ?></button>
    </form>

    <div class="bestiary-grid">
        <?php if ($mobs): ?>
            <?php foreach ($mobs as $m):
                $stmt_zone->execute([$m['Region'], $m['sx'], $m['sx'], $m['sy'], $m['sy']]);
                $zoneName = $stmt_zone->fetchColumn();
                if (!$zoneName) {
                    $stmt_zone_fallback->execute([$m['Region']]);
                    $zoneName = $stmt_zone_fallback->fetchColumn() ?: 'Unknown Zone';
                }
            ?>
                <div class="mob-card"
                     onclick="showDetails('<?php echo addslashes($m['Name']); ?>', '<?php echo addslashes($m['Region']); ?>', <?php echo (int)$m['Level']; ?>)">
                    <div class="bestiary-mob-realm"><?php echo h(getRealmName($m['Realm'])); ?></div>
                    <div class="bestiary-mob-header">
                        <strong class="bestiary-mob-name"><?php echo h($m['Name']); ?></strong>
                        <span class="spawn-badge"><?php echo (int)$m['spawn_count']; ?>x</span>
                    </div>
                    <div class="bestiary-mob-meta">
                        <?= t('pve_bestiary.lvl_short', [], 'Lvl'); ?> <?php echo (int)$m['Level']; ?> &mdash; <?php echo h($zoneName); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="bestiary-no-results"><?= t('pve_bestiary.no_entities', [], 'No entities found in the archives.'); ?></div>
        <?php endif; ?>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="bestiary-pagination">
        <?php
        $base_params = array_filter(['p' => $_GET['p'] ?? '', 'search' => $search, 'realm' => $realm ?: null, 'zone' => $zone_id ?: null, 'sort' => $sort, 'min_lvl' => $min_lvl, 'max_lvl' => $max_lvl]);
        $range = 2;
        for ($i = 1; $i <= $total_pages; $i++):
            if ($i == 1 || $i == $total_pages || ($i >= $page - $range && $i <= $page + $range)) {
                $link_params = http_build_query(array_merge($base_params, ['pg' => $i]));
                ?>
                <a href="?<?php echo $link_params; ?>" class="pg-link <?php echo ($i === $page) ? 'pg-active' : ''; ?>"><?php echo $i; ?></a>
                <?php
            } elseif ($i == $page - $range - 1 || $i == $page + $range + 1) {
                echo '<span class="bestiary-ellipsis">...</span>';
            }
        endfor; ?>
    </div>
    <?php endif; ?>
</div>

<div id="spawnModal" onclick="if(event.target===this) closeModal()">
    <div class="modal-content">
        <button onclick="closeModal()" class="bestiary-modal-close">
            <i class="fas fa-times"></i>
        </button>
        <h3 id="modalTitle" class="bestiary-modal-title"></h3>
        <div id="modalBody" class="bestiary-modal-body">Loading...</div>
    </div>
</div>

<script>
const BESTIARY_CSRF = '<?php echo $bestiary_token; ?>';

let lootCurPage = 1;
const lootLimit = 8;
let lootTotal = 1;

function changeLootPage(dir) {
    lootCurPage += dir;
    if (lootCurPage < 1) lootCurPage = 1;
    if (lootCurPage > lootTotal) lootCurPage = lootTotal;
    const rows = document.querySelectorAll('#spawnModal .loot-row');
    const start = (lootCurPage - 1) * lootLimit;
    const end = start + lootLimit;
    rows.forEach((r, i) => { r.style.display = (i >= start && i < end) ? 'table-row' : 'none'; });
    const info = document.getElementById('loot-page-info');
    if (info) info.innerText = lootCurPage + ' / ' + lootTotal;
}

function initLootPagination() {
    const rows = document.querySelectorAll('#spawnModal .loot-row');
    if (rows.length === 0) return;
    lootTotal = Math.ceil(rows.length / lootLimit);
    lootCurPage = 1;
    if (lootTotal > 0) { changeLootPage(0); }
}

let locCurPage = 1;
const locLimit = 8;
let locTotal = 1;

function changeLocPage(dir) {
    locCurPage += dir;
    if (locCurPage < 1) locCurPage = 1;
    if (locCurPage > locTotal) locCurPage = locTotal;
    const rows = document.querySelectorAll('#spawnModal .loc-row');
    const start = (locCurPage - 1) * locLimit;
    const end = start + locLimit;
    rows.forEach((r, i) => { r.style.display = (i >= start && i < end) ? 'table-row' : 'none'; });
    const info = document.getElementById('loc-page-info');
    if (info) info.innerText = locCurPage + ' / ' + locTotal;
}

function initLocPagination() {
    const rows = document.querySelectorAll('#spawnModal .loc-row');
    if (rows.length === 0) return;
    locTotal = Math.ceil(rows.length / locLimit);
    locCurPage = 1;
    if (locTotal > 0) { changeLocPage(0); }
}

function showDetails(name, region, level) {
    document.getElementById('spawnModal').style.display = 'flex';
    document.getElementById('modalTitle').textContent = name + ' — ' + region + ' (Lvl ' + level + ')';
    document.getElementById('modalBody').innerHTML = '<p class="bestiary-loading"><?= t('pve_bestiary.js.loading', [], 'Consulting the archives...'); ?></p>';

    const fd = new FormData();
    fd.append('get_spawns', '1');
    fd.append('mob_name',   name);
    fd.append('region',     region);
    fd.append('level',      level);
    fd.append('csrf_token', BESTIARY_CSRF);

    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.text())
        .then(html => {
            document.getElementById('modalBody').innerHTML = html;
            initLootPagination();
            initLocPagination();
        })
        .catch(() => { document.getElementById('modalBody').innerHTML = '<p class="bestiary-error"><?= t('pve_bestiary.js.error', [], 'Protocol error.'); ?></p>'; });
}

function closeModal() {
    document.getElementById('spawnModal').style.display = 'none';
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
</script>
