<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_ACP')) exit;

if (!isset($userPriv))      $userPriv      = (int)($_SESSION['priv_level'] ?? 0);
if (!isset($currentUserId)) $currentUserId = (int)($_SESSION['user_id']    ?? 0);
if ($userPriv < 3) { echo '<div class="acp-empty">Access denied.</div>'; return; }

if (!function_exists('t')) {
    function t(string $key, array $params = [], string $fallback = ''): string {
        return $fallback !== '' ? $fallback : $key;
    }
}

$canEdit = $userPriv >= 3;

$csrf       = generateToken();
$profiles   = $GLOBALS['SC_SERVER_PROFILES'];
$sc_slots   = $GLOBALS['SC_SLOTS'];
$sc_props   = $GLOBALS['SC_PROPERTIES'];
$sc_groups  = $GLOBALS['SC_GROUPS'];
$sc_classes = $GLOBALS['SC_CLASSES'];

$realmLabels     = [0=>t('acp_sc_realm_all', [], 'All Realms'),1=>'Albion',2=>'Midgard',3=>'Hibernia'];
$archetypeLabels = ['custom'=>'Custom','tank'=>'Tank','melee'=>'Melee DPS','caster'=>'Caster','healer'=>'Healer','support'=>'Support','hybrid'=>'Hybrid'];

$propsByGroup = [];
foreach ($sc_groups as $gkey => $_g) $propsByGroup[$gkey] = [];
foreach ($sc_props as $id => $p) {
    $g = $p['group'] ?? 'stat';
    if (!isset($propsByGroup[$g])) $propsByGroup[$g] = [];
    $propsByGroup[$g][$id] = $p;
}
$leftGroups  = array_values(array_diff(array_keys($sc_groups), ['resist']));
$rightGroups = ['resist'];

$ai_active = isset($botSettings) && $botSettings->isActive() && $botSettings->hasAiConfigured();

if (!function_exists('sc_group_render')):
function sc_group_render(array $groups, array $propsByGroup, array $sc_groups) {
    foreach ($groups as $gkey) {
        $props = $propsByGroup[$gkey] ?? [];
        if (!$props) continue;
        $g = $sc_groups[$gkey] ?? ['label'=>ucfirst($gkey),'icon'=>'fa-circle'];
        echo '<div class="sc-tgroup" data-group="'.htmlspecialchars($gkey).'">';
        echo '<div class="sc-tgroup-head">';
        echo '<label class="sc-tgroup-toggle"><input type="checkbox" class="sc-group-check" value="'.htmlspecialchars($gkey).'" checked>';
        echo '<i class="fas '.htmlspecialchars($g['icon']).'"></i> '.htmlspecialchars($g['label']).'</label>';
        echo '<button type="button" class="sc-mini" data-capgroup="'.htmlspecialchars($gkey).'" title="'.h(t('acp_sc_btn_cap_group_title', [], 'Cap this group')).'">Cap</button>';
        echo '</div>';
        foreach ($props as $id => $p) {
            $verify = !empty($p['verify']) ? ' <span class="sc-verify" title="'.h(t('acp_sc_verify_title', [], 'ID not verified - please check against GlobalConstants')).'">⚠</span>' : '';
            echo '<div class="sc-stat-row">';
            echo '<span class="sc-stat-label">'.htmlspecialchars($p['label']).$verify.'</span>';
            echo '<input type="number" class="sc-input sc-stat-target" min="0" value="0" '
               . 'data-type="'.(int)$id.'" data-group="'.htmlspecialchars($p['group']).'" '
               . 'data-weight="'.(float)$p['weight'].'">';
            echo '<button type="button" class="sc-mini sc-cap-one" data-type="'.(int)$id.'" title="'.h(t('acp_sc_btn_cap_one_title', [], 'Cap this stat')).'">⤒</button>';
            echo '</div>';
        }
        echo '</div>';
    }
}
endif;
?>


<div id="sc-flash" class="sc-flash"></div>

<div class="sc-wrap">
    <div class="sc-left">
        <?php if ($canEdit): ?>
        <button class="sc-btn sc-btn-primary sc-btn-full" id="sc-new-btn"><i class="fas fa-plus"></i> <?= t('acp_sc_btn_new_suit', [], 'New Suit') ?></button>
        <?php endif; ?>
        <input type="text" class="sc-input" id="sc-list-filter" placeholder="<?= h(t('acp_sc_filter_placeholder', [], 'Search suits...')) ?>">
        <div class="sc-list" id="sc-suit-list"><div class="acp-s-a1d15c9a"><?= t('acp_sc_loading_suits', [], 'Loading suits...') ?></div></div>
        <?php if ($canEdit): ?>
        <div class="acp-s-0d55f66a">
            <button class="sc-btn sc-btn-secondary sc-btn-sm acp-s-da5cd676" id="sc-dupe-btn" disabled><i class="fas fa-copy"></i> <?= t('acp_sc_btn_duplicate', [], 'Duplicate') ?></button>
            <button class="sc-btn sc-btn-danger sc-btn-sm acp-s-da5cd676" id="sc-delete-btn" disabled><i class="fas fa-trash"></i> <?= t('acp_sc_btn_delete', [], 'Delete') ?></button>
        </div>
        <?php endif; ?>
    </div>

    <div class="sc-main">
        <div class="sc-empty" id="sc-empty-state">
            <i class="fas fa-tshirt"></i>
            <p><?= t('acp_sc_empty_state', [], 'Select a suit on the left') ?><?= $canEdit ? ' ' . t('acp_sc_empty_state_or_new', [], 'or create a new one') : '' ?>.</p>
        </div>

        <div id="sc-form" class="acp-s-cb458930">
            <input type="hidden" id="sc-suit-id" value="0">

            <div class="sc-hud" id="sc-hud">
                <div class="sc-hud-top">
                    <span class="sc-hud-num" id="sc-hud-used">0<small>/0</small></span>
                    <span class="sc-hud-state idle" id="sc-hud-state"><?= t('acp_sc_hud_no_targets', [], 'No targets set') ?></span>
                    <span class="sc-hud-note" id="sc-hud-note"><?= count($sc_slots) ?> <?= t('acp_sc_hud_slots_active', [], 'slots active') ?></span>
                </div>
                <div class="sc-segbar" id="sc-segbar">
                    <?php foreach($sc_slots as $key=>$slot): ?>
                    <div class="sc-seg" id="sc-seg-<?=$key?>" title="<?=htmlspecialchars($slot['label'])?>">
                        <div class="sc-seg-fill acp-s-97765f2b"></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="sc-cov" id="sc-cov"></div>
            </div>

            <div class="sc-card">
                <div class="sc-card-title"><i class="fas fa-id-card"></i> <?= t('acp_sc_card_identity', [], 'Suit Identity') ?></div>
                <div class="sc-grid-4">
                    <div class="sc-field"><label class="sc-label"><?= t('acp_sc_label_name', [], 'Name') ?></label><input type="text" class="sc-input" id="sc-name" placeholder="<?= h(t('acp_sc_ph_name', [], 'e.g. Armsman Starter')) ?>"></div>
                    <div class="sc-field"><label class="sc-label"><?= t('acp_sc_label_realm', [], 'Realm') ?></label>
                        <select class="sc-select" id="sc-realm">
                            <?php foreach($realmLabels as $v=>$l): ?><option value="<?=$v?>"><?=$l?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sc-field"><label class="sc-label"><?= t('acp_sc_label_class', [], 'Class') ?></label>
                        <select class="sc-select" id="sc-class">
                            <option value=""><?= t('acp_sc_opt_none', [], '— none —') ?></option>
                            <?php foreach([1=>'Albion',2=>'Midgard',3=>'Hibernia'] as $r=>$rl): ?>
                            <optgroup label="<?=$rl?>" data-realm="<?=$r?>">
                                <?php foreach($sc_classes as $ck=>$c): if($c['realm']!==$r) continue; ?>
                                <option value="<?=$ck?>" data-realm="<?=$r?>"><?=htmlspecialchars($c['label'])?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sc-field"><label class="sc-label"><?= t('acp_sc_label_archetype', [], 'Archetype') ?></label>
                        <select class="sc-select" id="sc-archetype">
                            <?php foreach($archetypeLabels as $v=>$l): ?><option value="<?=$v?>"><?=$l?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="sc-field"><label class="sc-label"><?= t('acp_sc_label_desc', [], 'Description') ?></label><input type="text" class="sc-input" id="sc-description" placeholder="<?= h(t('acp_sc_ph_optional', [], 'Optional...')) ?>"></div>
            </div>

            <div class="sc-card">
                <div class="sc-card-title"><i class="fas fa-server"></i> <?= t('acp_sc_card_profile', [], 'Server Profile') ?>
                    <span class="sc-hint" id="sc-profile-info"></span>
                </div>
                <div class="sc-profile-cards">
                    <?php foreach($profiles as $key=>$profile): ?>
                    <div class="sc-profile-card <?=$key==='i50'?'active':''?>" data-profile="<?=$key?>">
                        <i class="fas fa-<?=$key==='i50'?'shield-alt':($key==='pvp'?'fist-raised':'star')?>"></i>
                        <?=htmlspecialchars($profile['label'])?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" id="sc-server-type" value="i50">
            </div>

            <div class="sc-card">
                <div class="sc-card-title"><i class="fas fa-sliders-h"></i> <?= t('acp_sc_card_targets', [], 'Target Stats') ?>
                    <span class="sc-hint"><?= t('acp_sc_hint_targets', [], 'Alt+D distributes · Alt+C caps everything') ?></span>
                </div>

                <div class="acp-s-d51f4b89">
                    <button type="button" class="sc-btn sc-btn-primary sc-btn-sm" id="sc-cap-all-btn"><i class="fas fa-bolt"></i> <?= t('acp_sc_btn_cap_all', [], 'Cap all') ?></button>
                    <button type="button" class="sc-btn sc-btn-secondary sc-btn-sm" data-capgroup="resist"><i class="fas fa-shield-alt"></i> <?= t('acp_sc_btn_cap_resists', [], 'All Resists') ?></button>
                    <button type="button" class="sc-btn sc-btn-secondary sc-btn-sm" data-capgroup="stat"><i class="fas fa-dumbbell"></i> <?= t('acp_sc_btn_cap_stats', [], 'All Stats') ?></button>
                    <button type="button" class="sc-btn sc-btn-secondary sc-btn-sm" id="sc-clear-targets"><i class="fas fa-times"></i> <?= t('acp_sc_btn_clear', [], 'Clear') ?></button>
                    <span class="acp-s-d3e06807"></span>
                    <button type="button" class="sc-btn sc-btn-secondary sc-btn-sm" id="sc-class-preset-btn" title="<?= h(t('acp_sc_title_preset', [], 'Set targets from selected class')) ?>"><i class="fas fa-user-shield"></i> <?= t('acp_sc_btn_preset', [], 'Class Preset') ?></button>
                </div>

                <div class="sc-field">
                    <label class="sc-label"><?= t('acp_sc_label_active_slots', [], 'Active Slots') ?> <span class="acp-s-dde4906f"><?= t('acp_sc_hint_active_slots', [], '— only these receive bonuses') ?></span></label>
                    <div class="acp-s-4ec702a9">
                        <?php foreach($sc_slots as $key=>$slot): ?>
                        <label class="acp-s-a1ded332">
                            <input type="checkbox" class="sc-slot-check acp-s-88b1dd7f" value="<?=$key?>" checked >
                            <?=htmlspecialchars($slot['label'])?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="sc-grid-2 acp-s-553a645f">
                    <div><?php sc_group_render($leftGroups,  $propsByGroup, $sc_groups); ?></div>
                    <div><?php sc_group_render($rightGroups, $propsByGroup, $sc_groups); ?></div>
                </div>

                <div class="acp-s-d50b6033">
                    <button type="button" class="sc-btn sc-btn-primary" id="sc-distribute-btn"><i class="fas fa-magic"></i> <?= t('acp_sc_btn_distribute', [], 'Distribute Stats') ?></button>
                    <label class="acp-s-76697bd0">
                        <?= t('acp_sc_label_max_bonuses', [], 'Max. bonuses / item') ?>
                        <input type="number" class="sc-input acp-s-73dc880f" id="sc-max-bonuses" value="4" min="1" max="10">
                    </label>
                    <span id="sc-dist-status" class="acp-s-c14bf3db"></span>
                </div>
            </div>

            <div class="sc-card">
                <div class="sc-card-title">
                    <i class="fas fa-th-large"></i> <?= t('acp_sc_card_slots', [], 'Slot Assignment') ?>
                    <div class="acp-s-055108f6">
                        <button type="button" class="sc-btn sc-btn-secondary sc-btn-sm" id="sc-toggle-view-grid"><i class="fas fa-th"></i> Grid</button>
                        <button type="button" class="sc-btn sc-btn-secondary sc-btn-sm" id="sc-toggle-view-doll"><i class="fas fa-user"></i> Paperdoll</button>
                    </div>
                </div>

                <div class="sc-slot-grid" id="sc-slot-grid">
                    <?php foreach($sc_slots as $key=>$slot): ?>
                    <div class="sc-slot-card" id="sc-slot-<?=$key?>" data-slot="<?=$key?>">
                        <div class="sc-slot-header">
                            <span class="sc-slot-name"><?=htmlspecialchars($slot['label'])?></span>
                            <span class="sc-slot-util" id="sc-slot-util-<?=$key?>">0</span>
                        </div>
                        <div class="sc-slot-search" id="sc-slot-search-wrap-<?=$key?>">
                            <input type="text" class="sc-input sc-slot-search-input acp-s-0df23b0f" data-slot="<?=$key?>" placeholder="<?= h(t('acp_sc_ph_search_item', [], 'Search item...')) ?>" autocomplete="off" >
                            <div class="sc-slot-search-results" id="sc-slot-sr-<?=$key?>"></div>
                        </div>
                        <div class="sc-slot-item-id" id="sc-slot-id-<?=$key?>"><?= t('acp_sc_slot_empty', [], '— unassigned —') ?></div>
                        <div class="sc-slot-bar" id="sc-slot-bar-<?=$key?>"><span></span></div>
                        <div class="sc-slot-bonuses" id="sc-slot-bonuses-<?=$key?>"></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="sc-paperdoll-container" id="sc-paperdoll-box">
                    <div class="sc-paperdoll-silhouette"><i class="fas fa-user-shield"></i></div>
                    <?php foreach($sc_slots as $key=>$slot): 
                        $pd = $slot['paperdoll'] ?? ['x'=>50, 'y'=>50];
                    ?>
                    <div class="sc-paperdoll-node" id="sc-pd-node-<?=$key?>" style="left:<?=$pd['x']?>%; top:<?=$pd['y']?>%;" onclick="sc_focus_slot('<?=$key?>')">
                        <div class="sc-paperdoll-node-title"><?=$slot['label']?></div>
                        <div class="sc-paperdoll-node-item" id="sc-pd-item-<?=$key?>">—</div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($canEdit): ?>
            <div class="sc-card">
                <div class="sc-card-title"><i class="fas fa-hammer"></i> <?= t('acp_sc_card_generator', [], 'Item Generator') ?>
                    <span class="sc-hint" id="sc-gen-hint"><?= t('acp_sc_hint_generator', [], 'Writes actual rows to') ?> <code>itemtemplate</code></span>
                </div>
                <div class="sc-grid-4">
                    <div class="sc-field"><label class="sc-label"><?= t('acp_sc_label_mode', [], 'Mode') ?></label>
                        <select class="sc-select" id="sc-gen-mode">
                            <option value="clone"><?= t('acp_sc_opt_clone', [], 'Clone base item (keep models)') ?></option>
                            <option value="blank"><?= t('acp_sc_opt_blank', [], 'Generate blank (set model manually)') ?></option>
                        </select>
                    </div>
                    <div class="sc-field"><label class="sc-label"><?= t('acp_sc_label_prefix', [], 'Id_nb Prefix') ?></label><input type="text" class="sc-input" id="sc-gen-prefix" placeholder="<?= h(t('acp_sc_ph_auto', [], 'auto')) ?>">
                        <div class="acp-s-bc3f953b"><?= t('acp_sc_hint_prefix', [], 'Leave blank to auto-name items after the suit, e.g. suitname_torso, suitname_arms.') ?></div>
                    </div>
                    <div class="sc-field"><label class="sc-label"><?= t('acp_sc_label_level', [], 'Level') ?></label><input type="number" class="sc-input" id="sc-gen-level" value="50" min="1" max="100"></div>
                    <div class="sc-field"><label class="sc-label"><?= t('acp_sc_label_quality', [], 'Quality') ?></label><input type="number" class="sc-input" id="sc-gen-quality" value="100" min="1" max="100"></div>
                </div>
                <div class="sc-field"><label class="sc-label"><?= t('acp_sc_label_pattern', [], 'Naming Pattern') ?></label>
                    <input type="text" class="sc-input" id="sc-gen-pattern" value="{suit} {slot}" placeholder="{suit} {slot}">
                </div>
                <div class="acp-s-c8395ac5">
                    <button type="button" class="sc-btn sc-btn-secondary sc-btn-sm" id="sc-gen-dry"><i class="fas fa-eye"></i> <?= t('acp_sc_btn_preview', [], 'Preview (Dry Run)') ?></button>
                    <button type="button" class="sc-btn sc-btn-primary sc-btn-sm" id="sc-gen-run"><i class="fas fa-bolt"></i> <?= t('acp_sc_btn_write_items', [], 'Write Items') ?></button>
                    <span class="acp-s-88c832e9"><?= t('acp_sc_hint_overwrite', [], 'Existing Id_nb entries will be overwritten.') ?></span>
                </div>
                <div id="sc-gen-report" class="acp-s-002535c2"></div>
            </div>
            <?php endif; ?>

            <?php if ($ai_active): ?>
            <div class="sc-ai-panel">
                <div class="sc-ai-panel-title">
                    <i class="fas fa-robot"></i> <?= t('acp_sc_card_ai', [], 'AI Assistant') ?>
                    <span class="acp-s-03274235">
                        <?= h(ucfirst($botSettings->getProvider())) ?>
                    </span>
                </div>
                <button type="button" class="sc-ai-btn" id="sc-ai-autobuild-btn" onclick="sc_ai_autobuild()"><i class="fas fa-wand-magic-sparkles"></i> <?= t('acp_sc_btn_ai_suggest', [], 'Suggest Targets') ?></button>
                <button type="button" class="sc-ai-btn" id="sc-ai-balance-btn"   onclick="sc_ai_balance()"><i class="fas fa-balance-scale"></i> <?= t('acp_sc_btn_ai_balance', [], 'Check Balance') ?></button>
                <button type="button" class="sc-ai-btn" id="sc-ai-missing-btn"   onclick="sc_ai_suggest_missing()"><i class="fas fa-search-plus"></i> <?= t('acp_sc_btn_ai_missing', [], 'Find Gaps') ?></button>
                <div id="sc-ai-result" class="sc-ai-result"></div>
            </div>
            <?php endif; ?>

            <div class="acp-s-20fd462d">
                <?php if ($canEdit): ?>
                <button type="button" class="sc-btn sc-btn-primary" id="sc-save-btn"><i class="fas fa-save"></i> <?= t('acp_sc_btn_save', [], 'Save Suit') ?></button>
                <button type="button" class="sc-btn sc-btn-secondary" id="sc-validate-btn" onclick="sc_validate_combination()"><i class="fas fa-check-double"></i> <?= t('acp_sc_btn_validate', [], 'Validate Combo') ?></button>
                <button type="button" class="sc-btn sc-btn-secondary" id="sc-merchant-btn"><i class="fas fa-store"></i> <?= t('acp_sc_btn_export_merchant', [], 'Export to Merchant') ?></button>
                <button type="button" class="sc-btn sc-btn-secondary" id="sc-revisions-btn"><i class="fas fa-history"></i> <?= t('acp_sc_btn_revisions', [], 'Revisions') ?></button>
                <?php endif; ?>
                <button type="button" class="sc-btn sc-btn-secondary" id="sc-export-btn"><i class="fas fa-download"></i> <?= t('acp_sc_btn_export', [], 'Export') ?></button>
                <?php if ($canEdit): ?>
                <button type="button" class="sc-btn sc-btn-secondary" id="sc-import-btn"><i class="fas fa-upload"></i> <?= t('acp_sc_btn_import', [], 'Import') ?></button>
                <button type="button" class="sc-btn sc-btn-secondary" id="sc-scan-btn" title="<?= h(t('acp_sc_title_scan', [], 'Shows which BonusType IDs are actually used in your itemtemplate')) ?>"><i class="fas fa-microscope"></i> <?= t('acp_sc_btn_scan', [], 'Scan IDs') ?></button>
                <?php endif; ?>
                <span id="sc-save-status" class="acp-s-dcce4098"></span>
            </div>
        </div>
    </div>
</div>

<div class="sc-modal-overlay" id="sc-merchant-modal">
    <div class="sc-modal">
        <div class="sc-modal-title"><i class="fas fa-store"></i> <?= t('acp_sc_modal_merchant_title', [], 'Export Suit to Merchant') ?></div>
        <div class="sc-field"><label class="sc-label"><?= t('acp_sc_label_merchant_id', [], 'Merchant ItemListID') ?></label><input type="text" class="sc-input" id="sc-merchant-id" placeholder="<?= h(t('acp_sc_ph_merchant_id', [], 'e.g. my_merchant_list')) ?>"></div>
        <div class="sc-grid-2">
            <div class="sc-field"><label class="sc-label"><?= t('acp_sc_label_price', [], 'Total Price (Copper)') ?></label><input type="number" class="sc-input" id="sc-merchant-price" value="0" min="0"></div>
            <div class="sc-field"><label class="sc-label"><?= t('acp_sc_label_page', [], 'Page') ?></label><input type="number" class="sc-input" id="sc-merchant-page" value="0" min="0"></div>
        </div>
        <label class="acp-s-1d3172aa">
            <input type="checkbox" id="sc-merchant-usegen" checked class="acp-s-88b1dd7f">
            <?= t('acp_sc_label_use_generated', [], 'Prefer generated items (instead of base items)') ?>
        </label>
        <div class="sc-modal-actions">
            <button class="sc-btn sc-btn-secondary" data-close="sc-merchant-modal"><?= t('acp_sc_btn_cancel', [], 'Cancel') ?></button>
            <button class="sc-btn sc-btn-primary" id="sc-merchant-confirm"><i class="fas fa-upload"></i> <?= t('acp_sc_btn_export', [], 'Export') ?></button>
        </div>
    </div>
</div>

<div class="sc-modal-overlay" id="sc-generic-modal">
    <div class="sc-modal">
        <div class="sc-modal-title" id="sc-generic-title">—</div>
        <div id="sc-generic-body"></div>
        <div class="sc-modal-actions" id="sc-generic-actions">
            <button class="sc-btn sc-btn-secondary" data-close="sc-generic-modal"><?= t('acp_sc_btn_close', [], 'Close') ?></button>
        </div>
    </div>
</div>

<script>
const SC_AJAX     = 'acp.php?s=suit_creator&ajax=';
const SC_CSRF     = '<?= $csrf ?>';
const SC_PROFILES = <?= json_encode($profiles) ?>;
const SC_PROPS    = <?= json_encode($sc_props) ?>;
const SC_SLOTDEF  = <?= json_encode($sc_slots) ?>;
const SC_CLASSES  = <?= json_encode($sc_classes) ?>;
const SC_CAN_EDIT = <?= $canEdit ? 'true' : 'false' ?>;
const SC_ALL_SLOTS = Object.keys(SC_SLOTDEF);

const SC_LANG = <?= json_encode([
    'no_suits' => t('acp_sc_js_no_suits', [], 'No suits found.'),
    'all' => t('acp_sc_js_all', [], 'All'),
    'discard_changes' => t('acp_sc_js_discard_changes', [], 'Discard unsaved changes?'),
    'suit_not_found' => t('acp_sc_js_suit_not_found', [], 'Suit not found.'),
    'cap_per_item' => t('acp_sc_js_cap_per_item', [], 'Cap per item: '),
    'cap_all_done' => t('acp_sc_js_cap_all_done', [], 'values capped. Unselected groups remain unchanged.'),
    'cap_group_done' => t('acp_sc_js_cap_group_done', [], 'values capped.'),
    'targets_cleared' => t('acp_sc_js_targets_cleared', [], 'Targets cleared.'),
    'err_no_class' => t('acp_sc_js_err_no_class', [], 'Please select a class first.'),
    'preset_set' => t('acp_sc_js_preset_set', [], 'preset applied.'),
    'armor' => t('acp_sc_js_armor', [], 'Armor: '),
    'class_id' => t('acp_sc_js_class_id', [], 'Class ID '),
    'hint_blank_class' => t('acp_sc_js_hint_blank_class', [], 'Without a class, Blank mode cannot determine the armor type — use Clone mode instead.'),
    'hud_no_targets' => t('acp_sc_hud_no_targets', [], 'No targets set'),
    'fits' => t('acp_sc_js_fits', [], 'fits · '),
    'occupied' => t('acp_sc_js_occupied', [], '% occupied'),
    'too_much' => t('acp_sc_js_too_much', [], ' too much · needs '),
    'of' => t('acp_sc_js_of', [], 'of '),
    'slots_active' => t('acp_sc_js_slots_active', [], 'slots active'),
    'err_no_slot' => t('acp_sc_js_err_no_slot', [], 'Activate at least one slot.'),
    'err_no_target' => t('acp_sc_js_err_no_target', [], 'Set at least one target value.'),
    'distributing' => t('acp_sc_js_distributing', [], 'Distributing...'),
    'err_distribute' => t('acp_sc_js_err_distribute', [], 'Error distributing: '),
    'leftover' => t('acp_sc_js_leftover', [], 'left over'),
    'not_all_fit' => t('acp_sc_js_not_all_fit', [], 'Could not fit everything — '),
    'distribute_warn' => t('acp_sc_js_distribute_warn', [], 'Distributed, but the budget is insufficient for everything. Details below the button.'),
    'distribute_on' => t('acp_sc_js_distribute_on', [], 'Distributed across '),
    'utility_used' => t('acp_sc_js_utility_used', [], ' Utility used.'),
    'stats_distributed' => t('acp_sc_js_stats_distributed', [], 'Stats distributed.'),
    'slot_empty' => t('acp_sc_slot_empty', [], '— unassigned —'),
    'only_bonuses' => t('acp_sc_js_only_bonuses', [], '⚡ bonuses only (no base item)'),
    'search_empty' => t('acp_sc_js_search_empty', [], 'Nothing found for this slot.'),
    'search_all_types' => t('acp_sc_js_search_all_types', [], 'Search all types'),
    'search_no_hits' => t('acp_sc_js_search_no_hits', [], 'No matches.'),
    'item_not_found' => t('acp_sc_js_item_not_found', [], 'Item not found.'),
    'no_bonuses' => t('acp_sc_js_no_bonuses', [], 'No bonuses.'),
    'err_needs_name' => t('acp_sc_js_err_needs_name', [], 'The suit needs a name.'),
    'saving' => t('acp_sc_js_saving', [], 'Saving...'),
    'saved' => t('acp_sc_js_saved', [], 'Saved ✓'),
    'suit_saved' => t('acp_sc_js_suit_saved', [], 'Suit saved.'),
    'err_save' => t('acp_sc_js_err_save', [], 'Save failed: '),
    'confirm_delete' => t('acp_sc_js_confirm_delete', [], 'Really delete this suit? A version will be backed up beforehand.'),
    'suit_deleted' => t('acp_sc_js_suit_deleted', [], 'Suit deleted.'),
    'err_delete' => t('acp_sc_js_err_delete', [], 'Delete failed: '),
    'dupe_done' => t('acp_sc_js_dupe_done', [], 'Copy created.'),
    'err_dupe' => t('acp_sc_js_err_dupe', [], 'Duplication failed.'),
    'err_save_first' => t('acp_sc_js_err_save_first', [], 'Please save first.'),
    'err_no_stats' => t('acp_sc_js_err_no_stats', [], 'Distribute stats first — nothing to generate.'),
    'working' => t('acp_sc_js_working', [], 'Working...'),
    'generator' => t('acp_sc_js_generator', [], 'Generator: '),
    'written' => t('acp_sc_js_written', [], 'written'),
    'preview' => t('acp_sc_js_preview', [], 'preview'),
    'skipped' => t('acp_sc_js_skipped', [], 'skipped'),
    'error' => t('acp_sc_js_error', [], 'error'),
    'bonuses_dont_fit' => t('acp_sc_js_bonuses_dont_fit', [], " bonuses don't fit (max "),
    'preview_done' => t('acp_sc_js_preview_done', [], 'Preview generated — nothing written yet.'),
    'items_written' => t('acp_sc_js_items_written', [], ' items written.'),
    'confirm_write' => t('acp_sc_js_confirm_write', [], 'Write items to itemtemplate now? Existing entries with the same Id_nb will be overwritten.'),
    'err_no_merchant_id' => t('acp_sc_js_err_no_merchant_id', [], 'Merchant ID missing.'),
    'items_to' => t('acp_sc_js_items_to', [], ' items exported to "'),
    'exported' => t('acp_sc_js_exported', [], '" ('),
    'per_item' => t('acp_sc_js_per_item', [], ' per item).'),
    'err_export' => t('acp_sc_js_err_export', [], 'Export failed: '),
    'no_revisions' => t('acp_sc_js_no_revisions', [], 'No revisions yet. They are created automatically upon saving.'),
    'restore' => t('acp_sc_js_restore', [], 'Restore'),
    'confirm_restore' => t('acp_sc_js_confirm_restore', [], 'Restore this version? The current one will be backed up.'),
    'restore_done' => t('acp_sc_js_restore_done', [], 'Version restored.'),
    'err_restore' => t('acp_sc_js_err_restore', [], 'Restore failed.'),
    'import_suit' => t('acp_sc_js_import_suit', [], 'Import Suit'),
    'insert_json' => t('acp_sc_js_insert_json', [], 'Insert JSON'),
    'btn_import' => t('acp_sc_btn_import', [], 'Import'),
    'import_done' => t('acp_sc_js_import_done', [], 'Suit imported.'),
    'err_import' => t('acp_sc_js_err_import', [], 'Import failed: '),
    'err_scan' => t('acp_sc_js_err_scan', [], 'Scan failed: '),
    'known' => t('acp_sc_js_known', [], 'known'),
    'missing_prop' => t('acp_sc_js_missing_prop', [], 'MISSING in SC_PROPERTIES'),
    'armor_by_class' => t('acp_sc_js_armor_by_class', [], 'Armor - depends on class'),
    'differs_from' => t('acp_sc_js_differs_from', [], 'differs from '),
    'differs_off' => t('acp_sc_js_differs_off', [], '', ''),
    'fits_ok' => t('acp_sc_js_fits_ok', [], 'matches'),
    'enum_sync' => t('acp_sc_js_enum_sync', [], 'Enum sync with itemtemplate'),
    'col_schema' => t('acp_sc_js_col_schema', [], 'Column schema: '),
    'bonus_slots' => t('acp_sc_js_bonus_slots', [], ' bonus slots.'),
    'missing_rows_go_in' => t('acp_sc_js_missing_rows_go_in', [], 'Rows marked "MISSING" belong in '),
    'block1_logic' => t('acp_sc_js_block1_logic', [], ' (Block 1 of the logic file).'),
    'slots_which' => t('acp_sc_js_slots_which', [], 'Slots: which '),
    'hang_on_which' => t('acp_sc_js_hang_on_which', [], ' belong to which '),
    'err_request' => t('acp_sc_js_err_request', [], 'Request failed: '),
    'err_save_balance' => t('acp_sc_js_err_save_balance', [], 'Save first — then the balance can be fully checked.'),
    'checking_balance' => t('acp_sc_js_checking_balance', [], 'Checking balance...'),
    'error_prefix' => t('acp_sc_js_error_prefix', [], 'Error: '),
    'search_missing' => t('acp_sc_js_search_missing', [], 'Finding gaps...'),
    'ai_prompt' => t('acp_sc_js_ai_prompt', [], 'What should the suit focus on? (optional, e.g. "RvR ready, focus on survival")'),
    'ai_designing' => t('acp_sc_js_ai_designing', [], 'Designing targets...'),
    'ai_targets_taken' => t('acp_sc_js_ai_targets_taken', [], 'Targets applied.'),
    'ai_values_set' => t('acp_sc_js_ai_values_set', [], ' values set — review them and distribute.'),
    'val_ok' => t('acp_sc_val_ok', [], 'Combination is completely valid!'),
    'val_title' => t('acp_sc_val_title', [], 'Validation Results'),
    'btn_close' => t('acp_sc_btn_close', [], 'Close')
], JSON_UNESCAPED_UNICODE) ?>;

let sc_current_id   = 0;
let sc_assign       = {};
let sc_active_slots = new Set(SC_ALL_SLOTS);
let sc_search_timers = {};
let sc_dirty = false;

const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

function sc_flash(msg, type) {
    if (type === undefined) type = 'ok';
    const el = $('sc-flash');
    el.textContent = msg; el.className = 'sc-flash ' + type; el.style.display = 'block';
    clearTimeout(el._t); el._t = setTimeout(function() { el.style.display = 'none'; }, 4000);
}
function sc_profile()  { return SC_PROFILES[$('sc-server-type').value] || SC_PROFILES['i50']; }
function sc_capFor(type) {
    const p = SC_PROPS[type]; if (!p) return 0;
    return sc_profile().caps[p.group] || 0;
}
function sc_markDirty() { sc_dirty = true; }

document.querySelectorAll('[data-close]').forEach(function(b) {
    b.addEventListener('click', function() { $(b.dataset.close).classList.remove('open'); });
});
document.querySelectorAll('.sc-modal-overlay').forEach(function(o) {
    o.addEventListener('click', function(e) { if (e.target === o) o.classList.remove('open'); });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.querySelectorAll('.sc-modal-overlay.open').forEach(function(o) { o.classList.remove('open'); });
});
function sc_modal(title, html, actionsHtml) {
    $('sc-generic-title').innerHTML = title;
    $('sc-generic-body').innerHTML  = html;
    $('sc-generic-actions').innerHTML = (actionsHtml || '') +
        '<button class="sc-btn sc-btn-secondary" data-close="sc-generic-modal">' + SC_LANG.btn_close + '</button>';
    $('sc-generic-actions').querySelectorAll('[data-close]').forEach(function(b) {
        b.addEventListener('click', function() { $('sc-generic-modal').classList.remove('open'); });
    });
    $('sc-generic-modal').classList.add('open');
}

$('sc-toggle-view-grid').addEventListener('click', function() {
    $('sc-slot-grid').style.display = 'grid';
    $('sc-paperdoll-box').classList.remove('active');
});
$('sc-toggle-view-doll').addEventListener('click', function() {
    $('sc-slot-grid').style.display = 'none';
    $('sc-paperdoll-box').classList.add('active');
});

function sc_focus_slot(slot) {
    $('sc-toggle-view-grid').click();
    const card = $('sc-slot-' + slot);
    if (card) card.scrollIntoView({behavior:'smooth'});
}

function sc_validate_combination() {
    const classKey = $('sc-class').value;
    const fd = new FormData();
    fd.append('csrf_token', SC_CSRF);
    fd.append('class_key', classKey);
    fd.append('items', JSON.stringify(sc_assign));

    fetch(SC_AJAX + 'validate_combination', {method:'POST', body:fd})
        .then(function(r) { return r.json(); })
        .then(function(d) {
            let html = '';
            if (d.valid && !d.warnings.length) {
                html = '<div class="sc-tag-ok"><i class="fas fa-check-circle"></i> ' + SC_LANG.val_ok + '</div>';
            } else {
                if (d.errors.length) {
                    html += '<div class="acp-s-f8ae83c3"><strong>Errors:</strong><ul>' +
                        d.errors.map(function(e) { return '<li>' + esc(e) + '</li>'; }).join('') + '</ul></div>';
                }
                if (d.warnings.length) {
                    html += '<div class="acp-s-ed2003de"><strong>Warnings:</strong><ul>' +
                        d.warnings.map(function(w) { return '<li>' + esc(w) + '</li>'; }).join('') + '</ul></div>';
                }
            }
            sc_modal('<i class="fas fa-check-double"></i> ' + SC_LANG.val_title, html);
        })
        .catch(function(e) { sc_flash(SC_LANG.err_request + e, 'err'); });
}

function sc_load_list() {
    const q = $('sc-list-filter').value.trim();
    fetch(SC_AJAX + 'list' + (q ? '&q=' + encodeURIComponent(q) : ''))
        .then(function(r) { return r.json(); })
        .then(function(suits) {
            const list = $('sc-suit-list');
            if (!Array.isArray(suits)) {
                list.innerHTML = '<div class="acp-s-2ec03dab">' + SC_LANG.err_request + (suits.error || 'invalid_response') + '</div>';
                return;
            }
            if (!suits.length) {
                list.innerHTML = '<div class="acp-s-2ec03dab">' + SC_LANG.no_suits + '</div>';
                return;
            }
            const realmMap = {0: SC_LANG.all, 1: 'Albion', 2: 'Midgard', 3: 'Hibernia'};
            list.innerHTML = suits.map(function(s) {
                const cls = s.class_key && SC_CLASSES[s.class_key] ? SC_CLASSES[s.class_key].label : s.archetype;
                return '<div class="sc-list-item' + (s.id == sc_current_id ? ' active' : '') + '" data-id="' + s.id + '">' +
                    '<div class="sc-list-name">' + esc(s.name) + '</div>' +
                    '<div class="sc-list-meta">' + (realmMap[s.realm] || '?') + ' · ' + esc((s.server_type||'').toUpperCase()) + ' · ' + esc(cls) + '</div>' +
                '</div>';
            }).join('');
            list.querySelectorAll('.sc-list-item').forEach(function(el) {
                el.addEventListener('click', function() { sc_load(parseInt(el.dataset.id)); });
            });
        })
        .catch(function(e) { sc_flash(SC_LANG.err_request + e, 'err'); });
}
let sc_listTimer;
$('sc-list-filter').addEventListener('input', function() {
    clearTimeout(sc_listTimer); sc_listTimer = setTimeout(sc_load_list, 220);
});
sc_load_list();

function sc_load(id) {
    if (sc_dirty && !confirm(SC_LANG.discard_changes)) return;
    fetch(SC_AJAX + 'load&id=' + id).then(function(r) { return r.json(); }).then(function(suit) {
        if (suit.error) { sc_flash(SC_LANG.suit_not_found, 'err'); return; }
        sc_current_id = parseInt(suit.id);
        $('sc-suit-id').value    = suit.id;
        $('sc-name').value        = suit.name || '';
        $('sc-description').value = suit.description || '';
        $('sc-realm').value       = suit.realm || 0;
        $('sc-archetype').value   = suit.archetype || 'custom';
        $('sc-class').value       = suit.class_key || '';
        $('sc-server-type').value = suit.server_type || 'i50';
        document.querySelectorAll('.sc-profile-card').forEach(function(c) {
            c.classList.toggle('active', c.dataset.profile === (suit.server_type || 'i50'));
        });
        sc_show_profile_info();
        sc_class_hint();

        sc_clear_targets(false);
        const tg = suit.target_stats || {};
        Object.entries(tg).forEach(function(entry) {
            const t = entry[0], v = entry[1];
            const el = document.querySelector('.sc-stat-target[data-type="' + t + '"]');
            if (el) el.value = v;
        });

        const gs = suit.gen_settings || {};
        if (gs.mode)    $('sc-gen-mode')    && ($('sc-gen-mode').value = gs.mode);
        if (gs.prefix)  $('sc-gen-prefix')  && ($('sc-gen-prefix').value = gs.prefix);
        if (gs.level)   $('sc-gen-level')   && ($('sc-gen-level').value = gs.level);
        if (gs.quality) $('sc-gen-quality') && ($('sc-gen-quality').value = gs.quality);
        if (gs.pattern) $('sc-gen-pattern') && ($('sc-gen-pattern').value = gs.pattern);

        sc_assign = {};
        sc_reset_slot_ui();
        (suit.items || []).forEach(function(it) {
            if (!it.slot || !SC_SLOTDEF[it.slot]) return;
            sc_assign[it.slot] = {
                item_template_id: it.item_template_id || '',
                bonuses: it.bonuses || [],
                generated_id_nb: it.generated_id_nb || '',
            };
            sc_render_slot(it.slot);
        });

        $('sc-empty-state').style.display = 'none';
        $('sc-form').style.display = 'block';
        if (SC_CAN_EDIT) {
            if ($('sc-delete-btn')) $('sc-delete-btn').disabled = false;
            if ($('sc-dupe-btn')) $('sc-dupe-btn').disabled = false;
        }
        sc_dirty = false;
        sc_recalc();
        sc_load_list();
        if (typeof sc_ai_reset === 'function') sc_ai_reset();
    }).catch(function(e) { sc_flash(SC_LANG.err_request + e, 'err'); });
}

if ($('sc-new-btn')) {
    $('sc-new-btn').addEventListener('click', function() {
        if (sc_dirty && !confirm(SC_LANG.discard_changes)) return;
        sc_current_id = 0;
        $('sc-suit-id').value = 0;
        ['sc-name','sc-description'].forEach(function(i) { $(i).value = ''; });
        $('sc-realm').value = 0; $('sc-archetype').value = 'custom'; $('sc-class').value = '';
        $('sc-server-type').value = 'i50';
        document.querySelectorAll('.sc-profile-card').forEach(function(c) {
            c.classList.toggle('active', c.dataset.profile === 'i50');
        });
        sc_show_profile_info();
        sc_clear_targets(false);
        sc_assign = {};
        sc_reset_slot_ui();
        $('sc-empty-state').style.display = 'none';
        $('sc-form').style.display = 'block';
        if ($('sc-delete-btn')) $('sc-delete-btn').disabled = true;
        if ($('sc-dupe-btn')) $('sc-dupe-btn').disabled = true;
        $('sc-name').focus();
        sc_dirty = false;
        sc_recalc();
        if (typeof sc_ai_reset === 'function') sc_ai_reset();
    });
}

document.querySelectorAll('.sc-profile-card').forEach(function(card) {
    card.addEventListener('click', function() {
        document.querySelectorAll('.sc-profile-card').forEach(function(c) { c.classList.remove('active'); });
        card.classList.add('active');
        $('sc-server-type').value = card.dataset.profile;
        sc_show_profile_info(); sc_markDirty(); sc_recalc();
    });
});

function sc_show_profile_info() {
    const p = sc_profile(), c = p.caps;
    $('sc-profile-info').textContent =
        `Utility/Item ${p.max_utility} · Stat ${c.stat} · Resist ${c.resist} · Hits ${c.hits} · Skill ${c.skill}`;
    document.querySelectorAll('.sc-stat-target').forEach(function(el) {
        const cap = sc_capFor(parseInt(el.dataset.type));
        el.max = cap * SC_ALL_SLOTS.length;
        el.placeholder = '0';
        el.title = SC_LANG.cap_per_item + cap;
    });
}
sc_show_profile_info();

function sc_groupEnabled(g) {
    const cb = document.querySelector('.sc-group-check[value="' + g + '"]');
    return !cb || cb.checked;
}
function sc_capGroup(group) {
    let n = 0;
    document.querySelectorAll('.sc-stat-target[data-group="' + group + '"]').forEach(function(el) {
        el.value = sc_capFor(parseInt(el.dataset.type)); n++;
    });
    sc_markDirty(); sc_recalc();
    return n;
}
$('sc-cap-all-btn').addEventListener('click', function() {
    let n = 0;
    document.querySelectorAll('.sc-stat-target').forEach(function(el) {
        if (!sc_groupEnabled(el.dataset.group)) return;
        el.value = sc_capFor(parseInt(el.dataset.type)); n++;
    });
    sc_markDirty(); sc_recalc();
    sc_flash(n + ' ' + SC_LANG.cap_all_done, 'ok');
});
document.querySelectorAll('[data-capgroup]').forEach(function(b) {
    b.addEventListener('click', function() {
        const n = sc_capGroup(b.dataset.capgroup);
        sc_flash(n + ' ' + SC_LANG.cap_group_done, 'ok');
    });
});
document.querySelectorAll('.sc-cap-one').forEach(function(b) {
    b.addEventListener('click', function() {
        const el = document.querySelector('.sc-stat-target[data-type="' + b.dataset.type + '"]');
        if (el) { el.value = sc_capFor(parseInt(b.dataset.type)); sc_markDirty(); sc_recalc(); }
    });
});
function sc_clear_targets(flash) {
    if (flash === undefined) flash = true;
    document.querySelectorAll('.sc-stat-target').forEach(function(el) { el.value = 0; });
    sc_recalc();
    if (flash) { sc_markDirty(); sc_flash(SC_LANG.targets_cleared, 'ok'); }
}
$('sc-clear-targets').addEventListener('click', function() { sc_clear_targets(true); });

$('sc-class-preset-btn').addEventListener('click', function() {
    const ck = $('sc-class').value;
    if (!ck || !SC_CLASSES[ck]) { sc_flash(SC_LANG.err_no_class, 'err'); return; }
    const c = SC_CLASSES[ck];
    document.querySelectorAll('.sc-stat-target').forEach(function(el) { el.value = 0; });
    c.stats.forEach(function(t) {
        const el = document.querySelector('.sc-stat-target[data-type="' + t + '"]');
        if (el) el.value = sc_capFor(t);
    });
    document.querySelectorAll('.sc-stat-target[data-group="hits"]').forEach(function(el) { el.value = sc_capFor(parseInt(el.dataset.type)); });
    document.querySelectorAll('.sc-stat-target[data-group="resist"]').forEach(function(el) { el.value = sc_capFor(parseInt(el.dataset.type)); });
    if (c.caster) document.querySelectorAll('.sc-stat-target[data-group="power"]').forEach(function(el) { el.value = sc_capFor(parseInt(el.dataset.type)); });
    $('sc-realm').value = c.realm;
    sc_markDirty(); sc_recalc();
    sc_flash('Preset "' + c.label + '" ' + SC_LANG.preset_set, 'ok');
});
$('sc-class').addEventListener('change', function() {
    const c = SC_CLASSES[this.value];
    if (c) $('sc-realm').value = c.realm;
    sc_class_hint();
    sc_markDirty();
});
function sc_class_hint() {
    const el = $('sc-gen-hint'); if (!el) return;
    const c = SC_CLASSES[$('sc-class').value];
    el.textContent = c
        ? SC_LANG.armor + c.armor + ' · ' + SC_LANG.class_id + (c.dol_id || '?')
        : SC_LANG.hint_blank_class;
}
sc_class_hint();

document.querySelectorAll('.sc-slot-check').forEach(function(cb) {
    cb.addEventListener('change', function() {
        if (this.checked) sc_active_slots.add(this.value); else sc_active_slots.delete(this.value);
        $('sc-slot-' + this.value).classList.toggle('disabled', !this.checked);
        $('sc-seg-' + this.value).classList.toggle('off', !this.checked);
        sc_recalc();
    });
});

function sc_targets() {
    const t = {};
    document.querySelectorAll('.sc-stat-target').forEach(function(el) {
        if (!sc_groupEnabled(el.dataset.group)) return;
        const type = parseInt(el.dataset.type), val = parseInt(el.value) || 0;
        if (type > 0 && val > 0) t[type] = val;
        el.classList.toggle('capped', val > 0 && val === sc_capFor(type));
    });
    return t;
}
function sc_recalc() {
    const prof = sc_profile();
    const slots = Array.from(sc_active_slots);
    const n = slots.length;
    const targets = sc_targets();

    let required = 0;
    Object.entries(targets).forEach(function(entry) {
        const t = entry[0], v = entry[1];
        const p = SC_PROPS[t]; if (!p) return;
        required += Math.min(v, sc_capFor(parseInt(t)) * n) * p.weight;
    });
    const capacity = n * prof.max_utility;

    $('sc-hud-used').innerHTML = required.toFixed(1) + '<small>/' + capacity.toFixed(0) + ' Utility</small>';
    const st = $('sc-hud-state');
    if (required <= 0)          { st.className = 'sc-hud-state idle'; st.textContent = SC_LANG.hud_no_targets; }
    else if (required <= capacity) { st.className = 'sc-hud-state fit';  st.textContent = SC_LANG.fits + Math.round(required/capacity*100) + SC_LANG.occupied; }
    else {
        st.className = 'sc-hud-state over';
        const need = Math.ceil(required / prof.max_utility);
        st.textContent = (required-capacity).toFixed(1) + SC_LANG.too_much + need + ' Slots';
    }
    $('sc-hud-note').textContent = n + ' ' + SC_LANG.of + SC_ALL_SLOTS.length + ' ' + SC_LANG.slots_active;

    SC_ALL_SLOTS.forEach(function(s) {
        const seg = $('sc-seg-' + s), fill = seg.querySelector('.sc-seg-fill');
        const util = sc_slotUtil(s);
        const pct = prof.max_utility > 0 ? Math.min(100, util / prof.max_utility * 100) : 0;
        fill.style.height = pct + '%';
        seg.classList.toggle('full', pct >= 99);
        seg.title = SC_SLOTDEF[s].label + ': ' + util.toFixed(1) + ' / ' + prof.max_utility;
        const bar = $('sc-slot-bar-' + s);
        if (bar) { bar.querySelector('span').style.width = pct + '%'; bar.classList.toggle('full', pct >= 99); }
        const u = $('sc-slot-util-' + s);
        if (u) u.textContent = util > 0 ? util.toFixed(1) : '0';
        $('sc-slot-' + s).classList.toggle('filled', util > 0);
    });

    sc_render_coverage(targets);
}
function sc_slotUtil(slot) {
    const b = (sc_assign[slot] && sc_assign[slot].bonuses) || [];
    return b.reduce(function(sum, x) { return sum + x.value * ((SC_PROPS[x.type] || {weight:1}).weight); }, 0);
}
function sc_render_coverage(targets) {
    const have = {};
    Object.values(sc_assign).forEach(function(a) {
        (a.bonuses || []).forEach(function(b) {
            have[b.type] = (have[b.type] || 0) + b.value;
        });
    });
    const keys = Object.keys(targets);
    if (!keys.length) { $('sc-cov').innerHTML = ''; return; }
    $('sc-cov').innerHTML = keys.map(function(t) {
        const want = targets[t], got = have[t] || 0;
        const p = SC_PROPS[t] || {short:'#'+t};
        const cls = got >= want ? 'ok' : (got > 0 ? 'miss' : '');
        return '<span class="sc-cov-chip ' + cls + '" title="' + esc(p.label||'') + '">' + esc(p.short||p.label) + ' ' + got + '/' + want + '</span>';
    }).join('');
}

$('sc-distribute-btn').addEventListener('click', sc_distribute);
function sc_distribute() {
    const targets = sc_targets();
    const slots = Array.from(sc_active_slots);
    if (!slots.length)                 { sc_flash(SC_LANG.err_no_slot, 'err'); return; }
    if (!Object.keys(targets).length)  { sc_flash(SC_LANG.err_no_target, 'err'); return; }

    const fd = new FormData();
    fd.append('csrf_token', SC_CSRF);
    fd.append('targets', JSON.stringify(targets));
    fd.append('slots', JSON.stringify(slots));
    fd.append('server_type', $('sc-server-type').value);
    fd.append('max_bonuses', $('sc-max-bonuses').value);
    $('sc-dist-status').textContent = SC_LANG.distributing;

    fetch(SC_AJAX + 'distribute', {method:'POST', body:fd}).then(function(r) { return r.json(); }).then(function(res) {
        if (!res.ok) { $('sc-dist-status').textContent = ''; sc_flash(SC_LANG.err_distribute + (res.error||'?'), 'err'); return; }
        Object.entries(res.items).forEach(function(entry) {
            const slot = entry[0], data = entry[1];
            if (!sc_assign[slot]) sc_assign[slot] = {item_template_id:'', bonuses:[]};
            sc_assign[slot].bonuses = data.bonuses || [];
            sc_render_slot(slot);
        });
        SC_ALL_SLOTS.filter(function(s) { return !sc_active_slots.has(s); }).forEach(function(s) {
            if (sc_assign[s]) { sc_assign[s].bonuses = []; sc_render_slot(s); }
        });
        sc_markDirty(); sc_recalc();

        const un = res.summary.unplaced || {};
        const unKeys = Object.keys(un);
        if (unKeys.length) {
            const txt = unKeys.map(function(t) { return (SC_PROPS[t]||{label:'#'+t}).label + ': ' + un[t] + ' ' + SC_LANG.leftover; }).join(', ');
            $('sc-dist-status').innerHTML = '<span class="acp-s-cd1eb893">' + SC_LANG.not_all_fit + esc(txt) + '</span>';
            sc_flash(SC_LANG.distribute_warn, 'err');
        } else {
            $('sc-dist-status').textContent = SC_LANG.distribute_on + res.summary.slots + ' Slots · ' + res.summary.total_utility + SC_LANG.utility_used;
            sc_flash(SC_LANG.stats_distributed, 'ok');
        }
    }).catch(function(e) {
        $('sc-dist-status').textContent = '';
        sc_flash(SC_LANG.err_request + e, 'err');
    });
}

function sc_reset_slot_ui() {
    SC_ALL_SLOTS.forEach(function(s) {
        $('sc-slot-id-' + s).textContent = SC_LANG.slot_empty;
        $('sc-slot-bonuses-' + s).innerHTML = '';
        const si = document.querySelector('#sc-slot-search-wrap-' + s + ' input');
        if (si) si.value = '';
        const pdItem = $('sc-pd-item-' + s);
        if (pdItem) pdItem.textContent = '—';
        const pdNode = $('sc-pd-node-' + s);
        if (pdNode) pdNode.classList.remove('filled');
    });
}
function sc_render_slot(slot) {
    const a = sc_assign[slot] || {};
    const idEl = $('sc-slot-id-' + slot);
    const pdItem = $('sc-pd-item-' + slot);
    const pdNode = $('sc-pd-node-' + slot);

    let displayTitle = '—';
    if (a.generated_id_nb) {
        displayTitle = a.generated_id_nb;
        idEl.innerHTML = '<span onclick="sc_delve(\'' + esc(a.generated_id_nb).replace(/'/g,"") + '\')" class="acp-s-158a5751">' + esc(a.generated_id_nb) + '</span>';
    } else if (a.item_template_id) {
        displayTitle = a.item_template_id;
        idEl.innerHTML = '<span onclick="sc_delve(\'' + esc(a.item_template_id).replace(/'/g,"") + '\')" class="acp-s-a7e074d5">' + esc(a.item_template_id) + '</span>';
    } else if ((a.bonuses || []).length) {
        displayTitle = '⚡ Bonuses';
        idEl.innerHTML = '<span class="acp-s-ed2003de">' + SC_LANG.only_bonuses + '</span>';
    } else {
        idEl.textContent = SC_LANG.slot_empty;
    }

    if (pdItem) pdItem.textContent = displayTitle;
    if (pdNode) pdNode.classList.toggle('filled', displayTitle !== '—');

    $('sc-slot-bonuses-' + slot).innerHTML = (a.bonuses || []).map(function(b) {
        const p = SC_PROPS[b.type] || {short:'#'+b.type, label:'Type '+b.type};
        return '<span class="sc-dist-badge" title="' + esc(p.label) + '">+' + b.value + ' ' + esc(p.short) + '</span>';
    }).join('');
}

document.querySelectorAll('.sc-slot-search-input').forEach(function(input) {
    input.addEventListener('input', function() {
        const slot = this.dataset.slot, q = this.value.trim();
        clearTimeout(sc_search_timers[slot]);
        const sr = $('sc-slot-sr-' + slot);
        if (q.length < 2) { sr.classList.remove('open'); return; }
        sc_search_timers[slot] = setTimeout(function() {
            fetch(SC_AJAX + 'search_items&slot=' + slot + '&q=' + encodeURIComponent(q))
                .then(function(r) { return r.json(); })
                .then(function(items) {
                    if (!items.length) {
                        sr.innerHTML = '<div class="sc-slot-sr-item acp-s-29ee2084">' + SC_LANG.search_empty + ' ' +
                            '<a href="#" data-all="' + slot + '" class="acp-s-ed2003de">' + SC_LANG.search_all_types + '</a></div>';
                    } else {
                        sr.innerHTML = items.map(function(it) {
                            return '<div class="sc-slot-sr-item" data-id="' + esc(it.Id_nb) + '" data-name="' + esc(it.Name) + '">' +
                                esc(it.Name) + ' <span class="acp-s-29ee2084">L' + (it.Level || '?') + ' · ' + esc(it.Id_nb) + '</span></div>';
                        }).join('');
                    }
                    sr.querySelectorAll('[data-id]').forEach(function(el) {
                        el.addEventListener('click', function() { sc_assign_slot(slot, el.dataset.id, el.dataset.name); });
                    });
                    const all = sr.querySelector('[data-all]');
                    if (all) all.addEventListener('click', function(e) {
                        e.preventDefault();
                        fetch(SC_AJAX + 'search_items&all=1&q=' + encodeURIComponent(q))
                            .then(function(r) { return r.json(); }).then(function(items2) {
                                sr.innerHTML = items2.length
                                    ? items2.map(function(it) { return '<div class="sc-slot-sr-item" data-id="' + esc(it.Id_nb) + '" data-name="' + esc(it.Name) + '">' + esc(it.Name) + ' <span class="acp-s-29ee2084">' + esc(it.Id_nb) + '</span></div>'; }).join('')
                                    : '<div class="sc-slot-sr-item acp-s-29ee2084">' + SC_LANG.search_no_hits + '</div>';
                                sr.querySelectorAll('[data-id]').forEach(function(el) {
                                    el.addEventListener('click', function() { sc_assign_slot(slot, el.dataset.id, el.dataset.name); });
                                });
                            }).catch(function(e) { sc_flash(SC_LANG.err_request + e, 'err'); });
                    });
                    sr.classList.add('open');
                })
                .catch(function(e) { sc_flash(SC_LANG.err_request + e, 'err'); });
        }, 250);
    });
});
document.addEventListener('click', function(e) {
    if (!e.target.closest('.sc-slot-search'))
        document.querySelectorAll('.sc-slot-search-results').forEach(function(el) { el.classList.remove('open'); });
});
function sc_assign_slot(slot, item_id, item_name) {
    if (!sc_assign[slot]) sc_assign[slot] = {bonuses: []};
    sc_assign[slot].item_template_id = item_id;
    sc_render_slot(slot);
    $('sc-slot-sr-' + slot).classList.remove('open');
    const input = document.querySelector('#sc-slot-search-wrap-' + slot + ' input');
    if (input) input.value = item_name;
    sc_markDirty(); sc_recalc();
}

function sc_delve(id) {
    fetch(SC_AJAX + 'item_delve&id=' + encodeURIComponent(id)).then(function(r) { return r.json(); }).then(function(d) {
        if (!d.ok) { sc_flash(SC_LANG.item_not_found, 'err'); return; }
        const i = d.item;
        const rows = d.bonuses.map(function(b) {
            const p = SC_PROPS[b.type] || {label: 'Type ' + b.type, group: ''};
            const unit = p.group === 'resist' ? '%' : '';
            return '<div class="acp-s-d544a354">+' + b.value + unit + ' ' + esc(p.label) + '</div>';
        }).join('') || '<div class="acp-s-6ba8f8cb">' + SC_LANG.no_bonuses + '</div>';
        sc_modal('<i class="fas fa-scroll"></i> ' + esc(i.Name),
            '<div class="acp-s-57cf1aff">' +
                '<div class="acp-s-8aee0bcf">' + esc(i.Name) + '</div>' +
                '<div class="acp-s-700f636b">' + esc(i.Id_nb) + ' · Level ' + i.Level + ' · Quality ' + i.Quality + '% · Model ' + i.Model + '</div>' +
                rows +
                '<div class="acp-s-3943e11a">' +
                    'Utility: <span class="acp-s-ed2003de">' + d.utility + '</span>' +
                '</div>' +
            '</div>');
    }).catch(function(e) { sc_flash(SC_LANG.err_request + e, 'err'); });
}

<?php if ($canEdit): ?>
function sc_save() {
    const name = $('sc-name').value.trim();
    if (!name) { sc_flash(SC_LANG.err_needs_name, 'err'); $('sc-name').focus(); return; }
    const items = {};
    Object.entries(sc_assign).forEach(function(entry) {
        const slot = entry[0], a = entry[1];
        if (!a.item_template_id && !(a.bonuses || []).length) return;
        items[slot] = {item_template_id: a.item_template_id || '', bonuses: a.bonuses || []};
    });
    const fd = new FormData();
    fd.append('csrf_token', SC_CSRF);
    fd.append('suit_id', sc_current_id);
    fd.append('name', name);
    fd.append('description', $('sc-description').value);
    fd.append('realm', $('sc-realm').value);
    fd.append('server_type', $('sc-server-type').value);
    fd.append('archetype', $('sc-archetype').value);
    fd.append('class_key', $('sc-class').value);
    fd.append('targets', JSON.stringify(sc_targets()));
    fd.append('gen_settings', JSON.stringify({
        mode: $('sc-gen-mode').value, prefix: $('sc-gen-prefix').value,
        level: $('sc-gen-level').value, quality: $('sc-gen-quality').value,
        pattern: $('sc-gen-pattern').value,
    }));
    fd.append('items', JSON.stringify(items));
    $('sc-save-status').textContent = SC_LANG.saving;
    fetch(SC_AJAX + 'save', {method:'POST', body:fd}).then(function(r) { return r.json(); }).then(function(res) {
        if (res.ok) {
            sc_current_id = res.id; $('sc-suit-id').value = res.id;
            if ($('sc-delete-btn')) $('sc-delete-btn').disabled = false;
            if ($('sc-dupe-btn')) $('sc-dupe-btn').disabled = false;
            $('sc-save-status').textContent = SC_LANG.saved;
            sc_dirty = false; sc_load_list();
            setTimeout(function() { $('sc-save-status').textContent = ''; }, 3000);
            sc_flash(SC_LANG.suit_saved, 'ok');
        } else {
            $('sc-save-status').textContent = '';
            sc_flash(SC_LANG.err_save + (res.msg || res.error), 'err');
        }
    }).catch(function(e) {
        $('sc-save-status').textContent = '';
        sc_flash(SC_LANG.err_request + e, 'err');
    });
}
if ($('sc-save-btn')) $('sc-save-btn').addEventListener('click', sc_save);

if ($('sc-delete-btn')) {
    $('sc-delete-btn').addEventListener('click', function() {
        if (!sc_current_id) return;
        if (!confirm(SC_LANG.confirm_delete)) return;
        const fd = new FormData(); fd.append('csrf_token', SC_CSRF); fd.append('suit_id', sc_current_id);
        fetch(SC_AJAX + 'delete', {method:'POST', body:fd}).then(function(r) { return r.json(); }).then(function(res) {
            if (res.ok) {
                sc_flash(SC_LANG.suit_deleted, 'ok');
                sc_current_id = 0; sc_dirty = false;
                $('sc-form').style.display = 'none'; $('sc-empty-state').style.display = '';
                $('sc-delete-btn').disabled = true; $('sc-dupe-btn').disabled = true;
                sc_load_list();
            } else sc_flash(SC_LANG.err_delete + (res.msg || res.error), 'err');
        }).catch(function(e) { sc_flash(SC_LANG.err_request + e, 'err'); });
    });
}

if ($('sc-dupe-btn')) {
    $('sc-dupe-btn').addEventListener('click', function() {
        if (!sc_current_id) return;
        const fd = new FormData(); fd.append('csrf_token', SC_CSRF); fd.append('suit_id', sc_current_id);
        fetch(SC_AJAX + 'duplicate', {method:'POST', body:fd}).then(function(r) { return r.json(); }).then(function(res) {
            if (res.ok) { sc_flash(SC_LANG.dupe_done, 'ok'); sc_dirty = false; sc_load(res.id); }
            else sc_flash(SC_LANG.err_dupe, 'err');
        }).catch(function(e) { sc_flash(SC_LANG.err_request + e, 'err'); });
    });
}

function sc_build(dry) {
    if (!sc_current_id) { sc_flash(SC_LANG.err_save_first, 'err'); return; }
    const items = {};
    Object.entries(sc_assign).forEach(function(entry) {
        const slot = entry[0], a = entry[1];
        if (!(a.bonuses || []).length) return;
        items[slot] = {item_template_id: a.item_template_id || '', bonuses: a.bonuses};
    });
    if (!Object.keys(items).length) { sc_flash(SC_LANG.err_no_stats, 'err'); return; }

    const fd = new FormData();
    fd.append('csrf_token', SC_CSRF);
    fd.append('suit_id', sc_current_id);
    fd.append('mode', $('sc-gen-mode').value);
    fd.append('prefix', $('sc-gen-prefix').value);
    fd.append('name_pattern', $('sc-gen-pattern').value);
    fd.append('level', $('sc-gen-level').value);
    fd.append('quality', $('sc-gen-quality').value);
    fd.append('realm', $('sc-realm').value);
    fd.append('items', JSON.stringify(items));
    if (dry) fd.append('dry_run', '1');

    $('sc-gen-report').innerHTML = '<div class="acp-s-6f3a470f">' + SC_LANG.working + '</div>';
    fetch(SC_AJAX + 'build_items', {method:'POST', body:fd}).then(function(r) { return r.json(); }).then(function(res) {
        if (!res.ok) { $('sc-gen-report').innerHTML = ''; sc_flash(SC_LANG.generator + (res.error || '?'), 'err'); return; }
        const rows = res.report.map(function(r) {
            const cls = r.status === 'ok' ? 'sc-tag-ok' : (r.status === 'dry' ? 'sc-tag-dry' : 'sc-tag-err');
            const label = {ok: SC_LANG.written, dry: SC_LANG.preview, skipped: SC_LANG.skipped, error: SC_LANG.error}[r.status] || r.status;
            const note = r.reason ? esc(r.reason)
                       : (r.overflow ? '<span class="acp-s-cd1eb893">' + r.overflow + SC_LANG.bonuses_dont_fit + res.bonus_slots + ')</span>' : '');
            return '<tr><td>' + esc(SC_SLOTDEF[r.slot] ? SC_SLOTDEF[r.slot].label : r.slot) + '</td>' +
                    '<td class="acp-s-8f0e8c00">' + esc(r.id_nb || '') + '</td>' +
                    '<td>' + esc(r.name || '') + '</td>' +
                    '<td>' + (r.bonuses ?? '') + '</td>' +
                    '<td class="' + cls + '">' + label + '</td><td>' + note + '</td></tr>';
        }).join('');
        $('sc-gen-report').innerHTML =
            '<table class="sc-table"><thead><tr><th>Slot</th><th>Id_nb</th><th>Name</th><th>Boni</th><th>Status</th><th></th></tr></thead><tbody>' + rows + '</tbody></table>';
        sc_flash(res.dry_run ? SC_LANG.preview_done : res.report.filter(function(r){ return r.status==='ok'; }).length + SC_LANG.items_written, 'ok');
        if (!res.dry_run) sc_load(sc_current_id);
    }).catch(function(e) {
        $('sc-gen-report').innerHTML = '';
        sc_flash(SC_LANG.err_request + e, 'err');
    });
}
if ($('sc-gen-dry')) $('sc-gen-dry').addEventListener('click', function() { sc_build(true); });
if ($('sc-gen-run')) {
    $('sc-gen-run').addEventListener('click', function() {
        if (!confirm(SC_LANG.confirm_write)) return;
        sc_build(false);
    });
}

if ($('sc-merchant-btn')) $('sc-merchant-btn').addEventListener('click', function() {
    if (!sc_current_id) { sc_flash(SC_LANG.err_save_first, 'err'); return; }
    $('sc-merchant-modal').classList.add('open');
});
if ($('sc-merchant-confirm')) {
    $('sc-merchant-confirm').addEventListener('click', function() {
        const merchant_id = $('sc-merchant-id').value.trim();
        if (!merchant_id) { sc_flash(SC_LANG.err_no_merchant_id, 'err'); return; }
        const fd = new FormData();
        fd.append('csrf_token', SC_CSRF); fd.append('suit_id', sc_current_id);
        fd.append('merchant_id', merchant_id);
        fd.append('base_price', $('sc-merchant-price').value);
        fd.append('page', $('sc-merchant-page').value);
        if ($('sc-merchant-usegen').checked) fd.append('use_generated', '1');
        fetch(SC_AJAX + 'export_merchant', {method:'POST', body:fd}).then(function(r) { return r.json(); }).then(function(res) {
            $('sc-merchant-modal').classList.remove('open');
            if (res.ok) sc_flash(res.items_exported + SC_LANG.items_to + merchant_id + SC_LANG.exported + res.price_each + SC_LANG.per_item, 'ok');
            else sc_flash(SC_LANG.err_export + (res.msg || res.error), 'err');
        }).catch(function(e) {
            $('sc-merchant-modal').classList.remove('open');
            sc_flash(SC_LANG.err_request + e, 'err');
        });
    });
}

if ($('sc-revisions-btn')) {
    $('sc-revisions-btn').addEventListener('click', function() {
        if (!sc_current_id) { sc_flash(SC_LANG.err_save_first, 'err'); return; }
        fetch(SC_AJAX + 'revisions&suit_id=' + sc_current_id).then(function(r) { return r.json(); }).then(function(revs) {
            if (!revs.length) { sc_modal('<i class="fas fa-history"></i> Revisions', '<div class="acp-s-08a10396">' + SC_LANG.no_revisions + '</div>'); return; }
            const rows = revs.map(function(r) {
                return '<tr>' +
                    '<td>' + esc(r.created_at) + '</td><td>' + esc(r.label || '—') + '</td>' +
                    '<td><button class="sc-btn sc-btn-secondary sc-btn-sm" data-rev="' + r.id + '">' + SC_LANG.restore + '</button></td>' +
                '</tr>';
            }).join('');
            sc_modal('<i class="fas fa-history"></i> Revisions',
                '<table class="sc-table"><thead><tr><th>Timestamp</th><th>Reason</th><th></th></tr></thead><tbody>' + rows + '</tbody></table>');
            document.querySelectorAll('[data-rev]').forEach(function(b) {
                b.addEventListener('click', function() {
                    if (!confirm(SC_LANG.confirm_restore)) return;
                    const fd = new FormData(); fd.append('csrf_token', SC_CSRF); fd.append('revision_id', b.dataset.rev);
                    fetch(SC_AJAX + 'revision_restore', {method:'POST', body:fd}).then(function(r) { return r.json(); }).then(function(res) {
                        $('sc-generic-modal').classList.remove('open');
                        if (res.ok) { sc_flash(SC_LANG.restore_done, 'ok'); sc_dirty = false; sc_load(res.id); }
                        else sc_flash(SC_LANG.err_restore, 'err');
                    }).catch(function(e) { sc_flash(SC_LANG.err_request + e, 'err'); });
                });
            });
        }).catch(function(e) { sc_flash(SC_LANG.err_request + e, 'err'); });
    });
}

if ($('sc-import-btn')) {
    $('sc-import-btn').addEventListener('click', function() {
        sc_modal('<i class="fas fa-upload"></i> ' + SC_LANG.import_suit,
            '<div class="sc-field"><label class="sc-label">' + SC_LANG.insert_json + '</label>' +
             '<textarea class="sc-input" id="sc-import-text" rows="10" placeholder=\'{"_format":"aldhran.suit/1", …}\'></textarea></div>',
            '<button class="sc-btn sc-btn-primary" id="sc-import-go">' + SC_LANG.btn_import + '</button>');
        $('sc-import-go').addEventListener('click', function() {
            const payload = $('sc-import-text').value.trim();
            if (!payload) return;
            const fd = new FormData(); fd.append('csrf_token', SC_CSRF); fd.append('payload', payload);
            fetch(SC_AJAX + 'import_json', {method:'POST', body:fd}).then(function(r) { return r.json(); }).then(function(res) {
                $('sc-generic-modal').classList.remove('open');
                if (res.ok) { sc_flash(SC_LANG.import_done, 'ok'); sc_dirty = false; sc_load(res.id); }
                else sc_flash(SC_LANG.err_import + (res.error || '?'), 'err');
            }).catch(function(e) { sc_flash(SC_LANG.err_request + e, 'err'); });
        });
    });
}

if ($('sc-scan-btn')) {
    $('sc-scan-btn').addEventListener('click', function() {
        fetch(SC_AJAX + 'scan_properties').then(function(r) { return r.json(); }).then(function(res) {
            if (!res.ok) { sc_flash(SC_LANG.err_scan + (res.error || '?'), 'err'); return; }
            const rows = res.properties.map(function(p) {
                return '<tr>' +
                    '<td class="acp-s-ed2003de">' + p.type + '</td>' +
                    '<td>' + esc(p.label) + '</td>' +
                    '<td>' + p.count + '</td>' +
                    '<td>' + p.min + '–' + p.max + '</td>' +
                    '<td class="' + (p.known ? 'sc-tag-ok' : 'sc-tag-err') + '">' + (p.known ? SC_LANG.known : SC_LANG.missing_prop) + '</td>' +
                    '<td class="acp-s-29ee2084">' + esc(p.hint) + '</td></tr>';
            }).join('');
            const slotRows = (res.slots || []).map(function(s) {
                const mismatch = s.expected !== null && s.expected !== s.object_type;
                return '<tr>' +
                    '<td>' + esc(s.slot) + '</td>' +
                    '<td class="acp-s-ed2003de">' + s.item_type + '</td>' +
                    '<td class="' + (mismatch ? 'sc-tag-err' : 'sc-tag-ok') + '">' + s.object_type + '</td>' +
                    '<td>' + s.count + '</td>' +
                    '<td class="acp-s-29ee2084">' + (s.expected === null ? SC_LANG.armor_by_class : (mismatch ? SC_LANG.differs_from + s.expected + SC_LANG.differs_off : SC_LANG.fits_ok)) + '</td>' +
                '</tr>';
            }).join('');

            sc_modal('<i class="fas fa-microscope"></i> ' + SC_LANG.enum_sync,
                '<div class="acp-s-b7cc8d93">' +
                    SC_LANG.col_schema + '<code>' + esc(res.columns.type.replace('%d','N')) + '</code> / <code>BonusN</code>, ' + res.columns.max + SC_LANG.bonus_slots +
                    ' ' + SC_LANG.missing_rows_go_in + '<code>SC_PROPERTIES</code>' + SC_LANG.block1_logic +
                 '</div>' +
                 '<table class="sc-table"><thead><tr><th>ID</th><th>Local</th><th>Items</th><th>Value range</th><th>Status</th><th>Heuristic</th></tr></thead><tbody>' + rows + '</tbody></table>' +
                 (slotRows ? '<div class="acp-s-4473f7bf">' +
                    SC_LANG.slots_which + '<code>Object_Type</code>' + SC_LANG.hang_on_which + '<code>Item_Type</code>?' +
                 '</div>' +
                 '<table class="sc-table"><thead><tr><th>Slot</th><th>Item_Type</th><th>Object_Type</th><th>Items</th><th>Comparison</th></tr></thead><tbody>' + slotRows + '</tbody></table>' : ''));
        }).catch(function(e) { sc_flash(SC_LANG.err_request + e, 'err'); });
    });
}

if ($('sc-export-btn')) {
    $('sc-export-btn').addEventListener('click', function() {
        if (!sc_current_id) { sc_flash(SC_LANG.err_save_first, 'err'); return; }
        fetch(SC_AJAX + 'export_json&suit_id=' + sc_current_id).then(function(r) { return r.text(); }).then(function(txt) {
            const blob = new Blob([txt], {type:'application/json'});
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'suit-' + sc_current_id + '.json';
            a.click(); URL.revokeObjectURL(a.href);
        }).catch(function(e) { sc_flash(SC_LANG.err_request + e, 'err'); });
    });
}

document.addEventListener('keydown', function(e) {
    if (e.ctrlKey && e.key === 's' && SC_CAN_EDIT && $('sc-form').style.display !== 'none') { e.preventDefault(); sc_save(); }
    if (e.altKey && e.key.toLowerCase() === 'd') { e.preventDefault(); sc_distribute(); }
    if (e.altKey && e.key.toLowerCase() === 'c') { e.preventDefault(); $('sc-cap-all-btn').click(); }
});
window.addEventListener('beforeunload', function(e) { if (sc_dirty) { e.preventDefault(); e.returnValue = ''; } });

<?php endif; ?>

<?php if ($ai_active): ?>
function sc_ai_show(text, state) {
    if (state === undefined) state = 'ok';
    const el = $('sc-ai-result'); if (!el) return;
    el.textContent = text; el.className = 'sc-ai-result visible ' + state;
}
function sc_ai_reset() {
    const el = $('sc-ai-result'); if (el) { el.className = 'sc-ai-result'; el.textContent = ''; }
}
function sc_ai_call(action, extra, btnId, loadingText) {
    const btn = $(btnId); if (btn) btn.disabled = true;
    sc_ai_show(loadingText, 'loading');
    const fd = new FormData();
    fd.append('csrf_token', SC_CSRF);
    fd.append('suit_id', sc_current_id || 0);
    fd.append('server_type', $('sc-server-type').value);
    fd.append('archetype', $('sc-archetype').value);
    fd.append('class_key', $('sc-class').value);
    Object.entries(extra || {}).forEach(function(entry) { fd.append(entry[0], entry[1]); });
    return fetch(SC_AJAX + action, {method:'POST', body:fd})
        .then(function(r) { return r.json(); })
        .then(function(data) { if (btn) btn.disabled = false; return data; })
        .catch(function(e) { if (btn) btn.disabled = false; sc_ai_show(SC_LANG.err_request + e, 'err'); return null; });
}
function sc_ai_balance() {
    if (!sc_current_id) { sc_ai_show(SC_LANG.err_save_balance, 'err'); return; }
    sc_ai_call('ai_balance_check', {}, 'sc-ai-balance-btn', SC_LANG.checking_balance).then(function(d) {
        if (!d) return;
        if (d.status === 'ok') sc_ai_show(d.result?.suggestion || '—', 'ok');
        else sc_ai_show(SC_LANG.error_prefix + (d.message || '?'), 'err');
    });
}
function sc_ai_suggest_missing() {
    const assigned = Object.keys(sc_assign).filter(function(k) { return sc_assign[k].item_template_id || (sc_assign[k].bonuses||[]).length; });
    sc_ai_call('ai_suggest_missing', {assigned_slots: JSON.stringify(assigned)}, 'sc-ai-missing-btn', SC_LANG.search_missing).then(function(d) {
        if (!d) return;
        if (d.status === 'ok') sc_ai_show(d.result?.suggestion || '—', 'ok');
        else sc_ai_show(SC_LANG.error_prefix + (d.message || '?'), 'err');
    });
}
function sc_ai_autobuild() {
    const brief = prompt(SC_LANG.ai_prompt) || '';
    sc_ai_call('ai_autobuild', {brief: brief}, 'sc-ai-autobuild-btn', SC_LANG.ai_designing).then(function(d) {
        if (!d) return;
        if (d.status !== 'ok') { sc_ai_show(SC_LANG.error_prefix + (d.message || '?'), 'err'); return; }
        const raw = d.result?.suggestion || '';
        let parsed = null;
        try { parsed = JSON.parse(raw.replace(/```json|```/g, '').trim()); } catch (err) { parsed = null; }
        if (!parsed || !parsed.targets) { sc_ai_show(raw, 'ok'); return; }
        let applied = 0;
        document.querySelectorAll('.sc-stat-target').forEach(function(el) { el.value = 0; });
        Object.entries(parsed.targets).forEach(function(entry) {
            const t = entry[0], v = entry[1];
            const el = document.querySelector('.sc-stat-target[data-type="' + t + '"]');
            if (el) { el.value = Math.min(parseInt(v) || 0, sc_capFor(parseInt(t))); applied++; }
        });
        sc_markDirty(); sc_recalc();
        sc_ai_show((parsed.reason || SC_LANG.ai_targets_taken) + '\n\n' + applied + SC_LANG.ai_values_set, 'ok');
    });
}
<?php endif; ?>

sc_recalc();
</script>
