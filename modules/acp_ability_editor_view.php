<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_ACP')) exit;

if (!isset($userPriv))      $userPriv      = (int)($_SESSION['priv_level'] ?? 0);
if (!isset($currentUserId)) $currentUserId = (int)($_SESSION['user_id']    ?? 0);

$csrf        = generateToken();
$active_tab  = preg_replace('/[^a-z0-9_]/', '', $_GET['tab'] ?? 'spells');
$valid_tabs  = ['npc', 'spells', 'spelllines', 'styles', 'abilities'];
if (!in_array($active_tab, $valid_tabs)) $active_tab = 'spells';

$_ae_ai_active   = isset($botSettings) && $botSettings->isActive() && $botSettings->hasAiConfigured();
$_ae_ai_provider = $_ae_ai_active ? ucfirst($botSettings->getProvider()) : '';
?>

<div id="ae-toast"><i class="fas fa-check-circle"></i><span id="ae-toast-msg">Saved</span></div>

<div class="ae-wrap">

    <div class="ae-tabs">
        <a class="ae-tab <?= $active_tab==='spells'     ?'active':'' ?>" href="#" data-tab="spells"><i class="fas fa-bolt"></i> Spells</a>
        <a class="ae-tab <?= $active_tab==='spelllines' ?'active':'' ?>" href="#" data-tab="spelllines"><i class="fas fa-layer-group"></i> Spell Lines</a>
        <a class="ae-tab <?= $active_tab==='styles'     ?'active':'' ?>" href="#" data-tab="styles"><i class="fas fa-khanda"></i> Styles</a>
        <a class="ae-tab <?= $active_tab==='abilities'  ?'active':'' ?>" href="#" data-tab="abilities"><i class="fas fa-star"></i> Abilities</a>
        <a class="ae-tab <?= $active_tab==='npc'        ?'active':'' ?>" href="#" data-tab="npc"><i class="fas fa-dragon"></i> NPC Templates</a>
        <?php if ($_ae_ai_active): ?>
        <span style="margin-left:auto;padding:0 14px;font-family:'Cinzel',serif;font-size:10px;letter-spacing:1px;color:#3a3020;display:flex;align-items:center;gap:6px;"><i class="fas fa-robot" style="color:#c5a059;opacity:0.5;"></i><?= h($_ae_ai_provider) ?></span>
        <?php endif; ?>
    </div>

    <!-- SPELLS -->
    <div class="ae-panel <?= $active_tab==='spells'?'active':'' ?>" id="panel-spells">
        <div class="ae-info-bar"><span><?= t('acp_abc_descr', [], 'Edit damage, cast times, cooldowns &amp; range.') ?></span><span><strong><?= t('acp_abc_tip', [], 'Tip') ?>:</strong> <?= t('acp_abc_select_a_spell', [], 'Select a spell to open the editor') ?>.</span></div>
        <div class="ae-pane">
            <div class="ae-list-col">
                <div class="ae-list-head">
                    <input type="text" class="ae-search" id="spell-search" placeholder="Search spells…">
                    <select class="ae-select" id="spell-type-filter"><option value=""><?= t('acp_abc_all_types', [], 'All types') ?></option></select>
                    <button class="ae-btn-new" onclick="AE.spell.openNew()"><i class="fas fa-plus"></i> New</button>
                </div>
                <div class="ae-list" id="spell-list"></div>
                <div class="ae-list-footer">
                    <button class="ae-page-btn" id="spell-prev" onclick="AE.spell.page(-1)">‹</button>
                    <span id="spell-pagination-info">—</span>
                    <button class="ae-page-btn" id="spell-next" onclick="AE.spell.page(1)">›</button>
                </div>
            </div>
            <div class="ae-detail-col" id="spell-detail">
                <div class="ae-empty-state"><i class="fas fa-bolt"></i><span>Select a Spell</span></div>
            </div>
        </div>
    </div>

    <!-- SPELL LINES -->
    <div class="ae-panel <?= $active_tab==='spelllines'?'active':'' ?>" id="panel-spelllines">
        <div class="ae-info-bar"><span>Group spells by specialization. <strong>LineXSpell</strong> maps spells to levels.</span></div>
        <div class="ae-pane">
            <div class="ae-list-col">
                <div class="ae-list-head">
                    <input type="text" class="ae-search" id="line-search" placeholder="Search lines…">
                    <button class="ae-btn-new" onclick="AE.line.openNew()"><i class="fas fa-plus"></i> New</button>
                </div>
                <div class="ae-list" id="line-list"></div>
            </div>
            <div class="ae-detail-col" id="line-detail">
                <div class="ae-empty-state"><i class="fas fa-layer-group"></i><span>Select a Spell Line</span></div>
            </div>
        </div>
    </div>

    <!-- STYLES -->
    <div class="ae-panel <?= $active_tab==='styles'?'active':'' ?>" id="panel-styles">
        <div class="ae-info-bar"><span><?= t('acp_abc_combat_style', [], 'Combat styles (melee combos). StyleXSpell links proc spells per class.') ?></span></div>
        <div class="ae-pane">
            <div class="ae-list-col">
                <div class="ae-list-head">
                    <input type="text" class="ae-search" id="style-search" placeholder="Search styles…">
                    <button class="ae-btn-new" onclick="AE.style.openNew()"><i class="fas fa-plus"></i> <?= t('acp_btn_new', [], 'New') ?></button>
                </div>
                <div class="ae-list" id="style-list"></div>
                <div class="ae-list-footer">
                    <button class="ae-page-btn" id="style-prev" onclick="AE.style.page(-1)">‹</button>
                    <span id="style-pagination-info">—</span>
                    <button class="ae-page-btn" id="style-next" onclick="AE.style.page(1)">›</button>
                </div>
            </div>
            <div class="ae-detail-col" id="style-detail">
                <div class="ae-empty-state"><i class="fas fa-khanda"></i><span><?= t('acp_abc_select_a_style', [], 'Select a style') ?></span></div>
            </div>
        </div>
    </div>

    <!-- ABILITIES -->
    <div class="ae-panel <?= $active_tab==='abilities'?'active':'' ?>" id="panel-abilities">
        <div class="ae-info-bar"><span><?= t('acp_abc_abilities.description', [], 'Passive or active abilities (non-spell). Realm abilities, passives & more.') ?></span></div>
        <div class="ae-pane">
            <div class="ae-list-col">
                <div class="ae-list-head">
                    <input type="text" class="ae-search" id="ability-search" placeholder="Search abilities…">
                    <button class="ae-btn-new" onclick="AE.ability.openNew()"><i class="fas fa-plus"></i> <?= t('acp_btn_new', [], 'New') ?></button>
                </div>
                <div class="ae-list" id="ability-list"></div>
                <div class="ae-list-footer">
                    <button class="ae-page-btn" id="ability-prev" onclick="AE.ability.page(-1)">‹</button>
                    <span id="ability-pagination-info">—</span>
                    <button class="ae-page-btn" id="ability-next" onclick="AE.ability.page(1)">›</button>
                </div>
            </div>
            <div class="ae-detail-col" id="ability-detail">
                <div class="ae-empty-state"><i class="fas fa-star"></i><span><?= t('acp_abc_select_ability', [], 'Select an Ability') ?></span></div>
            </div>
        </div>
    </div>

    <!-- NPC TEMPLATES -->
    <div class="ae-panel <?= $active_tab==='npc'?'active':'' ?>" id="panel-npc">
        <div class="ae-info-bar"><span><?= t('acp_abc_npc_info', [], 'Create and edit NPC Templates. Assign spells — compatibility is checked.') ?></span></div>
        <div class="ae-pane">
            <div class="ae-list-col">
                <div class="ae-list-head">
                    <input type="text" class="ae-search" id="npc-search" placeholder="Search templates…">
                    <button class="ae-btn-new" onclick="AE.npc.openNew()"><i class="fas fa-plus"></i> <?= t('acp_btn_new', [], 'New') ?></button>
                </div>
                <div class="ae-list" id="npc-list"></div>
                <div class="ae-list-footer">
                    <button class="ae-page-btn" id="npc-prev" onclick="AE.npc.page(-1)">‹</button>
                    <span id="npc-pagination-info">—</span>
                    <button class="ae-page-btn" id="npc-next" onclick="AE.npc.page(1)">›</button>
                </div>
            </div>
            <div class="ae-detail-col" id="npc-detail">
                <div class="ae-empty-state"><i class="fas fa-dragon"></i><span><?= t('acp_abc_select_npc_template', [], 'Select an NPC Template') ?></span></div>
            </div>
        </div>
    </div>

</div>

<script>
const AE_CSRF       = '<?= $csrf ?>';
const AE_BASE       = 'acp.php?s=ability_editor&ajax=1';
const AE_AI         = <?= $_ae_ai_active?'true':'false' ?>;
const AE_CAN_DELETE = <?= $userPriv>=5?'true':'false' ?>;

const AEToast = {
    el: document.getElementById('ae-toast'),
    msg: document.getElementById('ae-toast-msg'),
    t: null,
    show(text, type='ok') {
        this.msg.textContent = text;
        this.el.className = type==='error'?'error':'';
        void this.el.offsetWidth;
        this.el.classList.add('visible');
        clearTimeout(this.t);
        this.t = setTimeout(()=>this.el.classList.remove('visible'), 2600);
    }
};

document.querySelectorAll('a.ae-tab[data-tab]').forEach(tab=>{
    tab.addEventListener('click', e=>{
        e.preventDefault();
        const t = tab.dataset.tab;
        document.querySelectorAll('a.ae-tab').forEach(x=>x.classList.remove('active'));
        document.querySelectorAll('div.ae-panel').forEach(x=>x.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('panel-'+t)?.classList.add('active');
        if(t==='spells'     && !AE.spell._loaded)   AE.spell.load();
        if(t==='spelllines' && !AE.line._loaded)    AE.line.load();
        if(t==='styles'     && !AE.style._loaded)   AE.style.load();
        if(t==='abilities'  && !AE.ability._loaded) AE.ability.load();
        if(t==='npc'        && !AE.npc._loaded)     AE.npc.load();
    });
});

async function aePost(action, data) {
    const fd = new FormData();
    fd.append('action', action); fd.append('csrf_token', AE_CSRF);
    Object.entries(data||{}).forEach(([k,v])=>v!=null&&fd.append(k,v));
    return (await fetch(AE_BASE,{method:'POST',body:fd}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));})).json();
}
async function aeGet(params) {
    const qs = Object.entries(params).map(([k,v])=>k+'='+encodeURIComponent(v)).join('&');
    return (await fetch(AE_BASE+'&'+qs).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));})).json();
}
function h(s) { const el=document.createElement('div'); el.textContent=String(s??''); return el.innerHTML; }

function aeSpellColor(type) {
    if(!type) return '#2a2a2a';
    const t=type.toLowerCase();
    if(t.includes('heal')||t.includes('hot'))  return '#2a6a3a';
    if(t.includes('nuke')||t.includes('dd'))   return '#7a2020';
    if(t.includes('buff')||t.includes('enh'))  return '#3a5a2a';
    if(t.includes('dot')||t.includes('pois'))  return '#5a2a6a';
    if(t.includes('debuff'))                   return '#6a5a1a';
    if(t.includes('mez')||t.includes('stun')||t.includes('root')) return '#7a4a1a';
    if(t.includes('pet')||t.includes('summ'))  return '#1a5a5a';
    return '#3a3a2a';
}

function aeSkel() {
    return '<div class="acp-s-36ea36b8">'
        + '<div class="ae-skeleton"></div>'.repeat(4) + '</div>';
}
function aeSpinner() {
    return '<div class="acp-s-30efa8e5"><i class="fas fa-circle-notch fa-spin acp-s-779babf6"></i>LOADING</div>';
}

const AE = {};

/* ── SPELLS ───────────────────────────────────────────── */
AE.spell = {
    _loaded:false, offset:0, limit:10, total:0, current:null,

    async load() {
        this._loaded=true;
        const types=await aeGet({action:'spell_types'});
        const sel=document.getElementById('spell-type-filter');
        if(sel&&types.types) types.types.forEach(t=>{const o=document.createElement('option');o.value=t;o.textContent=t;sel.appendChild(o);});
        await this.reload();
        let st;
        document.getElementById('spell-search')?.addEventListener('input',()=>{clearTimeout(st);st=setTimeout(()=>{this.offset=0;this.reload();},280);});
        document.getElementById('spell-type-filter')?.addEventListener('change',()=>{this.offset=0;this.reload();});
    },

    async reload() {
        const q=document.getElementById('spell-search')?.value||'';
        const type=document.getElementById('spell-type-filter')?.value||'';
        document.getElementById('spell-list').innerHTML=aeSkel();
        const data=await aeGet({action:'spell_list',q,type,limit:this.limit,offset:this.offset});
        this.total=data.total||0;
        this.renderList(data.rows||[]);
        const info=document.getElementById('spell-pagination-info');
        if(info) info.textContent=`${this.offset+1}–${Math.min(this.offset+this.limit,this.total)} / ${this.total}`;
        document.getElementById('spell-prev').disabled=this.offset===0;
        document.getElementById('spell-next').disabled=this.offset+this.limit>=this.total;
    },

    renderList(rows) {
        const el=document.getElementById('spell-list');
        if(!rows.length){el.innerHTML='<div class="acp-s-694cc60d">No spells found</div>';return;}
        el.innerHTML=rows.map(r=>`
            <div class="ae-list-item ${this.current?.Spell_ID==r.Spell_ID?'selected':''}" data-spell-id="${r.Spell_ID}" onclick="AE.spell.select('${r.Spell_ID}')">
                <div class="ae-list-item-name">
                    <span class="ae-type-dot acp-s-1e039b33"></span>
                    ${h(r.Name)||'—'} <small>${h(r.Spell_ID)}</small>
                </div>
                <div class="ae-list-item-meta">
                    <span class="ae-tag">${h(r.Type||'—')}</span>
                    ${r.Damage?`<span>${r.Damage} dmg</span>`:''}
                    ${r.CastTime?`<span>${r.CastTime}s</span>`:''}
                </div>
            </div>`).join('');
    },

    page(dir){this.offset=Math.max(0,this.offset+dir*this.limit);this.reload();},

    async select(id) {
        document.querySelectorAll('#spell-list .ae-list-item').forEach(x=>x.classList.remove('selected'));
        document.querySelector(`#spell-list div.ae-list-item[data-spell-id="${id}"]`)?.classList.add('selected');
        const det=document.getElementById('spell-detail');
        det.innerHTML=aeSpinner();
        const data=await aeGet({action:'spell_get',id});
        if(!data.ok){det.innerHTML=`<div class="acp-s-f3886d2c">${data.error}</div>`;return;}
        this.current=data.row;
        try{this.renderDetail(data.row);}catch(e){det.innerHTML=`<div class="acp-s-f3886d2c">Error: ${e.message}</div>`;}
    },

    renderDetail(r) {
        const det=document.getElementById('spell-detail');
        const dps=r.Damage>0&&r.Duration>0?(r.Damage/r.Duration).toFixed(1):null;
        const usedLines=(r.used_in_lines||[]).map(l=>`<span class="ae-tag">${h(l.LineName||'?')} Lv${l.Level||'?'}</span>`).join(' ');
        const usedStyles=(r.used_in_styles||[]).map(s=>`<span class="ae-tag">${h(s.StyleName||'?')}</span>`).join(' ');
        const dmgType={0:'Natural',1:'Crush',2:'Slash',3:'Thrust',10:'Fire',12:'Cold',15:'Matter',16:'Body',17:'Spirit',18:'Energy'};

        det.innerHTML=`
        <div class="ae-detail-head">
            <div class="acp-s-da5cd676">
                <div class="ae-detail-title">
                    <span class="ae-type-dot acp-s-552e15ac"></span>
                    ${h(r.Name||'Unnamed Spell')}
                </div>
                <div class="ae-detail-subtitle">${h(r.Spell_ID)} · ${h(r.Type||'Unknown')} · Target: ${h(r.Target||'—')}</div>
            </div>
            <button class="ae-btn" onclick="AE.spell.copyAs('${r.Spell_ID}')"><i class="fas fa-copy"></i> Clone</button>
            ${AE_CAN_DELETE?`<button class="ae-btn danger" onclick="AE.spell.delete('${r.Spell_ID}')"><i class="fas fa-trash"></i></button>`:''}
        </div>
        <div class="ae-detail-body">
            ${dps?`<div class="ae-stat-block">
                <div class="ae-stat"><div class="ae-stat-label">DPS</div><div class="ae-stat-value ${parseFloat(dps)>150?'red':parseFloat(dps)>80?'gold':'green'}">${dps}</div></div>
                <div class="ae-stat"><div class="ae-stat-label">Damage</div><div class="ae-stat-value gold">${r.Damage||'—'}</div></div>
                <div class="ae-stat"><div class="ae-stat-label">Range</div><div class="ae-stat-value">${r.Range||'—'}</div></div>
            </div>`:''}
            ${usedLines?`<div class="acp-s-d51702bf"><div class="ae-label acp-s-d3c5339f">Used in Lines</div><div class="acp-s-5e254dbb">${usedLines}</div></div>`:''}
            ${usedStyles?`<div class="acp-s-fa3e1c7d"><div class="ae-label acp-s-d3c5339f">Used in Styles</div><div class="acp-s-5e254dbb">${usedStyles}</div></div>`:''}
            <form onsubmit="return AE.spell.save(event,'${r.Spell_ID}')">
                <div class="ae-form-grid">
                    <div class="ae-field ae-form-full"><label class="ae-label">Name</label><input class="ae-input" name="Name" value="${h(r.Name||'')}"></div>
                    <div class="ae-field"><label class="ae-label">Spell Type</label><input class="ae-input" name="Type" value="${h(r.Type||'')}"></div>
                    <div class="ae-field"><label class="ae-label">Target</label>
                        <select class="ae-select-styled" name="Target">${['Self','Enemy','Realm','Group','Pet','Area','Cone'].map(t=>`<option ${r.Target===t?'selected':''}>${t}</option>`).join('')}</select>
                    </div>
                    <div class="ae-field"><label class="ae-label">Damage <span>(base)</span></label><input class="ae-input" name="Damage" type="number" value="${r.Damage||0}"></div>
                    <div class="ae-field"><label class="ae-label">Damage Type</label>
                        <select class="ae-select-styled" name="DamageType">${Object.entries(dmgType).map(([v,n])=>`<option value="${v}" ${r.DamageType==v?'selected':''}>${n}</option>`).join('')}</select>
                    </div>
                    <div class="ae-field"><label class="ae-label">Value <span>(buff/heal)</span></label><input class="ae-input" name="Value" type="number" step="0.1" value="${r.Value||0}"></div>
                    <div class="ae-field"><label class="ae-label">Duration <span>(s)</span></label><input class="ae-input" name="Duration" type="number" step="0.1" value="${r.Duration||0}"></div>
                    <div class="ae-field"><label class="ae-label">Cast Time <span>(s)</span></label><input class="ae-input" name="CastTime" type="number" step="0.1" value="${r.CastTime||0}"></div>
                    <div class="ae-field"><label class="ae-label">Recast Delay <span>(s)</span></label><input class="ae-input" name="RecastDelay" type="number" value="${r.RecastDelay||0}"></div>
                    <div class="ae-field"><label class="ae-label">Range</label><input class="ae-input" name="Range" type="number" value="${r.Range||0}"></div>
                    <div class="ae-field"><label class="ae-label">Radius</label><input class="ae-input" name="Radius" type="number" value="${r.Radius||0}"></div>
                    <div class="ae-field"><label class="ae-label">Concentration</label><input class="ae-input" name="Concentration" type="number" value="${r.Concentration||0}"></div>
                    <div class="ae-field ae-form-full"><label class="ae-label">Description</label><textarea class="ae-textarea" name="Description">${h(r.Description||'')}</textarea></div>
                </div>
                <div class="acp-s-e0e9ec07">
                    <button type="submit" class="ae-btn"><i class="fas fa-save"></i> Save Spell</button>
                    ${AE_AI?`<button type="button" class="ae-btn" onclick="AE.spell.aiSuggest('${r.Spell_ID}')"><i class="fas fa-robot"></i> AI Check</button>`:''}
                </div>
            </form>
        </div>`;
    },

    async save(e,id){
        e.preventDefault();
        const fd=new FormData(e.target);fd.append('Spell_ID',id||'');fd.append('action','spell_save');fd.append('csrf_token',AE_CSRF);
        const d=await(await fetch(AE_BASE,{method:'POST',body:fd}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));})).json();
        d.ok?(AEToast.show('Spell saved'),this.reload()):AEToast.show(d.error||'Error','error');
        return false;
    },

    async delete(id){
        if(!confirm('Delete this spell?'))return;
        const d=await aePost('spell_delete',{Spell_ID:id});
        if(d.ok){AEToast.show('Deleted');document.getElementById('spell-detail').innerHTML='<div class="ae-empty-state"><i class="fas fa-bolt"></i><span>Select a Spell</span></div>';this.current=null;this.reload();}
        else AEToast.show(d.error||'Error','error');
    },

    openNew(){
        document.getElementById('spell-detail').innerHTML=`
        <div class="ae-detail-head"><div class="ae-detail-title"><i class="fas fa-plus" style="font-size:11px;opacity:0.5;"></i> <?= t('acp_abc_new_spell', [], 'New Spell') ?></div></div>
        <div class="ae-detail-body"><form onsubmit="return AE.spell.save(event,'')">
            <div class="ae-form-grid">
                <div class="ae-field ae-form-full"><label class="ae-label">Name</label><input class="ae-input" name="Name" placeholder="Fireball" required></div>
                <div class="ae-field"><label class="ae-label">Spell Type</label><input class="ae-input" name="Type" placeholder="DirectDamage"></div>
                <div class="ae-field"><label class="ae-label">Target</label><select class="ae-select-styled" name="Target">${['Self','Enemy','Realm','Group','Pet','Area','Cone'].map(t=>`<option>${t}</option>`).join('')}</select></div>
                <div class="ae-field"><label class="ae-label">Damage</label><input class="ae-input" name="Damage" type="number" value="0"></div>
                <div class="ae-field"><label class="ae-label">Damage Type</label><select class="ae-select-styled" name="DamageType"><option value="10">Fire</option><option value="12">Cold</option><option value="16">Body</option><option value="17">Spirit</option><option value="18">Energy</option></select></div>
                <div class="ae-field"><label class="ae-label">Value</label><input class="ae-input" name="Value" type="number" step="0.1" value="0"></div>
                <div class="ae-field"><label class="ae-label">Duration (s)</label><input class="ae-input" name="Duration" type="number" step="0.1" value="0"></div>
                <div class="ae-field"><label class="ae-label">Cast Time (s)</label><input class="ae-input" name="CastTime" type="number" step="0.1" value="3"></div>
                <div class="ae-field"><label class="ae-label">Recast Delay (s)</label><input class="ae-input" name="RecastDelay" type="number" value="0"></div>
                <div class="ae-field"><label class="ae-label">Range</label><input class="ae-input" name="Range" type="number" value="1500"></div>
                <div class="ae-field ae-form-full"><label class="ae-label"><?= t('acp_abc_description', [], 'Description') ?></label><textarea class="ae-textarea" name="Description"></textarea></div>
            </div>
            <button type="submit" class="ae-btn"><i class="fas fa-save"></i> <?= t('acp_abc_create_spell', [], 'Create a Spell') ?></button>
        </form></div>`;
    },

    copyAs(id){
        this.openNew();
        if(this.current&&this.current.Spell_ID==id){
            const form=document.querySelector('#spell-detail form');if(!form)return;
            const r=this.current;
            ['Name','Type','Damage','Value','Duration','CastTime','Range','Radius','RecastDelay','Description'].forEach(k=>{
                const el=form.querySelector(`[name=${k}]`);
                if(el)el.value=k==='Name'?'Copy of '+(r[k]||''):(r[k]||'');
            });
        }
        AEToast.show('Cloned — update name and save');
    },

    async aiSuggest(id){
        AEToast.show('Requesting AI analysis…');
        const r=this.current;if(!r)return;
        const fd=new FormData();
        fd.append('ca_ai_action','suggest_balance');fd.append('csrf_token',AE_CSRF);
        fd.append('issue',`Spell: ${r.Name} | Type: ${r.Type} | Damage: ${r.Damage} | Duration: ${r.Duration} | CastTime: ${r.CastTime}`);
        fd.append('realm','all');
        const d=await(await fetch('acp.php?s=core_architect',{method:'POST',body:fd}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));})).json();
        if(d.status==='ok'){
            const det=document.getElementById('spell-detail');
            det.querySelector('.ae-ai-result')?.remove();
            const div=document.createElement('div');div.className='ae-ai-result';
            div.textContent='AI:\n\n'+(d.result?.suggestion||'—');
            det.querySelector('.ae-detail-body')?.prepend(div);
        } else AEToast.show(d.message||'AI error','error');
    }
};

/* ── SPELL LINES ─────────────────────────────────────────── */
AE.line = {
    _loaded:false, current:null,

    async load(){
        this._loaded=true;await this.reload();
        let st;document.getElementById('line-search')?.addEventListener('input',()=>{clearTimeout(st);st=setTimeout(()=>this.reload(),280);});
    },

    async reload(){
        const q=document.getElementById('line-search')?.value||'';
        document.getElementById('line-list').innerHTML=aeSkel();
        const data=await aeGet({action:'spellline_list',q,limit:10});
        this.renderList(data.rows||[]);
    },

    renderList(rows){
        const el=document.getElementById('line-list');
        if(!rows.length){el.innerHTML='<div style="padding:20px;text-align:center;color:#2a2a2a;font-size:12px;font-family:sans-serif;"><?= t('acp_abc_no_spell_lines_found', [], 'No spell lines found') ?></div>';return;}
        el.innerHTML=rows.map(r=>`
            <div class="ae-list-item ${this.current?.KeyName===r.KeyName?'selected':''}" onclick="AE.line.select('${(r.KeyName||r.Name||'').replace(/'/g,"\\'")}')">
                <div class="ae-list-item-name">${h(r.Name||'Unnamed Line')}</div>
                <div class="ae-list-item-meta"><span>${h(r.Spec||'—')}</span>${r.IsBaseLine=='1'?'<span class="ae-tag">Baseline</span>':''}</div>
            </div>`).join('');
    },

    async select(name){
        document.querySelectorAll('#line-list .ae-list-item').forEach(x=>x.classList.remove('selected'));
        const det=document.getElementById('line-detail');det.innerHTML=aeSpinner();
        const data=await aeGet({action:'spellline_get',key:name});
        if(!data.ok){det.innerHTML=`<div class="acp-s-f3886d2c">${data.error}</div>`;return;}
        this.current=data.row;this.renderDetail(data.row);
    },

    renderDetail(r){
        const lineKey=r._lineKey||r.KeyName||r.Name||'';
        const spellsHtml=(r.spells||[]).length
            ?`<table class="ae-link-table"><thead><tr><th>Level</th><th>Spell</th><th>Type</th><th>DMG</th><th></th></tr></thead><tbody>
            ${(r.spells||[]).map(s=>`<tr>
                <td class="acp-s-ea320bd8">${s.Level||'—'}</td>
                <td>${h(s.SpellName||'ID '+s.SpellID)}</td>
                <td><span class="ae-type-dot acp-s-7a5a7d36"></span>${h(s.SpellType||'—')}</td>
                <td>${s.Damage||'—'}</td>
                <td><button class="ae-btn danger acp-s-1eba760d" onclick="AE.line.removeSpell('${lineKey.replace(/'/g,"\\'")}','${s.SpellID}')">✕</button></td>
            </tr>`).join('')}</tbody></table>`
            :'<div class="acp-s-524043ea">No spells in this line.</div>';

        document.getElementById('line-detail').innerHTML=`
        <div class="ae-detail-head">
            <div class="acp-s-da5cd676">
                <div class="ae-detail-title">${h(r.Name||'Unnamed Line')}</div>
                <div class="ae-detail-subtitle">Key: ${h(r.KeyName||'—')} · Spec: ${h(r.Spec||'—')} · ${r.IsBaseLine=='1'?'Baseline':'Specialization'}</div>
            </div>
        </div>
        <div class="ae-detail-body">
            <form onsubmit="return AE.line.save(event,'${lineKey.replace(/'/g,"\\'")}')">
                <div class="ae-form-grid">
                    <div class="ae-field"><label class="ae-label">Line Name</label><input class="ae-input" name="Name" value="${h(r.Name||'')}" required></div>
                    <div class="ae-field"><label class="ae-label">Spec Key</label><input class="ae-input" name="Spec" value="${h(r.Spec||'')}"></div>
                    <div class="ae-field"><label class="ae-label"><?= t('acp_abc_is_baseline', [], 'Is Baseline?') ?></label><select class="ae-select-styled" name="IsBaseLine"><option value="0" ${r.IsBaseLine!='1'?'selected':''}>No</option><option value="1" ${r.IsBaseLine=='1'?'selected':''}>Yes</option></select></div>
                    <div class="ae-field"><label class="ae-label">Override Shared Timer?</label><select class="ae-select-styled" name="OverrideSharedTimer"><option value="0" ${r.OverrideSharedTimer!='1'?'selected':''}>No</option><option value="1" ${r.OverrideSharedTimer=='1'?'selected':''}>Yes</option></select></div>
                </div>
                <button type="submit" class="ae-btn"><i class="fas fa-save"></i> Save Line</button>
            </form>
            <div class="ae-section-head"><i class="fas fa-link"></i> Spells in Line <span>${(r.spells||[]).length} spells</span></div>
            ${spellsHtml}
            <div class="ae-section-head" style="margin-top:16px;"><i class="fas fa-plus-circle"></i> Add Spell</div>
            <div style="display:grid;grid-template-columns:1fr 80px 60px;gap:8px;align-items:end;">
                <div class="ae-field"><label class="ae-label">Spell</label>
                    <div class="ae-autocomplete-wrap">
                        <input class="ae-input" id="line-add-spell-input" placeholder="Search by name…" autocomplete="off">
                        <input type="hidden" id="line-add-spell-id">
                        <div class="ae-autocomplete" id="line-add-spell-ac"></div>
                    </div>
                </div>
                <div class="ae-field"><label class="ae-label">Level</label><input class="ae-input" id="line-add-spell-level" type="number" min="1" max="50" value="1"></div>
                <button type="button" class="ae-btn" onclick="AE.line.addSpell('${lineKey.replace(/'/g,"\\'")}')"><i class="fas fa-plus"></i></button>
            </div>
        </div>`;
        AE._initAC('line-add-spell-input','line-add-spell-ac','line-add-spell-id');
    },

    async save(e,name){
        e.preventDefault();
        const fd=new FormData(e.target);fd.append('action','spellline_save');fd.append('csrf_token',AE_CSRF);
        const d=await(await fetch(AE_BASE,{method:'POST',body:fd}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));})).json();
        d.ok?(AEToast.show('Line saved'),this.reload()):AEToast.show(d.error||'Error','error');return false;
    },
    async addSpell(lineName){
        const sid=document.getElementById('line-add-spell-id')?.value;
        const lv=document.getElementById('line-add-spell-level')?.value||1;
        if(!sid){AEToast.show('Select a spell first','error');return;}
        const d=await aePost('linexspell_save',{LineName:lineName,SpellID:sid,Level:lv});
        d.ok?(AEToast.show('Spell added'),this.select(lineName)):AEToast.show(d.error||'Error','error');
    },
    async removeSpell(lineName,spellId){
        if(!confirm('Remove spell from line?'))return;
        const d=await aePost('linexspell_delete',{LineName:lineName,SpellID:spellId});
        d.ok?(AEToast.show('Removed'),this.select(lineName)):AEToast.show(d.error||'Error','error');
    },
    openNew(){
        document.getElementById('line-detail').innerHTML=`
        <div class="ae-detail-head"><div class="ae-detail-title"><i class="fas fa-plus" style="font-size:11px;opacity:0.5;"></i> <?= t('acp_abc_new_spell_line', [], 'New Spell Line') ?></div></div>
        <div class="ae-detail-body"><form onsubmit="return AE.line.save(event,'')">
            <div class="ae-form-grid">
                <div class="ae-field ae-form-full"><label class="ae-label"><?= t('acp_abc_line_name', [], 'Line Name') ?></label><input class="ae-input" name="Name" placeholder="Cabalist Baseline" required></div>
                <div class="ae-field"><label class="ae-label">Spec Key</label><input class="ae-input" name="Spec" placeholder="Cabalist"></div>
                <div class="ae-field"><label class="ae-label"><?= t('acp_abc_is_baseline', [], 'Is Baseline?') ?></label><select class="ae-select-styled" name="IsBaseLine"><option value="0"><?= t('acp_abc_no', [], 'No') ?></option><option value="1"><?= t('acp_abc_yes', [], 'Yes') ?></option></select></div>
            </div>
            <button type="submit" class="ae-btn"><i class="fas fa-save"></i> <?= t('acp_abc_create_line', [], 'Create Line') ?></button>
        </form></div>`;
    }
};

/* ── STYLES ──────────────────────────────────────────────── */
AE.style = {
    _loaded:false, offset:0, limit:10, total:0, current:null,
    async load(){this._loaded=true;await this.reload();let st;document.getElementById('style-search')?.addEventListener('input',()=>{clearTimeout(st);st=setTimeout(()=>{this.offset=0;this.reload();},280);});},
    async reload(){
        const q=document.getElementById('style-search')?.value||'';
        document.getElementById('style-list').innerHTML=aeSkel();
        const data=await aeGet({action:'style_list',q,limit:this.limit,offset:this.offset});
        this.total=data.total||0;this.renderList(data.rows||[]);
        const info=document.getElementById('style-pagination-info');
        if(info)info.textContent=`${this.offset+1}–${Math.min(this.offset+this.limit,this.total)} / ${this.total}`;
        document.getElementById('style-prev').disabled=this.offset===0;
        document.getElementById('style-next').disabled=this.offset+this.limit>=this.total;
    },
    renderList(rows){
        const el=document.getElementById('style-list');
        if(!rows.length){el.innerHTML='<div style="padding:20px;text-align:center;color:#2a2a2a;font-size:12px;font-family:sans-serif;"><?= t('acp_abc_no_styles_found', [], 'No styles found') ?></div>';return;}
        el.innerHTML=rows.map(r=>`
            <div class="ae-list-item ${this.current?.StyleID==r.StyleID?'selected':''}" onclick="AE.style.select(${r.StyleID})">
                <div class="ae-list-item-name">${h(r.Name||'—')} <small>#${r.StyleID}</small></div>
                <div class="ae-list-item-meta">
                    <span class="ae-tag">${h(r.SpecKeyName||'—')}</span>
                    <span>Lv${r.SpecLevelRequirement||'?'}</span>
                    ${r.BonusToDamage>0?`<span style="color:#7a3030;">+${r.BonusToDamage}</span>`:''}
                </div>
            </div>`).join('');
    },
    page(dir){this.offset=Math.max(0,this.offset+dir*this.limit);this.reload();},
    async select(id){
        const det=document.getElementById('style-detail');det.innerHTML=aeSpinner();
        const data=await aeGet({action:'style_get',id});
        if(!data.ok){det.innerHTML=`<div style="padding:20px;color:#8a3030;">${data.error}</div>`;return;}
        this.current=data.row;this.renderDetail(data.row);
    },
    renderDetail(r){
        const linked=(r.linked_spells||[]).length
            ?`<table class="ae-link-table"><thead><tr><th>Spell</th><th>Type</th><th><?= t('acp_abc_class', [], 'Class') ?></th><th></th></tr></thead><tbody>
            ${r.linked_spells.map(s=>`<tr>
                <td>${h(s.SpellName||'ID '+s.SpellID)}</td>
                <td><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:${aeSpellColor(s.SpellType)};margin-right:5px;"></span>${h(s.SpellType||'—')}</td>
                <td>${s.ClassID||'All'}</td>
                <td><button class="ae-btn danger" style="padding:2px 8px;font-size:9px;" onclick="AE.style.removeSpell(${r.StyleID},'${s.SpellID}',${s.ClassID||0})">✕</button></td>
            </tr>`).join('')}</tbody></table>`
            :'<div style="color:#2a2a2a;font-size:12px;font-family:sans-serif;font-style:italic;padding:6px 0;"><?= t('acp_abc_no_proc_spells_linked', [], 'No proc spells linked.') ?></div>';
        document.getElementById('style-detail').innerHTML=`
        <div class="ae-detail-head">
            <div style="flex:1;"><div class="ae-detail-title">${h(r.Name||'Style')}</div>
            <div class="ae-detail-subtitle">ID #${r.StyleID} · ${h(r.SpecKeyName||'—')} · Level ${r.SpecLevelRequirement||'?'}</div></div>
            ${AE_CAN_DELETE?`<button class="ae-btn danger" onclick="AE.style.delete(${r.StyleID})"><i class="fas fa-trash"></i></button>`:''}
        </div>
        <div class="ae-detail-body">
            <form onsubmit="return AE.style.save(event,${r.StyleID})">
                <div class="ae-form-grid ae-form-grid-3">
                    <div class="ae-field ae-form-full"><label class="ae-label">Name</label><input class="ae-input" name="Name" value="${h(r.Name||'')}"></div>
                    <div class="ae-field"><label class="ae-label">Spec Key</label><input class="ae-input" name="SpecKeyName" value="${h(r.SpecKeyName||'')}"></div>
                    <div class="ae-field"><label class="ae-label">Spec Level Req</label><input class="ae-input" name="SpecLevelRequirement" type="number" value="${r.SpecLevelRequirement||0}"></div>
                    <div class="ae-field"><label class="ae-label">Attack Result Req</label><input class="ae-input" name="AttackResultRequirement" type="number" value="${r.AttackResultRequirement||0}"></div>
                    <div class="ae-field"><label class="ae-label">Bonus To Hit</label><input class="ae-input" name="BonusToHit" type="number" value="${r.BonusToHit||0}"></div>
                    <div class="ae-field"><label class="ae-label">Bonus To Damage</label><input class="ae-input" name="BonusToDamage" type="number" value="${r.BonusToDamage||0}"></div>
                    <div class="ae-field"><label class="ae-label">Bonus To Defense</label><input class="ae-input" name="BonusToDefense" type="number" value="${r.BonusToDefense||0}"></div>
                    <div class="ae-field"><label class="ae-label">Growth Rate</label><input class="ae-input" name="GrowthRate" type="number" step="0.01" value="${r.GrowthRate||0}"></div>
                    <div class="ae-field"><label class="ae-label">Endurance Cost</label><input class="ae-input" name="EnduranceCost" type="number" value="${r.EnduranceCost||0}"></div>
                </div>
                <button type="submit" class="ae-btn"><i class="fas fa-save"></i> Save Style</button>
            </form>
            <div class="ae-section-head acp-s-553a645f"><i class="fas fa-magic"></i> Proc Spells</div>
            ${linked}
            <div class="acp-s-23606cae">
                <div class="ae-field"><label class="ae-label">Spell</label>
                    <div class="ae-autocomplete-wrap">
                        <input class="ae-input" id="style-add-spell-input" placeholder="Search spell…" autocomplete="off">
                        <input type="hidden" id="style-add-spell-id">
                        <div class="ae-autocomplete" id="style-add-spell-ac"></div>
                    </div>
                </div>
                <div class="ae-field"><label class="ae-label">Class ID <span>(0=all)</span></label><input class="ae-input" id="style-add-class" type="number" value="0" min="0"></div>
                <button type="button" class="ae-btn" onclick="AE.style.addSpell(${r.StyleID})"><i class="fas fa-link"></i></button>
            </div>
        </div>`;
        AE._initAC('style-add-spell-input','style-add-spell-ac','style-add-spell-id');
    },
    async save(e,id){
        e.preventDefault();
        const fd=new FormData(e.target);fd.append('StyleID',id||0);fd.append('action','style_save');fd.append('csrf_token',AE_CSRF);
        const d=await(await fetch(AE_BASE,{method:'POST',body:fd}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));})).json();
        d.ok?(AEToast.show('Style saved'),this.reload()):AEToast.show(d.error||'Error','error');return false;
    },
    async addSpell(styleId){
        const sid=document.getElementById('style-add-spell-id')?.value;
        const cid=document.getElementById('style-add-class')?.value||0;
        if(!sid){AEToast.show('Select a spell first','error');return;}
        const d=await aePost('stylexspell_save',{StyleID:styleId,SpellID:sid,ClassID:cid});
        d.ok?(AEToast.show('Linked'),this.select(styleId)):AEToast.show(d.error||'Error','error');
    },
    async removeSpell(styleId,spellId,classId){
        const d=await aePost('stylexspell_delete',{StyleID:styleId,SpellID:spellId,ClassID:classId});
        d.ok?(AEToast.show('Unlinked'),this.select(styleId)):AEToast.show(d.error||'Error','error');
    },
    async delete(id){
        if(!confirm('Delete this style?'))return;
        const d=await aePost('style_delete',{StyleID:id});
        if(d.ok){AEToast.show('Deleted');document.getElementById('style-detail').innerHTML='<div class="ae-empty-state"><i class="fas fa-khanda"></i><span>Select a Style</span></div>';this.current=null;this.reload();}
        else AEToast.show(d.error||'Error','error');
    },
    openNew(){
        document.getElementById('style-detail').innerHTML=`
        <div class="ae-detail-head"><div class="ae-detail-title"><i class="fas fa-plus acp-s-ad1259d3"></i> New Style</div></div>
        <div class="ae-detail-body"><form onsubmit="return AE.style.save(event,0)">
            <div class="ae-form-grid">
                <div class="ae-field ae-form-full"><label class="ae-label">Name</label><input class="ae-input" name="Name" required></div>
                <div class="ae-field"><label class="ae-label">Spec Key</label><input class="ae-input" name="SpecKeyName"></div>
                <div class="ae-field"><label class="ae-label">Spec Level Req</label><input class="ae-input" name="SpecLevelRequirement" type="number" value="1"></div>
                <div class="ae-field"><label class="ae-label">Bonus To Damage</label><input class="ae-input" name="BonusToDamage" type="number" value="0"></div>
                <div class="ae-field"><label class="ae-label">Bonus To Defense</label><input class="ae-input" name="BonusToDefense" type="number" value="0"></div>
                <div class="ae-field"><label class="ae-label">Growth Rate</label><input class="ae-input" name="GrowthRate" type="number" step="0.01" value="1.0"></div>
            </div>
            <button type="submit" class="ae-btn"><i class="fas fa-save"></i> Create Style</button>
        </form></div>`;
    }
};

/* ── ABILITIES ───────────────────────────────────────────── */
AE.ability = {
    _loaded:false, offset:0, limit:10, total:0, current:null,
    async load(){this._loaded=true;await this.reload();let st;document.getElementById('ability-search')?.addEventListener('input',()=>{clearTimeout(st);st=setTimeout(()=>{this.offset=0;this.reload();},280);});},
    async reload(){
        const q=document.getElementById('ability-search')?.value||'';
        document.getElementById('ability-list').innerHTML=aeSkel();
        const data=await aeGet({action:'ability_list',q,limit:this.limit,offset:this.offset});
        this.total=data.total||0;this.renderList(data.rows||[]);
        const info=document.getElementById('ability-pagination-info');
        if(info)info.textContent=`${this.offset+1}–${Math.min(this.offset+this.limit,this.total)} / ${this.total}`;
        document.getElementById('ability-prev').disabled=this.offset===0;
        document.getElementById('ability-next').disabled=this.offset+this.limit>=this.total;
    },
    renderList(rows){
        const el=document.getElementById('ability-list');
        if(!rows.length){el.innerHTML='<div class="acp-s-694cc60d">No abilities found</div>';return;}
        el.innerHTML=rows.map(r=>`
            <div class="ae-list-item ${this.current?.KeyName===r.KeyName?'selected':''}" onclick="AE.ability.select('${r.KeyName.replace(/'/g,"\\'")}')">
                <div class="ae-list-item-name">${h(r.Name||r.KeyName)}</div>
                <div class="ae-list-item-meta"><span class="ae-tag">${h(r.KeyName)}</span>${r.Value?`<span>Val ${r.Value}</span>`:''}</div>
            </div>`).join('');
    },
    page(dir){this.offset=Math.max(0,this.offset+dir*this.limit);this.reload();},
    async select(key){
        const det=document.getElementById('ability-detail');det.innerHTML=aeSpinner();
        const data=await aeGet({action:'ability_get',key});
        if(!data.ok){det.innerHTML=`<div class="acp-s-6e7e97d7">${data.error}</div>`;return;}
        this.current=data.row;
        det.innerHTML=`
        <div class="ae-detail-head"><div class="acp-s-da5cd676"><div class="ae-detail-title">${h(data.row.Name||data.row.KeyName)}</div></div></div>
        <div class="ae-detail-body"><form onsubmit="return AE.ability.save(event,'${key.replace(/'/g,"\\'")}')">
            <div class="ae-form-grid">
                <div class="ae-field ae-form-full"><label class="ae-label">Key Name</label><input class="ae-input" name="KeyName" value="${h(data.row.KeyName||'')}" required></div>
                <div class="ae-field ae-form-full"><label class="ae-label">Name</label><input class="ae-input" name="Name" value="${h(data.row.Name||'')}"></div>
                <div class="ae-field"><label class="ae-label">Value</label><input class="ae-input" name="Value" type="number" value="${data.row.Value||0}"></div>
                <div class="ae-field"><label class="ae-label">Icon ID</label><input class="ae-input" name="IconID" type="number" value="${data.row.IconID||0}"></div>
                <div class="ae-field ae-form-full"><label class="ae-label">Description</label><textarea class="ae-textarea" name="Description">${h(data.row.Description||'')}</textarea></div>
            </div>
            <div class="acp-s-9c68691e">
                <button type="submit" class="ae-btn"><i class="fas fa-save"></i> Save</button>
                ${AE_CAN_DELETE?`<button type="button" class="ae-btn danger" onclick="AE.ability.delete('${key.replace(/'/g,"\\'")}')"><i class="fas fa-trash"></i> Delete</button>`:''}
            </div>
        </form></div>`;
    },
    async save(e,key){
        e.preventDefault();
        const fd=new FormData(e.target);fd.append('action','ability_save');fd.append('csrf_token',AE_CSRF);
        const d=await(await fetch(AE_BASE,{method:'POST',body:fd}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));})).json();
        d.ok?(AEToast.show('Saved'),this.reload()):AEToast.show(d.error||'Error','error');return false;
    },
    async delete(key){
        if(!confirm('Delete ability: '+key+'?'))return;
        const d=await aePost('ability_delete',{KeyName:key});
        d.ok?(AEToast.show('Deleted'),document.getElementById('ability-detail').innerHTML='<div class="ae-empty-state"><i class="fas fa-star"></i><span>Select an Ability</span></div>',this.current=null,this.reload()):AEToast.show(d.error||'Error','error');
    },
    openNew(){
        document.getElementById('ability-detail').innerHTML=`
        <div class="ae-detail-head"><div class="ae-detail-title"><i class="fas fa-plus acp-s-ad1259d3"></i> New Ability</div></div>
        <div class="ae-detail-body"><form onsubmit="return AE.ability.save(event,'')">
            <div class="ae-form-grid">
                <div class="ae-field ae-form-full"><label class="ae-label">Key Name</label><input class="ae-input" name="KeyName" required placeholder="ABILITY_SPRINT"></div>
                <div class="ae-field ae-form-full"><label class="ae-label">Name</label><input class="ae-input" name="Name" placeholder="Sprint"></div>
                <div class="ae-field"><label class="ae-label">Value</label><input class="ae-input" name="Value" type="number" value="0"></div>
                <div class="ae-field"><label class="ae-label">Icon ID</label><input class="ae-input" name="IconID" type="number" value="0"></div>
                <div class="ae-field ae-form-full"><label class="ae-label">Description</label><textarea class="ae-textarea" name="Description"></textarea></div>
            </div>
            <button type="submit" class="ae-btn"><i class="fas fa-save"></i> Create Ability</button>
        </form></div>`;
    }
};

/* ── NPC TEMPLATES ───────────────────────────────────────── */
AE.npc = {
    _loaded:false, offset:0, limit:10, total:0, current:null,
    async load(){this._loaded=true;await this.reload();let st;document.getElementById('npc-search')?.addEventListener('input',()=>{clearTimeout(st);st=setTimeout(()=>{this.offset=0;this.reload();},280);});},
    async reload(){
        const q=document.getElementById('npc-search')?.value||'';
        document.getElementById('npc-list').innerHTML=aeSkel();
        const data=await aeGet({action:'npc_list',q,limit:this.limit,offset:this.offset});
        this.total=data.total||0;this.renderList(data.rows||[]);
        const info=document.getElementById('npc-pagination-info');
        if(info)info.textContent=`${this.offset+1}–${Math.min(this.offset+this.limit,this.total)} / ${this.total}`;
        document.getElementById('npc-prev').disabled=this.offset===0;
        document.getElementById('npc-next').disabled=this.offset+this.limit>=this.total;
    },
    renderList(rows){
        const el=document.getElementById('npc-list');
        if(!rows.length){el.innerHTML='<div class="acp-s-694cc60d">No templates found</div>';return;}
        el.innerHTML=rows.map(r=>`
            <div class="ae-list-item ${this.current?.TemplateId===r.TemplateId?'selected':''}" onclick="AE.npc.select(${r.TemplateId})">
                <div class="ae-list-item-name">${h(r.Name||'—')}</div>
                <div class="ae-list-item-meta"><span class="ae-tag">Lv ${r.Level||'?'}</span>${r.GuildName?`<span>${h(r.GuildName)}</span>`:''}</div>
            </div>`).join('');
    },
    page(dir){this.offset=Math.max(0,this.offset+dir*this.limit);this.reload();},
    async select(id){
        document.querySelectorAll('#npc-list .ae-list-item').forEach(x=>x.classList.remove('selected'));
        const det=document.getElementById('npc-detail');det.innerHTML=aeSpinner();
        const data=await aeGet({action:'npc_get',id});
        if(!data.ok){det.innerHTML=`<div class="acp-s-6e7e97d7">${data.error}</div>`;return;}
        this.current=data.row;this.renderDetail(data.row);
    },
    renderDetail(r){
        const spells=(r.linked_spells||[]).length
            ?`<table class="ae-link-table"><thead><tr><th>ID</th><th>Name</th><th>Type</th><th>DMG</th><th></th></tr></thead><tbody>
            ${r.linked_spells.map(s=>`<tr>
                <td class="acp-s-ea320bd8">${h(s.Spell_ID||'')}</td>
                <td>${h(s.Name||'—')}</td>
                <td><span class="acp-s-db4d0709"></span>${h(s.Type||'—')}</td>
                <td>${s.Damage||'—'}</td>
                <td><button class="ae-btn danger acp-s-1eba760d" onclick="AE.npc.removeSpell(${r.TemplateId},'${s.Spell_ID}')">✕</button></td>
            </tr>`).join('')}</tbody></table>`
            :'<div class="acp-s-524043ea">No spells assigned.</div>';
        document.getElementById('npc-detail').innerHTML=`
        <div class="ae-detail-head">
            <div class="acp-s-da5cd676"><div class="ae-detail-title">${h(r.Name||'Unnamed NPC')}</div>
            <div class="ae-detail-subtitle">Level ${r.Level||'?'} · Model ${r.Model||'—'} · Aggro ${r.AggroLevel||0}</div></div>
            ${AE_CAN_DELETE?`<button class="ae-btn danger" onclick="AE.npc.delete(${r.TemplateId})"><i class="fas fa-trash"></i></button>`:''}
        </div>
        <div class="ae-detail-body">
            <form onsubmit="return AE.npc.save(event,${r.TemplateId})">
                <div class="ae-form-grid">
                    <div class="ae-field ae-form-full"><label class="ae-label">Template Name</label><input class="ae-input" name="Name" value="${h(r.Name||'')}" required></div>
                    <div class="ae-field"><label class="ae-label">Class Type <span>(Brain)</span></label><input class="ae-input" name="ClassType" value="${h(r.ClassType||'')}"></div>
                    <div class="ae-field"><label class="ae-label">Guild Name</label><input class="ae-input" name="GuildName" value="${h(r.GuildName||'')}"></div>
                    <div class="ae-field"><label class="ae-label">Model</label><input class="ae-input" name="Model" type="number" value="${r.Model||0}"></div>
                    <div class="ae-field"><label class="ae-label">Size</label><input class="ae-input" name="Size" type="number" value="${r.Size||50}"></div>
                    <div class="ae-field"><label class="ae-label">Level</label><input class="ae-input" name="Level" type="number" value="${r.Level||1}" min="1" max="100"></div>
                    <div class="ae-field"><label class="ae-label">Region</label><input class="ae-input" name="Region" type="number" value="${r.Region||0}"></div>
                    <div class="ae-field"><label class="ae-label">Faction</label><input class="ae-input" name="Faction" type="number" value="${r.Faction||0}"></div>
                    <div class="ae-field"><label class="ae-label">Aggro Level <span>(0–100)</span></label><input class="ae-input" name="AggroLevel" type="number" value="${r.AggroLevel||0}" min="0" max="100"></div>
                    <div class="ae-field"><label class="ae-label">Aggro Range</label><input class="ae-input" name="AggroRange" type="number" value="${r.AggroRange||0}"></div>
                    <div class="ae-field"><label class="ae-label">Respawn (ms)</label><input class="ae-input" name="RespawnInterval" type="number" value="${r.RespawnInterval||0}"></div>
                </div>
                <button type="submit" class="ae-btn"><i class="fas fa-save"></i> Save NPC Template</button>
            </form>
            <div class="ae-section-head acp-s-553a645f"><i class="fas fa-bolt"></i> Assigned Spells <span>${(r.linked_spells||[]).length} spells</span></div>
            ${spells}
            <div class="ae-section-head acp-s-002535c2"><i class="fas fa-plus-circle"></i> Assign Spell</div>
            <div class="acp-s-2d9f9a92">
                <div class="ae-field"><label class="ae-label">Spell</label>
                    <div class="ae-autocomplete-wrap">
                        <input class="ae-input" id="npc-add-spell-input" placeholder="Search spell…" autocomplete="off">
                        <input type="hidden" id="npc-add-spell-id">
                        <div class="ae-autocomplete" id="npc-add-spell-ac"></div>
                    </div>
                </div>
                <button type="button" class="ae-btn" onclick="AE.npc.addSpell(${r.TemplateId})"><i class="fas fa-plus"></i></button>
            </div>
            <div id="npc-spell-warning"></div>
        </div>`;
        AE._initAC('npc-add-spell-input','npc-add-spell-ac','npc-add-spell-id');
    },
    async save(e,tid){
        e.preventDefault();
        const fd=new FormData(e.target);fd.append('action','npc_save');fd.append('csrf_token',AE_CSRF);fd.append('TemplateId',tid||0);
        const d=await(await fetch(AE_BASE,{method:'POST',body:fd}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));})).json();
        d.ok?(AEToast.show('NPC saved'),this.reload()):AEToast.show(d.error||'Error','error');return false;
    },
    async addSpell(tid){
        const sid=document.getElementById('npc-add-spell-id')?.value;
        if(!sid){AEToast.show('Select a spell first','error');return;}
        const d=await aePost('npc_spell_add',{TemplateId:tid,SpellID:sid});
        const w=document.getElementById('npc-spell-warning');
        if(d.ok){AEToast.show('Spell assigned');if(d.warning&&w)w.innerHTML=`<div class="ae-warning"><i class="fas fa-exclamation-triangle"></i>${d.warning}</div>`;else if(w)w.innerHTML='';this.select(tid);}
        else AEToast.show(d.error||'Error','error');
    },
    async removeSpell(tid,sid){
        const d=await aePost('npc_spell_remove',{TemplateId:tid,SpellID:sid});
        d.ok?(AEToast.show('Removed'),this.select(tid)):AEToast.show(d.error||'Error','error');
    },
    async delete(tid){
        if(!confirm('Delete NPC Template #'+tid+'?'))return;
        const d=await aePost('npc_delete',{TemplateId:tid});
        d.ok?(AEToast.show('Deleted'),document.getElementById('npc-detail').innerHTML='<div class="ae-empty-state"><i class="fas fa-dragon"></i><span>Select an NPC Template</span></div>',this.current=null,this.reload()):AEToast.show(d.error||'Error','error');
    },
    openNew(){
        document.getElementById('npc-detail').innerHTML=`
        <div class="ae-detail-head"><div class="ae-detail-title"><i class="fas fa-plus acp-s-ad1259d3"></i> New NPC Template</div></div>
        <div class="ae-detail-body"><form onsubmit="return AE.npc.save(event,0)">
            <div class="ae-form-grid">
                <div class="ae-field ae-form-full"><label class="ae-label">Template Name <span>(unique)</span></label><input class="ae-input" name="Name" required placeholder="Guard_Albion_L50"></div>
                <div class="ae-field"><label class="ae-label">Class Type</label><input class="ae-input" name="ClassType" value="DOL.AI.Brain.StandardMobBrain"></div>
                <div class="ae-field"><label class="ae-label">Guild Name</label><input class="ae-input" name="GuildName"></div>
                <div class="ae-field"><label class="ae-label">Model</label><input class="ae-input" name="Model" type="number" value="1"></div>
                <div class="ae-field"><label class="ae-label">Size</label><input class="ae-input" name="Size" type="number" value="50"></div>
                <div class="ae-field"><label class="ae-label">Level</label><input class="ae-input" name="Level" type="number" value="50" min="1" max="100"></div>
                <div class="ae-field"><label class="ae-label">Realm</label><select class="ae-select-styled" name="Realm"><option value="0">None</option><option value="1">Albion</option><option value="2">Midgard</option><option value="3">Hibernia</option></select></div>
                <div class="ae-field"><label class="ae-label">Aggro Level</label><input class="ae-input" name="AggroLevel" type="number" value="0" min="0" max="100"></div>
                <div class="ae-field"><label class="ae-label">Aggro Range</label><input class="ae-input" name="AggroRange" type="number" value="500"></div>
            </div>
            <button type="submit" class="ae-btn"><i class="fas fa-save"></i> Create Template</button>
        </form></div>`;
    }
};

/* ── Autocomplete ────────────────────────────────────────── */
AE._initAC = function(inputId,acId,hiddenId) {
    const input=document.getElementById(inputId),ac=document.getElementById(acId),hid=document.getElementById(hiddenId);
    if(!input||!ac||!hid)return;
    let st;
    input.addEventListener('input',()=>{
        clearTimeout(st);hid.value='';
        const q=input.value.trim();
        if(q.length<2){ac.style.display='none';return;}
        st=setTimeout(async()=>{
            const data=await aeGet({action:'spell_search',q});
            const rows=data.rows||[];
            if(!rows.length){ac.style.display='none';return;}
            ac.innerHTML=rows.map(r=>`<div class="ae-autocomplete-item" data-id="${r.Spell_ID}" data-name="${h(r.Name||'')}">
                <span>${h(r.Name||'?')}</span>
                <span class="acp-s-677f261d">${h(r.Type||'')} · ${h(r.Spell_ID)}</span>
            </div>`).join('');
            ac.style.display='block';
            ac.querySelectorAll('.ae-autocomplete-item').forEach(item=>{
                item.addEventListener('click',()=>{input.value=item.dataset.name;hid.value=item.dataset.id;ac.style.display='none';});
            });
        },200);
    });
    document.addEventListener('click',e=>{if(!input.contains(e.target)&&!ac.contains(e.target))ac.style.display='none';});
};

(function(){
    const a='<?= $active_tab ?>';
    if(a==='spells')     AE.spell.load();
    if(a==='spelllines') AE.line.load();
    if(a==='styles')     AE.style.load();
    if(a==='abilities')  AE.ability.load();
    if(a==='npc')        AE.npc.load();
})();
</script>