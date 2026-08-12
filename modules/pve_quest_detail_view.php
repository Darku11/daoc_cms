<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) exit;

$quest_id_raw = isset($_GET['id']) ? trim((string)$_GET['id']) : '';

if ($quest_id_raw === '' || !ctype_digit($quest_id_raw) || (int)$quest_id_raw < 1) {
    echo "<div class='admin-container'><p>" . t('pve_quest_detail.error.no_id', [], 'No Quest ID provided.') . "</p></div>";
    return;
}

$quest_id = (int)$quest_id_raw;
$q = daoc_game_dataquest($db, $quest_id);

if (!$q) {
    echo "<div class='admin-container'><p>" . t('pve_quest_detail.error.not_found', [], 'This chronicle has been lost to time.') . "</p></div>";
    return;
}

$rawName = $q['Name'] ?? t('pve_quest_detail.unknown', [], 'Unknown');
if (strpos($rawName, '.') !== false) {
    $parts = explode('.', $rawName);
    $cleanName = preg_replace('/(?<!^)([A-Z])/', ' $1', end($parts));
} else {
    $cleanName = $rawName;
}

$desc_text    = $q['Description'] ?: t('pve_quest_detail.no_desc', [], 'The archives are silent on the details of this task.');
$reward_xp    = daoc_game_serialized_reward_total($q['RewardXP'] ?? null);
$reward_money = daoc_game_serialized_reward_total($q['RewardMoney'] ?? null);
$reward_ids   = daoc_game_dataquest_reward_ids($q);
$reward_names = daoc_game_item_names($db, $reward_ids);
$min_lvl      = $q['MinLevel'] ?? t('pve_quest_detail.unknown_level', [], '??');
$region_name  = daoc_game_region_label($db, (int)($q['StartRegionID'] ?? 0));
?>
<div class="admin-container">
    <div class="quest-detail-header">
        <a href="?p=pve_quests" class="quest-back-link"><?= t('pve_quest_detail.back', [], '&larr; Back to Chronicles'); ?></a>
        <h2 class="quest-detail-title"><?php echo h($cleanName); ?></h2>
        <div class="quest-meta">
            <span><?= t('pve_quest_detail.meta.level', [], 'Level:'); ?> <strong><?php echo h($min_lvl); ?></strong></span>
            <span><?= t('pve_quest_detail.meta.region', [], 'Region:'); ?> <strong><?php echo h($region_name); ?></strong></span>
            <span><?= t('pve_quest_detail.meta.archive_id', [], 'Archive ID:'); ?> <strong><?php echo h($quest_id); ?></strong></span>
        </div>
    </div>

    <div class="quest-section">
        <h4><?= t('pve_quest_detail.objective', [], 'The Objective'); ?></h4>
        <p class="quest-desc-text"><?php echo h($desc_text); ?></p>
    </div>

    <div class="quest-rewards-grid">
        <div class="quest-section">
            <h4><?= t('pve_quest_detail.rewards', [], 'Rewards'); ?></h4>
            <div class="reward-item"><span><?= t('pve_quest_detail.experience', [], 'Experience'); ?></span><span class="reward-value"><?php echo number_format((float)$reward_xp); ?></span></div>
            <div class="reward-item"><span><?= t('pve_quest_detail.currency', [], 'Currency'); ?></span><span class="reward-value"><?php echo number_format((float)$reward_money); ?></span></div>
            <?php foreach ($reward_ids as $reward_id): ?>
                <div class="reward-item">
                    <span><?= t('pve_quest_detail.special_artifact', [], 'Special Artifact'); ?></span>
                    <a href="?p=pve_item&id=<?php echo urlencode($reward_id); ?>" class="reward-item-link"><?php echo h($reward_names[$reward_id] ?? $reward_id); ?></a>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="quest-section">
            <h4><?= t('pve_quest_detail.instructions', [], 'Instructions'); ?></h4>
            <p class="quest-instructions-text">
                <?= t('pve_quest_detail.seek_out', [], 'Seek out'); ?> <?php echo h($q['StartName'] ?: t('pve_quest_detail.questgiver', [], 'the Questgiver')); ?> <?= t('pve_quest_detail.begin_journey', [], 'to begin this journey.'); ?>
            </p>
        </div>
    </div>
</div>
