<?php
if (!defined('IN_CMS')) exit;

$itemshop_enabled = (int)($GLOBALS['cms_settings']['itemshop_enabled'] ?? 1);
$is_logged_in     = isset($_SESSION['user_id']);
$csrf             = generateToken();
?>
<div class="admin-container">

    <div style="margin-bottom: 25px;">
        <a href="?p=pve" style="color:#555; text-decoration:none; font-size:10px;">&larr; <?= t('pve_dash.back_to_overview', [], 'Back to PvE overview'); ?></a>
    </div>

    <h3 style="font-family:'Cinzel'; color:var(--gold); margin-top:0;">
        <?= t('itemshop.title', [], 'Itemshop'); ?>
    </h3>
    <p style="font-size:11px; color:#666; max-width:680px; margin-bottom:20px; line-height:1.6;">
        <?= t('itemshop.intro', [], 'Buy potions and respec stones either from another player\'s Housing Consignment Merchant (you pay the normal housing tax) or directly from the System (a flat 30% markup applies). You must be logged into the game with the character you want to buy for — this page checks your online status before allowing any purchase.'); ?>
    </p>

    <?php if (!$itemshop_enabled): ?>
        <div class="info-msg"><?= t('itemshop.disabled', [], 'The Itemshop is currently disabled.'); ?></div>
    <?php elseif (!$is_logged_in): ?>
        <div class="info-msg"><?= t('itemshop.login_required', [], 'Please log in to your account to use the Itemshop.'); ?></div>
    <?php else: ?>

        <!-- Online status box -->
        <div id="itemshop-status-box" style="border:1px solid #111; background:rgba(0,0,0,0.2); padding:14px 16px; margin-bottom:25px; display:flex; align-items:center; gap:12px;">
            <i class="fas fa-circle-notch fa-spin" style="color:#444;"></i>
            <span id="itemshop-status-text" style="font-size:12px; color:#666;"><?= t('itemshop.checking_status', [], 'Checking your online status...'); ?></span>
        </div>

        <!-- Source Tabs -->
        <div class="shop-source-tabs" style="display:flex; gap:8px; margin-bottom:20px;">
            <button class="shop-tab-btn active" data-source="housing" onclick="itemshopSwitchTab(this, 'housing')">
                <i class="fas fa-house"></i> <?= t('itemshop.tab_housing', [], 'Housing'); ?>
            </button>
            <button class="shop-tab-btn" data-source="system" onclick="itemshopSwitchTab(this, 'system')">
                <i class="fas fa-server"></i> <?= t('itemshop.tab_system', [], 'System (+30%)'); ?>
            </button>
        </div>

        <!-- Search (for both tabs) -->
        <div style="margin-bottom:20px;">
            <input type="text" id="itemshop-search" class="um-input" style="width:100%; max-width:420px;"
                   placeholder="<?= t('itemshop.search_placeholder', [], 'Search for an item by name...'); ?>"
                   oninput="itemshopHandleSearchInput()">
            <div id="itemshop-search-results" style="max-width:420px; margin-top:6px;"></div>
        </div>

        <!-- System catalog (system tab only, paginated) -->
        <div id="itemshop-system-catalog" style="display:none;">
            <div id="itemshop-system-list" style="border:1px solid #111; background:rgba(0,0,0,0.2);">
                <div class="igc-loading" style="padding:20px; text-align:center;"><i class="fas fa-circle-notch fa-spin"></i></div>
            </div>
            <div id="itemshop-pagination" style="display:flex; gap:6px; justify-content:center; margin-top:16px;"></div>
        </div>

    <?php endif; ?>
</div>

<!-- Purchase popup -->
<div id="itemshop-modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(4,2,1,0.85); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#0a0808; border:1px solid rgba(197,160,89,0.2); width:400px; max-width:92vw; padding:24px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid #1a1a1a; padding-bottom:10px;">
            <span style="font-family:'Cinzel'; font-size:0.75em; letter-spacing:2px; color:var(--gold); text-transform:uppercase;">
                <?= t('itemshop.modal_title', [], 'Confirm Purchase'); ?>
            </span>
            <button onclick="itemshopCloseModal()" style="background:none; border:none; color:#444; font-size:16px; cursor:pointer;">&times;</button>
        </div>

        <div id="itemshop-modal-body" style="font-size:12px; color:#aaa; line-height:1.7;">
            <!-- filled in via JS -->
        </div>

        <div id="itemshop-modal-listings" style="margin-top:14px;"></div>

        <div id="itemshop-modal-result" style="margin-top:12px; font-size:11px; display:none;"></div>

        <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:18px;">
            <button onclick="itemshopCloseModal()" class="igc-btn igc-btn--ghost" style="padding:7px 16px; font-size:11px;">
                <i class="fas fa-times"></i> <?= t('itemshop.cancel', [], 'Cancel'); ?>
            </button>
        </div>
    </div>
</div>

<?php if ($itemshop_enabled && $is_logged_in): ?>
<style>
.shop-tab-btn {
    background:transparent; border:1px solid #1a1a1a; color:#555;
    padding:8px 16px; font-family:'Cinzel',serif; font-size:0.65em;
    letter-spacing:1.5px; text-transform:uppercase; cursor:pointer; transition:all 0.2s;
}
.shop-tab-btn.active, .shop-tab-btn:hover { color:var(--gold); border-color:rgba(197,160,89,0.3); }
.shop-row {
    display:flex; justify-content:space-between; align-items:center;
    padding:10px 15px; border-bottom:1px solid #111; cursor:pointer; transition:background 0.15s;
}
.shop-row:hover { background:rgba(197,160,89,0.04); }
.shop-row:last-child { border-bottom:none; }
.shop-page-btn {
    background:transparent; border:1px solid #1a1a1a; color:#555;
    padding:5px 11px; font-size:11px; cursor:pointer; transition:all 0.15s;
}
.shop-page-btn.active { color:var(--gold); border-color:rgba(197,160,89,0.4); }
.shop-page-btn:hover:not(.active) { color:#888; }
.shop-page-btn:disabled { opacity:0.3; cursor:default; }
</style>
<script>
const ITEMSHOP_TOKEN = '<?= $csrf ?>';
// IMPORTANT: runs through index.php (sets IN_CMS), NOT directly on the module.
// The router loads modules/pve_items_logic.php automatically based on the
// page_slug "pve_items" — filename and slug must therefore exactly
// match "{page_slug}_logic.php".
const ITEMSHOP_URL   = 'index.php?p=pve_items&ajax=1';

let itemshopSource     = 'housing';
let itemshopOnlineChar = null;
let itemshopPage       = 1;
let itemshopSearchTimer= null;

function itemshopPost(action, extra = {}) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('csrf_token', ITEMSHOP_TOKEN);
    Object.entries(extra).forEach(([k, v]) => fd.append(k, v));
    return fetch(ITEMSHOP_URL, { method: 'POST', body: fd })
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.text();
        })
        .then(text => {
            try { return JSON.parse(text); }
            catch (e) {
                console.error('Itemshop: invalid JSON response', text);
                throw new Error('invalid_json');
            }
        }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

// ── Load online status ──────────────────────────────────────
function itemshopLoadStatus() {
    itemshopPost('status').then(data => {
        const box  = document.getElementById('itemshop-status-box');
        const text = document.getElementById('itemshop-status-text');
        if (!data.ok) {
            box.style.borderColor = '#3a1a1a';
            text.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:#a55;"></i> Could not reach the game server.';
            return;
        }
        if (data.online_char) {
            itemshopOnlineChar = data.online_char.Name;
            box.style.borderColor = 'rgba(80,160,80,0.3)';
            text.innerHTML = '<i class="fas fa-circle" style="color:#5a5; font-size:8px;"></i> Playing as <strong style="color:#ccc;">'
                + data.online_char.Name + '</strong> (Lv' + data.online_char.Level + ') — ready to buy.';
        } else {
            itemshopOnlineChar = null;
            box.style.borderColor = 'rgba(180,80,80,0.3)';
            text.innerHTML = '<i class="fas fa-circle" style="color:#a55; font-size:8px;"></i> '
                + 'No character of yours is online right now. Log into the game to enable purchases.';
        }
    }).catch(e => {
        const box  = document.getElementById('itemshop-status-box');
        const text = document.getElementById('itemshop-status-text');
        box.style.borderColor = '#3a1a1a';
        text.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:#a55;"></i> Connection error: ' + e.message;
    });
}

// ── Tabs ──────────────────────────────────────────────────────
function itemshopSwitchTab(btn, source) {
    document.querySelectorAll('.shop-tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    itemshopSource = source;
    document.getElementById('itemshop-search-results').innerHTML = '';
    document.getElementById('itemshop-search').value = '';

    const catalog = document.getElementById('itemshop-system-catalog');
    if (source === 'system') {
        catalog.style.display = 'block';
        itemshopPage = 1;
        itemshopLoadSystemList();
    } else {
        catalog.style.display = 'none';
    }
}

// ── Suche ─────────────────────────────────────────────────────
function itemshopHandleSearchInput() {
    clearTimeout(itemshopSearchTimer);
    const term = document.getElementById('itemshop-search').value.trim();
    const box  = document.getElementById('itemshop-search-results');
    if (term.length < 2) { box.innerHTML = ''; return; }

    itemshopSearchTimer = setTimeout(() => {
        itemshopPost('search', { term, source: itemshopSource }).then(data => {
            if (!data.ok || !data.results.length) {
                box.innerHTML = '<div style="font-size:11px; color:#444; padding:8px;">No items found.</div>';
                return;
            }
            box.innerHTML = data.results.map(r => `
                <div class="shop-row" onclick="itemshopOpenModal('${r.item_id}')">
                    <span style="font-size:12px; color:#aaa;">${r.name}</span>
                    <span style="font-size:10px; color:#555;">Lv${r.level}</span>
                </div>
            `).join('');
        }).catch(e => {
            box.innerHTML = `<div style="font-size:11px; color:#a55; padding:8px;">Connection error: ${e.message}</div>`;
        });
    }, 300);
}

// ── System-Liste (paginiert) ─────────────────────────────────
function itemshopLoadSystemList(page) {
    if (page) itemshopPage = page;
    const list = document.getElementById('itemshop-system-list');
    list.innerHTML = '<div class="igc-loading" style="padding:20px; text-align:center;"><i class="fas fa-circle-notch fa-spin"></i></div>';

    itemshopPost('system_list', { page: itemshopPage, search: document.getElementById('itemshop-search').value.trim() }).then(data => {
        if (!data.ok) {
            list.innerHTML = `<div style="padding:16px; font-size:11px; color:#a55;">${data.error}</div>`;
            document.getElementById('itemshop-pagination').innerHTML = '';
            return;
        }
        if (!data.items.length) {
            list.innerHTML = '<div style="padding:16px; font-size:11px; color:#666;">No items available.</div>';
            document.getElementById('itemshop-pagination').innerHTML = '';
            return;
        }
        list.innerHTML = data.items.map(it => `
            <div class="shop-row" onclick="itemshopOpenModal('${it.item_id}')">
                <span style="font-size:12px; color:#aaa;">${it.name} <small style="color:#444;">Lv${it.level}</small></span>
                <span style="font-size:11px; color:var(--gold);">${it.price_formatted}</span>
            </div>
        `).join('');

        itemshopRenderPagination(data.page, data.pages);
    }).catch(e => {
        list.innerHTML = `<div style="padding:16px; font-size:11px; color:#a55;">Connection error: ${e.message}</div>`;
    });
}

// Smarte Pagination: 1 … 4 5 [6] 7 8 … 20
function itemshopRenderPagination(current, total) {
    const wrap = document.getElementById('itemshop-pagination');
    if (total <= 1) { wrap.innerHTML = ''; return; }

    let pages = new Set([1, total, current, current - 1, current + 1]);
    pages = [...pages].filter(p => p >= 1 && p <= total).sort((a, b) => a - b);

    let html = `<button class="shop-page-btn" ${current === 1 ? 'disabled' : ''} onclick="itemshopLoadSystemList(${current - 1})"><i class="fas fa-chevron-left"></i></button>`;

    let prev = 0;
    for (const p of pages) {
        if (prev && p - prev > 1) html += `<span style="color:#333; padding:5px 2px;">…</span>`;
        html += `<button class="shop-page-btn ${p === current ? 'active' : ''}" onclick="itemshopLoadSystemList(${p})">${p}</button>`;
        prev = p;
    }

    html += `<button class="shop-page-btn" ${current === total ? 'disabled' : ''} onclick="itemshopLoadSystemList(${current + 1})"><i class="fas fa-chevron-right"></i></button>`;
    wrap.innerHTML = html;
}

// ── Kauf-Popup ────────────────────────────────────────────────
function itemshopOpenModal(itemId) {
    const backdrop = document.getElementById('itemshop-modal-backdrop');
    const body     = document.getElementById('itemshop-modal-body');
    const listings = document.getElementById('itemshop-modal-listings');
    const resultEl = document.getElementById('itemshop-modal-result');

    resultEl.style.display = 'none';
    body.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Loading...';
    listings.innerHTML = '';
    backdrop.style.display = 'flex';

    itemshopPost('item_detail', { item_id: itemId, source: itemshopSource }).then(data => {
        if (!data.ok) {
            body.innerHTML = `<span style="color:#a55;">${data.error}</span>`;
            return;
        }
        const it = data.item;
        body.innerHTML = `
            <div style="font-size:14px; color:var(--gold); font-family:'Cinzel'; margin-bottom:6px;">${it.name}</div>
            <div style="color:#666; margin-bottom:8px;">Level ${it.level}</div>
            <div style="color:#888; font-style:italic;">${it.effect}</div>
        `;

        if (!data.listings.length) {
            listings.innerHTML = '<div style="font-size:11px; color:#666; margin-top:10px;">No offers available right now.</div>';
            return;
        }

        listings.innerHTML = data.listings.map(l => `
            <div class="shop-row" style="border:1px solid #111; margin-bottom:6px;">
                <span style="font-size:11px; color:#888;">${l.seller_label} <small style="color:#444;">x${l.count}</small></span>
                <div style="display:flex; align-items:center; gap:10px;">
                    <span style="font-size:12px; color:var(--gold);">${l.price_formatted}</span>
                    <button class="igc-btn igc-btn--gold" style="padding:5px 12px; font-size:10px;"
                        onclick="itemshopBuy('${l.ref}')">
                        <i class="fas fa-coins"></i> Buy
                    </button>
                </div>
            </div>
        `).join('');
    });
}

function itemshopCloseModal() {
    document.getElementById('itemshop-modal-backdrop').style.display = 'none';
}

function itemshopBuy(ref) {
    if (!itemshopOnlineChar) {
        alert('No character of yours is online. Please log into the game first.');
        return;
    }
    const resultEl = document.getElementById('itemshop-modal-result');
    resultEl.style.display = 'block';
    resultEl.style.color = '#888';
    resultEl.textContent = 'Processing purchase...';

    itemshopPost('purchase', { item_ref: ref, source: itemshopSource, count: 1 }).then(data => {
        if (data.ok) {
            resultEl.style.color = '#5a5';
            resultEl.textContent = '✓ Purchase successful! Check your inventory in-game.';
            setTimeout(() => {
                itemshopCloseModal();
                if (itemshopSource === 'system') itemshopLoadSystemList();
            }, 1500);
        } else {
            resultEl.style.color = '#a55';
            resultEl.textContent = '✗ ' + (data.error || 'Purchase failed.');
        }
    });
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') itemshopCloseModal(); });

// Init
itemshopLoadStatus();
</script>
<?php endif; ?>
