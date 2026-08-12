<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) exit;
require_once __DIR__ . '/../includes/herald_helpers.php';

$char_name = isset($_GET['name']) ? trim($_GET['name']) : '';
if ($char_name === '') {
    echo "<div class='admin-box'>" . t('herald_char.error.no_char', [], 'No character specified.') . "</div>";
    return;
}

$c = daoc_game_herald_character($db, $char_name);

if (!$c) {
    echo "<div class='admin-box'>" . t('herald_char.error.not_found', [], 'Character not found.') . "</div>";
    return;
}

$r_info  = herald_realm_info();
$ri      = $r_info[(int)$c['Realm']] ?? ['name'=>'?','color'=>'#555','glow'=>'rgba(85,85,85,0.4)','slug'=>'neutral'];
$rank    = herald_realm_rank((int)$c['RealmPoints']);
$kills   = (int)($c['TotalKills'] ?? 0);
$deaths  = (int)($c['HeraldDeaths'] ?? 0);
$kd      = $deaths > 0 ? round($kills / $deaths, 2) : $kills;
$stats_private = !empty($c['IgnoreStatistics']) || !empty($c['IsAnonymous']);
?>

<div class="herald-char" style="--r-col:<?= $ri['color'] ?>; --r-glow:<?= $ri['glow'] ?>;">
    <a href="?p=herald" class="herald-back"><i class="fas fa-chevron-left"></i> <?= t('herald_char.back', [], 'Back to Herald') ?></a>

    <div class="herald-char-banner">
        <div class="herald-char-crest"><img src="assets/img/realm_<?= h($ri['slug']) ?>.png" alt="<?= h($ri['name']) ?>" class="herald-char-crest-icon" onerror="this.style.display='none'"></div>
        <div class="herald-char-id">
            <h1 class="herald-char-name"><?= h($c['Name']) ?></h1>
            <div class="herald-char-sub">
                <?= t('herald_char.level', [], 'Level') ?> <?= (int)$c['Level'] ?> <?= h(getClassName((int)$c['Class'])) ?>
                <span class="herald-char-sep">|</span> <span class="herald-char-realm"><?= h($ri['name']) ?></span>
            </div>
            <div class="herald-char-guild">
                <i class="fas fa-users"></i> <?= t('herald_char.guild', [], 'Guild:') ?>
                <?php if (!empty($c['GuildName'])): ?>
                    <a href="?p=herald_guild&id=<?= urlencode($c['GuildID']) ?>"><?= h($c['GuildName']) ?></a>
                <?php else: ?>
                    <span class="herald-char-noguild"><?= t('herald_char.guild_none', [], 'None') ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="herald-char-rr">
            <div class="herald-char-rr-label"><?= t('herald_char.realm_rank', [], 'Realm Rank') ?></div>
            <?php if ($stats_private): ?>
                <div class="herald-char-rr-val">—</div>
                <div class="herald-char-rr-pct"><?= t('herald_char.stats_private', [], 'Statistics are private') ?></div>
            <?php else: ?>
                <div class="herald-char-rr-val"><?= $rank['label'] ?></div>
                <div class="herald-char-rr-bar"><div class="herald-char-rr-fill" style="width:<?= $rank['pct'] ?>%;"></div></div>
                <div class="herald-char-rr-pct"><?= $rank['pct'] ?>% <?= t('herald_char.to_next', [], 'to next') ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($stats_private): ?>
    <div class="info-msg"><?= t('herald_char.stats_private_notice', [], 'This player has disabled public statistics.') ?></div>
    <?php else: ?>
    <div class="herald-char-stats">
        <div class="herald-stat"><label><?= t('herald_char.total_rp', [], 'Realm Points') ?></label><span class="herald-stat-gold"><?= number_format((int)$c['RealmPoints']) ?></span></div>
        <div class="herald-stat"><label><?= t('herald_char.bounty_points', [], 'Bounty Points') ?></label><span><?= number_format((int)($c['BountyPoints'] ?? 0)) ?></span></div>
        <div class="herald-stat"><label><?= t('herald_char.total_kills', [], 'Total Kills') ?></label><span class="herald-stat-green"><?= number_format($kills) ?></span></div>
        <div class="herald-stat"><label><?= t('herald_char.total_deaths', [], 'Deaths') ?></label><span class="herald-stat-red"><?= number_format($deaths) ?></span></div>
        <div class="herald-stat"><label><?= t('herald_char.kd_ratio', [], 'K/D Ratio') ?></label><span class="herald-stat-gold"><?= $kd ?></span></div>
    </div>

    <div class="herald-char-realmkills">
        <div class="herald-rk-title"><?= t('herald_char.rvr_breakdown', [], 'RvR Kill Breakdown') ?></div>
        <?php
        $rk = [
            1 => ['label'=>t('herald_char.kills_albion',[], 'Albion'),   'val'=>(int)($c['KillsAlbionPlayers'] ?? 0),  'col'=>'#c0392b'],
            2 => ['label'=>t('herald_char.kills_midgard',[], 'Midgard'),  'val'=>(int)($c['KillsMidgardPlayers'] ?? 0), 'col'=>'#2980b9'],
            3 => ['label'=>t('herald_char.kills_hibernia',[],'Hibernia'), 'val'=>(int)($c['KillsHiberniaPlayers'] ?? 0),'col'=>'#27ae60'],
        ];
        $rkMax = max(1, $rk[1]['val'], $rk[2]['val'], $rk[3]['val']);
        foreach ($rk as $row):
        ?>
        <div class="herald-rk-row">
            <div class="herald-rk-label" style="color:<?= $row['col'] ?>;"><?= h($row['label']) ?></div>
            <div class="herald-rk-track"><div class="herald-rk-fill" style="width:<?= round($row['val']/$rkMax*100) ?>%; background:<?= $row['col'] ?>;"></div></div>
            <div class="herald-rk-val"><?= number_format($row['val']) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="herald-char-foot"><?= t('herald_char.footer_sync', [], 'Data synced from the game server') ?></div>
</div>
