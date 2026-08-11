<?php
require_once('includes/db.php');
$pve_counts   = daoc_game_pve_counts($db);
$stats_mobs   = $pve_counts['mobs'];
$stats_items  = $pve_counts['items'];
$stats_quests = $pve_counts['quests'];

$stats_shop = 0;
try {
    $stats_shop = (int)($db->query("SELECT COUNT(*) FROM shop_system_items WHERE active = 1")->fetchColumn() ?: 0);
} catch (PDOException $e) {
    $stats_shop = 0;
}

$itemshop_enabled = ($GLOBALS['cms_settings']['itemshop_enabled'] ?? '1') === '1';
?>
<div class="admin-container">
    <div class="pve-dashboard">
        <a href="?p=pve_bestiary" class="pve-tile">
            <i class="fas fa-dragon"></i>
            <h3><?= t('pve_dash.bestiary.title', [], 'Bestiary'); ?></h3>
            <p><?= t('pve_dash.bestiary.desc', [], 'Browse creatures and entities.'); ?></p>
            <div class="stat-overlay"><?php echo number_format((int)$stats_mobs); ?> <?= t('pve_dash.bestiary.entities', [], 'Entities'); ?></div>
        </a>
        <a href="?p=pve_quests" class="pve-tile">
            <i class="fas fa-scroll"></i>
            <h3><?= t('pve_dash.quests.title', [], 'Quests'); ?></h3>
            <p><?= t('pve_dash.quests.desc', [], 'Browse quests and chronicles.'); ?></p>
            <div class="stat-overlay"><?php echo number_format((int)$stats_quests); ?> <?= t('pve_dash.quests.chronicles', [], 'Chronicles'); ?></div>
        </a>
        
        <?php if ($itemshop_enabled): ?>
        <!--
    The Item Shop does NOT have its own dedicated purchase page. The tile
    links to the filtered item list (modules/pve_items_view.php), while the
    actual purchase process continues to take place on the existing item
    detail page (pve_item_view.php), where the purchase widget is already
    integrated.
		-->
        <a href="?p=pve_items&filter=shop" class="pve-tile">
            <i class="fas fa-store"></i>
            <h3><?= t('pve_dash.itemshop.title', [], 'Itemshop'); ?></h3>
            <p><?= t('pve_dash.itemshop.desc', [], 'Buy potions and respec stones via the system or directly from housing.'); ?></p>
            <div class="stat-overlay"><?php echo number_format($stats_shop); ?> <?= t('pve_dash.itemshop.available', [], 'System Items'); ?></div>
        </a>
        <?php endif; ?>
    </div>
</div>
