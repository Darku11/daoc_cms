<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) exit;

require_once __DIR__ . '/../includes/pve_catalog.php';

$itemId = trim((string)($_GET['id'] ?? ''));
$item = $itemId !== '' ? daoc_pve_item_detail($db, $itemId) : null;
?>
<div class="admin-container pve-item-detail-page">
    <a href="?p=pve_bestiary" class="pve-item-back"><i class="fas fa-chevron-left"></i> <?= t('pve_item.back', [], 'Back to Bestiary') ?></a>

    <?php if ($itemId === ''): ?>
        <div class="pve-item-empty"><?= t('pve_item.no_item', [], 'No item was selected.') ?></div>
    <?php elseif (!$item): ?>
        <div class="pve-item-empty"><?= t('pve_item.not_found', [], 'Item not found.') ?></div>
    <?php else: ?>
        <section class="pve-item-card">
            <div class="pve-item-heading">
                <div>
                    <div class="pve-item-kicker"><?= h($item['type_label']) ?></div>
                    <h2><?= h($item['name']) ?></h2>
                    <div class="pve-item-id"><?= h($item['id']) ?></div>
                </div>
                <?php if (!empty($item['model'])): ?>
                    <img class="pve-item-icon" src="assets/img/icons/items/<?= (int)$item['model'] ?>.png" alt="" onerror="this.src='assets/img/icons/items/default.png'">
                <?php endif; ?>
            </div>

            <div class="pve-item-stat-grid">
                <div class="pve-item-stat"><span><?= t('pve_item.level', [], 'Level') ?></span><strong><?= (int)$item['level'] ?></strong></div>
                <?php if ($item['quality'] !== null): ?><div class="pve-item-stat"><span><?= t('pve_item.quality', [], 'Quality') ?></span><strong><?= (int)$item['quality'] ?>%</strong></div><?php endif; ?>
                <?php if ($item['bonus'] !== null): ?><div class="pve-item-stat"><span><?= t('pve_item.bonus', [], 'Bonus') ?></span><strong><?= (int)$item['bonus'] ?></strong></div><?php endif; ?>
                <?php if ($item['dps'] !== null): ?><div class="pve-item-stat"><span>DPS</span><strong><?= h((string)$item['dps']) ?></strong></div><?php endif; ?>
                <?php if ($item['speed'] !== null): ?><div class="pve-item-stat"><span><?= t('pve_item.speed', [], 'Speed') ?></span><strong><?= h((string)$item['speed']) ?></strong></div><?php endif; ?>
                <?php if ($item['af'] !== null): ?><div class="pve-item-stat"><span>AF</span><strong><?= h((string)$item['af']) ?></strong></div><?php endif; ?>
                <?php if ($item['abs'] !== null): ?><div class="pve-item-stat"><span>ABS</span><strong><?= h((string)$item['abs']) ?></strong></div><?php endif; ?>
            </div>

            <?php if ($item['description'] !== ''): ?>
                <p class="pve-item-description"><?= h($item['description']) ?></p>
            <?php endif; ?>

            <div class="pve-item-section">
                <h3><i class="fas fa-user-shield"></i> <?= t('pve_item.class_access', [], 'Class Access') ?></h3>
                <?php if (!$item['class_restricted']): ?>
                    <p class="pve-item-access-ok"><?= t('pve_item.all_classes', [], 'No explicit class restriction is stored for this item.') ?></p>
                <?php else: ?>
                    <p class="pve-item-access-note"><?= t('pve_item.excluded_classes', [], 'This item is unavailable to the following classes:') ?></p>
                    <div class="pve-item-class-list">
                        <?php foreach ($item['excluded_classes'] as $className): ?>
                            <span><?= h($className) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="pve-item-section">
                <div class="pve-item-section-head">
                    <h3><i class="fas fa-store"></i> <?= t('pve_item.merchants', [], 'Merchants') ?></h3>
                    <span class="pve-item-merchant-count"><?= (int)$item['merchant_count'] ?></span>
                </div>

                <?php if (!$item['merchants']): ?>
                    <p class="pve-item-access-note"><?= t('pve_item.no_merchants', [], 'This item is not assigned to a game-world merchant.') ?></p>
                <?php else: ?>
                    <p class="pve-item-access-note"><?= t('pve_item.has_merchants', [], 'This item is available from game-world merchants.') ?></p>
                    <button type="button" class="pve-merchant-toggle" aria-expanded="false" aria-controls="pveMerchantMaps" onclick="pveToggleMerchants(this)">
                        <i class="fas fa-map-location-dot"></i> <span><?= t('pve_item.show_merchants', [], 'Show Merchants') ?></span>
                    </button>

                    <div id="pveMerchantMaps" class="pve-merchant-panel" hidden>
                        <?php
                        $groups = [];
                        foreach ($item['merchants'] as $merchant) {
                            $groupKey = $merchant['zone_name'] . '|' . ($merchant['map_image'] ?? '');
                            $groups[$groupKey][] = $merchant;
                        }
                        foreach ($groups as $merchants):
                            $first = $merchants[0];
                        ?>
                            <section class="pve-merchant-zone">
                                <h4><?= h($first['zone_name']) ?></h4>
                                <?php if (!empty($first['map_image'])): ?>
                                    <div class="pve-merchant-map-wrap">
                                        <svg class="pve-merchant-map" viewBox="0 0 100 100" role="img" aria-label="<?= h($first['zone_name']) ?> merchant map" preserveAspectRatio="none">
                                            <image href="<?= h($first['map_image']) ?>" x="0" y="0" width="100" height="100" preserveAspectRatio="none"></image>
                                            <?php foreach ($merchants as $merchant): ?>
                                                <?php if ($merchant['map_x'] !== null && $merchant['map_y'] !== null): ?>
                                                    <circle class="pve-merchant-marker" cx="<?= h(number_format((float)$merchant['map_x'], 3, '.', '')) ?>" cy="<?= h(number_format((float)$merchant['map_y'], 3, '.', '')) ?>" r="1.8">
                                                        <title><?= h($merchant['name']) ?> — X <?= (int)$merchant['x'] ?>, Y <?= (int)$merchant['y'] ?></title>
                                                    </circle>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </svg>
                                    </div>
                                <?php endif; ?>
                                <div class="pve-merchant-list">
                                    <?php foreach ($merchants as $merchant): ?>
                                        <div class="pve-merchant-entry">
                                            <strong><?= h($merchant['name']) ?></strong>
                                            <span>X <?= (int)$merchant['x'] ?> · Y <?= (int)$merchant['y'] ?> · Z <?= (int)$merchant['z'] ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
</div>

<script>
function pveToggleMerchants(button) {
    const panel = document.getElementById('pveMerchantMaps');
    if (!panel) return;
    const opening = panel.hasAttribute('hidden');
    if (opening) panel.removeAttribute('hidden'); else panel.setAttribute('hidden', '');
    button.setAttribute('aria-expanded', opening ? 'true' : 'false');
    const label = button.querySelector('span');
    if (label) label.textContent = opening
        ? <?= json_encode(t('pve_item.hide_merchants', [], 'Hide Merchants')) ?>
        : <?= json_encode(t('pve_item.show_merchants', [], 'Show Merchants')) ?>;
}
</script>
