<?php
if (!defined('IN_CMS')) exit;

$search = $_GET['search'] ?? '';
$where_clauses = ["1=1"];
$params = [];

if (!empty($search)) {
    $where_clauses[] = "Name LIKE ?";
    $params[] = "%$search%";
}

$where_sql = "WHERE " . implode(' AND ', $where_clauses);
$stmt_quests = $db->prepare("
    SELECT ID, Name, MinLevel
    FROM dataquest
    $where_sql
    ORDER BY MinLevel ASC
    LIMIT 100
");
$stmt_quests->execute($params);
$quests = $stmt_quests->fetchAll();
?>
<div class="admin-container">
    <h2 class="pve-quests-title"><?= t('pve_quests.title', [], 'Quest Chronicles'); ?></h2>
    <p class="pve-quests-subtitle"><?= t('pve_quests.subtitle', [], 'Deciphering the DAoC Archives'); ?></p>

    <form method="GET" class="pve-quests-search-form">
        <input type="hidden" name="p" value="pve_quests">
        <input type="text" name="search" value="<?php echo h($search); ?>" class="um-input pve-quests-search-input" placeholder="<?= t('pve_quests.search_placeholder', [], 'Search chronicles...'); ?>">
        <button type="submit" class="btn-gold pve-quests-search-btn"><?= t('pve_quests.btn_filter', [], 'FILTER'); ?></button>
    </form>

    <div class="quest-list">
        <?php if ($quests): ?>
            <?php foreach ($quests as $q): ?>
                <div class="quest-card">
                    <div class="quest-info">
                        <span><?= t('pve_quests.level', [], 'Level'); ?> <?php echo (int)($q['MinLevel'] ?? 1); ?></span>
                        <h3><?php echo h($q['Name'] ?? t('pve_quests.unknown', [], 'Unknown Quest')); ?></h3>
                    </div>
                    <a href="?p=pve_quest_detail&id=<?php echo urlencode((string)$q['ID']); ?>" class="btn-view-quest"><?= t('pve_quests.read_archive', [], 'READ ARCHIVE'); ?></a>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="pve-quests-empty"><?= t('pve_quests.no_entries', [], 'No entries found in the data archives.'); ?></div>
        <?php endif; ?>
    </div>
</div>
