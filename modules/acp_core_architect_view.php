<?php
if (!defined('IN_ACP')) exit;

if (!isset($userPriv))      $userPriv      = (int)($_SESSION['priv_level'] ?? 0);
if (!isset($currentUserId)) $currentUserId = (int)($_SESSION['user_id']    ?? 0);

if ($userPriv < 3) return;

// ── Currency Helper ───────────────────────────────────────────
function ca_to_copper(int $p, int $g, int $s, int $c): int {
    return ($p * 100000) + ($g * 10000) + ($s * 100) + $c;
}
function ca_copper_str(float $v): string {
    $p = floor($v / 100000); $g = floor(($v % 100000) / 10000);
    $s = floor(($v % 10000) / 100); $c = $v % 100;
    $r = [];
    if ($p) $r[] = number_format($p).'p';
    if ($g) $r[] = $g.'g';
    if ($s) $r[] = $s.'s';
    if ($c) $r[] = $c.'c';
    return $r ? implode(' ', $r) : '0c';
}

$db->exec("CREATE TABLE IF NOT EXISTS ca_wealth_snapshot (
    id INT AUTO_INCREMENT PRIMARY KEY,
    taken_at DATETIME NOT NULL,
    total_chars INT, total_wealth BIGINT, avg_wealth BIGINT,
    active_7d INT, active_30d INT, gini_index DECIMAL(5,4)
) ENGINE=InnoDB");

$db->exec("CREATE TABLE IF NOT EXISTS ca_sim_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT, admin_name VARCHAR(64),
    sim_type VARCHAR(16), sim_target VARCHAR(16), sim_factor DECIMAL(4,2),
    affected INT, applied TINYINT(1) DEFAULT 0, created_at DATETIME NOT NULL
) ENGINE=InnoDB");

// ── Time range filter (flexible instead of hardcoded 7/30) ────────────
$allowed_ranges = [1, 7, 14, 30, 90];
$range_a = (int)($_GET['range_a'] ?? 7);
$range_b = (int)($_GET['range_b'] ?? 30);
if (!in_array($range_a, $allowed_ranges, true)) $range_a = 7;
if (!in_array($range_b, $allowed_ranges, true)) $range_b = 30;

// ── Analytics (base) ────────────────────────────────────────
$eco = $db->query("
    SELECT COUNT(*) total_chars,
           SUM(Platinum) total_plat, SUM(Gold) total_gold, SUM(Silver) total_silver, SUM(Copper) total_copper,
           AVG(Level) avg_level, MAX(Level) max_level,
           SUM(RealmPoints) total_rp, SUM(BountyPoints) total_bp,
           AVG(RealmPoints) avg_rp, AVG(BountyPoints) avg_bp
    FROM dolcharacters WHERE Level > 0
")->fetch(PDO::FETCH_ASSOC) ?: [];

$eco['total_wealth'] = ca_to_copper((int)($eco['total_plat']??0),(int)($eco['total_gold']??0),(int)($eco['total_silver']??0),(int)($eco['total_copper']??0));
$eco['avg_wealth']   = $eco['total_chars'] > 0 ? round($eco['total_wealth'] / $eco['total_chars']) : 0;

$stmt = $db->prepare("SELECT COUNT(DISTINCT AccountName) FROM dolcharacters WHERE LastPlayed >= DATE_SUB(NOW(), INTERVAL :d DAY)");
$stmt->execute([':d' => $range_a]);
$active_a = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(DISTINCT AccountName) FROM dolcharacters WHERE LastPlayed >= DATE_SUB(NOW(), INTERVAL :d DAY)");
$stmt->execute([':d' => $range_b]);
$active_b = (int)$stmt->fetchColumn();

// Item economy incl. crafted/dropped ratio + top item types
$inv = $db->query("SELECT COUNT(*) total_items, SUM(CASE WHEN IsCrafted=1 THEN 1 ELSE 0 END) crafted FROM inventory WHERE SlotPosition < 1000")->fetch(PDO::FETCH_ASSOC) ?: [];
$inv['dropped'] = (int)($inv['total_items']??0) - (int)($inv['crafted']??0);

$top_item_types = [];
try {
    $r = $db->query("
        SELECT Object_Type, COUNT(*) cnt FROM inventory
        WHERE SlotPosition < 1000
        GROUP BY Object_Type ORDER BY cnt DESC LIMIT 8
    ");
    while ($row = $r->fetch(PDO::FETCH_ASSOC)) $top_item_types[] = $row;
} catch (Throwable $e) { /* Column may be named differently - optional */ }

// ── Wealth buckets: via SQL instead of a PHP loop over all chars ─────
$wb_row = $db->query("
    SELECT
        SUM(CASE WHEN Platinum = 0 THEN 1 ELSE 0 END) b0,
        SUM(CASE WHEN Platinum > 0 AND Platinum < 10 THEN 1 ELSE 0 END) b1,
        SUM(CASE WHEN Platinum >= 10 AND Platinum < 100 THEN 1 ELSE 0 END) b2,
        SUM(CASE WHEN Platinum >= 100 THEN 1 ELSE 0 END) b3
    FROM dolcharacters WHERE Level > 0
")->fetch(PDO::FETCH_ASSOC) ?: [];
$wb = [
    '0p'      => (int)($wb_row['b0']??0),
    '1-10p'   => (int)($wb_row['b1']??0),
    '10-100p' => (int)($wb_row['b2']??0),
    '100p+'   => (int)($wb_row['b3']??0),
];

// RP/BP buckets, analogous (RvR balance)
$rpb_row = $db->query("
    SELECT
        SUM(CASE WHEN RealmPoints = 0 THEN 1 ELSE 0 END) b0,
        SUM(CASE WHEN RealmPoints > 0 AND RealmPoints < 50000 THEN 1 ELSE 0 END) b1,
        SUM(CASE WHEN RealmPoints >= 50000 AND RealmPoints < 500000 THEN 1 ELSE 0 END) b2,
        SUM(CASE WHEN RealmPoints >= 500000 THEN 1 ELSE 0 END) b3
    FROM dolcharacters WHERE Level > 0
")->fetch(PDO::FETCH_ASSOC) ?: [];
$rpb = [
    '0'          => (int)($rpb_row['b0']??0),
    '1-50k'      => (int)($rpb_row['b1']??0),
    '50k-500k'   => (int)($rpb_row['b2']??0),
    '500k+'      => (int)($rpb_row['b3']??0),
];

// Level dist
$level_dist = [];
$r = $db->query("
    SELECT CASE
        WHEN Level BETWEEN 1  AND 10 THEN '1-10'
        WHEN Level BETWEEN 11 AND 20 THEN '11-20'
        WHEN Level BETWEEN 21 AND 30 THEN '21-30'
        WHEN Level BETWEEN 31 AND 40 THEN '31-40'
        WHEN Level BETWEEN 41 AND 50 THEN '41-50'
        ELSE '50+' END bracket,
        COUNT(*) cnt
    FROM dolcharacters WHERE Level > 0
    GROUP BY bracket ORDER BY MIN(Level)
");
while ($row = $r->fetch(PDO::FETCH_ASSOC)) $level_dist[$row['bracket']] = (int)$row['cnt'];

// Realm dist
$realm_dist = [1=>0,2=>0,3=>0];
$r = $db->query("SELECT Realm, COUNT(*) cnt FROM dolcharacters WHERE Level > 0 GROUP BY Realm");
while ($row = $r->fetch(PDO::FETCH_ASSOC)) $realm_dist[(int)$row['Realm']] = (int)$row['cnt'];

// Class/race distribution per realm (balance diagnostics)
$class_dist = [1=>[], 2=>[], 3=>[]];
try {
    $r = $db->query("SELECT Realm, Class, COUNT(*) cnt FROM dolcharacters WHERE Level > 0 GROUP BY Realm, Class ORDER BY Realm, cnt DESC");
    while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
        $re = (int)$row['Realm'];
        if (isset($class_dist[$re]) && count($class_dist[$re]) < 6) {
            $class_dist[$re][] = ['class' => (int)$row['Class'], 'cnt' => (int)$row['cnt']];
        }
    }
} catch (Throwable $e) { /* Column may differ - optional */ }

// Top 10 wealth
$top_rich = $db->query("SELECT Name, AccountName, Realm, Level, Platinum, Gold FROM dolcharacters WHERE Level > 0 ORDER BY Platinum DESC, Gold DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

// ── Gini coefficient (wealth inequality) ────────────────────────
// Pull all wealth values (as copper), sorted - for very large shards, consider limiting to a sample.
function ca_gini(array $values): float {
    $n = count($values);
    if ($n === 0) return 0.0;
    sort($values);
    $cum = 0.0;
    foreach ($values as $i => $v) $cum += ($i + 1) * $v;
    $sum = array_sum($values);
    if ($sum <= 0) return 0.0;
    return round((2 * $cum) / ($n * $sum) - ($n + 1) / $n, 4);
}
$wealth_values = [];
$r = $db->query("SELECT Platinum, Gold, Silver, Copper FROM dolcharacters WHERE Level > 0");
while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
    $wealth_values[] = ca_to_copper((int)$row['Platinum'],(int)$row['Gold'],(int)$row['Silver'],(int)$row['Copper']);
}
$gini = ca_gini($wealth_values);
// Median (more robust than AVG for simulation purposes)
sort($wealth_values);
$cnt_w = count($wealth_values);
$median_wealth = $cnt_w > 0 ? $wealth_values[(int)floor($cnt_w / 2)] : 0;
$p90_wealth    = $cnt_w > 0 ? $wealth_values[(int)floor($cnt_w * 0.9)] : 0;
unset($wealth_values);

// ── Wealth snapshot for the inflation log (max 1x per day, automatic) ──
$last_snap = $db->query("SELECT taken_at FROM ca_wealth_snapshot ORDER BY id DESC LIMIT 1")->fetchColumn();
if (!$last_snap || date('Y-m-d', strtotime($last_snap)) !== date('Y-m-d')) {
    $stmt = $db->prepare("INSERT INTO ca_wealth_snapshot (taken_at,total_chars,total_wealth,avg_wealth,active_7d,active_30d,gini_index) VALUES (NOW(),:tc,:tw,:aw,:a7,:a30,:gi)");
    $stmt->execute([
        ':tc' => (int)($eco['total_chars']??0), ':tw' => $eco['total_wealth'], ':aw' => $eco['avg_wealth'],
        ':a7' => $active_a, ':a30' => $active_b, ':gi' => $gini,
    ]);
}
$trend = $db->query("SELECT taken_at, total_wealth, avg_wealth, gini_index FROM ca_wealth_snapshot ORDER BY taken_at ASC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);

// ── CSV export (top rich / distributions) ────────────────────────
if (isset($_GET['export']) && in_array($_GET['export'], ['top_rich','wealth_buckets','level_dist'], true)) {
    checkToken($_GET['csrf_token'] ?? '');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="core_architect_' . $_GET['export'] . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    if ($_GET['export'] === 'top_rich') {
        fputcsv($out, [t('core_architect.csv.rank', [], 'Rank'), t('core_architect.csv.name', [], 'Name'), t('core_architect.csv.account', [], 'Account'), t('core_architect.csv.realm', [], 'Realm'), t('core_architect.csv.level', [], 'Level'), t('core_architect.csv.platinum', [], 'Platinum'), t('core_architect.csv.gold', [], 'Gold')]);
        foreach ($top_rich as $i => $c) fputcsv($out, [$i+1,$c['Name'],$c['AccountName'],$c['Realm'],$c['Level'],$c['Platinum'],$c['Gold']]);
    } elseif ($_GET['export'] === 'wealth_buckets') {
        fputcsv($out, [t('core_architect.csv.bucket', [], 'Bucket'), t('core_architect.csv.count', [], 'Count')]);
        foreach ($wb as $k => $v) fputcsv($out, [$k, $v]);
    } else {
        fputcsv($out, [t('core_architect.csv.level_bracket', [], 'Level-Bracket'), t('core_architect.csv.count', [], 'Count')]);
        foreach ($level_dist as $k => $v) fputcsv($out, [$k, $v]);
    }
    fclose($out);
    exit;
}

// ── Simulation POST (incl. median/percentile + optional apply) ──
$sim_result = null;
$apply_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_simulation'])) {
    checkToken($_POST['csrf_token'] ?? '');
    $sim_type   = $_POST['sim_type']   ?? 'exp';
    $sim_factor = max(0.1, min(10.0, (float)($_POST['sim_factor'] ?? 1.0)));
    $sim_target = $_POST['sim_target'] ?? 'all';
    $rf = ['albion'=>1,'midgard'=>2,'hibernia'=>3];

    $col_map = ['exp' => 'Experience', 'rp' => 'RealmPoints', 'bp' => 'BountyPoints', 'gold' => 'Platinum'];
    $level_where = ($sim_type === 'exp') ? 'Level>0 AND Level<50' : (($sim_type === 'gold') ? 'Level>0' : 'Level=50');
    $col = $col_map[$sim_type] ?? $col_map['exp'];

    $sql = "SELECT AVG($col) v, COUNT(*) c FROM dolcharacters WHERE $level_where";
    $params = [];
    if (isset($rf[$sim_target])) { $sql .= " AND Realm = :realm"; $params[':realm'] = $rf[$sim_target]; }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $base = $stmt->fetch(PDO::FETCH_ASSOC);
    $now  = (float)($base['v'] ?? 0);

    // Median/P90 as well (more robust against whale distortion of the AVG)
    $sql2 = "SELECT $col v FROM dolcharacters WHERE $level_where" . (isset($rf[$sim_target]) ? " AND Realm = :realm" : '');
    $stmt2 = $db->prepare($sql2);
    $stmt2->execute($params);
    $vals = array_map(fn($r) => (float)$r['v'], $stmt2->fetchAll(PDO::FETCH_ASSOC));
    sort($vals);
    $cnt = count($vals);
    $median = $cnt ? $vals[(int)floor($cnt/2)] : 0;
    $p90    = $cnt ? $vals[(int)floor($cnt*0.9)] : 0;

    $sim_result = [
        'type'    => strtoupper($sim_type).' Rate',
        'factor'  => $sim_factor,
        'target'  => ucfirst($sim_target),
        'realm_id'=> $rf[$sim_target] ?? null,
        'affected'=> (int)($base['c'] ?? 0),
        'now'     => $now,
        'sim'     => $now * $sim_factor,
        'median'  => $median,
        'median_sim' => $median * $sim_factor,
        'p90'     => $p90,
        'p90_sim' => $p90 * $sim_factor,
        'delta'   => round(($sim_factor - 1) * 100),
        'col'     => $col,
        'where'   => $level_where,
    ];

    // Always log the simulation (audit trail), even without applying
    $stmt = $db->prepare("INSERT INTO ca_sim_log (admin_id,admin_name,sim_type,sim_target,sim_factor,affected,applied,created_at) VALUES (:aid,:an,:st,:tg,:sf,:af,:ap,NOW())");
    $stmt->execute([
        ':aid' => $currentUserId, ':an' => $_SESSION['username'] ?? ('id'.$currentUserId),
        ':st' => $sim_type, ':tg' => $sim_target, ':sf' => $sim_factor, ':af' => (int)($base['c']??0),
        ':ap' => 0,
    ]);

    // ── Apply: real DB update, only with an explicit second confirmation flag ──
    if (isset($_POST['confirm_apply']) && $_POST['confirm_apply'] === 'yes') {
        $sql3 = "UPDATE dolcharacters SET $col = $col * :factor WHERE $level_where" . (isset($rf[$sim_target]) ? " AND Realm = :realm" : '');
        $stmt3 = $db->prepare($sql3);
        $applyParams = [':factor' => $sim_factor] + $params;
        $stmt3->execute($applyParams);
        $apply_result = ['rows' => $stmt3->rowCount()];

        $stmt = $db->prepare("INSERT INTO ca_sim_log (admin_id,admin_name,sim_type,sim_target,sim_factor,affected,applied,created_at) VALUES (:aid,:an,:st,:tg,:sf,:af,1,NOW())");
        $stmt->execute([
            ':aid' => $currentUserId, ':an' => $_SESSION['username'] ?? ('id'.$currentUserId),
            ':st' => $sim_type, ':tg' => $sim_target, ':sf' => $sim_factor, ':af' => $apply_result['rows'],
        ]);
    }
}

// Latest simulation log entries for the audit display
$sim_history = $db->query("SELECT admin_name, sim_type, sim_target, sim_factor, affected, applied, created_at FROM ca_sim_log ORDER BY id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

$csrf   = generateToken();
$rnames = [1=>'Albion',2=>'Midgard',3=>'Hibernia'];
$rcols  = [1=>'#c0392b',2=>'#2980b9',3=>'#27ae60'];
?>
<!-- Mode bar -->
<div style="display:flex; align-items:center; gap:12px; margin-bottom:20px; flex-wrap:wrap;">
    <span class="ca2-mode ca2-mode--live"><?= t('core_architect.mode.live', [], '● LIVE') ?></span>
    <span style="font-size:0.62em; color:var(--parch-muted); font-family:sans-serif;">
        <?= number_format((int)($eco['total_chars']??0)) ?> <?= t('core_architect.chars_indexed', [], 'characters indexed') ?> &nbsp;·&nbsp; <?= date('d.m.Y H:i') ?>
    </span>
    <span style="font-size:0.62em; color:var(--parch-muted); font-family:sans-serif;">
        <?= t('core_architect.gini.label', [], 'Gini:') ?> <strong style="color:var(--gold);"><?= number_format($gini, 3) ?></strong>
        <span title="<?= t('core_architect.gini.tooltip', [], '0 = perfect equality, 1 = maximum inequality') ?>" style="opacity:.6; cursor:help;"> (?)</span>
    </span>
</div>

<!-- Time range filter -->
<form method="GET" style="display:flex; gap:8px; align-items:center; margin-bottom:14px; font-family:sans-serif; font-size:0.65em;">
    <input type="hidden" name="s" value="core_architect">
    <label><?= t('core_architect.filter.active_window_a', [], 'Active Window A:') ?>
        <select name="range_a" onchange="this.form.submit()" class="ca2-select">
            <?php foreach ($allowed_ranges as $ra): ?>
            <option value="<?= $ra ?>" <?= $ra===$range_a?'selected':'' ?>><?= $ra ?><?= t('core_architect.filter.days_short', [], 'd') ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label><?= t('core_architect.filter.active_window_b', [], 'Active Window B:') ?>
        <select name="range_b" onchange="this.form.submit()" class="ca2-select">
            <?php foreach ($allowed_ranges as $rb): ?>
            <option value="<?= $rb ?>" <?= $rb===$range_b?'selected':'' ?>><?= $rb ?><?= t('core_architect.filter.days_short', [], 'd') ?></option>
            <?php endforeach; ?>
        </select>
    </label>
</form>

<!-- ══ ECONOMY ANALYTICS ══ -->
<div class="ca2-section-head"><i class="fas fa-coins"></i> <?= t('core_architect.section.economy', [], 'Economy Analytics') ?></div>

<div class="ca2-kpi-row">
    <div class="ca2-kpi">
        <div class="ca2-kpi-val"><?= ca_copper_str($eco['total_wealth']) ?></div>
        <div class="ca2-kpi-lbl"><?= t('core_architect.kpi.total_wealth', [], 'Total Wealth') ?></div>
    </div>
    <div class="ca2-kpi">
        <div class="ca2-kpi-val"><?= ca_copper_str($eco['avg_wealth']) ?></div>
        <div class="ca2-kpi-lbl"><?= t('core_architect.kpi.avg_char', [], 'Avg / Char') ?></div>
    </div>
    <div class="ca2-kpi">
        <div class="ca2-kpi-val"><?= ca_copper_str($median_wealth) ?></div>
        <div class="ca2-kpi-lbl"><?= t('core_architect.kpi.median_wealth', [], 'Median Wealth') ?></div>
        <div class="ca2-kpi-sub"><?= t('core_architect.kpi.p90', [], 'P90:') ?> <?= ca_copper_str($p90_wealth) ?></div>
    </div>
    <div class="ca2-kpi">
        <div class="ca2-kpi-val ca2-kpi-val--green"><?= number_format($active_a) ?></div>
        <div class="ca2-kpi-lbl"><?= t('core_architect.kpi.active_accs', [], 'Active Accs') ?></div>
        <div class="ca2-kpi-sub"><?= t('core_architect.kpi.last', [], 'Last') ?> <?= $range_a ?> <?= t('core_architect.kpi.days', [], 'days') ?></div>
    </div>
    <div class="ca2-kpi">
        <div class="ca2-kpi-val ca2-kpi-val--blue"><?= number_format($active_b) ?></div>
        <div class="ca2-kpi-lbl"><?= t('core_architect.kpi.active_accs', [], 'Active Accs') ?></div>
        <div class="ca2-kpi-sub"><?= t('core_architect.kpi.last', [], 'Last') ?> <?= $range_b ?> <?= t('core_architect.kpi.days', [], 'days') ?></div>
    </div>
    <div class="ca2-kpi">
        <div class="ca2-kpi-val"><?= round((float)($eco['avg_level']??0),1) ?></div>
        <div class="ca2-kpi-lbl"><?= t('core_architect.kpi.avg_level', [], 'Avg Level') ?></div>
        <div class="ca2-kpi-sub"><?= t('core_architect.kpi.max', [], 'max') ?> <?= (int)($eco['max_level']??0) ?></div>
    </div>
    <div class="ca2-kpi">
        <div class="ca2-kpi-val"><?= number_format((int)($inv['total_items']??0)) ?></div>
        <div class="ca2-kpi-lbl"><?= t('core_architect.kpi.items', [], 'Items') ?></div>
        <div class="ca2-kpi-sub"><?= number_format((int)($inv['crafted']??0)) ?> <?= t('core_architect.kpi.crafted', [], 'crafted') ?> / <?= number_format((int)($inv['dropped']??0)) ?> <?= t('core_architect.kpi.dropped', [], 'Drop') ?></div>
    </div>
    <div class="ca2-kpi">
        <div class="ca2-kpi-val ca2-kpi-val--blue"><?= number_format((int)($eco['total_rp']??0)) ?></div>
        <div class="ca2-kpi-lbl"><?= t('core_architect.kpi.total_rp', [], 'Total RP') ?></div>
    </div>
    <div class="ca2-kpi">
        <div class="ca2-kpi-val"><?= number_format((int)($eco['total_bp']??0)) ?></div>
        <div class="ca2-kpi-lbl"><?= t('core_architect.kpi.total_bp', [], 'Total BP') ?></div>
    </div>
</div>

<!-- Charts -->
<div class="ca2-charts">
    <div class="ca2-chart">
        <div class="ca2-chart-title"><i class="fas fa-coins" style="margin-right:5px;"></i><?= t('core_architect.chart.wealth_dist', [], 'Wealth Distribution') ?></div>
        <canvas id="ca-wealth" height="160"></canvas>
    </div>
    <div class="ca2-chart">
        <div class="ca2-chart-title"><i class="fas fa-chart-bar" style="margin-right:5px;"></i><?= t('core_architect.chart.level_dist', [], 'Level Distribution') ?></div>
        <canvas id="ca-levels" height="160"></canvas>
    </div>
    <div class="ca2-chart">
        <div class="ca2-chart-title"><i class="fas fa-shield-alt" style="margin-right:5px;"></i><?= t('core_architect.chart.realm_pop', [], 'Realm Population') ?></div>
        <canvas id="ca-realm" height="160"></canvas>
    </div>
    <div class="ca2-chart">
        <div class="ca2-chart-title"><i class="fas fa-medal" style="margin-right:5px;"></i><?= t('core_architect.chart.rp_dist', [], 'RP Distribution') ?></div>
        <canvas id="ca-rp" height="160"></canvas>
    </div>
    <div class="ca2-chart">
        <div class="ca2-chart-title"><i class="fas fa-chart-line" style="margin-right:5px;"></i><?= t('core_architect.chart.wealth_trend', [], 'Wealth Trend (Inflation)') ?></div>
        <canvas id="ca-trend" height="160"></canvas>
    </div>
    <?php if (!empty($top_item_types)): ?>
    <div class="ca2-chart">
        <div class="ca2-chart-title"><i class="fas fa-box" style="margin-right:5px;"></i><?= t('core_architect.chart.top_items', [], 'Top Item Types in Circulation') ?></div>
        <canvas id="ca-items" height="160"></canvas>
    </div>
    <?php endif; ?>
</div>

<!-- Class/race distribution per realm -->
<?php if (array_filter($class_dist)): ?>
<div class="ca2-section-head"><i class="fas fa-users"></i> <?= t('core_architect.chart.class_dist', [], 'Class Distribution per Realm (Top 6)') ?></div>
<div class="ca2-charts">
    <?php foreach ($class_dist as $rid => $rows): if (empty($rows)) continue; ?>
    <div class="ca2-chart">
        <div class="ca2-chart-title" style="color:<?= $rcols[$rid] ?>;"><?= $rnames[$rid] ?></div>
        <canvas id="ca-class-<?= $rid ?>" height="160"></canvas>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Top Wealth -->
<div style="background:var(--bg-2); border:1px solid var(--border-1); margin-bottom:28px;">
    <div style="padding:10px 14px; border-bottom:1px solid var(--border-0); display:flex; justify-content:space-between; align-items:center;">
        <span style="font-family:'Cinzel',serif; font-size:0.52em; letter-spacing:2px; color:var(--gold-muted); text-transform:uppercase;">
            <i class="fas fa-crown" style="margin-right:6px; opacity:0.5;"></i><?= t('core_architect.top10', [], 'Top 10 Wealthiest Characters') ?>
        </span>
        <a href="acp.php?s=core_architect&export=top_rich&csrf_token=<?= urlencode($csrf) ?>" class="ca2-btn" style="font-size:0.55em; padding:4px 10px;">
            <i class="fas fa-file-csv"></i> CSV
        </a>
    </div>
    <table class="ca2-table">
        <thead><tr>
            <th>#</th>
            <th><?= t('core_architect.table.name', [], 'Name') ?></th>
            <th><?= t('core_architect.table.account', [], 'Account') ?></th>
            <th><?= t('core_architect.table.realm', [], 'Realm') ?></th>
            <th><?= t('core_architect.table.level', [], 'Lvl') ?></th>
            <th><?= t('core_architect.table.wealth', [], 'Wealth') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($top_rich as $i => $char):
            $rc  = $rcols[(int)$char['Realm']] ?? '#444';
            $rn  = $rnames[(int)$char['Realm']] ?? '?';
            $cls = $i===0?'ca2-rank--1':($i===1?'ca2-rank--2':($i===2?'ca2-rank--3':''));
        ?>
        <tr>
            <td><span class="ca2-rank <?= $cls ?>"><?= $i+1 ?></span></td>
            <td class="acp-s-f9ae3ca6"><?= h($char['Name']) ?></td>
            <td class="acp-s-2506005f"><?= h($char['AccountName']) ?></td>
            <td><span style="color:<?= $rc ?>; font-size:0.85em;"><?= $rn ?></span></td>
            <td class="acp-s-26deb9b6"><?= (int)$char['Level'] ?></td>
            <td class="acp-s-574f38d7">
                <?= number_format((int)$char['Platinum']) ?>p <?= (int)$char['Gold'] ?>g
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ══ IMPACT SIMULATION ══ -->
<div class="ca2-section-head">
    <i class="fas fa-flask"></i> <?= t('core_architect.section.simulation', [], 'Impact Simulation') ?>
    <span class="ca2-mode ca2-mode--draft" style="margin-left:auto;"><?= t('core_architect.mode.draft', [], 'DRAFT MODE') ?></span>
</div>

<div class="ca2-sim-grid">
    <div class="ca2-sim-form">
        <form method="POST" action="acp.php?s=core_architect" id="ca-sim-form" onsubmit="return ca_confirm_apply(event)">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="run_simulation" value="1">
            <input type="hidden" name="confirm_apply" id="ca-confirm-apply" value="no">
            <div class="ca2-field">
                <label class="ca2-label"><?= t('core_architect.form.type', [], 'Type') ?></label>
                <select name="sim_type" class="ca2-select">
                    <option value="exp"  <?= (($_POST['sim_type']??'')==='exp')  ?'selected':'' ?>><?= t('core_architect.form.exp_rate', [], 'EXP Rate') ?></option>
                    <option value="rp"   <?= (($_POST['sim_type']??'')==='rp')   ?'selected':'' ?>><?= t('core_architect.form.rp_rate', [], 'RP Rate') ?></option>
                    <option value="bp"   <?= (($_POST['sim_type']??'')==='bp')   ?'selected':'' ?>><?= t('core_architect.form.bp_rate', [], 'BP Rate') ?></option>
                    <option value="gold" <?= (($_POST['sim_type']??'')==='gold') ?'selected':'' ?>><?= t('core_architect.form.gold_drop', [], 'Gold Drop') ?></option>
                </select>
            </div>
            <div class="ca2-field">
                <label class="ca2-label"><?= t('core_architect.form.realm', [], 'Realm') ?></label>
                <select name="sim_target" class="ca2-select">
                    <option value="all" <?= (($_POST['sim_target']??'all')==='all') ?'selected':'' ?>><?= t('core_architect.form.all_realms', [], 'All Realms') ?></option>
                    <option value="albion" <?= (($_POST['sim_target']??'')==='albion') ?'selected':'' ?>>Albion</option>
                    <option value="midgard" <?= (($_POST['sim_target']??'')==='midgard') ?'selected':'' ?>>Midgard</option>
                    <option value="hibernia" <?= (($_POST['sim_target']??'')==='hibernia') ?'selected':'' ?>>Hibernia</option>
                </select>
            </div>
            <div class="ca2-field">
                <label class="ca2-label"><?= t('core_architect.form.multiplier', [], 'Multiplier') ?>
                    <span id="ca-factor-lbl" style="color:var(--gold); float:right;">
                        <?= number_format((float)($_POST['sim_factor']??1.0),1) ?>x
                    </span>
                </label>
                <div class="ca2-factor-row">
                    <span style="font-size:0.55em; color:var(--border-2); font-family:'Cinzel',serif;">0.1x</span>
                    <input type="range" name="sim_factor" class="ca2-range" min="0.1" max="10" step="0.1"
                           value="<?= number_format((float)($_POST['sim_factor']??1.0),1) ?>"
                           oninput="document.getElementById('ca-factor-lbl').textContent=parseFloat(this.value).toFixed(1)+'x'">
                    <span style="font-size:0.55em; color:var(--border-2); font-family:'Cinzel',serif;">10x</span>
                </div>
            </div>
            <button type="submit" name="do_sim" value="1" class="ca2-btn">
                <i class="fas fa-play" style="font-size:0.8em; margin-right:6px;"></i><?= t('core_architect.action.run_sim', [], 'Run Simulation') ?>
            </button>
            <?php if ($sim_result): ?>
            <button type="submit" name="do_apply" value="1" class="ca2-btn" style="background:#8a2020; margin-top:6px;" onclick="document.getElementById('ca-confirm-apply').value='yes';">
                <i class="fas fa-exclamation-triangle" style="font-size:0.8em; margin-right:6px;"></i><?= t('core_architect.action.apply_db', [], 'Apply to DB (permanent!)') ?>
            </button>
            <?php endif; ?>
        </form>
    </div>

    <div class="ca2-sim-result">
        <?php if ($apply_result): ?>
            <div style="color:#e07070; font-family:'Cinzel',serif; font-size:0.62em; margin-bottom:10px;">
                <i class="fas fa-check-circle"></i> <?= t('core_architect.sim.applied_prefix', [], 'Applied:') ?> <?= number_format($apply_result['rows']) ?> <?= t('core_architect.sim.applied_suffix', [], 'rows updated. Action recorded in audit log.') ?>
            </div>
        <?php endif; ?>
        <?php if ($sim_result): ?>
            <div style="font-family:'Cinzel',serif; font-size:0.62em; letter-spacing:1px; color:var(--gold); margin-bottom:12px;">
                <?= h($sim_result['type']) ?> · <?= $sim_result['factor'] ?>x · <?= $sim_result['target'] ?>
            </div>
            <div style="font-size:0.7em; font-family:'Cinzel',serif; letter-spacing:1px; margin-bottom:10px;"
                 class="<?= $sim_result['delta'] >= 0 ? 'ca2-delta-up' : 'ca2-delta-down' ?>">
                <i class="fas <?= $sim_result['delta'] >= 0 ? 'fa-arrow-up' : 'fa-arrow-down' ?>"></i>
                <?= abs($sim_result['delta']) ?>% <?= t('core_architect.delta.from_current', [], 'from current rate') ?>
            </div>
            <div class="ca2-res-row">
                <div class="ca2-res-item">
                    <div class="ca2-res-val"><?= number_format($sim_result['now']) ?></div>
                    <div class="ca2-res-lbl"><?= t('core_architect.result.current_avg', [], 'Current Avg.') ?></div>
                </div>
                <div class="ca2-res-item">
                    <div class="ca2-res-val ca2-res-val--sim"><?= number_format($sim_result['sim']) ?></div>
                    <div class="ca2-res-lbl"><?= t('core_architect.result.sim_avg', [], 'Simulated Avg.') ?></div>
                </div>
                <div class="ca2-res-item">
                    <div class="ca2-res-val"><?= number_format($sim_result['median']) ?> → <?= number_format($sim_result['median_sim']) ?></div>
                    <div class="ca2-res-lbl"><?= t('core_architect.result.median', [], 'Median (robust)') ?></div>
                </div>
                <div class="ca2-res-item">
                    <div class="ca2-res-val"><?= number_format($sim_result['p90']) ?> → <?= number_format($sim_result['p90_sim']) ?></div>
                    <div class="ca2-res-lbl"><?= t('core_architect.result.p90', [], 'P90') ?></div>
                </div>
                <div class="ca2-res-item">
                    <div class="ca2-res-val ca2-kpi-val--blue"><?= number_format($sim_result['affected']) ?></div>
                    <div class="ca2-res-lbl"><?= t('core_architect.result.chars_affected', [], 'Chars Affected') ?></div>
                </div>
                <div class="ca2-res-item">
                    <div class="ca2-res-val"><?= $sim_result['factor'] ?>x</div>
                    <div class="ca2-res-lbl"><?= t('core_architect.result.multiplier', [], 'Multiplier') ?></div>
                </div>
            </div>
            <div class="ca2-draft"><?= t('core_architect.draft.warning', [], '⚠ DRAFT — No changes applied to live server') ?> <?= t('core_architect.draft.warning_desc', [], 'Median/P90 show the real impact more robustly than the average (Whale effect).') ?></div>
        <?php else: ?>
            <div class="ca2-sim-empty">
                <i class="fas fa-flask" style="display:block; font-size:1.8em; margin-bottom:8px; opacity:0.15;"></i>
                <?= t('core_architect.sim.none', [], 'No simulation running') ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Sim-Audit-History -->
<div style="background:var(--bg-2); border:1px solid var(--border-1); margin-bottom:28px;">
    <div style="padding:10px 14px; border-bottom:1px solid var(--border-0); font-family:'Cinzel',serif; font-size:0.52em; letter-spacing:2px; color:var(--gold-muted); text-transform:uppercase;">
        <i class="fas fa-history" style="margin-right:6px; opacity:0.5;"></i><?= t('core_architect.audit.title', [], 'Recent Simulations (Audit)') ?>
    </div>
    <table class="ca2-table">
        <thead><tr><th><?= t('core_architect.audit.admin', [], 'Admin') ?></th><th><?= t('core_architect.audit.type', [], 'Type') ?></th><th><?= t('core_architect.audit.target', [], 'Target') ?></th><th><?= t('core_architect.audit.factor', [], 'Factor') ?></th><th><?= t('core_architect.audit.affected', [], 'Affected') ?></th><th><?= t('core_architect.audit.status', [], 'Status') ?></th><th><?= t('core_architect.audit.time', [], 'Time') ?></th></tr></thead>
        <tbody>
        <?php foreach ($sim_history as $h): ?>
        <tr>
            <td><?= h($h['admin_name']) ?></td>
            <td><?= strtoupper(h($h['sim_type'])) ?></td>
            <td><?= h($h['sim_target']) ?></td>
            <td><?= number_format((float)$h['sim_factor'],1) ?>x</td>
            <td><?= number_format((int)$h['affected']) ?></td>
            <td><?= $h['applied'] ? '<span class="acp-s-94294764">' . t('core_architect.audit.applied', [], 'Applied') . '</span>' : t('core_architect.audit.draft', [], 'Draft Only') ?></td>
            <td class="acp-s-8f244e56"><?= h($h['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$sim_history): ?>
        <tr><td colspan="7" class="acp-s-9cc97d20"><?= t('core_architect.audit.no_entries', [], 'No entries') ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
Chart.defaults.color='#504540';
Chart.defaults.borderColor='rgba(255,255,255,0.03)';
Chart.defaults.font.family="'Cinzel',serif";
Chart.defaults.font.size=9;

function ca_confirm_apply(ev) {
    const submitter = ev.submitter;
    if (submitter && submitter.name === 'do_apply') {
        return window.confirm('<?= addslashes(t('core_architect.js.confirm_apply', [], 'WARNING: This action writes the factor PERMANENTLY to the database and CANNOT be undone. Really proceed?')) ?>');
    }
    return true;
}

new Chart(document.getElementById('ca-wealth'),{type:'bar',data:{
    labels:<?= json_encode(array_keys($wb)) ?>,
    datasets:[{data:<?= json_encode(array_values($wb)) ?>,
    backgroundColor:['rgba(80,70,60,0.4)','rgba(200,160,64,0.2)','rgba(200,160,64,0.5)','rgba(200,160,64,0.85)'],
    borderColor:'transparent',borderRadius:0}]
},options:{responsive:true,plugins:{legend:{display:false}},scales:{
    x:{grid:{color:'rgba(255,255,255,0.02)'},ticks:{color:'#504540'}},
    y:{grid:{color:'rgba(255,255,255,0.02)'},ticks:{color:'#504540'}}
}}});

new Chart(document.getElementById('ca-levels'),{type:'bar',data:{
    labels:<?= json_encode(array_keys($level_dist)) ?>,
    datasets:[{data:<?= json_encode(array_values($level_dist)) ?>,
    backgroundColor:'rgba(104,152,184,0.45)',borderColor:'rgba(104,152,184,0.7)',borderWidth:1,borderRadius:0}]
},options:{responsive:true,plugins:{legend:{display:false}},scales:{
    x:{grid:{color:'rgba(255,255,255,0.02)'},ticks:{color:'#504540'}},
    y:{grid:{color:'rgba(255,255,255,0.02)'},ticks:{color:'#504540'}}
}}});

new Chart(document.getElementById('ca-realm'),{type:'doughnut',data:{
    labels:['Albion','Midgard','Hibernia'],
    datasets:[{data:[<?= (int)($realm_dist[1]??0) ?>,<?= (int)($realm_dist[2]??0) ?>,<?= (int)($realm_dist[3]??0) ?>],
    backgroundColor:['rgba(192,57,43,0.6)','rgba(41,128,185,0.6)','rgba(39,174,96,0.6)'],
    borderColor:'#22201c',borderWidth:2}]
},options:{responsive:true,cutout:'60%',plugins:{legend:{display:true,position:'bottom',labels:{color:'#504540',padding:10,font:{size:9}}}}}});

new Chart(document.getElementById('ca-rp'),{type:'bar',data:{
    labels:<?= json_encode(array_keys($rpb)) ?>,
    datasets:[{data:<?= json_encode(array_values($rpb)) ?>,
    backgroundColor:'rgba(155,89,182,0.5)',borderColor:'rgba(155,89,182,0.8)',borderWidth:1,borderRadius:0}]
},options:{responsive:true,plugins:{legend:{display:false}},scales:{
    x:{grid:{color:'rgba(255,255,255,0.02)'},ticks:{color:'#504540'}},
    y:{grid:{color:'rgba(255,255,255,0.02)'},ticks:{color:'#504540'}}
}}});

new Chart(document.getElementById('ca-trend'),{type:'line',data:{
    labels:<?= json_encode(array_map(fn($t)=>date('d.m',strtotime($t['taken_at'])), $trend)) ?>,
    datasets:[
        {label:'<?= addslashes(t('core_architect.chart.avg_wealth', [], 'Avg Wealth')) ?>',data:<?= json_encode(array_map(fn($t)=>(float)$t['avg_wealth'], $trend)) ?>,
         borderColor:'rgba(200,160,64,0.9)',backgroundColor:'rgba(200,160,64,0.15)',fill:true,tension:0.25,pointRadius:2},
    ]
},options:{responsive:true,plugins:{legend:{display:true,labels:{color:'#504540',font:{size:8}}}},scales:{
    x:{grid:{color:'rgba(255,255,255,0.02)'},ticks:{color:'#504540'}},
    y:{grid:{color:'rgba(255,255,255,0.02)'},ticks:{color:'#504540'}}
}}});

<?php if (!empty($top_item_types)): ?>
new Chart(document.getElementById('ca-items'),{type:'bar',data:{
    labels:<?= json_encode(array_map(fn($i)=> t('core_architect.chart.type', [], 'Type ') . $i['Object_Type'], $top_item_types)) ?>,
    datasets:[{data:<?= json_encode(array_map(fn($i)=>(int)$i['cnt'], $top_item_types)) ?>,
    backgroundColor:'rgba(90,160,120,0.5)',borderColor:'rgba(90,160,120,0.8)',borderWidth:1,borderRadius:0}]
},options:{indexAxis:'y',responsive:true,plugins:{legend:{display:false}},scales:{
    x:{grid:{color:'rgba(255,255,255,0.02)'},ticks:{color:'#504540'}},
    y:{grid:{color:'rgba(255,255,255,0.02)'},ticks:{color:'#504540'}}
}}});
<?php endif; ?>

<?php foreach ($class_dist as $rid => $rows): if (empty($rows)) continue; ?>
new Chart(document.getElementById('ca-class-<?= $rid ?>'),{type:'doughnut',data:{
    labels:<?= json_encode(array_map(fn($c)=> t('core_architect.chart.class', [], 'Class ') . $c['class'], $rows)) ?>,
    datasets:[{data:<?= json_encode(array_map(fn($c)=>$c['cnt'], $rows)) ?>,
    backgroundColor:['#c0392b','#2980b9','#27ae60','#8e44ad','#d4ac0d','#16a085'],
    borderColor:'#22201c',borderWidth:2}]
},options:{responsive:true,cutout:'55%',plugins:{legend:{display:true,position:'bottom',labels:{color:'#504540',padding:6,font:{size:8}}}}}});
<?php endforeach; ?>
</script>
<?php require_once __DIR__ . '/acp_all_views_ai_extensions.php'; ?>