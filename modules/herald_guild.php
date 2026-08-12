<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) exit;
require_once __DIR__ . '/../includes/herald_helpers.php';

$guild_id = isset($_GET['id']) ? trim($_GET['id']) : '';
if ($guild_id === '') {
    echo "<div class='admin-box'>" . t('herald_guild.error.no_guild', [], 'No guild specified.') . "</div>";
    return;
}

$g = daoc_game_herald_guild($db, $guild_id);
if (!$g) {
    echo "<div class='admin-box'>" . t('herald_guild.error.not_found', [], 'Guild not found.') . "</div>";
    return;
}

$members = daoc_game_herald_guild_members($db, $guild_id);

$r_info   = herald_realm_info();
$realm_id = (int)($g['Realm'] ?? 0);
$ri       = $r_info[$realm_id] ?? ['name'=>'?','color'=>'#555','glow'=>'rgba(85,85,85,0.4)','icon'=>'fa-flag','slug'=>'neutral'];

$publicMembers = array_filter(
    $members,
    static fn(array $member): bool => empty($member['IgnoreStatistics']) && empty($member['IsAnonymous'])
);
$totalRP  = array_sum(array_column($publicMembers, 'RealmPoints'));
$avgLevel = $members ? round(array_sum(array_column($members,'Level')) / count($members)) : 0;
?>

<div class="herald-guild" style="--r-col:<?= $ri['color'] ?>; --r-glow:<?= $ri['glow'] ?>;">
    <a href="?p=herald" class="herald-back"><i class="fas fa-chevron-left"></i> <?= t('herald_guild.back', [], 'Back to Herald') ?></a>

    <div class="herald-guild-banner">
        <div class="herald-guild-crest"><img src="assets/img/realm_<?= h($ri['slug']) ?>.png" alt="<?= h($ri['name']) ?>" class="herald-guild-crest-icon" onerror="this.style.display='none'"></div>
        <h1 class="herald-guild-name">&lt; <?= h($g['GuildName']) ?> &gt;</h1>
        <div class="herald-guild-realm"><?= h($ri['name']) ?></div>
        <div class="herald-guild-agg">
            <div><span><?= count($members) ?></span><?= t('herald_guild.members', [], 'Members') ?></div>
            <div><span><?= number_format($totalRP) ?></span><?= t('herald_guild.total_rp', [], 'Total RP') ?></div>
            <div><span><?= $avgLevel ?></span><?= t('herald_guild.avg_level', [], 'Avg Level') ?></div>
        </div>
    </div>

    <div class="herald-panel">
        <h3 class="herald-panel-head"><i class="fas fa-users"></i> <?= t('herald_guild.roster', [], 'Roster') ?></h3>
        <div class="herald-ladder">
            <?php foreach ($members as $i => $m):
                $rank = herald_realm_rank((int)$m['RealmPoints']);
                $statsPrivate = !empty($m['IgnoreStatistics']) || !empty($m['IsAnonymous']);
            ?>
            <a href="?p=herald_char&name=<?= urlencode($m['Name']) ?>" class="herald-ladder-row" style="--r-col:<?= $ri['color'] ?>;">
                <div class="herald-ladder-rank <?= $i < 3 ? 'is-top' : '' ?>"><?= $i + 1 ?></div>
                <div class="herald-ladder-class"><img src="assets/img/realm_<?= h($ri['slug']) ?>.png" alt="<?= h($ri['name']) ?>" class="herald-realm-icon" onerror="this.style.display='none'"></div>
                <div class="herald-ladder-main">
                    <div class="herald-ladder-name"><?= h($m['Name']) ?></div>
                    <div class="herald-ladder-sub"><?= h(getClassName((int)$m['Class'])) ?> · L<?= (int)$m['Level'] ?></div>
                </div>
                <div class="herald-ladder-rr">
                    <?php if ($statsPrivate): ?>
                        <div class="herald-rr-label">—</div>
                        <div class="herald-ladder-rp"><?= t('herald_char.stats_private', [], 'Statistics are private') ?></div>
                    <?php else: ?>
                        <div class="herald-rr-label"><?= $rank['label'] ?></div>
                        <div class="herald-rr-bar"><div class="herald-rr-fill" style="width:<?= $rank['pct'] ?>%;"></div></div>
                        <div class="herald-ladder-rp"><?= number_format((int)$m['RealmPoints']) ?></div>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
