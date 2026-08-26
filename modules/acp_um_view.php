<?php
// SPDX-License-Identifier: GPL-3.0-only
if ((int)($_SESSION['priv_level'] ?? 0) < 3) return;
$is_super_user = (isset($_SESSION['priv_level']) && $_SESSION['priv_level'] >= 5);
$ajax_token    = generateToken();
?>
<div class="um-nexus-wrapper">
    <div class="um-internal-header">
        <h2 class="um-internal-title"><?= t('acp_um_user_db', [], 'User Database') ?></h2>
        <?php if ($_SESSION['priv_level'] >= 4): ?>
            <button onclick="loadNewUserForm()" class="um-btn-add-user"><?= t('acp_um_add_user', [], 'Add User') ?></button>
        <?php endif; ?>
    </div>
    <div class="um-search-vault">
        <input type="text" id="nexus-live-search" class="um-input-search-glow"
               placeholder="<?= t('acp_um_search_placeholder', [], 'Search by user, Email or IP') ?>" autocomplete="off">
        <div id="search-results-overlay"></div>
    </div>
    <div class="um-quick-grid">
        <div class="um-quick-card" onclick="loadCategory('all')"><h3><?= t('acp_um_user_list', [], 'User List') ?></h3></div>
        <div class="um-quick-card" onclick="loadCategory('restricted')"><h3 style="color:var(--red)"><?= t('acp_um_restricted', [], 'Restricted') ?></h3></div>
        <div class="um-quick-card" onclick="loadCategory('warned')"><h3 style="color:var(--amber-warn)"><?= t('acp_um_warned', [], 'Warned') ?></h3></div>
        <?php if ($is_super_user): ?>
            <div class="um-quick-card" onclick="loadCategory('staff')"><h3 style="color:var(--blue)"><?= t('acp_um_staff', [], 'Staff') ?></h3></div>
        <?php endif; ?>
    </div>
    <div id="nexus-ajax-container"></div>
</div>
<script>
const searchInput    = document.getElementById('nexus-live-search');
const resultsOverlay = document.getElementById('search-results-overlay');
const container      = document.getElementById('nexus-ajax-container');
const CSRF_TOKEN     = '<?= $ajax_token ?>';
const UM_SUPER_USER  = <?= $is_super_user ? 'true' : 'false' ?>;

function umFetch(params, callback) {
    params.append('csrf_token', CSRF_TOKEN);
    fetch('modules/acp_um_sync_worker.php', { method:'POST', body:params })
        .then(async r => {
            const text = await r.text();
            if (!r.ok) throw new Error(text || ('HTTP ' + r.status));
            return text;
        })
        .then(callback)
        .catch(err => alert('Error: ' + err.message));
}

function umRestoreSuperadminStanding() {
    if (!UM_SUPER_USER) return;
    const select = container.querySelector('select[name="u_stand"]');
    if (!select) return;

    let option5 = select.querySelector('option[value="5"]');
    if (!option5) {
        option5 = document.createElement('option');
        option5.value = '5';
        option5.textContent = '5 — Banned';
        select.appendChild(option5);
    }

    const storedStanding = container.querySelector('input[type="hidden"][name="u_stand"]');
    if (storedStanding && storedStanding.value === '5') select.value = '5';
}

searchInput.addEventListener('input', function() {
    if (this.value.length > 1) {
        const p = new URLSearchParams();
        p.append('um_ajax_search', this.value);
        umFetch(p, data => { resultsOverlay.innerHTML = data; resultsOverlay.style.display = 'block'; });
    } else {
        resultsOverlay.style.display = 'none';
    }
});

function loadCategory(cat) {
    resultsOverlay.style.display = 'none';
    container.innerHTML = '<p class="acp-s-1f033c63">Loading...</p>';
    const p = new URLSearchParams();
    p.append('um_load_cat', cat);
    umFetch(p, data => { container.innerHTML = data; });
}

function loadUserEditor(id) {
    resultsOverlay.style.display = 'none';
    container.innerHTML = '<p class="acp-s-1f033c63">Loading editor...</p>';
    const p = new URLSearchParams();
    p.append('um_ajax_get_editor', id);
    umFetch(p, data => {
        container.innerHTML = data;
        umRestoreSuperadminStanding();
        container.querySelectorAll('script').forEach(function(s) {
            const sc = document.createElement('script');
            sc.textContent = s.textContent;
            document.head.appendChild(sc);
        });
    });
}

function loadNewUserForm() {
    resultsOverlay.style.display = 'none';
    container.innerHTML = '<p class="acp-s-1f033c63">Loading...</p>';
    const p = new URLSearchParams();
    p.append('um_ajax_get_add_form', '1');
    umFetch(p, data => { container.innerHTML = data; });
}

document.addEventListener('click', function(e) {
    if (e.target !== searchInput && !resultsOverlay.contains(e.target)) resultsOverlay.style.display = 'none';
});
</script>