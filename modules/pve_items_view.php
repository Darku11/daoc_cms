<?php
if (!defined('IN_CMS')) exit;

$itemshop_enabled = (int)($GLOBALS['cms_settings']['itemshop_enabled'] ?? 1);
$is_logged_in     = isset($_SESSION['user_id']);
$csrf             = generateToken();
?>
<div class="valor-spk-wrap admin-container">

    <div style="margin-bottom: 25px;">
        <a href="?p=pve" class="itemshop-back-link">
            <i class="fas fa-chevron-left"></i> <?= t('pve_dash.back_to_overview', [], 'Back to PvE overview'); ?>
        </a>
    </div>

    <div class="valor-spk-cat-title" style="margin-bottom: 15px;">
        <i class="fas fa-store"></i>
        <?= t('itemshop.title', [], 'Itemshop'); ?>
    </div>

    <p class="valor-board-desc" style="max-width:720px; margin-bottom:20px; line-height:1.7; white-space: normal; overflow: visible; text-overflow: clip;">
        <?= t('itemshop.intro', [], 'Buy potions and respec stones either from another player\'s Housing Consignment Merchant (you pay the normal housing tax) or directly from the System (a flat 30% markup applies). You must be logged into the game with the character you want to buy for — this page checks your online status before allowing any purchase.'); ?>
    </p>

    <?php if (!$itemshop_enabled): ?>
        <div class="valor-spk-empty"><?= t('itemshop.disabled', [], 'The Itemshop is currently disabled.'); ?></div>
    <?php elseif (!$is_logged_in): ?>
        <div class="valor-spk-empty"><?= t('itemshop.login_required', [], 'Please log in to your account to use the Itemshop.'); ?></div>
    <?php else: ?>

        <!-- Online-Status-Box -->
        <div id="itemshop-status-box" class="valor-profile-standing itemshop-status-box">
            <i class="fas fa-circle-notch fa-spin"></i>
            <span id="itemshop-status-text" class="status-label itemshop-status-text"><?= t('itemshop.checking_status', [], 'Checking your online status...'); ?></span>
        </div>

        <!-- Source Tabs -->
        <div class="shop-source-tabs">
            <button class="shop-tab-btn active" data-source="housing" onclick="itemshopSwitchTab(this, 'housing')">
                <i class="fas fa-home"></i> <?= t('itemshop.tab_housing', [], 'Housing'); ?>
            </button>
            <button class="shop-tab-btn" data-source="system" onclick="itemshopSwitchTab(this, 'system')">
                <i class="fas fa-server"></i> <?= t('itemshop.tab_system', [], 'System (+30%)'); ?>
            </button>
        </div>

        <!-- Search (for both tabs) -->
        <div class="itemshop-search-wrap">
            <input type="text" id="itemshop-search" class="valor-input itemshop-search-input"
                   placeholder="<?= t('itemshop.search_placeholder', [], 'Search for an item by name...'); ?>"
                   oninput="itemshopHandleSearchInput()">
            <div id="itemshop-search-results" class="valor-boards-wrapper itemshop-search-results"></div>
        </div>

        <!-- System catalog (system tab only, paginated) -->
        <div id="itemshop-system-catalog" style="display:none;">
            <div id="itemshop-system-list" class="valor-boards-wrapper itemshop-system-list">
                <div class="igc-loading itemshop-loading"><i class="fas fa-circle-notch fa-spin fa-2x"></i></div>
            </div>
            <div id="itemshop-pagination" class="spk-pagination" style="justify-content:center; margin-top:20px;"></div>
        </div>

    <?php endif; ?>
</div>

<!-- Item popup (Eden style): name, owner (housing only), level, type, effect, realm -->
<div id="itemshop-modal-backdrop" class="itemshop-modal-overlay" style="display:none;">
    <div class="itemshop-modal-box" id="itemshop-modal-box">
        <button onclick="itemshopCloseModal()" class="itemshop-modal-close">&times;</button>

        <div id="itemshop-modal-body" class="itemshop-modal-body">
            <!-- filled in via JS -->
        </div>

        <div id="itemshop-modal-listings" class="itemshop-modal-listings"></div>

        <div id="itemshop-modal-result" class="itemshop-modal-result" style="display:none;"></div>

        <div class="itemshop-modal-actions">
            <button onclick="itemshopCloseModal()" class="itemshop-btn-cancel">
                <?= t('itemshop.cancel', [], 'Cancel'); ?>
            </button>
        </div>
    </div>
</div>

<?php if ($itemshop_enabled && $is_logged_in): ?>
<script>
const ITEMSHOP_TOKEN = '<?= $csrf ?>';
const ITEMSHOP_URL   = 'index.php?p=pve_items&ajax=1';

// All client-side labels come from t() with an English fallback,
// so the language is controlled consistently via PHP language files
// instead of hardcoded strings in the JS.
const ITEMSHOP_I18N = {
    labelOwner:        <?= json_encode(t('itemshop.label_owner', [], 'Owner')) ?>,
    labelLevel:        <?= json_encode(t('itemshop.label_level', [], 'Level')) ?>,
    labelType:         <?= json_encode(t('itemshop.label_type', [], 'Type')) ?>,
    labelEffect:       <?= json_encode(t('itemshop.label_effect', [], 'Effect')) ?>,
    labelRealm:        <?= json_encode(t('itemshop.label_realm', [], 'Realm')) ?>,
    typePotion:        <?= json_encode(t('itemshop.type_potion', [], 'Potion')) ?>,
    typeStone:         <?= json_encode(t('itemshop.type_stone', [], 'Respec Stone')) ?>,
    realmAlb:          <?= json_encode(t('itemshop.realm_alb', [], 'Albion')) ?>,
    realmHib:          <?= json_encode(t('itemshop.realm_hib', [], 'Hibernia')) ?>,
    realmMid:          <?= json_encode(t('itemshop.realm_mid', [], 'Midgard')) ?>,
    realmNone:         <?= json_encode(t('itemshop.realm_none', [], 'All Realms')) ?>,
    buy:               <?= json_encode(t('itemshop.buy', [], 'Buy')) ?>,
    cancel:            <?= json_encode(t('itemshop.cancel', [], 'Cancel')) ?>,
    loading:           <?= json_encode(t('itemshop.loading', [], 'Loading...')) ?>,
    noItemsFound:      <?= json_encode(t('itemshop.no_items_found', [], 'No items found.')) ?>,
    noOffersAvailable: <?= json_encode(t('itemshop.no_offers_available', [], 'No offers available right now.')) ?>,
    noItemsAvailable:  <?= json_encode(t('itemshop.no_items_available', [], 'No items available.')) ?>,
    connectionError:   <?= json_encode(t('itemshop.connection_error', [], 'Connection error:')) ?>,
    noCharOnlineAlert: <?= json_encode(t('itemshop.no_char_online_alert', [], 'No character of yours is online. Please log into the game first.')) ?>,
    processingPurchase:<?= json_encode(t('itemshop.processing_purchase', [], 'Processing purchase...')) ?>,
    purchaseSuccess:   <?= json_encode(t('itemshop.purchase_success', [], 'Purchase successful! Check your inventory in-game.')) ?>,
    purchaseFailed:    <?= json_encode(t('itemshop.purchase_failed', [], 'Purchase failed.')) ?>,
    playingAs:         <?= json_encode(t('itemshop.playing_as', [], 'Playing as')) ?>,
    readyToBuy:        <?= json_encode(t('itemshop.ready_to_buy', [], 'ready to buy.')) ?>,
    onlineLabel:       <?= json_encode(t('itemshop.online_label', [], 'Online:')) ?>,
    selectCharHint:    <?= json_encode(t('itemshop.select_char_hint', [], 'select a character when buying.')) ?>,
    noCharOnline:      <?= json_encode(t('itemshop.no_char_online_status', [], 'No character of yours is online right now. Log into the game to enable purchases.')) ?>,
    serverUnreachable: <?= json_encode(t('itemshop.server_unreachable', [], 'Could not reach the game server.')) ?>,
};

let itemshopSource      = 'housing';
let itemshopOnlineChar  = null;
let itemshopPage        = 1;
let itemshopSearchTimer = null;
let itemshopListBusy    = false; // prevents overlapping requests on rapid clicks

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

// ── Level color coding (Eden-style: gray -> white -> green -> blue -> gold) ──
function itemshopLevelTier(level) {
    if (level >= 50) return 'epic';
    if (level >= 40) return 'high';
    if (level >= 25) return 'mid';
    if (level >= 10) return 'low';
    return 'trivial';
}

// Realm abbreviation -> full name via i18n (class color still comes from CSS tier-*)
function itemshopRealmLabel(realm) {
    const map = { alb: ITEMSHOP_I18N.realmAlb, hib: ITEMSHOP_I18N.realmHib, mid: ITEMSHOP_I18N.realmMid, none: ITEMSHOP_I18N.realmNone };
    const key = (realm || 'none').toString().toLowerCase();
    return map[key] || (realm || ITEMSHOP_I18N.realmNone);
}

// ── Load online status ──────────────────────────────────────
// Supports both the old format (data.online_char, a single character)
// and multiple characters online at once (data.online_chars, array) - in case
// the backend delivers that later. This falls back cleanly when only one value is present.
function itemshopLoadStatus() {
    itemshopPost('status').then(data => {
        const box  = document.getElementById('itemshop-status-box');
        const text = document.getElementById('itemshop-status-text');
        const icon = box.querySelector('i');

        icon.className = 'fas fa-circle';
        box.classList.remove('is-error', 'is-online', 'is-offline');

        if (!data.ok) {
            box.classList.add('is-error');
            text.textContent = ITEMSHOP_I18N.serverUnreachable;
            return;
        }

        const chars = Array.isArray(data.online_chars)
            ? data.online_chars
            : (data.online_char ? [data.online_char] : []);

        if (chars.length > 0) {
            itemshopOnlineChar = chars[0].Name;
            box.classList.add('is-online');
            if (chars.length === 1) {
                text.innerHTML = ITEMSHOP_I18N.playingAs + ' <strong class="itemshop-highlight">'
                    + chars[0].Name + '</strong> (Lv' + chars[0].Level + ') — ' + ITEMSHOP_I18N.readyToBuy;
            } else {
                text.innerHTML = ITEMSHOP_I18N.onlineLabel + ' ' + chars.map(c =>
                    `<strong class="itemshop-highlight">${c.Name}</strong> (Lv${c.Level})`
                ).join(', ') + ' — ' + ITEMSHOP_I18N.selectCharHint;
            }
        } else {
            itemshopOnlineChar = null;
            box.classList.add('is-offline');
            text.textContent = ITEMSHOP_I18N.noCharOnline;
        }
    }).catch(e => {
        const box  = document.getElementById('itemshop-status-box');
        const text = document.getElementById('itemshop-status-text');
        const icon = box.querySelector('i');
        box.classList.add('is-error');
        icon.className = 'fas fa-exclamation-triangle';
        text.textContent = ITEMSHOP_I18N.connectionError + ' ' + e.message;
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
                box.innerHTML = `<div class="valor-spk-empty itemshop-empty">${ITEMSHOP_I18N.noItemsFound}</div>`;
                return;
            }

            box.innerHTML = data.results.map(r => `
                <div class="shop-row" onclick="itemshopOpenModal('${r.item_id}')">
                    <span class="itemshop-row-name tier-${itemshopLevelTier(r.level)}">${r.name}</span>
                    <span class="itemshop-row-level">Lv${r.level}</span>
                </div>
            `).join('');

            if (data.results.length === 1) {
                itemshopOpenModal(data.results[0].item_id);
            }
        }).catch(e => {
            box.innerHTML = `<div class="valor-msg-box error itemshop-error">${ITEMSHOP_I18N.connectionError} ${e.message}</div>`;
        });
    }, 200);
}

// ── System-Liste (paginiert) ─────────────────────────────────
function itemshopLoadSystemList(page) {
    if (itemshopListBusy) return;
    if (page) itemshopPage = page;

    const list = document.getElementById('itemshop-system-list');
    const isFirstLoad = list.querySelector('.itemshop-loading') !== null;

    itemshopListBusy = true;
    if (!isFirstLoad) list.classList.add('is-loading');

    itemshopPost('system_list', { page: itemshopPage, search: document.getElementById('itemshop-search').value.trim() }).then(data => {
        itemshopListBusy = false;
        list.classList.remove('is-loading');

        if (!data.ok) {
            list.innerHTML = `<div class="valor-msg-box error itemshop-error" style="margin: 15px;">${data.error}</div>`;
            document.getElementById('itemshop-pagination').innerHTML = '';
            return;
        }
        if (!data.items.length) {
            list.innerHTML = `<div class="valor-spk-empty itemshop-empty" style="border:none;">${ITEMSHOP_I18N.noItemsAvailable}</div>`;
            document.getElementById('itemshop-pagination').innerHTML = '';
            return;
        }
        list.innerHTML = data.items.map(it => `
            <div class="shop-row" onclick="itemshopOpenModal('${it.item_id}')">
                <span class="itemshop-row-name tier-${itemshopLevelTier(it.level)}">${it.name} <small class="itemshop-row-level-inline">Lv${it.level}</small></span>
                <span class="itemshop-row-price">${it.price_formatted}</span>
            </div>
        `).join('');

        itemshopRenderPagination(data.page, data.pages);
    }).catch(e => {
        itemshopListBusy = false;
        list.classList.remove('is-loading');
        list.innerHTML = `<div class="valor-msg-box error itemshop-error" style="margin: 15px;">${ITEMSHOP_I18N.connectionError} ${e.message}</div>`;
    });
}

function itemshopRenderPagination(current, total) {
    const wrap = document.getElementById('itemshop-pagination');
    if (total <= 1) { wrap.innerHTML = ''; return; }

    let pages = new Set([1, total, current, current - 1, current + 1]);
    pages = [...pages].filter(p => p >= 1 && p <= total).sort((a, b) => a - b);

    let html = `<button class="shop-page-btn" ${current === 1 ? 'disabled' : ''} onclick="itemshopLoadSystemList(${current - 1})"><i class="fas fa-chevron-left"></i></button>`;

    let prev = 0;
    for (const p of pages) {
        if (prev && p - prev > 1) html += `<span class="itemshop-page-ellipsis">…</span>`;
        html += `<button class="shop-page-btn ${p === current ? 'active' : ''}" onclick="itemshopLoadSystemList(${p})">${p}</button>`;
        prev = p;
    }

    html += `<button class="shop-page-btn" ${current === total ? 'disabled' : ''} onclick="itemshopLoadSystemList(${current + 1})"><i class="fas fa-chevron-right"></i></button>`;
    wrap.innerHTML = html;
}

// ── Item-Popup (Eden-Style) ──────────────────────────────────
// The item_detail endpoint must provide at least name, level, and effect.
// Optional when supplied by the backend: type ('potion'|'stone'),
// realm ('alb'|'hib'|'mid'|'none'), and owner for housing sources.
// Hide missing optional fields instead of displaying placeholders.
function itemshopOpenModal(itemId) {
    const backdrop = document.getElementById('itemshop-modal-backdrop');
    const modalBox = document.getElementById('itemshop-modal-box');
    const body     = document.getElementById('itemshop-modal-body');
    const listings = document.getElementById('itemshop-modal-listings');
    const resultEl = document.getElementById('itemshop-modal-result');

    resultEl.style.display = 'none';
    resultEl.className = 'itemshop-modal-result';
    body.innerHTML = '<div class="itemshop-modal-loading"><i class="fas fa-circle-notch fa-spin"></i> Loading...</div>';
    listings.innerHTML = '';
    modalBox.className = 'itemshop-modal-box'; // reset tier class
    backdrop.style.display = 'flex';

    itemshopPost('item_detail', { item_id: itemId, source: itemshopSource }).then(data => {
        if (!data.ok) {
            body.innerHTML = `<span class="itemshop-error-inline">${data.error}</span>`;
            return;
        }
        const it   = data.item;
        const tier = itemshopLevelTier(it.level);
        modalBox.classList.add('tier-' + tier);

        const rows = [];
        if (itemshopSource === 'housing' && it.owner) {
            rows.push([ITEMSHOP_I18N.labelOwner, it.owner]);
        }
        rows.push([ITEMSHOP_I18N.labelLevel, it.level]);
        if (it.type) {
            rows.push([ITEMSHOP_I18N.labelType, it.type === 'stone' ? ITEMSHOP_I18N.typeStone : ITEMSHOP_I18N.typePotion]);
        }
        if (it.effect) {
            rows.push([ITEMSHOP_I18N.labelEffect, it.effect, true]);
        }
        if (it.realm) {
            rows.push([ITEMSHOP_I18N.labelRealm, itemshopRealmLabel(it.realm)]);
        }

        body.innerHTML = `
            <div class="itemshop-item-name tier-${tier}">${it.name}</div>
            <div class="itemshop-item-rows">
                ${rows.map(([label, val, multiline]) => `
                    <div class="itemshop-item-row${multiline ? ' is-multiline' : ''}">
                        <span class="itemshop-item-row-label">${label}</span>
                        <span class="itemshop-item-row-val">${val}</span>
                    </div>
                `).join('')}
            </div>
        `;

        if (!data.listings.length) {
            listings.innerHTML = `<div class="valor-spk-empty itemshop-empty" style="border-style:dashed;">${ITEMSHOP_I18N.noOffersAvailable}</div>`;
            return;
        }

        listings.innerHTML = data.listings.map(l => `
            <div class="shop-row itemshop-listing-row">
                <span class="itemshop-listing-seller">
                    ${l.seller_online ? '<i class="fas fa-circle itemshop-online-dot" title="Online"></i>' : '<i class="fas fa-circle itemshop-offline-dot" title="Offline"></i>'}
                    ${l.seller_label} <small class="itemshop-listing-count">x${l.count}</small>
                </span>
                <div class="itemshop-listing-right">
                    <span class="itemshop-listing-price">${l.price_formatted}</span>
                    <button class="itemshop-buy-btn" onclick="itemshopBuy('${l.ref}')">
                        <i class="fas fa-coins"></i> ${ITEMSHOP_I18N.buy}
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
        alert(ITEMSHOP_I18N.noCharOnlineAlert);
        return;
    }
    const resultEl = document.getElementById('itemshop-modal-result');
    resultEl.style.display = 'block';
    resultEl.className = 'itemshop-modal-result';
    resultEl.innerHTML = `<i class="fas fa-circle-notch fa-spin"></i> ${ITEMSHOP_I18N.processingPurchase}`;

    itemshopPost('purchase', { item_ref: ref, source: itemshopSource, count: 1 }).then(data => {
        if (data.ok) {
            resultEl.classList.add('is-success');
            resultEl.innerHTML = `<i class="fas fa-check-circle"></i> ${ITEMSHOP_I18N.purchaseSuccess}`;
            setTimeout(() => {
                itemshopCloseModal();
                if (itemshopSource === 'system') itemshopLoadSystemList();
            }, 1500);
        } else {
            resultEl.classList.add('is-error');
            resultEl.innerHTML = '<i class="fas fa-times-circle"></i> ' + (data.error || ITEMSHOP_I18N.purchaseFailed);
        }
    });
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') itemshopCloseModal(); });
document.getElementById('itemshop-modal-backdrop').addEventListener('click', e => {
    if (e.target.id === 'itemshop-modal-backdrop') itemshopCloseModal();
});

// Init
itemshopLoadStatus();
</script>
<?php endif; ?>
