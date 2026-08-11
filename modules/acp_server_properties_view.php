<?php
if (!defined('IN_ACP')) exit;
if ($userPriv < 4) { echo '<div class="acp-empty">Access denied.</div>'; return; }

// Build pagination URL helper
function sp_url(array $params = []): string {
    $base = ['s' => 'server_properties'];
    $current = [
        'sp_page' => $_GET['sp_page'] ?? 1,
        'sp_cat'  => $_GET['sp_cat']  ?? '',
        'sp_q'    => $_GET['sp_q']    ?? '',
    ];
    $merged = array_merge($base, $current, $params);
    $merged = array_filter($merged, fn($v) => $v !== '' && $v !== null);
    return 'acp.php?' . http_build_query($merged);
}
?>

<link rel="stylesheet" href="assets/acp_server_properties.css">

<!-- Header -->
<div class="sp-header">
    <span class="sp-header-title">
        <i class="fas fa-sliders-h"></i> SERVER PROPERTIES
    </span>
    <span class="sp-header-meta">
        <?= $sp_total ?> of <?= count($sp_all) ?> properties
        <?= !empty($sp_cat_filter) ? '· <strong>' . h(ucfirst($sp_cat_filter)) . '</strong>' : '' ?>
    </span>
    <div class="sp-header-legend">
        <span class="sp-legend sp-legend--ok">● Default</span>
        <span class="sp-legend sp-legend--warn">● Caution</span>
        <span class="sp-legend sp-legend--danger">● High Impact</span>
    </div>
</div>

<?php if (!empty($sp_msg)): ?>
<div class="sp-msg sp-msg--ok"><i class="fas fa-check-circle"></i> <?= $sp_msg ?></div>
<?php endif; ?>
<?php if (!empty($sp_error)): ?>
<div class="sp-msg sp-msg--err"><i class="fas fa-exclamation-circle"></i> <?= $sp_error ?></div>
<?php endif; ?>

<!-- Controls: Search + Category Dropdown -->
<form method="GET" action="acp.php" id="sp-filter-form">
    <input type="hidden" name="s" value="server_properties">
    <input type="hidden" name="sp_page" value="1">
    <input type="hidden" name="sp_cat" id="sp_cat_hidden" value="<?= h($sp_cat_filter) ?>">

    <div class="sp-controls">
        <!-- Search -->
        <div class="sp-search-wrap">
            <i class="fas fa-search sp-search-icon"></i>
            <input type="text" name="sp_q" id="sp-search" class="sp-search-input"
                   placeholder="Search properties..."
                   value="<?= h($sp_search) ?>"
                   autocomplete="off" spellcheck="false">
        </div>

        <!-- Category Dropdown -->
        <div class="sp-cat-dropdown-wrap">
            <button type="button" class="sp-cat-dropdown-btn" id="sp-cat-btn" onclick="spCatToggle()">
                <span id="sp-cat-label">
                    <?= !empty($sp_cat_filter) ? ucfirst(h($sp_cat_filter)) . ' (' . ($sp_cats[$sp_cat_filter] ?? 0) . ')' : 'All Categories' ?>
                </span>
                <i class="fas fa-chevron-down"></i>
            </button>
            <div class="sp-cat-dropdown-panel" id="sp-cat-panel">
                <input type="text" class="sp-cat-dropdown-search" id="sp-cat-search"
                       placeholder="Filter categories..." autocomplete="off"
                       oninput="spCatFilter(this.value)">
                <div class="sp-cat-dropdown-list" id="sp-cat-list">
                    <div class="sp-cat-option <?= empty($sp_cat_filter) ? 'active' : '' ?>"
                         onclick="spCatSelect('', 'All Categories')">
                        <span>All Categories</span>
                        <span class="sp-cat-option-count"><?= count($sp_all) ?></span>
                    </div>
                    <?php foreach ($sp_cats as $cat => $count):
                        $icon  = $sp_cat_icons[$cat]  ?? 'fa-circle';
                        $color = $sp_cat_colors[$cat] ?? '#888';
                        $active = (strtolower($sp_cat_filter) === strtolower($cat));
                    ?>
                    <div class="sp-cat-option <?= $active ? 'active' : '' ?>"
                         data-cat="<?= h(strtolower($cat)) ?>"
                         onclick="spCatSelect('<?= h(strtolower($cat)) ?>', '<?= ucfirst(h($cat)) ?> (<?= $count ?>)')">
                        <span style="color:<?= $color ?>;">
                            <i class="fas <?= $icon ?> acp-s-62d5e117" ></i>
                            <?= ucfirst(h($cat)) ?>
                        </span>
                        <span class="sp-cat-option-count"><?= $count ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Apply button (for search) -->
        <button type="submit" class="sp-btn-save acp-s-3285619d">
            <i class="fas fa-search"></i> Filter
        </button>

        <?php if (!empty($sp_cat_filter) || !empty($sp_search)): ?>
        <a href="acp.php?s=server_properties" class="sp-btn-reset acp-s-5b388486">
            <i class="fas fa-times"></i> Clear
        </a>
        <?php endif; ?>
    </div>
</form>

<!-- Properties -->
<?php if (empty($sp_page_items)): ?>
<div class="sp-empty"><i class="fas fa-search acp-s-c33316f5"></i>No properties found.</div>
<?php else: ?>

<?php foreach ($sp_grouped as $cat => $rows):
    $icon  = $sp_cat_icons[$cat]  ?? 'fa-circle';
    $color = $sp_cat_colors[$cat] ?? '#888';
?>
<div class="sp-cat-header" style="--sp-cat-color:<?= $color ?>;">
    <i class="fas <?= $icon ?>"></i>
    <?= ucfirst(h($cat)) ?>
    <span class="sp-cat-header-count"><?= count($rows) ?> shown</span>
</div>

<div class="sp-props-grid">
<?php foreach ($rows as $row):
    $warn       = sp_warn_level($row, $sp_warn_rules);
    $vtype      = sp_value_type($row['Value'] ?? '');
    $isModified = ($row['Value'] !== $row['DefaultValue']);
    $cardClass  = 'sp-prop-card';
    if ($warn !== 'none') $cardClass .= ' sp-prop-card--' . $warn;
    elseif ($isModified)  $cardClass .= ' sp-prop-card--modified';
?>
<div class="<?= $cardClass ?>">

    <div class="sp-prop-head">
        <span class="sp-prop-key"><?= h($row['Key']) ?></span>
        <div class="sp-prop-badges">
            <?php if ($isModified): ?>
            <span class="sp-prop-badge sp-prop-badge--mod">MOD</span>
            <?php endif; ?>
            <?php if ($warn !== 'none'): ?>
            <span class="sp-prop-badge sp-prop-badge--<?= $warn ?>">
                <?= $warn === 'danger' ? '⚠' : '△' ?>
            </span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($row['Description'])): ?>
    <div class="sp-prop-desc"><?= h($row['Description']) ?></div>
    <?php endif; ?>

    <div class="sp-prop-vals">
        <div class="sp-prop-val-item">
            <span class="sp-prop-val-lbl">Default</span>
            <span class="sp-prop-val-val sp-prop-val-val--dim"><?= h($row['DefaultValue']) ?></span>
        </div>
        <div class="sp-prop-val-item">
            <span class="sp-prop-val-lbl">Current</span>
            <span class="sp-prop-val-val <?= $isModified ? 'sp-prop-val-val--mod' : '' ?>"><?= h($row['Value']) ?></span>
        </div>
        <?php if (!empty($row['LastTimeRowUpdated']) && $row['LastTimeRowUpdated'] !== '2000-01-01 00:00:00'): ?>
        <div class="sp-prop-val-item">
            <span class="sp-prop-val-lbl">Updated</span>
            <span class="sp-prop-val-val sp-prop-val-val--dim acp-s-36d30cc1">
                <?= date('d.m.y H:i', strtotime($row['LastTimeRowUpdated'])) ?>
            </span>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($warn !== 'none'): ?>
    <div class="sp-prop-warn sp-prop-warn--<?= $warn ?>">
        <i class="fas <?= $warn === 'danger' ? 'fa-exclamation-triangle' : 'fa-info-circle' ?>"></i>
        <?= sp_warn_msg($row) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="<?= sp_url(['sp_page' => $sp_page]) ?>" class="sp-prop-form"
          onsubmit="return spConfirm(this, '<?= $warn ?>')">
        <input type="hidden" name="csrf_token"  value="<?= $sp_csrf ?>">
        <input type="hidden" name="sp_save"     value="1">
        <input type="hidden" name="sp_key"      value="<?= h($row['Key']) ?>">
        <div class="sp-prop-input-row">
            <?php if ($vtype === 'bool'): ?>
            <select name="sp_value" class="sp-input sp-input--select">
                <option value="True"  <?= $row['Value'] === 'True'  ? 'selected' : '' ?>>True</option>
                <option value="False" <?= $row['Value'] === 'False' ? 'selected' : '' ?>>False</option>
            </select>
            <?php elseif ($vtype === 'number'): ?>
            <input type="number" name="sp_value" class="sp-input" value="<?= h($row['Value']) ?>" step="any">
            <?php else: ?>
            <input type="text" name="sp_value" class="sp-input" value="<?= h($row['Value']) ?>">
            <?php endif; ?>
            <button type="submit" class="sp-btn-save" title="Save"><i class="fas fa-check"></i></button>
            <?php if ($isModified): ?>
            <button type="button" class="sp-btn-reset" title="Reset to default"
                    onclick="spReset(this, '<?= h($row['Key']) ?>', '<?= h($row['DefaultValue']) ?>')">
                <i class="fas fa-undo"></i>
            </button>
            <?php endif; ?>
        </div>
    </form>

</div>
<?php endforeach; ?>
</div><!-- /sp-props-grid -->

<?php endforeach; ?>

<!-- Pagination -->
<?php if ($sp_total_pages > 1): ?>
<div class="sp-pagination">
    <button class="sp-page-btn" onclick="spGoPage(1)" <?= $sp_page <= 1 ? 'disabled' : '' ?>>
        <i class="fas fa-angle-double-left"></i>
    </button>
    <button class="sp-page-btn" onclick="spGoPage(<?= $sp_page - 1 ?>)" <?= $sp_page <= 1 ? 'disabled' : '' ?>>
        <i class="fas fa-angle-left"></i>
    </button>

    <?php
    $range = 2;
    $start = max(1, $sp_page - $range);
    $end   = min($sp_total_pages, $sp_page + $range);
    if ($start > 1): ?>
        <button class="sp-page-btn" onclick="spGoPage(1)">1</button>
        <?php if ($start > 2): ?><span class="sp-legend acp-s-1236b02e">…</span><?php endif; ?>
    <?php endif; ?>

    <?php for ($i = $start; $i <= $end; $i++): ?>
    <button class="sp-page-btn <?= $i === $sp_page ? 'active' : '' ?>" onclick="spGoPage(<?= $i ?>)"><?= $i ?></button>
    <?php endfor; ?>

    <?php if ($end < $sp_total_pages): ?>
        <?php if ($end < $sp_total_pages - 1): ?><span class="sp-legend acp-s-1236b02e">…</span><?php endif; ?>
        <button class="sp-page-btn" onclick="spGoPage(<?= $sp_total_pages ?>)"><?= $sp_total_pages ?></button>
    <?php endif; ?>

    <button class="sp-page-btn" onclick="spGoPage(<?= $sp_page + 1 ?>)" <?= $sp_page >= $sp_total_pages ? 'disabled' : '' ?>>
        <i class="fas fa-angle-right"></i>
    </button>
    <button class="sp-page-btn" onclick="spGoPage(<?= $sp_total_pages ?>)" <?= $sp_page >= $sp_total_pages ? 'disabled' : '' ?>>
        <i class="fas fa-angle-double-right"></i>
    </button>

    <span class="sp-page-info">
        Page <?= $sp_page ?> of <?= $sp_total_pages ?> &nbsp;·&nbsp;
        <?= $sp_offset + 1 ?>–<?= min($sp_offset + $sp_per_page, $sp_total) ?> of <?= $sp_total ?>
    </span>
</div>
<?php endif; ?>

<?php endif; // empty check ?>

<script>
const SP_BASE = 'acp.php?s=server_properties';

function spGoPage(page) {
    const url = new URL(window.location.href);
    url.searchParams.set('sp_page', page);
    window.location.href = url.toString();
}

// ── Category dropdown ─────────────────────────────────────────
function spCatToggle() {
    const btn   = document.getElementById('sp-cat-btn');
    const panel = document.getElementById('sp-cat-panel');
    const open  = panel.classList.toggle('open');
    btn.classList.toggle('open', open);
    if (open) setTimeout(() => document.getElementById('sp-cat-search')?.focus(), 50);
}

function spCatSelect(cat, label) {
    document.getElementById('sp_cat_hidden').value = cat;
    document.getElementById('sp-cat-label').textContent = label || 'All Categories';
    document.getElementById('sp-cat-panel').classList.remove('open');
    document.getElementById('sp-cat-btn').classList.remove('open');
    // Reset to page 1 and submit
    document.querySelector('[name="sp_page"]').value = 1;
    document.getElementById('sp-filter-form').submit();
}

function spCatFilter(q) {
    q = q.toLowerCase();
    document.querySelectorAll('#sp-cat-list .sp-cat-option').forEach(opt => {
        const cat = (opt.dataset.cat || '').toLowerCase();
        const txt = opt.textContent.toLowerCase();
        opt.style.display = (!q || cat.includes(q) || txt.includes(q) || opt.dataset.cat === undefined) ? '' : 'none';
    });
}

// Close on outside click
document.addEventListener('click', function(e) {
    const wrap = document.querySelector('.sp-cat-dropdown-wrap');
    if (wrap && !wrap.contains(e.target)) {
        document.getElementById('sp-cat-panel')?.classList.remove('open');
        document.getElementById('sp-cat-btn')?.classList.remove('open');
    }
});

// ── Confirm on danger ─────────────────────────────────────────
function spConfirm(form, warn) {
    if (warn === 'danger') {
        const key = form.querySelector('[name="sp_key"]').value;
        const val = form.querySelector('[name="sp_value"]').value;
        return confirm('⚠ High Impact Change\n\nYou are changing "' + key + '" to "' + val + '".\n\nThis value deviates significantly from the server default and may have major gameplay consequences.\n\nContinue?');
    }
    return true;
}

// ── Reset to default ──────────────────────────────────────────
function spReset(btn, key, defaultVal) {
    if (!confirm('Reset "' + key + '" to default value "' + defaultVal + '"?')) return;
    const input = btn.closest('form').querySelector('[name="sp_value"]');
    if (input) { input.value = defaultVal; btn.closest('form').submit(); }
}
</script>