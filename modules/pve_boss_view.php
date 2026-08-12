<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) exit;

$mob_id = isset($_GET['id']) ? trim((string)$_GET['id']) : '';

if ($mob_id === '' || strlen($mob_id) > 255) {
    echo "<div class='admin-container'><p>" . t('pve_boss.not_found', [], 'Entity not found.') . "</p></div>";
    return;
}

$mob = daoc_game_mob_by_id($db, $mob_id);

if (!$mob) return;

$is_epic = ((int)($mob['Level'] ?? 0) >= 50);
?>
<div class="admin-container">
    <div class="pve-boss-back-wrap">
        <a href="?p=pve_bestiary" class="pve-boss-back-link"><?= t('pve_boss.back', [], 'BACK TO BESTIARY'); ?></a>
    </div>

    <div class="boss-header">
        <div class="pve-boss-subtitle"><?= t('pve_boss.elite_encounter', [], 'Elite Encounter'); ?></div>
        <h1 class="boss-title"><?php echo h($mob['Name']); ?></h1>
        <div class="pve-boss-meta"><?= t('pve_boss.level', [], 'Level'); ?> <?php echo (int)$mob['Level']; ?> // <?php echo h($mob['Region']); ?></div>
    </div>

    <div class="encounter-grid">
        <div>
            <h3 class="pve-boss-section-title"><?= t('pve_boss.encounter_title', [], 'The Encounter'); ?></h3>

            <div class="mechanic-box">
                <div class="mechanic-title"><i class="fas fa-skull-crossbones"></i> <?= t('pve_boss.guardian_traits', [], 'Guardian Traits'); ?></div>
                <p class="pve-boss-desc">
                    <?= t('pve_boss.desc.known_to_be', [], 'This entity is known to be'); ?> <?php echo (int)($mob['AggroRange'] ?? 0) > 0 ? t('pve_boss.desc.aggressive', [], 'highly aggressive') : t('pve_boss.desc.passive', [], 'passive until provoked'); ?><?= t('pve_boss.desc.power_level', [], '. It commands a power level of'); ?> <?php echo (int)$mob['Level']; ?> <?= t('pve_boss.desc.guards_region', [], 'and guards the region of'); ?> <?php echo h($mob['Region']); ?>.
                </p>
            </div>

            <div class="mechanic-box pve-boss-mechanic--tactical">
                <div class="mechanic-title pve-boss-tactical-title"><?= t('pve_boss.tactical_note', [], 'Tactical Note'); ?></div>
                <p class="pve-boss-desc">
                    <?= t('pve_boss.tactical_desc', [], 'Beware of the high health pool and magical resistances. Bring a balanced party of Atlantis classes, especially a <strong>Poet</strong> for inspiration and a <strong>Myrmidon</strong> for frontline control.'); ?>
                </p>
            </div>
        </div>

        <div class="loot-sidebar">
            <h3 class="pve-boss-loot-title"><?= t('pve_boss.relics', [], 'Relics of Power'); ?></h3>
            <?php
            $loot_items = daoc_game_mob_loot($db, (string)$mob['Name'], 8);
            if ($loot_items):
                foreach ($loot_items as $loot_item): ?>
                    <div class="pve-boss-loot-row">
                        <div class="pve-boss-loot-info">
                            <a href="?p=pve_item&id=<?php echo urlencode((string)$loot_item['ItemTemplateID']); ?>"
                               class="pve-boss-loot-link"><?php echo h($loot_item['ItemName'] ?: $loot_item['ItemTemplateID']); ?></a>
                            <div class="pve-boss-loot-quality"><?php echo (int)$loot_item['Quality']; ?><?= t('pve_boss.quality', [], '% Quality'); ?></div>
                        </div>
                        <div class="pve-boss-loot-chance"><?php echo (int)$loot_item['Chance']; ?>%</div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="pve-boss-no-relics"><?= t('pve_boss.no_relics', [], 'No documented relics.'); ?></p>
            <?php endif; ?>
        </div>
    </div>
</div>
