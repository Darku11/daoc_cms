<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_ACP')) exit;
?>

<?php if ($zones_msg === 'saved'): ?>
<div class="acp-s-af733e8f">
    <i class="fas fa-check-circle acp-s-f15522cd"></i> <?= t('zones_msg_saved', [], 'Zone successfully updated.') ?>
</div>
<?php endif; ?>

<?php if ($zones_msg === 'error'): ?>
<div class="acp-s-c75c8b24">
    <i class="fas fa-exclamation-triangle acp-s-f15522cd"></i> <?= t('zones_msg_error', [], 'Error updating zone.') ?>
</div>
<?php endif; ?>

<?php if ($edit_zone): ?>
<div class="ze-wrap ze-wrap-edit">
    <div class="ze-hdr">
        <span><i class="fas fa-map acp-s-243b9724"></i><?= t('zones_edit_hdr', [], 'Zone Configuration') ?></span>
        <span class="acp-s-1017b50e">ID: <?= h($edit_zone['ZoneID']) ?></span>
    </div>
    
    <form method="POST" action="acp.php?s=zones_editor&id=<?= h($edit_zone['ZoneID']) ?>">
        <input type="hidden" name="csrf_token" value="<?= h(generateToken()) ?>">
        <input type="hidden" name="save_zone" value="1">
        <input type="hidden" name="zone_id" value="<?= h($edit_zone['ZoneID']) ?>">

        <div class="acp-s-6013eea9">
            <div class="acp-s-7df92bc4">
                <div class="ze-field">
                    <label class="ze-label"><?= t('zones_name_lbl', [], 'Internal Name') ?></label>
                    <div class="ze-readonly"><?= h($edit_zone['Name']) ?></div>
                </div>

                <div class="ze-field">
                    <label class="ze-label"><?= t('zones_regionid_lbl', [], 'Region Mapping ID') ?></label>
                    <div class="ze-readonly"><?= h($edit_zone['RegionID']) ?></div>
                </div>
                
                <div class="ze-field">
                    <label class="ze-label"><?= t('zones_realm_lbl', [], 'Realm Identity') ?></label>
                    <input type="number" name="realm" value="<?= h($edit_zone['Realm']) ?>" class="ze-input">
                </div>

                <div class="ze-field acp-s-be6b6b9b">
                    <label class="ze-checkbox">
                        <input type="checkbox" name="is_lava" value="1" <?= $edit_zone['IsLava'] ? 'checked' : '' ?> class="acp-s-26c79b3c" >
                        <span class="acp-s-7e5bf5a7"><?= t('zones_is_lava_lbl', [], 'Lava Environment Active') ?></span>
                    </label>
                </div>
                
                <div class="ze-field">
                    <label class="ze-label"><?= t('zones_diving_flag_lbl', [], 'Diving Mechanics Flag') ?></label>
                    <input type="number" name="diving_flag" value="<?= h($edit_zone['DivingFlag']) ?>" class="ze-input">
                </div>
            </div>

            <div class="acp-s-7df92bc4">
                <div class="ze-field">
                    <label class="ze-label"><?= t('zones_water_level_lbl', [], 'Water Level Z-Axis') ?></label>
                    <input type="number" name="water_level" value="<?= h($edit_zone['WaterLevel']) ?>" class="ze-input">
                </div>
                
                <div class="ze-field">
                    <label class="ze-label"><?= t('zones_experience_lbl', [], 'Experience Modifier') ?></label>
                    <input type="number" name="experience" value="<?= h($edit_zone['Experience']) ?>" class="ze-input">
                </div>

                <div class="ze-field">
                    <label class="ze-label"><?= t('zones_realmpoints_lbl', [], 'Realm Points Modifier') ?></label>
                    <input type="number" name="realmpoints" value="<?= h($edit_zone['Realmpoints']) ?>" class="ze-input">
                </div>

                <div class="ze-field">
                    <label class="ze-label"><?= t('zones_bountypoints_lbl', [], 'Bounty Points Modifier') ?></label>
                    <input type="number" name="bountypoints" value="<?= h($edit_zone['Bountypoints']) ?>" class="ze-input">
                </div>

                <div class="ze-field">
                    <label class="ze-label"><?= t('zones_coin_lbl', [], 'Coin Drop Modifier') ?></label>
                    <input type="number" name="coin" value="<?= h($edit_zone['Coin']) ?>" class="ze-input">
                </div>
            </div>
        </div>

        <div class="acp-s-5d8ab173">
            <button type="submit" class="ze-btn ze-btn-save">
                <i class="fas fa-save"></i> <?= t('zones_btn_save', [], 'Commit Changes') ?>
            </button>
            <a href="acp.php?s=zones_editor" class="ze-btn ze-btn-cancel">
                <i class="fas fa-times"></i> <?= t('zones_btn_cancel', [], 'Discard') ?>
            </a>
        </div>
    </form>
</div>
<?php else: ?>
<div class="ze-wrap">
    <div class="ze-hdr">
        <span><i class="fas fa-globe acp-s-243b9724"></i><?= t('zones_list_hdr', [], 'Global Zones') ?></span>
        
        <form method="GET" action="acp.php" class="ze-search">
            <input type="hidden" name="s" value="zones_editor">
            <input type="text" name="q" value="<?= h($search_q) ?>" placeholder="<?= t('zones_search_placeholder', [], 'Search by ZoneID or Name...') ?>" class="ze-search-input" autocomplete="off">
            <button type="submit" class="ze-search-btn" title="Search"><i class="fas fa-search"></i></button>
            <?php if ($search_q !== ''): ?>
                <a href="acp.php?s=zones_editor" class="ze-search-clear" title="Clear Search"><i class="fas fa-times"></i></a>
            <?php endif; ?>
        </form>
    </div>
    
    <table class="ze-table">
        <thead>
            <tr>
                <th class="acp-s-db80b537">ID</th>
                <th>Name</th>
                <th class="acp-s-d44c18c4">Region ID</th>
                <th class="acp-s-e8dbada6">Attributes</th>
                <th class="acp-s-9a105346">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($zones_list)): ?>
            <tr>
                <td colspan="5" class="acp-s-d3dc9b76"><i class="fas fa-ghost acp-s-03f6d9fe"></i><?= t('zones_no_results', [], 'No structural anomalies found.') ?></td>
            </tr>
            <?php else: ?>
                <?php foreach ($zones_list as $z): ?>
                <tr>
                    <td class="acp-s-2898685e"><?= h($z['ZoneID']) ?></td>
                    <td><strong class="acp-s-cbf40eab"><?= h($z['Name']) ?></strong></td>
                    <td class="acp-s-a5a4ce2f"><?= h($z['RegionID']) ?></td>
                    <td>
                        <div class="acp-s-8ac55174">
                            <?php if ($z['IsLava']): ?>
                                <span class="ze-badge ze-badge-lava" title="Lava Enabled"><i class="fas fa-fire-alt"></i> Lava</span>
                            <?php endif; ?>
                            <?php if ($z['Realm'] > 0): ?>
                                <span class="ze-badge ze-badge-realm" title="Realm Restrictions Apply"><i class="fas fa-shield-alt"></i> Realm <?= h($z['Realm']) ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="acp-s-28bbfa87">
                        <a href="acp.php?s=zones_editor&id=<?= h($z['ZoneID']) ?>" class="ze-btn ze-btn-save acp-s-bf036719" >
                            <i class="fas fa-sliders-h"></i> Edit
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($total_pages > 1): ?>
    <div class="ze-pg">
        <div class="ze-pg-info">
            <i class="fas fa-database acp-s-567400e0"></i> <?= number_format($total_zones) ?> <?= t('zones_total_entries', [], 'Records Indexed') ?>
        </div>
        <div class="ze-pg-links">
            <?php
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);
            $q_param = $search_q !== '' ? '&q=' . urlencode($search_q) : '';

            if ($start_page > 1) {
                echo '<a href="acp.php?s=zones_editor&page=1' . $q_param . '" class="ze-pg-link">1</a>';
                if ($start_page > 2) echo '<span class="ze-pg-dots">...</span>';
            }

            for ($i = $start_page; $i <= $end_page; $i++) {
                $cls = ($i === $page) ? 'ze-pg-link is-active' : 'ze-pg-link';
                echo '<a href="acp.php?s=zones_editor&page=' . $i . $q_param . '" class="' . $cls . '">' . $i . '</a>';
            }

            if ($end_page < $total_pages) {
                if ($end_page < $total_pages - 1) echo '<span class="ze-pg-dots">...</span>';
                echo '<a href="acp.php?s=zones_editor&page=' . $total_pages . $q_param . '" class="ze-pg-link">' . $total_pages . '</a>';
            }
            ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>