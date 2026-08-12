<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_ACP')) exit;
if ($userPriv < 4) { echo '<div class="acp-empty">' . t('general_access_denied', [], 'Access denied') . '</div>'; return; }
if (isset($_GET['ajax'])) return;

$csrf = generateToken();

$themes = $db->query("SELECT DISTINCT theme_slug FROM aldhran_styles ORDER BY theme_slug")->fetchAll(PDO::FETCH_COLUMN);
$activeTheme = preg_replace('/[^a-z0-9_-]/', '', $_GET['theme'] ?? ($GLOBALS['cms_settings']['active_theme'] ?? 'default'));

if (!in_array($activeTheme, $themes) && !empty($themes)) {
    $activeTheme = $themes[0];
}

$stmt    = $db->prepare("SELECT module_key FROM aldhran_styles WHERE module_key NOT LIKE 'acp\\_%' AND theme_slug = ? ORDER BY module_key");
$stmt->execute([$activeTheme]);
$modules = $stmt->fetchAll(PDO::FETCH_COLUMN);
$default = $modules[0] ?? '';

$ai_active = isset($botSettings) && $botSettings->isActive() && $botSettings->hasAiConfigured();
?>

<div class="te-page-header">
    <span class="te-page-title"><i class="fas fa-paint-brush"></i> <?= t('te_title', [], 'Theme Editor') ?></span>
    <div class="te-page-actions">
        <div class="te-theme-selector">
            <i class="fas fa-swatchbook"></i>
            <select id="te-theme-switch" class="te-theme-select" onchange="window.location.href='acp.php?s=theme_editor&theme='+this.value">
                <?php foreach($themes as $t): ?>
                    <option value="<?= h($t) ?>" <?= $t === $activeTheme ? 'selected' : '' ?>><?= h($t) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($activeTheme !== 'default'): ?>
            <button class="te-theme-delete-btn" onclick="te_delete_theme()" title="<?= t('te_btn_delete_theme', [], 'Delete this theme') ?>"><i class="fas fa-trash"></i></button>
            <?php endif; ?>
        </div>

        <button class="te-hdr-btn" onclick="openCloneModal()"><i class="fas fa-copy"></i> <?= t('te_btn_clone', [], 'New Theme / Clone') ?></button>
        <button class="te-hdr-btn" onclick="openUploadModal()"><i class="fas fa-upload"></i> <?= t('te_btn_upload', [], 'Upload') ?></button>
        <button class="te-hdr-btn" onclick="te_export_theme()"><i class="fas fa-download"></i> <?= t('te_btn_export', [], 'Export') ?></button>
        <button class="te-hdr-btn" id="te-preview-toggle"><i class="fas fa-eye"></i> <?= t('te_btn_preview', [], 'Live Preview') ?></button>
        <button class="te-hdr-btn" id="te-vars-toggle"><i class="fas fa-palette"></i> <?= t('te_btn_palette', [], 'Palette') ?></button>
        <button class="te-hdr-btn" id="te-new-module-btn"><i class="fas fa-plus"></i> <?= t('te_btn_new_mod', [], 'Module') ?></button>
    </div>
</div>

<div class="te-tabs">
    <button class="te-maintab active" data-tab="editor" onclick="teSwitchTab('editor')"><i class="fas fa-code"></i> <?= t('te_tab_editor', [], 'Editor') ?></button>
    <button class="te-maintab" data-tab="styleguide" onclick="teSwitchTab('styleguide')"><i class="fas fa-swatchbook"></i> <?= t('te_tab_styleguide', [], 'Style Guide') ?></button>
    <button class="te-maintab" data-tab="history" onclick="teSwitchTab('history')"><i class="fas fa-history"></i> <?= t('te_tab_history', [], 'History') ?></button>
</div>

<div class="te-tabpane active" id="te-pane-editor">
<div class="te-layout">

    <div class="te-sidebar">
        <div class="te-sidebar-head"><?= t('te_modules', [], 'Modules') ?></div>
        <div class="te-module-list" id="te-module-list">
            <?php foreach ($modules as $m): ?>
            <div class="te-module <?= $m === $default ? 'active' : '' ?>"
                 data-mod="<?= h($m) ?>" onclick="te_load('<?= h($m) ?>')">
                <?= h($m) ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($ai_active): ?>
        <div class="te-ai-panel">
            <div class="te-ai-title"><i class="fas fa-robot"></i> <?= t('te_ai_assistant', [], 'AI Assistant') ?></div>
            <input type="text" class="te-ai-input" id="te-ai-request"
                   placeholder="<?= t('te_ai_placeholder', [], 'e.g. More vibrant gold…') ?>">
            <div class="te-ai-btn-row">
                <button class="te-ai-btn" id="te-ai-suggest-btn" onclick="te_ai_suggest()">
                    <i class="fas fa-magic"></i> <?= t('te_ai_suggest', [], 'Suggest') ?>
                </button>
                <button class="te-ai-btn" id="te-ai-explain-btn" onclick="te_ai_explain()">
                    <i class="fas fa-question-circle"></i> <?= t('te_ai_explain', [], 'Explain') ?>
                </button>
            </div>
            <div id="te-ai-result" class="te-ai-result"></div>
            <button id="te-ai-apply-btn" class="te-ai-apply" onclick="te_ai_apply()">
                <i class="fas fa-check"></i> <?= t('te_ai_append', [], 'Append to CSS') ?>
            </button>
        </div>
        <?php endif; ?>
    </div>

    <div class="te-editor">
        <div class="te-toolbar">
            <span class="te-module-name" id="te-current-label">module: <?= h($default) ?></span>
            <span class="te-inherit-badge acp-s-cb458930" id="te-inherit-badge"></span>
            <button class="te-save-btn" onclick="te_save()"><i class="fas fa-save"></i> <?= t('te_save', [], 'Save') ?></button>
            <button class="te-tool-btn" onclick="te_reload()" title="<?= t('te_discard_reload', [], 'Discard & reload') ?>"><i class="fas fa-undo"></i></button>
            <button class="te-tool-btn" onclick="te_copy()" title="<?= t('te_copy_css', [], 'Copy CSS') ?>"><i class="fas fa-copy"></i></button>
            <span id="te-save-msg" class="te-save-msg"></span>
        </div>
        <div class="te-editor-body" id="te-editor-body">
            <textarea id="te-css" class="te-css-area" spellcheck="false"></textarea>
            <iframe id="te-preview-frame" class="te-preview-frame acp-s-cb458930"></iframe>
        </div>
        <div class="te-statusbar" id="te-status"></div>
    </div>

    <div class="te-vars-panel" id="te-vars-panel">
        <div class="te-vars-head">
            <i class="fas fa-palette"></i> <?= t('te_palette_vars', [], 'Palette & Variables') ?>
        </div>

        <div class="te-palette-section">
            <span class="te-section-lbl" id="te-palette-source"><?= t('te_theme_colors', [], 'Theme Colors') ?></span>
            <div class="te-swatch-grid" id="te-swatch-container"></div>
        </div>

        <div class="te-contrast-section">
            <span class="te-section-lbl"><?= t('te_contrast_checker', [], 'Contrast Checker') ?></span>
            <div class="te-contrast-row">
                <select id="te-contrast-fg" class="te-contrast-select"></select>
                <i class="fas fa-arrow-right acp-s-3cff1d83"></i>
                <select id="te-contrast-bg" class="te-contrast-select"></select>
                <button class="te-contrast-btn" onclick="te_check_contrast()"><i class="fas fa-check-double"></i></button>
            </div>
            <div id="te-contrast-result" class="te-contrast-result"></div>
        </div>

        <div class="te-vars-scroll" id="te-vars-groups"></div>
    </div>

</div>
</div>

<div class="te-tabpane" id="te-pane-styleguide">
    <div class="te-sg-toolbar">
        <span><?= t('te_sg_desc', [], 'Renders sample UI components using the saved CSS for this theme (including inherited parent themes).') ?></span>
        <button class="te-tool-btn" onclick="te_load_styleguide()" title="<?= t('te_sg_refresh', [], 'Refresh') ?>"><i class="fas fa-sync-alt"></i></button>
    </div>
    <iframe id="te-sg-frame" class="te-sg-frame"></iframe>
</div>

<div class="te-tabpane" id="te-pane-history">
    <div class="te-history-toolbar">
        <span><?= t('te_history_module', [], 'Module') ?>:</span>
        <select id="te-history-module-select"></select>
    </div>
    <div class="te-history-list" id="te-history-list"></div>
    <div class="te-history-diff acp-s-cb458930" id="te-history-diff">
        <div class="te-diff-head">
            <span><?= t('te_diff_title', [], 'Comparing selected version to current') ?></span>
            <button class="te-modal-btn te-modal-btn--gold" id="te-history-rollback-btn"><i class="fas fa-undo"></i> <?= t('te_rollback', [], 'Rollback to this version') ?></button>
        </div>
        <div class="te-diff-body" id="te-diff-body"></div>
    </div>
</div>

<input type="color" id="te-color-picker" class="acp-s-cb458930">

<div class="te-modal-overlay" id="te-new-modal">
    <div class="te-modal">
        <div class="te-modal-title"><i class="fas fa-plus"></i> <?= t('te_new_module', [], 'New CSS Module') ?></div>
        <input type="text" class="te-modal-input" id="te-new-name"
               placeholder="module_name" maxlength="64">
        <div class="te-modal-actions">
            <button class="te-modal-btn te-modal-btn--sec" onclick="document.getElementById('te-new-modal').classList.remove('open')"><?= t('general_cancel', [], 'Cancel') ?></button>
            <button class="te-modal-btn te-modal-btn--gold" id="te-new-confirm">
                <i class="fas fa-plus"></i> <?= t('general_create', [], 'Create') ?>
            </button>
        </div>
    </div>
</div>

<div class="te-modal-overlay" id="te-clone-modal">
    <div class="te-modal">
        <div class="te-modal-title"><i class="fas fa-copy"></i> <?= t('te_clone_theme', [], 'Create New Theme') ?></div>
        <div class="te-modal-input-group">
            <span class="te-modal-label"><?= t('te_new_theme_mode', [], 'Mode') ?></span>
            <div class="te-mode-row">
                <label class="te-mode-opt"><input type="radio" name="te-clone-mode" value="duplicate" checked> <?= t('te_mode_duplicate', [], 'Duplicate (independent copy)') ?></label>
                <label class="te-mode-opt"><input type="radio" name="te-clone-mode" value="inherit"> <?= t('te_mode_inherit', [], 'Inherit (child theme, overrides only)') ?></label>
            </div>
        </div>
        <div class="te-modal-input-group">
            <span class="te-modal-label"><?= t('te_base_theme', [], 'Base / Parent Theme') ?></span>
            <select id="te-clone-base" class="te-modal-select">
                <?php foreach($themes as $t): ?><option value="<?= h($t) ?>"><?= h($t) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="te-modal-input-group">
            <span class="te-modal-label"><?= t('te_new_theme_slug', [], 'New Theme Name (Slug)') ?></span>
            <input type="text" class="te-modal-input" id="te-clone-name" placeholder="e.g. custom_nexus_theme" maxlength="50">
        </div>
        <div class="te-modal-actions">
            <button class="te-modal-btn te-modal-btn--sec" onclick="closeCloneModal()"><?= t('general_cancel', [], 'Cancel') ?></button>
            <button class="te-modal-btn te-modal-btn--gold" onclick="te_clone_theme()"><i class="fas fa-copy"></i> <?= t('te_btn_clone', [], 'Create Theme') ?></button>
        </div>
    </div>
</div>

<div class="te-modal-overlay" id="te-upload-modal">
    <div class="te-modal">
        <div class="te-modal-title"><i class="fas fa-upload"></i> <?= t('te_upload_sql', [], 'Upload Theme (SQL)') ?></div>
        <div class="te-modal-input-group">
            <span class="te-modal-label"><?= t('te_select_sql', [], 'Select SQL File') ?></span>
            <input type="file" id="te-upload-file" accept=".sql" class="te-modal-input acp-s-416c260c">
        </div>
        <div class="te-modal-actions">
            <button class="te-modal-btn te-modal-btn--sec" onclick="closeUploadModal()"><?= t('general_cancel', [], 'Cancel') ?></button>
            <button class="te-modal-btn te-modal-btn--gold" onclick="te_upload_theme()"><i class="fas fa-upload"></i> <?= t('te_btn_upload', [], 'Upload') ?></button>
        </div>
    </div>
</div>

<script>
const TE_AJAX      = 'acp.php?s=theme_editor&ajax=1';
const TE_CSRF      = '<?= $csrf ?>';
const TE_THEME     = '<?= h($activeTheme) ?>';
let te_current     = '<?= h($default) ?>';
let te_last_ai_css = '';
let te_var_source  = te_current;
let te_all_vars    = [];
let te_preview_mode = false;
let te_history_data  = [];

function cssColorToHex(color) {
    const ctx = document.createElement('canvas').getContext('2d');
    ctx.fillStyle = color;
    return ctx.fillStyle.startsWith('#') ? ctx.fillStyle : '#ffffff';
}

document.getElementById('te-css').addEventListener('keydown', function(e) {
    if (e.key === 'Tab') {
        e.preventDefault();
        const start = this.selectionStart;
        const end = this.selectionEnd;
        this.value = this.value.substring(0, start) + "    " + this.value.substring(end);
        this.selectionStart = this.selectionEnd = start + 4;
    }
});

let te_preview_debounce = null;
document.getElementById('te-css').addEventListener('input', function() {
    if (!te_preview_mode) return;
    clearTimeout(te_preview_debounce);
    te_preview_debounce = setTimeout(te_render_preview, 400);
});

function teSwitchTab(tab) {
    document.querySelectorAll('.te-maintab').forEach(el => el.classList.toggle('active', el.dataset.tab === tab));
    document.querySelectorAll('.te-tabpane').forEach(el => el.classList.remove('active'));
    document.getElementById('te-pane-' + tab).classList.add('active');
    if (tab === 'styleguide') te_load_styleguide();
    if (tab === 'history') te_init_history_tab();
}

function te_load(mod) {
    te_current = mod;
    document.querySelectorAll('.te-module').forEach(el => el.classList.toggle('active', el.dataset.mod === mod));
    document.getElementById('te-current-label').textContent = 'module: ' + mod;
    te_status('');
    te_ai_reset();
    const fd = new FormData();
    fd.append('ajax_action', 'load_module');
    fd.append('module', mod);
    fd.append('theme', TE_THEME);
    fd.append('csrf_token', TE_CSRF);
    fetch(TE_AJAX, { method:'POST', body:fd })
        .then(r=>r.json())
        .then(d => {
            document.getElementById('te-css').value = d.ok ? d.css : '';
            te_status(d.ok ? (d.css.length + ' chars') : ('Error: ' + d.error));
            const badge = document.getElementById('te-inherit-badge');
            if (d.ok && d.inherited) {
                badge.style.display = 'inline-flex';
                badge.innerHTML = '<i class="fas fa-link"></i> <?= t('te_inherited_from', [], 'inherited from') ?> ' + d.inherited_from;
            } else {
                badge.style.display = 'none';
            }
            if (document.getElementById('te-vars-panel').classList.contains('open')) te_load_vars();
            if (te_preview_mode) te_render_preview();
        })
        .catch(e => te_status('Load failed: ' + e));
}

function te_braces_balanced(css) {
    let depth = 0;
    for (const ch of css) {
        if (ch === '{') depth++;
        else if (ch === '}') depth--;
        if (depth < 0) return false;
    }
    return depth === 0;
}

function te_save() {
    const css = document.getElementById('te-css').value;
    const msg = document.getElementById('te-save-msg');
    if (!te_braces_balanced(css)) {
        if (!confirm('<?= t('te_confirm_unbalanced', [], 'Braces look unbalanced - save anyway?') ?>')) return;
    }
    msg.textContent = '<?= t('general_saving', [], 'Saving...') ?>'; msg.className = 'te-save-msg';
    const fd = new FormData();
    fd.append('ajax_action', 'save_module');
    fd.append('module', te_current);
    fd.append('css', css);
    fd.append('theme', TE_THEME);
    fd.append('csrf_token', TE_CSRF);
    fetch(TE_AJAX, { method:'POST', body:fd })
        .then(r=>r.json())
        .then(d => {
            msg.textContent = d.ok ? '✓ <?= t('general_saved', [], 'Saved') ?>' : '✗ ' + d.error;
            msg.className = 'te-save-msg ' + (d.ok ? 'ok' : 'err');
            setTimeout(() => { msg.textContent = ''; msg.className = 'te-save-msg'; }, 3000);
            if (d.ok) {
                te_status(css.length + ' chars');
                document.getElementById('te-inherit-badge').style.display = 'none';
                if (document.getElementById('te-vars-panel').classList.contains('open')) te_load_vars();
            }
        }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

function te_reload() { if (confirm('<?= t('te_confirm_discard', [], 'Discard changes and reload?') ?>')) te_load(te_current); }

function te_copy() {
    navigator.clipboard?.writeText(document.getElementById('te-css').value)
        .then(() => {
            const m = document.getElementById('te-save-msg');
            m.textContent = '✓ Copied'; m.className = 'te-save-msg ok';
            setTimeout(() => { m.textContent = ''; m.className = 'te-save-msg'; }, 2000);
        });
}

function te_status(text) { document.getElementById('te-status').textContent = text; }

document.getElementById('te-vars-toggle').addEventListener('click', () => {
    document.getElementById('te-vars-panel').classList.toggle('open');
    if (document.getElementById('te-vars-panel').classList.contains('open')) te_load_vars();
});

document.getElementById('te-preview-toggle').addEventListener('click', () => {
    te_preview_mode = !te_preview_mode;
    document.getElementById('te-preview-toggle').classList.toggle('active', te_preview_mode);
    document.getElementById('te-css').style.display = te_preview_mode ? 'none' : 'block';
    document.getElementById('te-preview-frame').style.display = te_preview_mode ? 'block' : 'none';
    if (te_preview_mode) te_render_preview();
});

const TE_PREVIEW_HTML = `
<div class="p-wrap">
  <button class="p-btn p-btn-gold">Gold Button</button>
  <button class="p-btn p-btn-danger">Danger Button</button>
  <div class="p-card">
    <div class="p-card-head">Card Title</div>
    <div class="p-card-body">Some card body text to preview readability and contrast.</div>
  </div>
  <span class="p-badge p-badge-ok">Active</span>
  <span class="p-badge p-badge-warn">Pending</span>
  <input class="p-input" placeholder="Input field" type="text">
  <a class="p-link" href="#">A sample link</a>
</div>`;

function te_render_preview() {
    const frame = document.getElementById('te-preview-frame');
    const css = document.getElementById('te-css').value;
    const doc = `<!DOCTYPE html><html><head>
</head><body>${TE_PREVIEW_HTML}</body></html>`;
    frame.srcdoc = doc;
}

function te_load_styleguide() {
    const frame = document.getElementById('te-sg-frame');
    frame.srcdoc = '<html><body class="acp-s-c20c974c">Loading…</body></html>';
    const fd = new FormData();
    fd.append('ajax_action', 'get_style_guide_css');
    fd.append('theme', TE_THEME);
    fd.append('csrf_token', TE_CSRF);
    fetch(TE_AJAX, { method:'POST', body:fd }).then(r=>r.json()).then(d => {
        if (!d.ok) { frame.srcdoc = '<body class="acp-s-94294764">Error: ' + d.error + '</body>'; return; }
        const doc = `<!DOCTYPE html><html><head>
</head>
        <body class="acp-body acp-s-0f8ab67f">
        <h2>Buttons</h2>
        <button class="acp-btn acp-btn-gold">Gold Action</button>
        <button class="acp-btn acp-btn-red">Danger Action</button>
        <h2>Card</h2>
        <div class="acp-card acp-s-f758c5d0">
            <div class="acp-card-header"><i class="fas fa-star"></i> Sample Card</div>
            <div class="acp-card-body"><p>Sample body text inside a card component.</p></div>
        </div>
        <h2>Status pills</h2>
        <span class="acp-status-pill">Online</span>
        <span class="pill-green">Success</span>
        <span class="pill-red">Error</span>
        <h2>Forum thread row</h2>
        <div class="vb-row"><div class="vb-title-text">Sample Thread Title</div></div>
        </body></html>`;
        frame.srcdoc = doc;
    }).catch(e => { frame.srcdoc = '<body class="acp-s-94294764">Failed: ' + e + '</body>'; });
}

function te_categorize(name, value) {
    const isColor = /^(#|rgb|rgba|hsl|hsla)/i.test(value.trim());
    if (isColor) return 'colors';
    if (/font|letter-spacing|weight/i.test(name)) return 'typography';
    if (/nav-h|gap|padding|margin|spacing|radius/i.test(name) || /^-?\d+(\.\d+)?(px|em|rem|vh|vw)$/.test(value.trim())) return 'spacing';
    return 'other';
}

function te_load_vars() {
    const fd = new FormData();
    fd.append('ajax_action', 'list_variables');
    fd.append('module', te_current);
    fd.append('theme', TE_THEME);
    fd.append('csrf_token', TE_CSRF);
    fetch(TE_AJAX, { method:'POST', body:fd }).then(r=>r.json()).then(d => {
        if (!d.ok || !d.variables.length) {
            document.getElementById('te-swatch-container').innerHTML = '<div class="te-vars-empty"><?= t('te_no_colors', [], 'No colors found.') ?></div>';
            document.getElementById('te-vars-groups').innerHTML = '<div class="te-vars-empty"><?= t('te_no_vars', [], 'No CSS variables found.') ?></div>';
            te_all_vars = [];
            te_fill_contrast_selects();
            return;
        }

        te_var_source = d.source_module || te_current;
        te_all_vars = d.variables;
        document.getElementById('te-palette-source').textContent = `Theme Colors (${te_var_source})`;

        let colorsHtml = '';
        d.variables.forEach(v => {
            const isColor = /^(#|rgb|rgba|hsl|hsla)/i.test(v.value.trim());
            if (isColor) colorsHtml += `<div class="te-swatch acp-s-a6bcd740" title="${v.name}: ${v.value}" onclick="te_pick_color('${v.name}', '${v.value}')"></div>`;
        });
        document.getElementById('te-swatch-container').innerHTML = colorsHtml || '<div class="te-vars-empty"><?= t('te_no_static_colors', [], 'No static colors found.') ?></div>';

        const groups = { colors: [], typography: [], spacing: [], other: [] };
        d.variables.forEach(v => groups[te_categorize(v.name, v.value)].push(v));

        const labels = {
            colors: '<?= t('te_group_colors', [], 'Colors') ?>',
            typography: '<?= t('te_group_typography', [], 'Typography') ?>',
            spacing: '<?= t('te_group_spacing', [], 'Spacing') ?>',
            other: '<?= t('te_group_other', [], 'Other') ?>',
        };

        let html = '';
        for (const key of ['colors', 'typography', 'spacing', 'other']) {
            if (!groups[key].length) continue;
            html += `<div class="te-var-group"><div class="te-var-group-head" onclick="this.parentElement.classList.toggle('collapsed')">
                <i class="fas fa-chevron-down"></i> ${labels[key]} <span class="te-var-group-count">${groups[key].length}</span></div>
                <div class="te-var-group-body">`;
            groups[key].forEach(v => {
                const isColor = /^(#|rgb|rgba|hsl|hsla)/i.test(v.value.trim());
                const swatch = isColor ? `<span class="te-var-swatch acp-s-a6bcd740"></span>` : '';
                html += `<div class="te-var-item">
                    <div class="te-var-name" title="<?= t('te_copy_var', [], 'Click to copy var()') ?>" onclick="te_copy_var('${v.name}')">${v.name}</div>
                    <div class="te-var-val" onclick="te_jump_to_var('${v.name}')" title="<?= t('te_jump_to_var', [], 'Click to locate in editor') ?>">${swatch}${v.value}</div>
                </div>`;
            });
            html += `</div></div>`;
        }
        document.getElementById('te-vars-groups').innerHTML = html;
        te_fill_contrast_selects();
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

function te_fill_contrast_selects() {
    const fg = document.getElementById('te-contrast-fg');
    const bg = document.getElementById('te-contrast-bg');
    const colorVars = te_all_vars.filter(v => /^(#|rgb|rgba|hsl|hsla)/i.test(v.value.trim()));
    const opts = colorVars.map(v => `<option value="${v.value}">${v.name}</option>`).join('');
    fg.innerHTML = opts; bg.innerHTML = opts;
    document.getElementById('te-contrast-result').innerHTML = '';
}

function te_check_contrast() {
    const fg = document.getElementById('te-contrast-fg').value;
    const bg = document.getElementById('te-contrast-bg').value;
    const out = document.getElementById('te-contrast-result');
    if (!fg || !bg) return;
    out.textContent = '…';
    const fd = new FormData();
    fd.append('ajax_action', 'check_contrast');
    fd.append('fg', fg); fd.append('bg', bg);
    fd.append('theme', TE_THEME); fd.append('csrf_token', TE_CSRF);
    fetch(TE_AJAX, { method:'POST', body:fd }).then(r=>r.json()).then(d => {
        if (!d.ok) { out.textContent = 'Error'; return; }
        const cls = d.aa_normal ? 'ok' : (d.aa_large ? 'warn' : 'err');
        out.innerHTML = `<span class="te-contrast-ratio ${cls}">${d.ratio}:1</span> ` +
            (d.aaa_normal ? 'AAA' : d.aa_normal ? 'AA' : d.aa_large ? 'AA Large only' : '<?= t('te_contrast_fail', [], 'Fails WCAG AA') ?>');
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

function te_copy_var(name) {
    const text = `var(${name})`;
    navigator.clipboard?.writeText(text).then(() => {
        const m = document.getElementById('te-save-msg');
        m.textContent = '✓ Copied ' + text; m.className = 'te-save-msg ok';
        setTimeout(() => { m.textContent = ''; m.className = 'te-save-msg'; }, 2000);
    });
}

function te_pick_color(varName, currentVal) {
    const picker = document.getElementById('te-color-picker');
    picker.value = cssColorToHex(currentVal);

    picker.oninput = function() {
        if (te_var_source === te_current) {
            const textarea = document.getElementById('te-css');
            const regex = new RegExp(varName.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&') + '\\s*:\\s*[^;]+;');
            textarea.value = textarea.value.replace(regex, varName + ': ' + this.value + ';');
            if (te_preview_mode) te_render_preview();
        }
    };

    picker.onchange = function() {
        const fd = new FormData();
        fd.append('ajax_action', 'update_variable');
        fd.append('module', te_current);
        fd.append('source_module', te_var_source);
        fd.append('var_name', varName);
        fd.append('var_value', this.value);
        fd.append('theme', TE_THEME);
        fd.append('csrf_token', TE_CSRF);

        fetch(TE_AJAX, { method:'POST', body:fd }).then(r=>r.json()).then(d=>{
            if (d.ok) {
                if (te_var_source === te_current) {
                    te_load(te_current);
                } else {
                    te_load_vars();
                }
            }
        }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
    };
    picker.click();
}

function te_jump_to_var(name) {
    if (te_var_source !== te_current) {
        alert("<?= t('te_warn_jump_different_mod', [], 'This variable is located in the master module: ') ?>" + te_var_source);
        return;
    }
    const textarea = document.getElementById('te-css');
    const idx = textarea.value.indexOf(name);
    if (idx >= 0) {
        textarea.focus();
        textarea.setSelectionRange(idx, idx + name.length);
        const lines = textarea.value.substring(0, idx).split('\n').length;
        const lineH = textarea.scrollHeight / textarea.value.split('\n').length;
        textarea.scrollTop = Math.max(0, (lines - 5) * lineH);
    }
}

function te_init_history_tab() {
    const sel = document.getElementById('te-history-module-select');
    if (!sel.dataset.filled) {
        sel.innerHTML = <?= json_encode($modules) ?>.map(m => `<option value="${m}" ${m === te_current ? 'selected' : ''}>${m}</option>`).join('');
        sel.dataset.filled = '1';
        sel.addEventListener('change', te_load_history);
    } else {
        sel.value = te_current;
    }
    te_load_history();
}

function te_load_history() {
    const mod = document.getElementById('te-history-module-select').value;
    const list = document.getElementById('te-history-list');
    list.innerHTML = '<div class="te-vars-empty"><?= t('general_loading', [], 'Loading…') ?></div>';
    document.getElementById('te-history-diff').style.display = 'none';

    const fd = new FormData();
    fd.append('ajax_action', 'list_history');
    fd.append('module', mod);
    fd.append('theme', TE_THEME);
    fd.append('csrf_token', TE_CSRF);
    fetch(TE_AJAX, { method:'POST', body:fd }).then(r=>r.json()).then(d => {
        if (!d.ok || !d.history.length) {
            list.innerHTML = '<div class="te-vars-empty"><?= t('te_no_history', [], 'No previous versions saved yet.') ?></div>';
            return;
        }
        te_history_data = d.history;
        list.innerHTML = d.history.map(h => `
            <div class="te-history-item" onclick="te_show_diff(${h.id}, '${mod}')">
                <span class="te-history-user">${h.username || 'System'}</span>
                <span class="te-history-date">${h.changed_at}</span>
            </div>`).join('');
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

function te_show_diff(historyId, mod) {
    const fd = new FormData();
    fd.append('ajax_action', 'get_history_version');
    fd.append('history_id', historyId);
    fd.append('theme', TE_THEME);
    fd.append('csrf_token', TE_CSRF);
    fetch(TE_AJAX, { method:'POST', body:fd }).then(r=>r.json()).then(d => {
        if (!d.ok) return;
        const fd2 = new FormData();
        fd2.append('ajax_action', 'load_module');
        fd2.append('module', mod);
        fd2.append('theme', TE_THEME);
        fd2.append('csrf_token', TE_CSRF);
        fetch(TE_AJAX, { method:'POST', body:fd2 }).then(r=>r.json()).then(cur => {
            const oldLines = d.css.split('\n');
            const newLines = (cur.ok ? cur.css : '').split('\n');
            document.getElementById('te-diff-body').innerHTML = te_render_diff(oldLines, newLines);
            document.getElementById('te-history-diff').style.display = 'block';
            document.getElementById('te-history-rollback-btn').onclick = () => te_rollback(historyId, mod);
        }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

function te_render_diff(oldLines, newLines) {
    const max = Math.max(oldLines.length, newLines.length);
    let html = '';
    for (let i = 0; i < max; i++) {
        const o = oldLines[i], n = newLines[i];
        if (o === n) {
            html += `<div class="te-diff-line same">${te_diff_esc(o ?? '')}</div>`;
        } else {
            if (o !== undefined) html += `<div class="te-diff-line removed">- ${te_diff_esc(o)}</div>`;
            if (n !== undefined) html += `<div class="te-diff-line added">+ ${te_diff_esc(n)}</div>`;
        }
    }
    return html;
}
function te_diff_esc(s) { return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function te_rollback(historyId, mod) {
    if (!confirm('<?= t('te_confirm_rollback', [], 'Restore this version? The current version will be saved to history first.') ?>')) return;
    const fd = new FormData();
    fd.append('ajax_action', 'rollback_history');
    fd.append('history_id', historyId);
    fd.append('module', mod);
    fd.append('theme', TE_THEME);
    fd.append('csrf_token', TE_CSRF);
    fetch(TE_AJAX, { method:'POST', body:fd }).then(r=>r.json()).then(d => {
        if (!d.ok) { alert('Error: ' + d.error); return; }
        alert('<?= t('te_rollback_done', [], 'Rolled back.') ?>');
        te_load_history();
        if (mod === te_current) te_load(mod);
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

document.getElementById('te-new-module-btn').addEventListener('click', () => {
    document.getElementById('te-new-name').value = '';
    document.getElementById('te-new-modal').classList.add('open');
    document.getElementById('te-new-name').focus();
});

document.getElementById('te-new-confirm').addEventListener('click', () => {
    const name = document.getElementById('te-new-name').value.trim().replace(/[^a-z0-9_]/g,'');
    if (!name) return;
    const fd = new FormData();
    fd.append('ajax_action', 'create_module');
    fd.append('module', name);
    fd.append('theme', TE_THEME);
    fd.append('csrf_token', TE_CSRF);
    fetch(TE_AJAX, { method:'POST', body:fd }).then(r=>r.json()).then(d => {
        document.getElementById('te-new-modal').classList.remove('open');
        if (d.ok) {
            const list = document.getElementById('te-module-list');
            const div  = document.createElement('div');
            div.className = 'te-module'; div.dataset.mod = name;
            div.textContent = name; div.onclick = () => te_load(name);
            list.appendChild(div); te_load(name);
        } else { alert('Error: ' + d.error); }
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
});
document.getElementById('te-new-name').addEventListener('keydown', e => {
    if (e.key==='Enter') document.getElementById('te-new-confirm').click();
});

function openCloneModal() { document.getElementById('te-clone-modal').classList.add('open'); }
function closeCloneModal() { document.getElementById('te-clone-modal').classList.remove('open'); }

function te_delete_theme() {
    if (TE_THEME === 'default') return;
    if (!confirm('<?= t('te_confirm_delete_theme', [], 'Permanently delete this theme and all its CSS modules? This cannot be undone.') ?> (' + TE_THEME + ')')) return;

    const fd = new FormData();
    fd.append('ajax_action', 'delete_theme');
    fd.append('slug', TE_THEME);
    fd.append('theme', TE_THEME);
    fd.append('csrf_token', TE_CSRF);

    fetch(TE_AJAX, { method: 'POST', body: fd }).then(r=>r.json()).then(d => {
        if (d.ok) {
            window.location.href = 'acp.php?s=theme_editor&theme=default';
        } else {
            alert('Error: ' + d.error);
        }
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

function te_clone_theme() {
    const base = document.getElementById('te-clone-base').value;
    const newName = document.getElementById('te-clone-name').value.trim().replace(/[^a-z0-9_-]/gi,'');
    const mode = document.querySelector('input[name="te-clone-mode"]:checked').value;
    if (!newName) { alert("<?= t('te_err_name_required', [], 'Please provide a new theme name.') ?>"); return; }

    const fd = new FormData();
    fd.append('ajax_action', 'clone_theme');
    fd.append('base_theme', base);
    fd.append('new_theme', newName);
    fd.append('mode', mode);
    fd.append('csrf_token', TE_CSRF);

    fetch(TE_AJAX, { method: 'POST', body: fd }).then(r=>r.json()).then(d => {
        if(d.ok) {
            window.location.href = 'acp.php?s=theme_editor&theme=' + newName;
        } else {
            alert('Error: ' + d.error);
        }
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

function openUploadModal() { document.getElementById('te-upload-modal').classList.add('open'); }
function closeUploadModal() { document.getElementById('te-upload-modal').classList.remove('open'); }

function te_upload_theme() {
    const fileInput = document.getElementById('te-upload-file');
    if (!fileInput.files.length) { alert("<?= t('te_err_select_file', [], 'Select a SQL file first.') ?>"); return; }

    const fd = new FormData();
    fd.append('ajax_action', 'upload_theme');
    fd.append('theme_sql', fileInput.files[0]);
    fd.append('csrf_token', TE_CSRF);

    fetch(TE_AJAX, { method: 'POST', body: fd }).then(r=>r.json()).then(d => {
        if(d.ok) {
            window.location.reload();
        } else {
            alert('Upload Error: ' + d.error);
        }
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

function te_export_theme() {
    const fd = new FormData();
    fd.append('ajax_action', 'export_theme');
    fd.append('theme', TE_THEME);
    fd.append('csrf_token', TE_CSRF);

    fetch(TE_AJAX, { method: 'POST', body: fd }).then(r=>r.json()).then(d => {
        if (d.ok) {
            const blob = new Blob([d.sql], { type: 'text/sql' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `theme_export_${TE_THEME}.sql`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        } else {
            alert('Export Error: ' + d.error);
        }
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

<?php if ($ai_active): ?>
function te_ai_show(text, state='ok') {
    const el = document.getElementById('te-ai-result');
    if (!el) return;
    el.textContent = text;
    el.className = 'te-ai-result visible ' + state;
}
function te_ai_reset() {
    const el = document.getElementById('te-ai-result');
    if (el) { el.className = 'te-ai-result'; el.textContent = ''; }
    te_last_ai_css = '';
    const ab = document.getElementById('te-ai-apply-btn');
    if (ab) ab.className = 'te-ai-apply';
}
function te_ai_suggest() {
    const request = document.getElementById('te-ai-request')?.value.trim();
    const css = document.getElementById('te-css')?.value || '';
    if (!request) { te_ai_show('<?= t('te_ai_describe', [], 'Please describe what to change.') ?>', 'err'); return; }
    const btn = document.getElementById('te-ai-suggest-btn');
    if (btn) btn.disabled = true;
    te_ai_show('<?= t('general_generating', [], 'Generating...') ?>', 'loading');
    const fd = new FormData();
    fd.append('ajax_action','ai_suggest_css'); fd.append('csrf_token',TE_CSRF);
    fd.append('current_css',css); fd.append('module',te_current); fd.append('request',request);
    fetch(TE_AJAX,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
        if(btn)btn.disabled=false;
        if(data.status==='ok'){
            te_last_ai_css=data.result?.suggestion||'';
            te_ai_show(te_last_ai_css,'ok');
            const ab=document.getElementById('te-ai-apply-btn');
            if(ab&&te_last_ai_css)ab.className='te-ai-apply visible';
        }else te_ai_show('Error: '+(data.message||'?'),'err');
    }).catch(e=>{if(btn)btn.disabled=false;te_ai_show('Failed: '+e,'err');});
}
function te_ai_explain() {
    const textarea = document.getElementById('te-css');
    const sel = textarea?textarea.value.substring(textarea.selectionStart,textarea.selectionEnd).trim():'';
    const varName = sel||document.getElementById('te-ai-request')?.value.trim();
    if(!varName){te_ai_show('<?= t('te_ai_select_var', [], 'Select a CSS variable or type above.') ?>','err');return;}
    const btn=document.getElementById('te-ai-explain-btn');
    if(btn)btn.disabled=true;
    te_ai_show('<?= t('general_generating', [], 'Explaining...') ?>','loading');
    const fd=new FormData();
    fd.append('ajax_action','ai_explain_variable');fd.append('csrf_token',TE_CSRF);fd.append('css_variable',varName);
    fetch(TE_AJAX,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
        if(btn)btn.disabled=false;
        if(data.status==='ok')te_ai_show(data.result?.suggestion||'—','ok');
        else te_ai_show('Error: '+(data.message||'?'),'err');
    }).catch(e=>{if(btn)btn.disabled=false;te_ai_show('Failed: '+e,'err');});
}
function te_ai_apply() {
    if(!te_last_ai_css)return;
    const t=document.getElementById('te-css');
    if(!t)return;
    t.value+='\n\n/* AI Suggestion */\n'+te_last_ai_css;
    te_ai_show('✓ <?= t('te_ai_applied', [], 'Appended. Click Save to apply.') ?>','ok');
    document.getElementById('te-ai-apply-btn').className='te-ai-apply';
    if (te_preview_mode) te_render_preview();
}
<?php else: ?>
function te_ai_reset(){}
<?php endif; ?>

document.addEventListener('keydown', e => {
    if ((e.ctrlKey||e.metaKey) && e.key==='s' && document.activeElement.id==='te-css') {
        e.preventDefault(); te_save();
    }
});
te_load('<?= h($default) ?>');
</script>
