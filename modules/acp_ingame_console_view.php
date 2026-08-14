<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_ACP')) exit;
if ($userPriv < 4) { echo '<div class="acp-empty">' . t('igc_access_denied', [], 'Access denied.') . '</div>'; return; }

// Dedicated translation context for the ingame console (in addition to
// 'core', which is already loaded globally in cms_language_parser.php).
cms_load_language_context('igc');

$igc_token = generateToken();
?>

<div class="igc-wrap">

    <!-- Header -->
    <div class="igc-page-header">
        <div class="igc-page-title">
            <i class="fas fa-terminal"></i>
            <span><?= t('igc_page_title', [], 'INGAME CONSOLE') ?></span>
        </div>
        <div class="igc-status-row">
            <div class="igc-server-indicator" id="igc-server-dot">
                <span class="igc-dot igc-dot--unknown"></span>
                <span class="igc-server-label" id="igc-server-label"><?= t('igc_checking', [], 'Checking...') ?></span>
            </div>
            <span class="igc-online-count"><span id="igc-player-count">—</span> <?= t('igc_online', [], 'online') ?></span>
            <button class="igc-refresh-btn" onclick="igcRefresh()" title="<?= t('igc_refresh', [], 'Refresh') ?>">
                <i class="fas fa-sync-alt"></i>
            </button>
        </div>
    </div>

    <!-- Online Players -->
    <div class="igc-window igc-window--players">
        <div class="igc-window-bar">
            <i class="fas fa-users"></i> <?= t('igc_online_players', [], 'ONLINE PLAYERS') ?>
            <span class="igc-window-hint"><?= t('igc_click_to_target', [], 'Click a player to target') ?></span>
        </div>
        <div class="igc-player-list" id="igc-player-list">
            <div class="igc-loading"><i class="fas fa-circle-notch fa-spin"></i></div>
        </div>
    </div>

    <!-- Action Panels -->
    <div class="igc-panels">

        <!-- Kick -->
        <div class="igc-panel">
            <div class="igc-panel-title"><i class="fas fa-door-open"></i> <?= t('igc_kick_title', [], 'Kick') ?></div>
            <div class="igc-panel-body">
                <div class="igc-field">
                    <label><?= t('igc_field_player', [], 'Player') ?></label>
                    <input type="text" id="igc-kick-name" class="igc-input" placeholder="<?= t('igc_placeholder_char_name', [], 'Character name') ?>">
                </div>
                <div class="igc-field">
                    <label><?= t('igc_field_reason', [], 'Reason') ?></label>
                    <input type="text" id="igc-kick-reason" class="igc-input" placeholder="<?= t('igc_kick_default_reason', [], 'Kicked by Admin') ?>">
                </div>
                <button class="igc-btn igc-btn--red" onclick="igcAction('kick', {name: igcV('igc-kick-name'), reason: igcV('igc-kick-reason') || '<?= t('igc_kick_default_reason', [], 'Kicked by Admin') ?>'})">
                    <i class="fas fa-sign-out-alt"></i> <?= t('igc_kick_title', [], 'Kick') ?>
                </button>
            </div>
        </div>

        <!-- PrivLevel -->
        <div class="igc-panel">
            <div class="igc-panel-title"><i class="fas fa-shield-alt"></i> <?= t('igc_privlevel_title', [], 'PrivLevel') ?></div>
            <div class="igc-panel-body">
                <div class="igc-field">
                    <label><?= t('igc_field_player', [], 'Player') ?></label>
                    <input type="text" id="igc-priv-name" class="igc-input" placeholder="<?= t('igc_placeholder_char_name', [], 'Character name') ?>">
                </div>
                <div class="igc-field">
                    <label><?= t('igc_field_level', [], 'Level') ?></label>
                    <select id="igc-priv-level" class="igc-input">
                        <option value="0">0 — <?= t('igc_priv_player', [], 'Player') ?></option>
                        <option value="1">1 — <?= t('igc_priv_trial_gm', [], 'Trial GM') ?></option>
                        <option value="2">2 — <?= t('igc_priv_gm', [], 'GM') ?></option>
                        <option value="3">3 — <?= t('igc_priv_admin', [], 'Admin') ?></option>
                    </select>
                </div>
                <button class="igc-btn igc-btn--gold" onclick="igcAction('privlevel', {name: igcV('igc-priv-name'), level: parseInt(igcV('igc-priv-level'))})">
                    <i class="fas fa-check"></i> <?= t('igc_btn_set', [], 'Set') ?>
                </button>
            </div>
        </div>

        <!-- Teleport -->
        <div class="igc-panel">
            <div class="igc-panel-title"><i class="fas fa-map-pin"></i> <?= t('igc_teleport_title', [], 'Teleport') ?></div>
            <div class="igc-panel-body">
                <div class="igc-field">
                    <label><?= t('igc_field_player', [], 'Player') ?></label>
                    <input type="text" id="igc-tp-name" class="igc-input" placeholder="<?= t('igc_placeholder_char_name', [], 'Character name') ?>">
                </div>
                <div class="igc-field">
                    <label><?= t('igc_field_zone', [], 'Zone (or leave blank for coords)') ?></label>
                    <input type="text" id="igc-tp-zone" class="igc-input" placeholder="<?= t('igc_placeholder_zone', [], 'e.g. camelot_city') ?>">
                </div>
                <div class="igc-field igc-field--row">
                    <input type="number" id="igc-tp-x" class="igc-input" placeholder="X">
                    <input type="number" id="igc-tp-y" class="igc-input" placeholder="Y">
                    <input type="number" id="igc-tp-region" class="igc-input" placeholder="<?= t('igc_field_region', [], 'Region') ?>">
                </div>
                <button class="igc-btn igc-btn--gold" onclick="igcAction('teleport', {name: igcV('igc-tp-name'), zone: igcV('igc-tp-zone'), x: parseInt(igcV('igc-tp-x')||0), y: parseInt(igcV('igc-tp-y')||0), region: parseInt(igcV('igc-tp-region')||0)})">
                    <i class="fas fa-location-arrow"></i> <?= t('igc_teleport_title', [], 'Teleport') ?>
                </button>
            </div>
        </div>

        <!-- Give Item -->
        <div class="igc-panel">
            <div class="igc-panel-title"><i class="fas fa-gift"></i> <?= t('igc_giveitem_title', [], 'Give Item') ?></div>
            <div class="igc-panel-body">
                <div class="igc-field">
                    <label><?= t('igc_field_player', [], 'Player') ?></label>
                    <input type="text" id="igc-item-name" class="igc-input" placeholder="<?= t('igc_placeholder_char_name', [], 'Character name') ?>">
                </div>
                <div class="igc-field igc-field--autocomplete">
                    <label><?= t('igc_field_item_id', [], 'Item ID (template ID)') ?></label>
                    <input type="text" id="igc-item-id" class="igc-input" placeholder="<?= t('igc_placeholder_item_search', [], 'Type to search...') ?>" autocomplete="off"
                           oninput="igcItemSearch(this.value)">
                    <div class="igc-autocomplete-dropdown" id="igc-item-dropdown"></div>
                </div>
                <div class="igc-field">
                    <label><?= t('igc_field_count', [], 'Count') ?></label>
                    <input type="number" id="igc-item-count" class="igc-input" value="1" min="1" max="100">
                </div>
                <button class="igc-btn igc-btn--gold" onclick="igcAction('giveitem', {name: igcV('igc-item-name'), item_id: igcV('igc-item-id'), count: parseInt(igcV('igc-item-count')||1)})">
                    <i class="fas fa-hand-holding"></i> <?= t('igc_btn_give', [], 'Give') ?>
                </button>
            </div>
        </div>

        <!-- Set Stats -->
        <div class="igc-panel">
            <div class="igc-panel-title"><i class="fas fa-heartbeat"></i> <?= t('igc_setstats_title', [], 'Set Stats') ?></div>
            <div class="igc-panel-body">
                <div class="igc-field">
                    <label><?= t('igc_field_player', [], 'Player') ?></label>
                    <input type="text" id="igc-stat-name" class="igc-input" placeholder="<?= t('igc_placeholder_char_name', [], 'Character name') ?>">
                </div>
                <div class="igc-field igc-field--row">
                    <select id="igc-stat-type" class="igc-input">
                        <option value="hp"><?= t('igc_stat_hp', [], 'HP') ?></option>
                        <option value="mana"><?= t('igc_stat_mana', [], 'Mana') ?></option>
                        <option value="endurance"><?= t('igc_stat_endurance', [], 'Endurance') ?></option>
                        <option value="level"><?= t('igc_stat_level', [], 'Level') ?></option>
                        <option value="xp"><?= t('igc_stat_xp', [], 'XP') ?></option>
                        <option value="gold"><?= t('igc_stat_gold', [], 'Gold') ?></option>
                    </select>
                    <input type="number" id="igc-stat-value" class="igc-input" placeholder="<?= t('igc_field_value', [], 'Value') ?>">
                </div>
                <button class="igc-btn igc-btn--gold" onclick="igcAction('setstats', {name: igcV('igc-stat-name'), stat: igcV('igc-stat-type'), value: parseInt(igcV('igc-stat-value')||0)})">
                    <i class="fas fa-check"></i> <?= t('igc_btn_set', [], 'Set') ?>
                </button>
            </div>
        </div>

        <!-- Heal / Revive -->
        <div class="igc-panel">
            <div class="igc-panel-title"><i class="fas fa-first-aid"></i> <?= t('igc_healrevive_title', [], 'Heal / Revive') ?></div>
            <div class="igc-panel-body">
                <div class="igc-field">
                    <label><?= t('igc_field_player', [], 'Player') ?></label>
                    <input type="text" id="igc-heal-name" class="igc-input" placeholder="<?= t('igc_placeholder_char_name', [], 'Character name') ?>">
                </div>
                <div class="igc-btn-row">
                    <button class="igc-btn igc-btn--blue" onclick="igcAction('heal', {name: igcV('igc-heal-name')})">
                        <i class="fas fa-heart"></i> <?= t('igc_btn_heal', [], 'Heal') ?>
                    </button>
                    <button class="igc-btn igc-btn--gold" onclick="igcAction('revive', {name: igcV('igc-heal-name')})">
                        <i class="fas fa-redo-alt"></i> <?= t('igc_btn_revive', [], 'Revive') ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Freeze -->
        <div class="igc-panel">
            <div class="igc-panel-title"><i class="fas fa-snowflake"></i> <?= t('igc_freeze_title', [], 'Freeze') ?></div>
            <div class="igc-panel-body">
                <div class="igc-field">
                    <label><?= t('igc_field_player', [], 'Player') ?></label>
                    <input type="text" id="igc-freeze-name" class="igc-input" placeholder="<?= t('igc_placeholder_char_name', [], 'Character name') ?>">
                </div>
                <div class="igc-btn-row">
                    <button class="igc-btn igc-btn--blue" onclick="igcAction('freeze', {name: igcV('igc-freeze-name'), on: true})">
                        <i class="fas fa-lock"></i> <?= t('igc_btn_freeze', [], 'Freeze') ?>
                    </button>
                    <button class="igc-btn igc-btn--ghost" onclick="igcAction('freeze', {name: igcV('igc-freeze-name'), on: false})">
                        <i class="fas fa-lock-open"></i> <?= t('igc_btn_unfreeze', [], 'Unfreeze') ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Mute -->
        <div class="igc-panel">
            <div class="igc-panel-title"><i class="fas fa-comment-slash"></i> <?= t('igc_mute_title', [], 'Mute') ?></div>
            <div class="igc-panel-body">
                <div class="igc-field">
                    <label><?= t('igc_field_player', [], 'Player') ?></label>
                    <input type="text" id="igc-mute-name" class="igc-input" placeholder="<?= t('igc_placeholder_char_name', [], 'Character name') ?>">
                </div>
                <div class="igc-btn-row">
                    <button class="igc-btn igc-btn--red" onclick="igcAction('mute', {name: igcV('igc-mute-name'), on: true})">
                        <i class="fas fa-microphone-slash"></i> <?= t('igc_btn_mute', [], 'Mute') ?>
                    </button>
                    <button class="igc-btn igc-btn--ghost" onclick="igcAction('mute', {name: igcV('igc-mute-name'), on: false})">
                        <i class="fas fa-microphone"></i> <?= t('igc_btn_unmute', [], 'Unmute') ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Broadcast -->
        <div class="igc-panel igc-panel--wide">
            <div class="igc-panel-title"><i class="fas fa-bullhorn"></i> <?= t('igc_broadcast_title', [], 'Server Broadcast') ?></div>
            <div class="igc-panel-body igc-panel-body--row">
                <input type="text" id="igc-broadcast-msg" class="igc-input igc-input--grow"
                       placeholder="<?= t('igc_placeholder_broadcast', [], 'Message to all online players...') ?>"
                       onkeydown="if(event.key==='Enter') igcAction('broadcast', {message: igcV('igc-broadcast-msg')})">
                <button class="igc-btn igc-btn--gold" onclick="igcAction('broadcast', {message: igcV('igc-broadcast-msg')})">
                    <i class="fas fa-paper-plane"></i> <?= t('igc_btn_send', [], 'Send') ?>
                </button>
            </div>
        </div>

        <?php if ($userPriv >= 5): ?>
        <!-- Raw Command (SuperAdmin only) -->
        <div class="igc-panel igc-panel--wide igc-panel--danger">
            <div class="igc-panel-title"><i class="fas fa-skull"></i> <?= t('igc_raw_title', [], 'Raw Game Server Command') ?> <span class="igc-superadmin-badge"><?= t('igc_superadmin_badge', [], 'SUPERADMIN') ?></span></div>
            <div class="igc-panel-body igc-panel-body--row">
                <input type="text" id="igc-raw-executor" class="igc-input acp-s-74286440"
                       placeholder="<?= t('igc_placeholder_executor', [], 'Executor (optional)') ?>">
                <input type="text" id="igc-raw-cmd" class="igc-input igc-input--grow igc-input--mono"
                       placeholder="<?= t('igc_placeholder_raw_cmd', [], '/command args...') ?>"
                       onkeydown="if(event.key==='Enter') igcRawSend()">
                <button class="igc-btn igc-btn--red" onclick="igcRawSend()">
                    <i class="fas fa-terminal"></i> <?= t('igc_btn_execute', [], 'Execute') ?>
                </button>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /igc-panels -->

    <!-- Console Output -->
    <div class="igc-window igc-window--console">
        <div class="igc-window-bar">
            <i class="fas fa-terminal"></i> <?= t('igc_console_output', [], 'CONSOLE OUTPUT') ?>
            <button class="igc-clear-btn" onclick="igcClearLog()"><i class="fas fa-trash"></i> <?= t('igc_btn_clear', [], 'Clear') ?></button>
        </div>
        <div class="igc-console-log" id="igc-console-log">
            <div class="igc-console-line igc-console-line--sys">— <?= t('igc_console_ready', [], 'Console ready. Actions will be logged here.') ?> —</div>
        </div>
    </div>

</div><!-- /igc-wrap -->

<script>
const IGC_TOKEN = '<?= $igc_token ?>';
const IGC_URL   = 'acp.php?s=ingame_console&ajax=1';

// Translated strings needed at runtime in JS (console log, dynamic status
// displays). PHP t() can't be called directly from JS, so they're exposed
// once here as an object.
const IGC_I18N = {
    logCleared:      '<?= addslashes(t('igc_log_cleared', [], 'Log cleared.')) ?>',
    targetSet:       '<?= addslashes(t('igc_target_set', [], 'Target set:')) ?>',
    success:         '<?= addslashes(t('igc_success', [], 'Success')) ?>',
    error:           '<?= addslashes(t('igc_error', [], 'Error')) ?>',
    requestFailed:   '<?= addslashes(t('igc_request_failed', [], 'Request failed:')) ?>',
    serverOnline:    '<?= addslashes(t('igc_server_online', [], 'Server Online')) ?>',
    serverOffline:   '<?= addslashes(t('igc_server_offline', [], 'Server Offline')) ?>',
    noPlayersOnline: '<?= addslashes(t('igc_no_players_online', [], 'No players online')) ?>',
    serviceUnreach:  '<?= addslashes(t('igc_service_unreachable', [], 'Service unreachable')) ?>',
    statusCheckFail: '<?= addslashes(t('igc_status_check_failed', [], 'Status check failed:')) ?>',
    noItemsFound:    '<?= addslashes(t('igc_no_items_found', [], 'No items found')) ?>',
    gm:              '<?= addslashes(t('igc_gm_label', [], 'GM')) ?>',
    player:          '<?= addslashes(t('igc_player_label', [], 'Player')) ?>',
    zone:            '<?= addslashes(t('igc_zone_label', [], 'Zone')) ?>'
};

function igcV(id) { return document.getElementById(id)?.value || ''; }

function igcLog(msg, type = 'ok') {
    const log  = document.getElementById('igc-console-log');
    const line = document.createElement('div');
    const ts   = new Date().toLocaleTimeString(undefined, {hour:'2-digit', minute:'2-digit', second:'2-digit'});
    line.className = 'igc-console-line igc-console-line--' + type;
    line.textContent = '[' + ts + '] ' + msg;
    log.appendChild(line);
    log.scrollTop = log.scrollHeight;
}

function igcClearLog() {
    document.getElementById('igc-console-log').innerHTML =
        '<div class="igc-console-line igc-console-line--sys">— ' + IGC_I18N.logCleared + ' —</div>';
}

function igcSetTarget(name) {
    // Fill name into all player fields
    ['igc-kick-name','igc-priv-name','igc-tp-name','igc-item-name','igc-stat-name','igc-heal-name','igc-freeze-name','igc-mute-name'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = name;
    });
    igcLog(IGC_I18N.targetSet + ' ' + name, 'sys');
}

async function igcAction(action, payload = {}) {
    const fd = new FormData();
    fd.append('igc_action',  action);
    fd.append('csrf_token',  IGC_TOKEN);
    Object.entries(payload).forEach(([k,v]) => fd.append(k, v));

    igcLog('► ' + action.toUpperCase() + ': ' + JSON.stringify(payload), 'cmd');

    try {
        const r    = await fetch(IGC_URL, { method: 'POST', body: fd }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
        const data = await r.json();
        if (data.ok) {
            igcLog('✓ ' + (data.result || IGC_I18N.success), 'ok');
        } else {
            igcLog('✗ ' + (data.error || IGC_I18N.error), 'err');
        }
    } catch(e) {
        igcLog('✗ ' + IGC_I18N.requestFailed + ' ' + e, 'err');
    }
}

function igcRawSend() {
    const cmd = igcV('igc-raw-cmd');
    if (!cmd.trim()) return;
    igcAction('raw', { command: cmd, executor: igcV('igc-raw-executor') });
    document.getElementById('igc-raw-cmd').value = '';
}

// ── Item Autocomplete ───────────────────────────────────────────
let igcItemSearchTimer = null;
function igcItemSearch(q) {
    clearTimeout(igcItemSearchTimer);
    const dropdown = document.getElementById('igc-item-dropdown');

    if (!q || q.trim().length < 2) {
        dropdown.innerHTML = '';
        dropdown.style.display = 'none';
        return;
    }

    igcItemSearchTimer = setTimeout(async () => {
        const fd = new FormData();
        fd.append('igc_action', 'item_search');
        fd.append('csrf_token', IGC_TOKEN);
        fd.append('q', q);

        try {
            const r    = await fetch(IGC_URL, { method: 'POST', body: fd }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
            const data = await r.json();
            const items = data.items || [];

            if (!items.length) {
                dropdown.innerHTML = '<div class="igc-autocomplete-empty">' + IGC_I18N.noItemsFound + '</div>';
                dropdown.style.display = 'block';
                return;
            }

            dropdown.innerHTML = items.map(it => `
                <div class="igc-autocomplete-row" onclick="igcItemPick('${it.item_id}')">
                    <span class="igc-autocomplete-name">${it.name}</span>
                    <span class="igc-autocomplete-meta">Lv${it.level} · ${it.item_id}</span>
                </div>
            `).join('');
            dropdown.style.display = 'block';
        } catch(e) {
            dropdown.innerHTML = '';
            dropdown.style.display = 'none';
        }
    }, 250);
}

function igcItemPick(itemId) {
    document.getElementById('igc-item-id').value = itemId;
    const dropdown = document.getElementById('igc-item-dropdown');
    dropdown.innerHTML = '';
    dropdown.style.display = 'none';
}

document.addEventListener('click', (e) => {
    const field = document.querySelector('.igc-field--autocomplete');
    if (field && !field.contains(e.target)) {
        const dropdown = document.getElementById('igc-item-dropdown');
        if (dropdown) dropdown.style.display = 'none';
    }
});

async function igcRefresh() {
    document.getElementById('igc-player-list').innerHTML =
        '<div class="igc-loading"><i class="fas fa-circle-notch fa-spin"></i></div>';

    const fd = new FormData();
    fd.append('igc_action', 'status');
    fd.append('csrf_token', IGC_TOKEN);

    try {
        const r    = await fetch(IGC_URL, { method: 'POST', body: fd }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
        const data = await r.json();

        // Server indicator
        const dot   = document.querySelector('.igc-dot');
        const label = document.getElementById('igc-server-label');
        if (data.server_online === true) {
            dot.className   = 'igc-dot igc-dot--on';
            label.textContent = IGC_I18N.serverOnline;
        } else {
            dot.className   = 'igc-dot igc-dot--off';
            label.textContent = IGC_I18N.serverOffline;
        }

        const list = document.getElementById('igc-player-list');
        if (data.ok !== true) {
            document.getElementById('igc-player-count').textContent = '0';
            const errorBox = document.createElement('div');
            errorBox.className = 'igc-empty-players igc-empty-players--err';
            errorBox.textContent = data.error || IGC_I18N.statusCheckFail;
            list.replaceChildren(errorBox);
            igcLog('✗ ' + (data.error || IGC_I18N.statusCheckFail), 'err');
            return;
        }

        const players = data.players || [];
        document.getElementById('igc-player-count').textContent = players.length;

        if (!players.length) {
            list.innerHTML = '<div class="igc-empty-players">' + IGC_I18N.noPlayersOnline + '</div>';
            return;
        }

        list.innerHTML = players.map(p => `
            <div class="igc-player-row" onclick="igcSetTarget('${p.Name}')">
                <span class="igc-player-name">${p.Name}</span>
                <span class="igc-player-meta">Lv${p.Level} · ${p.Class}</span>
                <span class="igc-player-region">${IGC_I18N.zone} ${p.Region}</span>
                <span class="igc-player-priv ${p.PrivLevel > 0 ? 'igc-player-priv--gm' : ''}">
                    ${p.PrivLevel > 0 ? IGC_I18N.gm : IGC_I18N.player}
                </span>
            </div>
        `).join('');
    } catch(e) {
        document.getElementById('igc-player-list').innerHTML =
            '<div class="igc-empty-players igc-empty-players--err">' + IGC_I18N.serviceUnreach + '</div>';
        igcLog('✗ ' + IGC_I18N.statusCheckFail + ' ' + e, 'err');
    }
}

// Auto-refresh every 30s
igcRefresh();
setInterval(igcRefresh, 30000);
</script>
