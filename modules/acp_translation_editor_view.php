<?php if (!defined('IN_CMS')) exit; ?>

<div class="tle-container">
    <div class="tle-header">
        <div class="tle-header-title">
            <i class="fas fa-language"></i>
            <h2><?= t('lang_editor.title', [], 'Edit Language Variables') ?></h2>
        </div>

        <div class="tle-controls">
            <select id="tle-lang-select" onchange="tle_load(1)" class="tle-select">
                <option value="__all__"><?= t('lang_editor.filter.all_languages', [], '— All Languages —') ?></option>
                <?php foreach($available_languages as $l): ?>
                    <option value="<?= h($l) ?>"><?= strtoupper(h($l)) ?></option>
                <?php endforeach; ?>
            </select>

            <select id="tle-context-select" onchange="tle_load(1)" class="tle-select">
                <option value=""><?= t('lang_editor.filter.all_contexts', [], 'All Contexts') ?></option>
            </select>

            <div class="tle-search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="tle-search-input" placeholder="<?= t('lang_editor.search.placeholder', [], 'Search key, value, context…') ?>" onkeyup="tle_debounce()">
            </div>

            <button class="tle-btn-add" onclick="tle_modal_open()">
                <i class="fas fa-plus"></i> <?= t('lang_editor.action.new_variable', [], 'New variable') ?>
            </button>
        </div>
    </div>

    <div id="tle-ajax-target"></div>
    <div id="tle-pagination" class="tle-pagination"></div>
</div>


<!-- Modal: New variable -->
<div class="tle-backdrop" id="tle-modal-backdrop" onclick="tle_backdrop_close(event)">
    <div class="tle-modal">
        <div class="tle-modal-header">
            <h3><i class="fas fa-plus-circle"></i> <?= t('lang_editor.modal.add.title', [], 'Add new variable') ?></h3>
            <button class="tle-modal-close" onclick="tle_modal_close()" title="<?= t('lang_editor.action.close', [], 'Close') ?>">✕</button>
        </div>

        <div class="tle-field" id="field-lang">
            <label><?= t('lang_editor.label.language', [], 'Language') ?></label>
            <div style="display:flex;gap:8px;align-items:center;">
                <select id="m-lang" style="flex:1;">
                    <?php foreach($available_languages as $l): ?>
                        <option value="<?= h($l) ?>"><?= strtoupper(h($l)) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="tle-btn-also" onclick="tle_also_open()" title="<?= t('lang_editor.tooltip.also_for', [], 'Also create for another language') ?>">
                    <i class="fas fa-copy"></i> <?= t('lang_editor.action.also_for', [], 'Also for…') ?>
                </button>
            </div>
        </div>

        <div class="tle-field" id="field-key">
            <label><?= t('lang_editor.label.key', [], 'Key') ?> <span class="tle-required">*</span></label>
            <input type="text" id="m-key"
                   placeholder="<?= t('lang_editor.placeholder.key', [], 'e.g. nav.home or btn.save_changes') ?>"
                   oninput="tle_clear_err('field-key')">
            <div class="tle-field-error"><?= t('lang_editor.error.key_required', [], 'Key is required.') ?></div>
        </div>

        <div class="tle-field" id="field-context">
            <label><?= t('lang_editor.label.context', [], 'Context') ?></label>
            <input type="text" id="m-context"
                   placeholder="<?= t('lang_editor.placeholder.context', [], 'e.g. core, nav, buttons') ?>"
                   value="core">
        </div>

        <div class="tle-field" id="field-value">
            <label><?= t('lang_editor.label.value', [], 'Value') ?> <span class="tle-required">*</span></label>
            <textarea id="m-value"
                      placeholder="<?= t('lang_editor.placeholder.value', [], 'Translation text…') ?>"
                      oninput="tle_clear_err('field-value')"></textarea>
            <div class="tle-field-error"><?= t('lang_editor.error.value_required', [], 'Value is required.') ?></div>
        </div>

        <div class="tle-modal-actions">
            <button class="tle-btn-secondary" onclick="tle_modal_close()"><?= t('lang_editor.action.cancel', [], 'Cancel') ?></button>
            <button class="tle-btn-primary" onclick="tle_create()">
                <i class="fas fa-save"></i> <?= t('lang_editor.action.save_variable', [], 'Save variable') ?>
            </button>
        </div>
    </div>
</div>


<!-- Modal: Also create for another language -->
<div class="tle-backdrop" id="tle-also-backdrop" onclick="tle_also_backdrop_close(event)">
    <div class="tle-modal" style="max-width:400px;">
        <div class="tle-modal-header">
            <h3><i class="fas fa-copy"></i> <?= t('lang_editor.modal.also.title', [], 'Also create for…') ?></h3>
            <button class="tle-modal-close" onclick="tle_also_close()" title="<?= t('lang_editor.action.close', [], 'Close') ?>">✕</button>
        </div>

        <p style="font-size:0.8em;color:#666;margin:0 0 16px;">
            <?= t('lang_editor.modal.also.description', [], 'This will create the same key/context with the value below for the selected language.') ?>
        </p>

        <div class="tle-field">
            <label><?= t('lang_editor.label.target_language', [], 'Target Language') ?></label>
            <select id="also-lang">
                <?php foreach($available_languages as $l): ?>
                    <option value="<?= h($l) ?>"><?= strtoupper(h($l)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="tle-field" id="also-field-value">
            <label><?= t('lang_editor.label.value_for_language', [], 'Value for this language') ?> <span class="tle-required">*</span></label>
            <textarea id="also-value" placeholder="<?= t('lang_editor.placeholder.value', [], 'Translation text…') ?>"></textarea>
            <div class="tle-field-error"><?= t('lang_editor.error.value_required', [], 'Value is required.') ?></div>
        </div>

        <div class="tle-modal-actions">
            <button class="tle-btn-secondary" onclick="tle_also_close()"><?= t('lang_editor.action.cancel', [], 'Cancel') ?></button>
            <button class="tle-btn-primary" onclick="tle_also_save()">
                <i class="fas fa-save"></i> <?= t('lang_editor.action.save', [], 'Save') ?>
            </button>
        </div>
    </div>
</div>



<script>
let tle_timer;
let tle_raw_data    = [];
let tle_all_langs   = <?= json_encode($available_languages) ?>;
const tle_items_per_page = 12;
const tle_csrf = "<?= generateToken() ?>";

// Remembers the last successfully saved "also for" language across popup opens.
let tle_also_last_lang = null;

// ── Search debounce ────────────────────────────────────────
function tle_debounce() {
    clearTimeout(tle_timer);
    tle_timer = setTimeout(() => tle_load(1), 300);
}

// ── Load translations via AJAX ─────────────────────────────
function tle_load(page = 1) {
    const lang    = document.getElementById('tle-lang-select').value;
    const search  = document.getElementById('tle-search-input').value;
    const context = document.getElementById('tle-context-select').value;
    const target  = document.getElementById('tle-ajax-target');

    target.innerHTML = '<div class="tle-loading">Fetching strings…</div>';

    if (lang === '__all__') {
        Promise.all(tle_all_langs.map(l => tle_fetch_lang(l, search)))
            .then(results => {
                tle_raw_data = results;
                tle_render_all(results, context);
            });
        return;
    }

    const fd = new FormData();
    fd.append('ajax_action', 'load_translations');
    fd.append('lang', lang);
    fd.append('search', search);
    fd.append('csrf_token', tle_csrf);

    fetch('acp.php?s=translation_editor&ajax=1', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) return;
            tle_raw_data = d.data;
            tle_populate_context_filter(d.data);
            const filtered = context ? d.data.filter(i => i.var_context === context) : d.data;
            tle_render(page, filtered);
        }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

function tle_fetch_lang(lang, search) {
    const fd = new FormData();
    fd.append('ajax_action', 'load_translations');
    fd.append('lang', lang);
    fd.append('search', search);
    fd.append('csrf_token', tle_csrf);
    return fetch('acp.php?s=translation_editor&ajax=1', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => ({ lang, data: d.ok ? d.data : [] })).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

// ── Populate context dropdown ──────────────────────────────
function tle_populate_context_filter(data) {
    const sel = document.getElementById('tle-context-select');
    const current = sel.value;
    const contexts = [...new Set(data.map(i => i.var_context))].sort();
    sel.innerHTML = '<option value="">All Contexts</option>';
    contexts.forEach(c => {
        const o = document.createElement('option');
        o.value = c; o.textContent = c;
        if (c === current) o.selected = true;
        sel.appendChild(o);
    });
}

// ── Render single-language paginated grid ──────────────────
function tle_render(page, data) {
    const target      = document.getElementById('tle-ajax-target');
    const paginTarget = document.getElementById('tle-pagination');

    if (!data || data.length === 0) {
        target.innerHTML = '<div class="tle-no-results">No translation keys found.</div>';
        paginTarget.innerHTML = '';
        return;
    }

    const totalPages     = Math.ceil(data.length / tle_items_per_page);
    const start          = (page - 1) * tle_items_per_page;
    const paginatedItems = data.slice(start, start + tle_items_per_page);

    let html = '<div class="tle-grid">';
    paginatedItems.forEach(item => { html += tle_card_html(item); });
    html += '</div>';
    target.innerHTML = html;
    tle_render_pagination(page, totalPages, data);
}

// ── Render all-languages grouped view ─────────────────────
function tle_render_all(results, contextFilter) {
    const target = document.getElementById('tle-ajax-target');
    document.getElementById('tle-pagination').innerHTML = '';

    const allKeys = new Set();
    results.forEach(r => r.data.forEach(i => allKeys.add(i.var_key)));

    if (allKeys.size === 0) {
        target.innerHTML = '<div class="tle-no-results">No translation keys found.</div>';
        return;
    }

    const keyMap = {};
    results.forEach(r => {
        r.data.forEach(item => {
            if (!keyMap[item.var_key]) keyMap[item.var_key] = { context: item.var_context, langs: {} };
            keyMap[item.var_key].langs[r.lang] = item;
        });
    });

    const byContext = {};
    Object.entries(keyMap).forEach(([key, meta]) => {
        if (contextFilter && meta.context !== contextFilter) return;
        if (!byContext[meta.context]) byContext[meta.context] = [];
        byContext[meta.context].push({ key, ...meta });
    });

    let html = '';
    Object.keys(byContext).sort().forEach(ctx => {
        html += `<div class="tle-lang-group">
            <div class="tle-lang-group-head"><i class="fas fa-tag acp-s-129713b1"></i>${ctx}</div>
            <div class="tle-grid">`;
        byContext[ctx].forEach(entry => {
            tle_all_langs.forEach(lang => {
                const item = entry.langs[lang];
                if (item) {
                    html += tle_card_html(item, lang);
                } else {
                    html += `<div class="tle-card acp-s-4dc0994d">
                        <div class="tle-card-meta">
                            <span class="tle-badge">${entry.context}</span>
                            <span class="tle-key-name">${entry.key}</span>
                            <span class="acp-s-6ab760c9">MISSING ${lang.toUpperCase()}</span>
                        </div>
                        <div class="acp-s-6bfa9d45">
                            Not yet translated.
                            <button onclick="tle_quick_create('${lang}','${entry.key}','${entry.context}')"
                                    class="acp-s-382bbab6">
                                + Add
                            </button>
                        </div>
                    </div>`;
                }
            });
        });
        html += '</div></div>';
    });

    target.innerHTML = html || '<div class="tle-no-results">No results.</div>';
}

// ── Card HTML builder ──────────────────────────────────────
function tle_card_html(item, langBadge) {
    const langTag = langBadge
        ? `<span class="tle-badge acp-s-00a0560b">${langBadge.toUpperCase()}</span>`
        : '';
    return `
        <div class="tle-card" id="card-${item.id}" data-orig-key="${tle_esc(item.var_key)}" data-orig-ctx="${tle_esc(item.var_context)}" data-orig-val="${tle_esc(item.var_value)}">
            <div class="tle-card-meta">
                ${langTag}
                <span class="tle-badge">${item.var_context}</span>
                <span class="tle-key-name">${item.var_key}</span>
                <button class="tle-card-delete-btn" onclick="tle_delete(${item.id})" title="Delete">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </div>
            <div class="acp-s-d4316970">
                <div class="tle-card-field-label">Key</div>
                <input class="tle-card-inline-input" data-field="key" value="${tle_esc(item.var_key)}"
                       oninput="tle_mark_dirty(${item.id})">
            </div>
            <div class="acp-s-9e3c4ccb">
                <div class="tle-card-field-label">Context</div>
                <input class="tle-card-inline-input" data-field="context" value="${tle_esc(item.var_context)}"
                       oninput="tle_mark_dirty(${item.id})">
            </div>
            <div class="acp-s-9e3c4ccb">
                <div class="tle-card-field-label">Value</div>
                <div class="tle-textarea-container">
                    <textarea oninput="tle_mark_dirty(${item.id})">${item.var_value}</textarea>
                    <div class="tle-status-icon"><i class="fas fa-check-circle"></i></div>
                </div>
            </div>
            <div class="tle-card-save-row">
                <button class="tle-card-discard-btn" onclick="tle_discard(${item.id})">Discard</button>
                <button class="tle-card-save-btn" onclick="tle_save_full(${item.id})">
                    <i class="fas fa-save"></i> Save
                </button>
            </div>
        </div>`;
}

function tle_esc(str) {
    return String(str ?? '').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}

// ── Dirty tracking ─────────────────────────────────────────
function tle_mark_dirty(id) {
    document.getElementById('card-' + id)?.classList.add('is-dirty');
}

function tle_discard(id) {
    const card = document.getElementById('card-' + id);
    if (!card) return;
    card.querySelector('[data-field="key"]').value     = card.dataset.origKey;
    card.querySelector('[data-field="context"]').value = card.dataset.origCtx;
    card.querySelector('textarea').value               = card.dataset.origVal;
    card.classList.remove('is-dirty');
}

// ── Save full card ─────────────────────────────────────────
function tle_save_full(id) {
    const card    = document.getElementById('card-' + id);
    if (!card) return;
    const key     = card.querySelector('[data-field="key"]').value.trim();
    const context = card.querySelector('[data-field="context"]').value.trim();
    const value   = card.querySelector('textarea').value;

    const fd = new FormData();
    fd.append('ajax_action', 'save_translation_full');
    fd.append('id',          id);
    fd.append('var_key',     key);
    fd.append('var_context', context);
    fd.append('value',       value);
    fd.append('csrf_token',  tle_csrf);

    card.classList.add('tle-state-saving');

    fetch('acp.php?s=translation_editor&ajax=1', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            card.classList.remove('tle-state-saving');
            if (d.ok) {
                card.classList.remove('is-dirty');
                card.classList.add('tle-state-success');
                card.dataset.origKey = key;
                card.dataset.origCtx = context;
                card.dataset.origVal = value;
                setTimeout(() => card.classList.remove('tle-state-success'), 2000);
            }
        }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

// ── Delete ─────────────────────────────────────────────────
function tle_delete(id) {
    if (!confirm('Delete this translation key? This cannot be undone.')) return;

    const fd = new FormData();
    fd.append('ajax_action', 'delete_translation');
    fd.append('id',          id);
    fd.append('csrf_token',  tle_csrf);

    fetch('acp.php?s=translation_editor&ajax=1', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (d.ok) document.getElementById('card-' + id)?.remove(); }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

// ── Quick-create a missing entry ───────────────────────────
function tle_quick_create(lang, key, context) {
    document.getElementById('m-lang').value    = lang;
    document.getElementById('m-key').value     = key;
    document.getElementById('m-context').value = context;
    document.getElementById('m-value').value   = '';
    document.getElementById('tle-modal-backdrop').classList.add('show');
    document.getElementById('m-value').focus();
}

// ── Pagination ─────────────────────────────────────────────
function tle_render_pagination(current, total, data) {
    const target = document.getElementById('tle-pagination');
    if (total <= 1) { target.innerHTML = ''; return; }

    const range = 1;
    let btns = [];
    btns.push(tle_create_btn(1, current === 1, data));
    if (current > range + 2) btns.push('<span class="tle-pg-dots">…</span>');
    for (let i = Math.max(2, current - range); i <= Math.min(total - 1, current + range); i++)
        btns.push(tle_create_btn(i, i === current, data));
    if (current < total - range - 1) btns.push('<span class="tle-pg-dots">…</span>');
    if (total > 1) btns.push(tle_create_btn(total, current === total, data));
    target.innerHTML = btns.join('');
}

function tle_create_btn(num, isActive, data) {
    return `<button onclick="tle_render(${num}, tle_get_filtered())" class="tle-pg-btn ${isActive ? 'active' : ''}">${num}</button>`;
}

function tle_get_filtered() {
    const context = document.getElementById('tle-context-select').value;
    return context ? tle_raw_data.filter(i => i.var_context === context) : tle_raw_data;
}

// ── Modal: New variable ────────────────────────────────────
function tle_modal_open() {
    const currentLang = document.getElementById('tle-lang-select').value;
    if (currentLang !== '__all__') document.getElementById('m-lang').value = currentLang;
    document.getElementById('tle-modal-backdrop').classList.add('show');
    document.getElementById('m-key').focus();
}

function tle_modal_close() {
    document.getElementById('tle-modal-backdrop').classList.remove('show');
}

function tle_backdrop_close(e) {
    if (e.target.id === 'tle-modal-backdrop') tle_modal_close();
}

function tle_clear_err(fieldId) {
    document.getElementById(fieldId).classList.remove('has-error');
}

// ── Helper: set <select> by value, case-insensitive ───────
function tle_select_set(selectEl, value) {
    const lc = (value ?? '').toLowerCase();
    for (let i = 0; i < selectEl.options.length; i++) {
        if (selectEl.options[i].value.toLowerCase() === lc) {
            selectEl.selectedIndex = i;
            return true;
        }
    }
    return false; // not found
}

// ── Modal: "Also for…" ─────────────────────────────────────
function tle_also_open() {
    const mainLang = document.getElementById('m-lang').value.toLowerCase();
    const alsoSel  = document.getElementById('also-lang');

    // Prefer last successfully saved language, if it differs from the main one.
    // Fall back to the first available language that differs from main.
    let target = null;
    if (tle_also_last_lang && tle_also_last_lang.toLowerCase() !== mainLang) {
        target = tle_also_last_lang;
    } else {
        target = tle_all_langs.find(l => l.toLowerCase() !== mainLang) ?? tle_all_langs[0];
    }

    // Use index-based selection to avoid silent value/case mismatches
    if (!tle_select_set(alsoSel, target)) {
        alsoSel.selectedIndex = 0; // safe fallback
    }

    document.getElementById('also-value').value = '';
    document.getElementById('tle-also-backdrop').classList.add('show');
    document.getElementById('also-value').focus();
}

function tle_also_close() {
    document.getElementById('tle-also-backdrop').classList.remove('show');
}

function tle_also_backdrop_close(e) {
    if (e.target.id === 'tle-also-backdrop') tle_also_close();
}

function tle_also_save() {
    const key     = document.getElementById('m-key').value.trim();
    const context = document.getElementById('m-context').value.trim() || 'core';
    const lang    = document.getElementById('also-lang').value;
    const value   = document.getElementById('also-value').value.trim();

    if (!key) {
        alert('Please fill in the key in the main form first.');
        tle_also_close();
        return;
    }
    if (!value) {
        document.getElementById('also-field-value').classList.add('has-error');
        return;
    }

    const fd = new FormData();
    fd.append('ajax_action', 'create_translation');
    fd.append('csrf_token',  tle_csrf);
    fd.append('lang',        lang);
    fd.append('var_key',     key);
    fd.append('var_value',   value);
    fd.append('var_context', context);

    fetch('acp.php?s=translation_editor&ajax=1', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                tle_also_last_lang = lang; // remember for next open
                tle_also_close();
            } else {
                document.getElementById('also-field-value').classList.add('has-error');
                document.getElementById('also-field-value').querySelector('.tle-field-error').textContent =
                    d.error || 'Error saving.';
            }
        }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

// ── Modal: Create main variable ────────────────────────────
function tle_create() {
    const key   = document.getElementById('m-key').value.trim();
    const value = document.getElementById('m-value').value.trim();
    let valid = true;

    if (!key)   { document.getElementById('field-key').classList.add('has-error');   valid = false; }
    if (!value) { document.getElementById('field-value').classList.add('has-error'); valid = false; }
    if (!valid) return;

    const lang    = document.getElementById('m-lang').value;
    const context = document.getElementById('m-context').value.trim() || 'core';

    const fd = new FormData();
    fd.append('ajax_action', 'create_translation');
    fd.append('csrf_token',  tle_csrf);
    fd.append('lang',        lang);
    fd.append('var_key',     key);
    fd.append('var_value',   value);
    fd.append('var_context', context);

    fetch('acp.php?s=translation_editor&ajax=1', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) {
                const fk = document.getElementById('field-key');
                fk.classList.add('has-error');
                fk.querySelector('.tle-field-error').textContent =
                    d.error || 'Key already exists or a database error occurred.';
                return;
            }
            document.getElementById('m-key').value     = '';
            document.getElementById('m-value').value   = '';
            document.getElementById('m-context').value = 'core';
            tle_modal_close();
            document.getElementById('tle-lang-select').value = lang;
            tle_load(1);
        }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

// ── Keyboard shortcuts ─────────────────────────────────────
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        tle_modal_close();
        tle_also_close();
    }
});

// Initial load
tle_load(1);
</script>

<?php require_once __DIR__ . '/acp_all_views_ai_extensions.php'; ?>