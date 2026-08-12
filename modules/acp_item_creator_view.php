<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_ACP')) exit;

// Fallback: pull $userPriv/$currentUserId from session if not already in scope
if (!isset($userPriv))      $userPriv      = (int)($_SESSION['priv_level'] ?? 0);
if (!isset($currentUserId)) $currentUserId = (int)($_SESSION['user_id']    ?? 0);


$csrf = generateToken();

$bonusTypeLabels = [
    0=>'— None —',1=>'Strength',2=>'Dexterity',3=>'Constitution',4=>'Quickness',
    5=>'Intelligence',6=>'Piety',7=>'Empathy',8=>'Charisma',10=>'Hits',11=>'Skill',12=>'Focus',
    13=>'Crush Resist',14=>'Slash Resist',15=>'Thrust Resist',16=>'Heat Resist',17=>'Cold Resist',
    18=>'Matter Resist',19=>'Body Resist',20=>'Spirit Resist',21=>'Energy Resist',22=>'Essence Resist',
    26=>'Fatigue',163=>'Armor Factor',
];
$objectTypes = [
    0=>'Misc',1=>'GenericItem',2=>'GameInventoryItem',3=>'Poison',4=>'Dye',5=>'AlchemyTincture',
    6=>'SpellcraftGem',7=>'Scroll',8=>'Arrow',9=>'Bolt',10=>'Thrown',11=>'Instrument',
    12=>'Shield',13=>'Armor',14=>'Cloth',15=>'Leather',16=>'Studded',17=>'Chain',18=>'Plate',
    19=>'Reinforced',20=>'Scale',21=>'1-Handed',22=>'2-Handed',23=>'Polearm',24=>'Staff',
    25=>'Longbow',26=>'Crossbow',27=>'Sword',28=>'Knife',29=>'Axe',30=>'Spear',31=>'Composite',
    32=>'Blunt',33=>'Jousting',34=>'Fired',35=>'MagicalItem',36=>'Magical',
    41=>'Cloak',42=>'Jewelry',43=>'Belt',44=>'Bracer',45=>'Ring',46=>'Neck',47=>'Waist',48=>'Mythirian',
];
$itemTypes = [
    0=>'None',1=>'Horse',2=>'Reins',3=>'Barding',10=>'Hand Left',11=>'Hand Right',12=>'Two-Hand',
    13=>'Ranged',21=>'Head',22=>'Hand',23=>'Feet',24=>'Jewel',25=>'Torso',26=>'Cloak',
    27=>'Back',28=>'Waist',29=>'Neck',30=>'Bracer',31=>'Right Bracer',32=>'Ring',
    33=>'Right Ring',34=>'Mythirian',40=>'Legs',41=>'Arms',
];
$realmLabels = [0=>'All',1=>'Albion',2=>'Midgard',3=>'Hibernia'];

// AI available?
$ai_active = isset($botSettings) && $botSettings->isActive() && $botSettings->hasAiConfigured();
?>


<div id="ie-flash" class="ie-flash"></div>

<div class="ie-wrap">
    <!-- ── Sidebar ── -->
    <div class="ie-sidebar">
        <div class="ie-search-box">
            <i class="fas fa-search ie-search-icon"></i>
            <input type="text" id="ie-search" placeholder="Search items by name or ID…" autocomplete="off">
        </div>
        <div class="ie-sidebar-actions">
            <button class="ie-btn ie-btn-primary acp-s-da5cd676" id="ie-btn-new"><i class="fas fa-plus"></i> New</button>
            <button class="ie-btn ie-btn-secondary ie-btn-sm" id="ie-btn-clone" title="Clone current item" disabled><i class="fas fa-copy"></i></button>
            <button class="ie-btn ie-btn-danger ie-btn-sm" id="ie-btn-delete" title="Delete item" disabled><i class="fas fa-trash"></i></button>
        </div>
        <div class="ie-result-list" id="ie-result-list">
            <div class="acp-s-a1d15c9a"><i class="fas fa-arrow-up acp-s-487b71ac"></i>Search for an item or click <strong>New</strong>.</div>
        </div>
    </div>

    <!-- ── Main Editor ── -->
    <div class="ie-main" id="ie-main">
        <div class="ie-empty-state" id="ie-empty-state">
            <i class="fas fa-shield-alt"></i>
            <p>Select an item from the list or create a new one.</p>
        </div>

        <div id="ie-editor-form" class="acp-s-cb458930">
            <input type="hidden" id="ie-is-new" value="0">
            <input type="hidden" id="ie-original-id" value="">

            <!-- ── Identity ── -->
            <div class="ie-card">
                <div class="ie-card-title"><i class="fas fa-id-card"></i> Identity</div>
                <div class="ie-grid-3">
                    <div class="ie-field"><label class="ie-label">ID (Id_nb)</label><input type="text" class="ie-input" id="f-Id_nb" placeholder="unique_item_id"></div>
                    <div class="ie-field"><label class="ie-label">Name</label><input type="text" class="ie-input" id="f-Name" placeholder="Item Name"></div>
                    <div class="ie-field"><label class="ie-label">TranslationId</label><input type="text" class="ie-input" id="f-TranslationId"></div>
                </div>
                <div class="ie-grid-3 acp-s-d4316970">
                    <div class="ie-field"><label class="ie-label">Examine Article</label><input type="text" class="ie-input" id="f-ExamineArticle" placeholder="a / an / the"></div>
                    <div class="ie-field"><label class="ie-label">Message Article</label><input type="text" class="ie-input" id="f-MessageArticle" placeholder="a / an / the"></div>
                    <div class="ie-field"><label class="ie-label">Description</label><input type="text" class="ie-input" id="f-Description"></div>
                </div>
            </div>

            <!-- ── Classification ── -->
            <div class="ie-card">
                <div class="ie-card-title"><i class="fas fa-tag"></i> Classification</div>
                <div class="ie-grid-4">
                    <div class="ie-field"><label class="ie-label">Level</label><input type="number" class="ie-input" id="f-Level" min="0" max="100" value="50"></div>
                    <div class="ie-field"><label class="ie-label">Object Type</label><select class="ie-select" id="f-Object_Type"><?php foreach($objectTypes as $v=>$l): ?><option value="<?=$v?>"><?=$l?></option><?php endforeach; ?></select></div>
                    <div class="ie-field"><label class="ie-label">Item Type (Slot)</label><select class="ie-select" id="f-Item_Type"><?php foreach($itemTypes as $v=>$l): ?><option value="<?=$v?>"><?=$l?></option><?php endforeach; ?></select></div>
                    <div class="ie-field"><label class="ie-label">Realm</label><select class="ie-select" id="f-Realm"><?php foreach($realmLabels as $v=>$l): ?><option value="<?=$v?>"><?=$l?></option><?php endforeach; ?></select></div>
                </div>
                <div class="ie-grid-4 acp-s-d4316970">
                    <div class="ie-field"><label class="ie-label">DPS / AF</label><input type="number" class="ie-input" id="f-DPS_AF" min="0" value="0"></div>
                    <div class="ie-field"><label class="ie-label">Speed / ABS</label><input type="number" class="ie-input" id="f-SPD_ABS" min="0" value="0"></div>
                    <div class="ie-field"><label class="ie-label">Hand</label><select class="ie-select" id="f-Hand"><option value="0">Right / Any</option><option value="1">Left</option><option value="2">Two-Handed</option></select></div>
                    <div class="ie-field"><label class="ie-label">Damage Type</label><select class="ie-select" id="f-Type_Damage"><option value="0">None</option><option value="1">Crush</option><option value="2">Slash</option><option value="3">Thrust</option></select></div>
                </div>
            </div>

            <!-- ── Appearance ── -->
            <div class="ie-card">
                <div class="ie-card-title"><i class="fas fa-eye"></i> Appearance</div>
                <div class="ie-grid-4">
                    <div class="ie-field acp-s-a74d27d5">
                        <label class="ie-label">Model ID</label>
                        <div class="ie-model-row">
                            <div class="ie-model-wrap">
                                <input type="number" class="ie-input" id="f-Model" min="0" placeholder="0">
                                <div class="ie-model-suggest-list" id="ie-model-suggest-list"></div>
                            </div>
                            <button type="button" class="ie-btn ie-btn-secondary ie-btn-sm" id="ie-model-suggest-btn" title="Suggest models"><i class="fas fa-magic"></i> Suggest</button>
                        </div>
                    </div>
                    <div class="ie-field"><label class="ie-label">Color</label><input type="number" class="ie-input" id="f-Color" min="0" value="0"></div>
                    <div class="ie-field"><label class="ie-label">Extension</label><input type="number" class="ie-input" id="f-Extension" min="0" value="0"></div>
                    <div class="ie-field"><label class="ie-label">Effect</label><input type="number" class="ie-input" id="f-Effect" min="0" value="0"></div>
                    <div class="ie-field"><label class="ie-label">Emblem</label><input type="number" class="ie-input" id="f-Emblem" min="0" value="0"></div>
                </div>
            </div>

            <!-- ── Condition & Quality ── -->
            <div class="ie-card">
                <div class="ie-card-title"><i class="fas fa-heart"></i> Condition & Quality</div>
                <div class="ie-grid-4">
                    <div class="ie-field"><label class="ie-label">Quality (%)</label><input type="number" class="ie-input" id="f-Quality" min="0" max="100" value="100"></div>
                    <div class="ie-field"><label class="ie-label">Condition</label><input type="number" class="ie-input" id="f-Condition" value="100"></div>
                    <div class="ie-field"><label class="ie-label">Max Condition</label><input type="number" class="ie-input" id="f-MaxCondition" value="100"></div>
                    <div class="ie-field"><label class="ie-label">Durability</label><input type="number" class="ie-input" id="f-Durability" value="100"></div>
                    <div class="ie-field"><label class="ie-label">Max Durability</label><input type="number" class="ie-input" id="f-MaxDurability" value="100"></div>
                    <div class="ie-field"><label class="ie-label">Weight</label><input type="number" class="ie-input" id="f-Weight" value="0"></div>
                    <div class="ie-field"><label class="ie-label">Price (copper)</label><input type="number" class="ie-input" id="f-Price" value="0"></div>
                    <div class="ie-field"><label class="ie-label">Bonus Level</label><input type="number" class="ie-input" id="f-BonusLevel" value="0"></div>
                </div>
            </div>

            <!-- ── Bonuses + Utility ── -->
            <div class="ie-card">
                <div class="ie-card-title"><i class="fas fa-star"></i> Bonuses</div>
                <div class="acp-s-39027c43">
                    <div>
                        <?php for($i=1;$i<=10;$i++): ?>
                        <div class="ie-bonus-row">
                            <span class="ie-bonus-num"><?=$i?></span>
                            <select class="ie-select ie-bonus-type" id="f-BonusType<?=$i?>" data-slot="<?=$i?>">
                                <?php foreach($bonusTypeLabels as $v=>$l): ?><option value="<?=$v?>"><?=$l?></option><?php endforeach; ?>
                            </select>
                            <input type="number" class="ie-input ie-bonus-val" id="f-Bonus<?=$i?>" min="0" value="0">
                        </div>
                        <?php endfor; ?>
                        <div class="ie-bonus-row acp-s-d4316970">
                            <span class="ie-bonus-num acp-s-ed2003de">+</span>
                            <select class="ie-select ie-bonus-type" id="f-ExtraBonusType">
                                <?php foreach($bonusTypeLabels as $v=>$l): ?><option value="<?=$v?>"><?=$l?></option><?php endforeach; ?>
                            </select>
                            <input type="number" class="ie-input ie-bonus-val" id="f-ExtraBonus" min="0" value="0">
                        </div>
                    </div>
                    <div>
                        <div class="ie-card acp-s-20ab54a3">
                            <div class="ie-card-title acp-s-d51702bf"><i class="fas fa-calculator"></i> Utility Calculator</div>
                            <div class="ie-util-bar-wrap">
                                <div class="ie-util-stats">
                                    <span>Utility: <strong class="ie-util-val" id="util-val">0.00</strong></span>
                                    <span class="ie-util-cap">Cap: <span id="util-cap">100.0</span></span>
                                </div>
                                <div class="ie-util-bar-bg"><div class="ie-util-bar-fill acp-s-9c33ea5e" id="util-bar"></div></div>
                                <div class="ie-util-stats acp-s-9e3c4ccb"><span class="acp-s-e0248186"><span id="util-pct">0</span>% of cap used</span></div>
                                <div class="ie-util-warn acp-s-cb458930" id="util-warn"><i class="fas fa-exclamation-triangle"></i> Item exceeds utility cap!</div>
                            </div>
                            <div class="acp-s-514ac14d">
                                <div class="ie-card-title acp-s-d51702bf"><i class="fas fa-layer-group"></i> Quick Fill</div>
                                <div class="acp-s-0adf588c">
                                    <button type="button" class="ie-btn ie-btn-secondary ie-btn-sm" onclick="ie_quickfill('tank')"><i class="fas fa-shield-alt"></i> Tank</button>
                                    <button type="button" class="ie-btn ie-btn-secondary ie-btn-sm" onclick="ie_quickfill('caster')"><i class="fas fa-magic"></i> Caster</button>
                                    <button type="button" class="ie-btn ie-btn-secondary ie-btn-sm" onclick="ie_quickfill('melee')"><i class="fas fa-sword"></i> Melee DPS</button>
                                    <button type="button" class="ie-btn ie-btn-secondary ie-btn-sm" id="ie-clear-bonuses"><i class="fas fa-times"></i> Clear All</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Procs & Charges ── -->
            <div class="ie-card">
                <div class="ie-card-title"><i class="fas fa-bolt"></i> Procs & Charges</div>
                <div class="ie-grid-4">
                    <div class="ie-field"><label class="ie-label">Proc Spell ID</label><input type="number" class="ie-input" id="f-ProcSpellID" value="0"></div>
                    <div class="ie-field"><label class="ie-label">Proc Chance (%)</label><input type="number" class="ie-input" id="f-ProcChance" min="0" max="100" value="0"></div>
                    <div class="ie-field"><label class="ie-label">Charge Spell ID</label><input type="number" class="ie-input" id="f-SpellID" value="0"></div>
                    <div class="ie-field"><label class="ie-label">Charges / Max</label><div class="acp-s-e5e86cb2"><input type="number" class="ie-input" id="f-Charges" min="0" value="0" placeholder="Cur"><input type="number" class="ie-input" id="f-MaxCharges" min="0" value="0" placeholder="Max"></div></div>
                </div>
            </div>

            <!-- ── Flags & Restrictions ── -->
            <div class="ie-card">
                <div class="ie-card-title"><i class="fas fa-lock"></i> Flags & Restrictions</div>
                <div class="ie-grid-4">
                    <div class="ie-field"><label class="ie-label">Allowed Classes</label><input type="text" class="ie-input" id="f-AllowedClasses" placeholder="0 = all"></div>
                    <div class="ie-field"><label class="ie-label">Level Requirement</label><input type="number" class="ie-input" id="f-LevelRequirement" value="0"></div>
                    <div class="ie-field"><label class="ie-label">Can Use Every (s)</label><input type="number" class="ie-input" id="f-CanUseEvery" value="0"></div>
                    <div class="ie-field"><label class="ie-label">Package ID</label><input type="text" class="ie-input" id="f-PackageID"></div>
                </div>
                <div class="ie-grid-4 acp-s-d4316970">
                    <?php foreach(['IsPickable'=>'Pickable','IsDropable'=>'Dropable','IsTradable'=>'Tradable','IsIndestructible'=>'Indestructible','IsNotLosingDur'=>'No Dur. Loss'] as $fid=>$flabel): ?>
                    <label class="acp-s-7c046cfe">
                        <input type="checkbox" id="f-<?=$fid?>" class="acp-s-88b1dd7f" > <?=$flabel?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($ai_active): ?>
            <!-- ── AI Assistant ── -->
            <div class="ie-ai-panel">
                <div class="ie-ai-panel-title">
                    <i class="fas fa-robot"></i> AI Assistant
                    <span class="acp-s-03274235">
                        Powered by <?= h(ucfirst($botSettings->getProvider())) ?>
                    </span>
                </div>

                <button type="button" class="ie-ai-btn" id="ie-ai-balance-btn" onclick="ie_ai_balance()">
                    <i class="fas fa-balance-scale"></i> Balance Check
                </button>
                <button type="button" class="ie-ai-btn" id="ie-ai-lore-btn" onclick="ie_ai_lore()">
                    <i class="fas fa-book-open"></i> Generate Lore
                </button>
                <button type="button" class="ie-ai-btn" id="ie-ai-stats-btn" onclick="ie_ai_suggest_stats()">
                    <i class="fas fa-magic"></i> Suggest Stats
                </button>
                <span class="acp-s-2d686b3c">
                    <i class="fas fa-info-circle acp-s-831b94f4"></i>
                    Set Detection runs automatically on Save — checks if your items could form a gear set.
                </span>

                <div id="ie-ai-result" class="ie-ai-result"></div>
                <button type="button" class="ie-ai-apply-btn" id="ie-ai-apply-lore-btn" onclick="ie_ai_apply_lore()">
                    <i class="fas fa-check"></i> Apply Lore to Description
                </button>
                <button type="button" class="ie-ai-apply-btn" id="ie-ai-apply-stats-btn" onclick="ie_ai_apply_stats()">
                    <i class="fas fa-check"></i> Apply Suggested Stats
                </button>
            </div>
            <?php else: ?>
            <div class="acp-s-e055f279">
                <i class="fas fa-robot acp-s-5f7cfd62"></i>
                AI Assistant is not configured. Enable it in <a href="acp.php?s=bot_settings&tab=ai" class="acp-s-29ee2084">Bot & AI Settings</a>.
            </div>
            <?php endif; ?>

            <!-- ── Save Bar ── -->
            <div class="acp-s-dd957f81">
                <button type="button" class="ie-btn ie-btn-primary acp-s-307c3df0" id="ie-save-btn">
                    <i class="fas fa-save"></i> Save Item
                </button>
                <span id="ie-save-status" class="acp-s-dcce4098"></span>
            </div>
        </div>
    </div>
</div>

<!-- ── Clone Modal ── -->
<div class="ie-modal-overlay" id="ie-clone-modal">
    <div class="ie-modal">
        <div class="ie-modal-title"><i class="fas fa-copy"></i> Clone Item</div>
        <div class="ie-field acp-s-a7409f75"><label class="ie-label">New ID (Id_nb)</label><input type="text" class="ie-input" id="clone-new-id" placeholder="new_item_id"></div>
        <div class="ie-field"><label class="ie-label">New Name (optional)</label><input type="text" class="ie-input" id="clone-new-name"></div>
        <div class="ie-modal-actions">
            <button class="ie-btn ie-btn-secondary" id="clone-cancel">Cancel</button>
            <button class="ie-btn ie-btn-primary" id="clone-confirm"><i class="fas fa-copy"></i> Clone</button>
        </div>
    </div>
</div>

<script>
const IE_AJAX = 'acp.php?s=item_creator&ajax=';
const IE_CSRF = '<?= $csrf ?>';
let ie_current_id   = '';
let ie_search_timer = null;
let ie_util_timer   = null;
let ie_ai_last_lore  = '';
let ie_ai_last_stats = null;

function ie_flash(msg, type='ok') {
    const el = document.getElementById('ie-flash');
    el.textContent = msg; el.className = 'ie-flash ' + type; el.style.display = 'block';
    clearTimeout(el._t); el._t = setTimeout(() => { el.style.display = 'none'; }, 3500);
}

// ── Search ──────────────────────────────────────────────────────
document.getElementById('ie-search').addEventListener('input', function() {
    clearTimeout(ie_search_timer);
    const q = this.value.trim();
    if (q.length < 2) { document.getElementById('ie-result-list').innerHTML = '<div style="padding:12px; color:#444; font-size:.82em;">Type at least 2 characters…</div>'; return; }
    ie_search_timer = setTimeout(() => ie_do_search(q), 250);
});

function ie_do_search(q) {
    fetch(IE_AJAX + 'search&q=' + encodeURIComponent(q)).then(r=>r.json()).then(items => {
        const list = document.getElementById('ie-result-list');
        if (!items.length) { list.innerHTML = '<div style="padding:12px; color:#444; font-size:.82em;">No items found.</div>'; return; }
        list.innerHTML = items.map(it => `<div class="ie-result-item" onclick="ie_load_item('${it.Id_nb.replace(/'/g,"\\'")}')"><div class="ie-result-name">${it.Name}</div><div class="ie-result-meta">${it.Id_nb} · Lvl ${it.Level} · Model ${it.Model}</div></div>`).join('');
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

function ie_load_item(id) {
    fetch(IE_AJAX + 'load&id=' + encodeURIComponent(id)).then(r=>r.json()).then(item => {
        if (item.error) { ie_flash('Item not found: ' + id, 'err'); return; }
        ie_populate_form(item, false);
        ie_current_id = id;
        document.getElementById('ie-btn-clone').disabled  = false;
        document.getElementById('ie-btn-delete').disabled = false;
        ie_ai_reset();
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

const IE_BOOL_FIELDS = ['IsPickable','IsDropable','IsTradable','IsIndestructible','IsNotLosingDur'];
const IE_FIELDS = ['Id_nb','TranslationId','Name','ExamineArticle','MessageArticle','Description','Level','Object_Type','Item_Type','Realm','DPS_AF','SPD_ABS','Hand','Type_Damage','Quality','Condition','MaxCondition','Durability','MaxDurability','Weight','Price','BonusLevel','Model','Color','Extension','Effect','Emblem','ProcSpellID','ProcChance','SpellID','Charges','MaxCharges','AllowedClasses','LevelRequirement','CanUseEvery','PackageID'];

function ie_populate_form(item, is_new) {
    document.getElementById('ie-empty-state').style.display  = 'none';
    document.getElementById('ie-editor-form').style.display  = '';
    document.getElementById('ie-is-new').value      = is_new ? '1' : '0';
    document.getElementById('ie-original-id').value = is_new ? '' : (item.Id_nb || '');
    IE_FIELDS.forEach(f => { const el = document.getElementById('f-'+f); if (el) el.value = item[f] ?? (el.type==='number'?0:''); });
    IE_BOOL_FIELDS.forEach(f => { const el = document.getElementById('f-'+f); if (el) el.checked = parseInt(item[f]??0)===1; });
    for (let i=1;i<=10;i++) { const bt=document.getElementById('f-BonusType'+i); const bv=document.getElementById('f-Bonus'+i); if(bt) bt.value=item['BonusType'+i]??0; if(bv) bv.value=item['Bonus'+i]??0; }
    const et=document.getElementById('f-ExtraBonusType'); const ev=document.getElementById('f-ExtraBonus');
    if(et) et.value=item['ExtraBonusType']??0; if(ev) ev.value=item['ExtraBonus']??0;
    ie_update_utility();
}

document.getElementById('ie-btn-new').addEventListener('click', () => {
    ie_current_id = '';
    ie_populate_form({}, true);
    document.getElementById('f-Id_nb').focus();
    document.getElementById('ie-btn-clone').disabled  = true;
    document.getElementById('ie-btn-delete').disabled = true;
    ie_ai_reset();
});

// ── Utility Calculator ─────────────────────────────────────────
function ie_update_utility() {
    const level = parseInt(document.getElementById('f-Level')?.value || 50);
    const cap   = Math.round((level/50)*100*10)/10;
    const weights = {1:.6667,2:.6667,3:.6667,4:.6667,5:.6667,6:.6667,7:.6667,8:.6667,10:.25,11:2,12:2,13:2,14:2,15:2,16:2,17:2,18:2,19:2,20:2,21:2,22:2,26:1,163:1};
    let util = 0;
    for(let i=1;i<=10;i++){const type=parseInt(document.getElementById('f-BonusType'+i)?.value||0);const value=parseFloat(document.getElementById('f-Bonus'+i)?.value||0);if(type>0&&value>0) util+=value*(weights[type]??1);}
    const et=parseInt(document.getElementById('f-ExtraBonusType')?.value||0); const ev=parseFloat(document.getElementById('f-ExtraBonus')?.value||0);
    if(et>0&&ev>0) util+=ev*(weights[et]??1);
    util=Math.round(util*100)/100;
    const pct=cap>0?Math.min(Math.round((util/cap)*1000)/10,999):0; const over=util>cap;
    document.getElementById('util-val').textContent=util.toFixed(2);
    document.getElementById('util-cap').textContent=cap.toFixed(1);
    document.getElementById('util-pct').textContent=pct.toFixed(1);
    document.getElementById('util-bar').style.width=Math.min(pct,100)+'%';
    document.getElementById('util-bar').className='ie-util-bar-fill'+(over?' over':'');
    document.getElementById('util-warn').style.display=over?'':'none';
}
document.addEventListener('change', e => { if(e.target.classList.contains('ie-bonus-type')||e.target.classList.contains('ie-bonus-val')||e.target.id==='f-Level') ie_update_utility(); });
document.addEventListener('input',  e => { if(e.target.classList.contains('ie-bonus-val')||e.target.id==='f-Level'){clearTimeout(ie_util_timer);ie_util_timer=setTimeout(ie_update_utility,150);}});

// ── Quick Fill ─────────────────────────────────────────────────
const IE_QUICKFILL_PRESETS = {
    tank:[{type:1,val:26},{type:3,val:26},{type:10,val:40},{type:13,val:18},{type:14,val:18},{type:15,val:18},{type:19,val:18},{type:16,val:18}],
    caster:[{type:5,val:26},{type:6,val:26},{type:12,val:50},{type:16,val:18},{type:17,val:18},{type:20,val:18},{type:18,val:18},{type:19,val:18}],
    melee:[{type:1,val:26},{type:2,val:26},{type:4,val:26},{type:10,val:40},{type:13,val:18},{type:14,val:18}]
};
function ie_quickfill(preset) {
    const slots=IE_QUICKFILL_PRESETS[preset]||[];
    for(let i=1;i<=10;i++){const s=slots[i-1]||{type:0,val:0};const bt=document.getElementById('f-BonusType'+i);const bv=document.getElementById('f-Bonus'+i);if(bt) bt.value=s.type;if(bv) bv.value=s.val;}
    ie_update_utility();
}
document.getElementById('ie-clear-bonuses').addEventListener('click', () => {
    for(let i=1;i<=10;i++){const bt=document.getElementById('f-BonusType'+i);const bv=document.getElementById('f-Bonus'+i);if(bt) bt.value=0;if(bv) bv.value=0;}
    document.getElementById('f-ExtraBonusType').value=0; document.getElementById('f-ExtraBonus').value=0;
    ie_update_utility();
});

// ── Model Suggest ──────────────────────────────────────────────
document.getElementById('ie-model-suggest-btn').addEventListener('click', () => {
    const name=document.getElementById('f-Name').value.trim();
    if(!name){ie_flash('Enter an item name first.','err');return;}
    fetch(IE_AJAX+'model_suggest&name='+encodeURIComponent(name)).then(r=>r.json()).then(suggestions=>{
        const list=document.getElementById('ie-model-suggest-list');
        if(!suggestions.length){list.innerHTML='<div class="ie-model-sug-item" style="color:#555;">No suggestions.</div>';}
        else{list.innerHTML=suggestions.map(s=>`<div class="ie-model-sug-item" onclick="ie_apply_model(${s.Model})">${s.Name} — <strong>${s.Model}</strong> (×${s.cnt})</div>`).join('');}
        list.classList.add('open');
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
});
function ie_apply_model(model){document.getElementById('f-Model').value=model;document.getElementById('ie-model-suggest-list').classList.remove('open');}
document.addEventListener('click', e=>{if(!e.target.closest('#ie-model-suggest-btn')&&!e.target.closest('#ie-model-suggest-list'))document.getElementById('ie-model-suggest-list').classList.remove('open');});

// ── Save ───────────────────────────────────────────────────────
document.getElementById('ie-save-btn').addEventListener('click', () => {
    const is_new=document.getElementById('ie-is-new').value==='1';
    const id_nb=document.getElementById('f-Id_nb').value.trim();
    if(!id_nb){ie_flash('ID (Id_nb) is required.','err');return;}
    const fd=new FormData();
    fd.append('csrf_token',IE_CSRF); fd.append('is_new',is_new?'1':'0');
    const allFields=['Id_nb','TranslationId','Name','ExamineArticle','MessageArticle','Description','Level','Object_Type','Item_Type','Realm','DPS_AF','SPD_ABS','Hand','Type_Damage','Quality','Condition','MaxCondition','Durability','MaxDurability','Weight','Price','BonusLevel','Model','Color','Extension','Effect','Emblem','ProcSpellID','ProcChance','SpellID','Charges','MaxCharges','AllowedClasses','LevelRequirement','CanUseEvery','PackageID'];
    allFields.forEach(f=>{const el=document.getElementById('f-'+f);if(el) fd.append(f,el.value);});
    IE_BOOL_FIELDS.forEach(f=>{const el=document.getElementById('f-'+f);fd.append(f,(el&&el.checked)?'1':'0');});
    for(let i=1;i<=10;i++){fd.append('BonusType'+i,document.getElementById('f-BonusType'+i)?.value||0);fd.append('Bonus'+i,document.getElementById('f-Bonus'+i)?.value||0);}
    fd.append('ExtraBonusType',document.getElementById('f-ExtraBonusType')?.value||0);
    fd.append('ExtraBonus',document.getElementById('f-ExtraBonus')?.value||0);
    const statusEl=document.getElementById('ie-save-status');
    statusEl.textContent='Saving…';
    fetch(IE_AJAX+'save',{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
        if(res.ok){ie_flash('Item saved: '+res.id,'ok');statusEl.textContent='Saved ✓';ie_current_id=res.id;document.getElementById('ie-is-new').value='0';document.getElementById('ie-original-id').value=res.id;document.getElementById('ie-btn-clone').disabled=false;document.getElementById('ie-btn-delete').disabled=false;setTimeout(()=>{statusEl.textContent='';},3000);}
        else{ie_flash('Error: '+(res.msg||res.error),'err');statusEl.textContent='';}
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
});

// ── Clone ──────────────────────────────────────────────────────
document.getElementById('ie-btn-clone').addEventListener('click', () => {
    if(!ie_current_id) return;
    document.getElementById('clone-new-id').value=ie_current_id+'_copy';
    document.getElementById('clone-new-name').value='';
    document.getElementById('ie-clone-modal').classList.add('open');
});
document.getElementById('clone-cancel').addEventListener('click', () => document.getElementById('ie-clone-modal').classList.remove('open'));
document.getElementById('clone-confirm').addEventListener('click', () => {
    const new_id=document.getElementById('clone-new-id').value.trim();
    const new_name=document.getElementById('clone-new-name').value.trim();
    if(!new_id){ie_flash('New ID is required.','err');return;}
    const fd=new FormData();
    fd.append('csrf_token',IE_CSRF);fd.append('src_id',ie_current_id);fd.append('new_id',new_id);fd.append('new_name',new_name);
    fetch(IE_AJAX+'clone',{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
        document.getElementById('ie-clone-modal').classList.remove('open');
        if(res.ok){ie_flash('Cloned to: '+res.id,'ok');ie_load_item(res.id);}
        else{ie_flash('Clone failed: '+(res.msg||res.error),'err');}
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
});

// ── Delete ─────────────────────────────────────────────────────
document.getElementById('ie-btn-delete').addEventListener('click', () => {
    if(!ie_current_id) return;
    if(!confirm('Really delete item "'+ie_current_id+'"?')) return;
    const fd=new FormData();fd.append('csrf_token',IE_CSRF);fd.append('id',ie_current_id);
    fetch(IE_AJAX+'delete',{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
        if(res.ok){ie_flash('Item deleted.','ok');ie_current_id='';document.getElementById('ie-editor-form').style.display='none';document.getElementById('ie-empty-state').style.display='';document.getElementById('ie-btn-clone').disabled=true;document.getElementById('ie-btn-delete').disabled=true;}
        else{ie_flash('Delete failed: '+(res.msg||res.error),'err');}
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
});

// ════════════════════════════════════════════════════
// AI FUNCTIONS
// ════════════════════════════════════════════════════

function ie_ai_get_form_data() {
    const fd = new FormData();
    fd.append('csrf_token', IE_CSRF);
    ['Name','Level','Object_Type','Realm','AllowedClasses'].forEach(f => {
        const el = document.getElementById('f-'+f);
        if (el) fd.append(f, el.value);
    });
    for(let i=1;i<=10;i++){fd.append('BonusType'+i,document.getElementById('f-BonusType'+i)?.value||0);fd.append('Bonus'+i,document.getElementById('f-Bonus'+i)?.value||0);}
    fd.append('ExtraBonusType',document.getElementById('f-ExtraBonusType')?.value||0);
    fd.append('ExtraBonus',document.getElementById('f-ExtraBonus')?.value||0);
    return fd;
}

function ie_ai_show(text, state='ok') {
    const el = document.getElementById('ie-ai-result');
    el.textContent = text;
    el.className = 'ie-ai-result visible ' + state;
}

function ie_ai_reset() {
    const el = document.getElementById('ie-ai-result');
    if (el) { el.className = 'ie-ai-result'; el.textContent = ''; }
    const applyLore  = document.getElementById('ie-ai-apply-lore-btn');
    const applyStats = document.getElementById('ie-ai-apply-stats-btn');
    if (applyLore)  applyLore.className  = 'ie-ai-apply-btn';
    if (applyStats) applyStats.className = 'ie-ai-apply-btn';
    ie_ai_last_lore = ''; ie_ai_last_stats = null;
}

function ie_ai_balance() {
    const btn = document.getElementById('ie-ai-balance-btn');
    if (!btn) return;
    btn.disabled = true;
    ie_ai_show('Analyzing balance…', 'loading');
    fetch(IE_AJAX + 'ai_balance_check', { method: 'POST', body: ie_ai_get_form_data() })
        .then(r => r.json())
        .then(res => {
            btn.disabled = false;
            if (res.status === 'ok') {
                ie_ai_show(res.result?.suggestion || 'No suggestion returned.', 'ok');
            } else {
                ie_ai_show('Error: ' + (res.message || 'Unknown error'), 'err');
            }
        })
        .catch(e => { btn.disabled = false; ie_ai_show('Request failed: ' + e, 'err'); });
}

function ie_ai_lore() {
    const btn = document.getElementById('ie-ai-lore-btn');
    if (!btn) return;
    btn.disabled = true;
    ie_ai_show('Generating lore…', 'loading');
    fetch(IE_AJAX + 'ai_generate_lore', { method: 'POST', body: ie_ai_get_form_data() })
        .then(r => r.json())
        .then(res => {
            btn.disabled = false;
            if (res.status === 'ok') {
                ie_ai_last_lore = res.result?.suggestion || '';
                ie_ai_show(ie_ai_last_lore, 'ok');
                const applyBtn = document.getElementById('ie-ai-apply-lore-btn');
                if (applyBtn && ie_ai_last_lore) applyBtn.className = 'ie-ai-apply-btn visible';
            } else {
                ie_ai_show('Error: ' + (res.message || 'Unknown error'), 'err');
            }
        })
        .catch(e => { btn.disabled = false; ie_ai_show('Request failed: ' + e, 'err'); });
}

function ie_ai_suggest_stats() {
    const btn = document.getElementById('ie-ai-stats-btn');
    if (!btn) return;
    btn.disabled = true;
    ie_ai_show('Suggesting stats…', 'loading');
    fetch(IE_AJAX + 'ai_suggest_stats', { method: 'POST', body: ie_ai_get_form_data() })
        .then(r => r.json())
        .then(res => {
            btn.disabled = false;
            if (res.status === 'ok') {
                const suggestion = res.result?.suggestion || '';
                ie_ai_show(suggestion, 'ok');
                // Extract JSON from suggestion
                try {
                    const match = suggestion.match(/\{[\s\S]*\}/);
                    if (match) {
                        const parsed = JSON.parse(match[0]);
                        if (parsed.bonuses && Array.isArray(parsed.bonuses)) {
                            ie_ai_last_stats = parsed.bonuses;
                            const applyBtn = document.getElementById('ie-ai-apply-stats-btn');
                            if (applyBtn) applyBtn.className = 'ie-ai-apply-btn visible';
                        }
                    }
                } catch(e) { /* JSON parse failed – no apply button */ }
            } else {
                ie_ai_show('Error: ' + (res.message || 'Unknown error'), 'err');
            }
        })
        .catch(e => { btn.disabled = false; ie_ai_show('Request failed: ' + e, 'err'); });
}

function ie_ai_apply_lore() {
    if (!ie_ai_last_lore) return;
    document.getElementById('f-Description').value = ie_ai_last_lore;
    ie_flash('Lore applied to Description field.', 'ok');
    document.getElementById('ie-ai-apply-lore-btn').className = 'ie-ai-apply-btn';
}

function ie_ai_apply_stats() {
    if (!ie_ai_last_stats || !ie_ai_last_stats.length) return;
    // Reset bonuses
    for (let i=1;i<=10;i++){
        const bt=document.getElementById('f-BonusType'+i);
        const bv=document.getElementById('f-Bonus'+i);
        if(bt) bt.value=0; if(bv) bv.value=0;
    }
    // Apply AI stats (max 10 slots)
    ie_ai_last_stats.slice(0,10).forEach((bonus, i) => {
        const bt = document.getElementById('f-BonusType'+(i+1));
        const bv = document.getElementById('f-Bonus'+(i+1));
        if (bt) bt.value = bonus.type  || 0;
        if (bv) bv.value = bonus.value || 0;
    });
    ie_update_utility();
    ie_flash('AI stats applied. Review and save to confirm.', 'ok');
    document.getElementById('ie-ai-apply-stats-btn').className = 'ie-ai-apply-btn';
}
</script>
