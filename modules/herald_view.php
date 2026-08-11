<?php
if (!defined('IN_CMS')) exit;

if (($GLOBALS['cms_settings']['mod_herald'] ?? '1') === '0' && ($GLOBALS['userPriv'] ?? 0) < 4) {
    echo '<div class="info-msg">' . t('herald.error.unavailable', [], 'This section is currently not available.') . '</div>';
    return;
}

require_once __DIR__ . '/../includes/herald_helpers.php';

$keep_rows = daoc_game_herald_keeps($db);

$realm_counts = [1 => 0, 2 => 0, 3 => 0];
$keep_details = [];
foreach ($keep_rows as $k) {
    $r_id = (int)$k['Realm'];
    if (isset($realm_counts[$r_id])) $realm_counts[$r_id]++;
    $keep_details[] = $k;
}
$keep_total = max(1, array_sum($realm_counts));

// Population per realm (for might bar)
$pop = [1=>0,2=>0,3=>0];
try {
    $pop = daoc_game_herald_population($db);
} catch (Exception $e) {}

$top_chars = daoc_game_herald_top_characters($db, 15);

$r_info = herald_realm_info();
?>

<div class="herald-wrap">

    <!-- Realm might bar -->
    <div class="herald-might">
        <?php foreach ($r_info as $id => $realm):
            $keepPct = round($realm_counts[$id] / $keep_total * 100);
        ?>
        <div class="herald-might-realm" style="--r-col:<?= $realm['color'] ?>; --r-glow:<?= $realm['glow'] ?>;">
            <div class="herald-might-head">
                <img src="assets/img/realm_<?= h($realm['slug']) ?>.png" alt="<?= h($realm['name']) ?>" class="herald-might-icon" onerror="this.style.display='none'">
                <span class="herald-might-name"><?= strtoupper($realm['name']) ?></span>
            </div>
            <div class="herald-might-keeps"><?= $realm_counts[$id] ?><span><?= t('herald.keeps', [], 'Keeps') ?></span></div>
            <div class="herald-might-bar"><div class="herald-might-fill" style="width:<?= $keepPct ?>%;"></div></div>
            <div class="herald-might-pop"><i class="fas fa-users"></i> <?= number_format($pop[$id]) ?> <?= t('herald.warriors', [], 'warriors') ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Search bar -->
    <div class="herald-search">
        <h3><i class="fas fa-search"></i><?= t('herald.search_title', [], 'Character Search') ?></h3>
        <form action="index.php" method="GET">
            <input type="hidden" name="p" value="herald_char">
            <input type="text" name="name" placeholder="<?= t('herald.search_placeholder', [], 'Enter character name...') ?>" required class="herald-search-input">
            <button type="submit" class="herald-search-btn"><?= t('herald.search_btn', [], 'Search') ?></button>
        </form>
    </div>

    <div class="herald-grid">

        <!-- Top combatants -->
        <div class="herald-panel">
            <h3 class="herald-panel-head"><i class="fas fa-trophy"></i> <?= t('herald.top_combatants', [], 'Top Combatants') ?></h3>
            <div class="herald-ladder">
                <?php foreach ($top_chars as $i => $char):
                    $ri = $r_info[(int)$char['Realm']] ?? null;
                    $rank = herald_realm_rank((int)$char['RealmPoints']);
                    $rankNum = $i + 1;
                ?>
                <a href="?p=herald_char&name=<?= urlencode($char['Name']) ?>" class="herald-ladder-row" style="--r-col:<?= $ri['color'] ?? '#555' ?>;">
                    <div class="herald-ladder-rank <?= $rankNum <= 3 ? 'is-top' : '' ?>"><?= $rankNum ?></div>
                    <div class="herald-ladder-class"><img src="assets/img/realm_<?= h($ri['slug'] ?? 'neutral') ?>.png" alt="<?= h($ri['name'] ?? '') ?>" class="herald-realm-icon" onerror="this.style.display='none'"></div>
                    <div class="herald-ladder-main">
                        <div class="herald-ladder-name"><?= h($char['Name']) ?></div>
                        <div class="herald-ladder-sub">
                            <span class="herald-ladder-realm"><?= h($ri['name'] ?? '?') ?></span>
                            · <?= h(getClassName((int)$char['Class'])) ?> · L<?= (int)$char['Level'] ?>
                        </div>
                    </div>
                    <div class="herald-ladder-rr">
                        <div class="herald-rr-label"><?= $rank['label'] ?></div>
                        <div class="herald-rr-bar"><div class="herald-rr-fill" style="width:<?= $rank['pct'] ?>%;"></div></div>
                        <div class="herald-ladder-rp"><?= number_format((int)$char['RealmPoints']) ?></div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Frontier status -->
        <div class="herald-panel">
            <h3 class="herald-panel-head"><i class="fas fa-fort-awesome"></i> <?= t('herald.frontier_status', [], 'Frontier Status') ?></h3>
            <div class="herald-keeps">
                <?php foreach ($keep_details as $keep):
                    $ki = $r_info[(int)$keep['Realm']] ?? null;
                ?>
                    <div class="herald-keep" style="--r-col:<?= $ki['color'] ?? '#555' ?>;">
                        <div class="herald-keep-flag"><i class="fas <?= $ki['icon'] ?? 'fa-flag' ?>"></i></div>
                        <div class="herald-keep-info">
                            <div class="herald-keep-name"><?= h($keep['Name']) ?></div>
                            <div class="herald-keep-guild">
                                <?php if (!empty($keep['GuildName'])): ?>
                                    <i class="fas fa-shield-halved"></i> &lt;<?= h($keep['GuildName']) ?>&gt;
                                <?php else: ?>
                                    <span class="herald-keep-unclaimed"><?= t('herald.no_guild', [], 'Unclaimed') ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>
</div>
