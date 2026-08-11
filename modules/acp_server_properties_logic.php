<?php
if (!defined('IN_ACP')) exit;
if (!isset($userPriv))      $userPriv      = (int)($_SESSION['priv_level'] ?? 0);
if (!isset($currentUserId)) $currentUserId = (int)($_SESSION['user_id']    ?? 0);
if ($userPriv < 4) return;

$sp_msg   = '';
$sp_error = '';
$sp_csrf  = generateToken();

// ── Warning rules ─────────────────────────────────────────────
$sp_warn_rules = [
    'xp_rate'          => ['threshold' => 5],
    'rp_rate'          => ['threshold' => 5],
    'bp_rate'          => ['threshold' => 5],
    'artifact_xp_rate' => ['threshold' => 5],
    'loot_chance'      => ['threshold' => 3],
    'money_drop'       => ['threshold' => 3],
    'maxclient'        => ['threshold' => 500],
];

// ── Save ──────────────────────────────────────────────────────
if (isset($_POST['sp_save']) && $userPriv >= 4) {
    checkToken($_POST['csrf_token'] ?? '');
    $key   = trim($_POST['sp_key']   ?? '');
    $value = trim($_POST['sp_value'] ?? '');

    if (!empty($key)) {
        $stmt = $db->prepare("SELECT `Key`, Value FROM serverproperty WHERE `Key` = ?");
        $stmt->execute([$key]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($current) {
            try {
                $updateStmt = $db->prepare("UPDATE serverproperty SET Value = ?, LastTimeRowUpdated = NOW() WHERE `Key` = ?");
                $updateStmt->execute([$value, $key]);
                
                if ($updateStmt->rowCount() > 0) {
                    aldhran_log('SP_CHANGE', "ServerProperty '{$key}' changed from '{$current['Value']}' to '{$value}'", $currentUserId);
                    $sp_msg = "Property <strong>" . h($key) . "</strong> updated to <strong>" . h($value) . "</strong>";
                } elseif ($current['Value'] === $value) {
                    $sp_msg = "Property <strong>" . h($key) . "</strong> was already set to <strong>" . h($value) . "</strong>.";
                } else {
                    $sp_error = "Update failed: No rows affected in database. Check database mapping.";
                }
            } catch (\PDOException $e) {
                $sp_error = "Database Error: " . $e->getMessage();
            }
        } else {
            $sp_error = "Property not found: " . h($key);
        }
    }
}

// ── Pagination & filter params ────────────────────────────────
$sp_per_page   = 20;
$sp_page       = max(1, (int)($_GET['sp_page'] ?? 1));
$sp_cat_filter = trim($_GET['sp_cat'] ?? '');
$sp_search     = trim($_GET['sp_q']   ?? '');

// ── Load all properties ───────────────────────────────────────
$sp_all   = [];
$sp_cats  = [];

try {
    $r = $db->query(
        "SELECT Category, `Key`, Description, DefaultValue, Value, LastTimeRowUpdated
         FROM serverproperty
         ORDER BY Category ASC, `Key` ASC"
    );
    while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
        $sp_all[] = $row;
        $cat = $row['Category'];
        if (!isset($sp_cats[$cat])) $sp_cats[$cat] = 0;
        $sp_cats[$cat]++;
    }
} catch (\Throwable $e) {
    $sp_error = 'Failed to load: ' . $e->getMessage();
}

// ── Filter ────────────────────────────────────────────────────
$sp_filtered = $sp_all;

if (!empty($sp_cat_filter)) {
    $sp_filtered = array_filter($sp_filtered, fn($r) => strtolower($r['Category']) === strtolower($sp_cat_filter));
}

if (!empty($sp_search)) {
    $q = strtolower($sp_search);
    $sp_filtered = array_filter($sp_filtered, function($r) use ($q) {
        return str_contains(strtolower($r['Key']), $q)
            || str_contains(strtolower($r['Description'] ?? ''), $q);
    });
}

$sp_filtered   = array_values($sp_filtered);
$sp_total      = count($sp_filtered);
$sp_total_pages= max(1, (int)ceil($sp_total / $sp_per_page));
$sp_page       = min($sp_page, $sp_total_pages);
$sp_offset     = ($sp_page - 1) * $sp_per_page;
$sp_page_items = array_slice($sp_filtered, $sp_offset, $sp_per_page);

// Group current page items by category
$sp_grouped = [];
foreach ($sp_page_items as $row) {
    $sp_grouped[$row['Category']][] = $row;
}

// ── Helpers ───────────────────────────────────────────────────
function sp_value_type(string $val): string {
    $v = strtolower(trim($val));
    if ($v === 'true' || $v === 'false') return 'bool';
    if (is_numeric($val)) return 'number';
    return 'string';
}

function sp_warn_level(array $row, array $rules): string {
    if (sp_value_type($row['DefaultValue']) !== 'number') return 'none';
    if (!is_numeric($row['DefaultValue']) || !is_numeric($row['Value'])) return 'none';
    $def = (float)$row['DefaultValue'];
    $cur = (float)$row['Value'];
    if ($def == 0) return 'none';
    $ratio = $cur / $def;
    $key   = strtolower($row['Key']);
    foreach ($rules as $rkey => $rule) {
        if (str_contains($key, $rkey)) {
            if ($ratio >= $rule['threshold'])       return 'danger';
            if ($ratio >= $rule['threshold'] * 0.6) return 'warn';
        }
    }
    if ($ratio >= 10) return 'danger';
    if ($ratio >= 3)  return 'warn';
    if ($ratio <= 0.1 && $cur != $def) return 'warn';
    return 'none';
}

function sp_warn_msg(array $row): string {
    $key   = strtolower($row['Key']);
    $def   = (float)$row['DefaultValue'];
    $cur   = (float)$row['Value'];
    if ($def == 0) return '';
    $ratio = round($cur / $def, 1);
    if (str_contains($key, 'xp_rate') || str_contains($key, 'rp_rate') || str_contains($key, 'bp_rate')) {
        return "This rate is {$ratio}x the default. High rates can significantly accelerate progression — consider simulating the impact in <a href='acp.php?s=core_architect'>Core Architect</a> before applying.";
    }
    if (str_contains($key, 'drop') || str_contains($key, 'loot')) {
        return "Drop rate is {$ratio}x the default. Very high values may cause economy inflation — check <a href='acp.php?s=core_architect'>Core Architect → Detect Inflation</a>.";
    }
    if (str_contains($key, 'money') || str_contains($key, 'gold')) {
        return "Gold modifier is {$ratio}x the default. This may affect server economy balance.";
    }
    return "This value is {$ratio}x the default — significant deviation from intended server behavior.";
}

// Category colors & icons
$sp_cat_colors = [
    'system'      => '#6898b8', 'server'   => '#6898b8', 'account' => '#c5a059',
    'world'       => '#6aaa70', 'pve'      => '#b85050', 'pvp'     => '#c0392b',
    'guild'       => '#8e44ad', 'craft'    => '#d4a017', 'classes' => '#2980b9',
    'keeps'       => '#16a085', 'npc'      => '#7f8c8d', 'log'     => '#555',
    'rates'       => '#e67e22', 'spells'   => '#9b59b6', 'housing' => '#1abc9c',
    'salvage'     => '#95a5a6', 'startup'  => '#e74c3c', 'xmlautoload' => '#34495e',
];
$sp_cat_icons = [
    'system'   => 'fa-server',       'server'      => 'fa-server',
    'account'  => 'fa-user',         'world'       => 'fa-globe',
    'pve'      => 'fa-dragon',       'pvp'         => 'fa-shield-alt',
    'guild'    => 'fa-flag',         'craft'       => 'fa-hammer',
    'classes'  => 'fa-hat-wizard',   'keeps'       => 'fa-chess-rook',
    'npc'      => 'fa-robot',        'log'         => 'fa-file-alt',
    'rates'    => 'fa-tachometer-alt','spells'     => 'fa-magic',
    'housing'  => 'fa-home',         'salvage'     => 'fa-recycle',
    'startup'  => 'fa-power-off',    'xmlautoload' => 'fa-code',
];