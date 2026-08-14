<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) exit;

$itemshop_enabled = (int)($GLOBALS['cms_settings']['itemshop_enabled'] ?? 1);
$is_logged_in     = isset($_SESSION['user_id']);
$csrf             = generateToken();
?>
<div class="valor-spk-wrap admin-container">

    <div class="itemshop-back-wrap">
        <a href="?p=pve" class="itemshop-back-link">
            <i class="fas fa-chevron-left"></i> <?= t('pve_dash.back_to_overview', [], 'Back to PvE overview'); ?>
        </a>
    </div>

    <div class="valor-spk-cat-title itemshop-title">
        <i class="fas fa-store"></i>
        <?= t('itemshop.title', [], 'Itemshop'); ?>
    </div>

    <p class="valor-board-desc itemshop-intro">
        <?= t('itemshop.intro', [], 'Buy potions and respec stones either from another player\'s Housing Consignment Merchant (you pay the normal housing tax) or directly from the System (a flat 30% markup applies). You must be logged into the game with the character you want to buy for — this page checks your online status before allowing any purchase.'); ?>
    </p>

    <?php if (!$itemshop_enabled): ?>
        <div class="valor-spk-empty"><?= t('itemshop.disabled', [], 'The Itemshop is currently disabled.'); ?></div>
    <?php elseif (!$is_logged_in): ?>
        <div class="valor-spk-empty"><?= t('itemshop.login_required', [], 'Please log in to your account to use the Itemshop.'); ?></div>
    <?php else: ?>
        <div id="itemshop-status-box" class="valor-profile-standing itemshop-status-box">
            <i class="fas fa-circle-notch fa-spin"></i>
            <span id="itemshop-status-text" class="status-label itemshop-status-text"><?= t('itemshop.checking_status', [], 'Checking your online status...'); ?></span>
        </div>

        <div class="shop-source-tabs">
            <button class="shop-tab-btn active" data-source="housing" onclick="itemshopSwitchTab(this, 'housing')">
                <i class="fas fa-home"></i> <?= t('itemshop.tab_housing', [], 'Housing'); ?>
            </button>
            <button class="shop-tab-btn" data-source="system" onclick="itemshopSwitchTab(this, 'system')">
                <i class="fas fa-server"></i> <?= t('itemshop.tab_system', [], 'System (+30%)'); ?>
            </button>
        </div>

        <div class="itemshop-search-wrap">
            <input type="text" id="itemshop-search" class="valor-input itemshop-search-input"
                   placeholder="<?= t('itemshop.search_placeholder', [], 'Search for an item by name...'); ?>"
                   oninput="itemshopHandleSearchInput()">
            <div id="itemshop-search-results" class="valor-boards-wrapper itemshop-search-results"></div>
        </div>

        <div id="itemshop-system-catalog" hidden>
            <div id="itemshop-system-list" class="valor-boards-wrapper itemshop-system-list">
                <div class="igc-loading itemshop-loading"><i class="fas fa-circle-notch fa-spin fa-2x"></i></div>
            </div>
            <div id="itemshop-pagination" class="spk-pagination itemshop-pagination"></div>
        </div>
    <?php endif; ?>
</div>

<div id="itemshop-modal-backdrop" class="itemshop-modal-overlay" hidden>
    <div class="itemshop-modal-box" id="itemshop-modal-box" role="dialog" aria-modal="true" aria-labelledby="itemshop-modal-name">
        <button onclick="itemshopCloseModal()" class="itemshop-modal-close" aria-label="<?= h(t('itemshop.cancel', [], 'Close')) ?>">&times;</button>
        <div id="itemshop-modal-body" class="itemshop-modal-body"></div>
        <div id="itemshop-modal-listings" class="itemshop-modal-listings"></div>
        <div id="itemshop-modal-result" class="itemshop-modal-result" hidden></div>
        <div class="itemshop-modal-actions">
            <button onclick="itemshopCloseModal()" class="itemshop-btn-cancel"><?= t('itemshop.cancel', [], 'Cancel'); ?></button>
        </div>
    </div>
</div>

<?php if ($itemshop_enabled && $is_logged_in): ?>
<script>
const ITEMSHOP_TOKEN = <?= json_encode($csrf) ?>;
const ITEMSHOP_URL   = 'index.php?p=pve_items&ajax=1';
const ITEMSHOP_I18N = {
    labelOwner:        <?= json_encode(t('itemshop.label_owner', [], 'Owner')) ?>,
    labelLevel:        <?= json_encode(t('itemshop.label_level', [], 'Level')) ?>,
    labelType:         <?= json_encode(t('itemshop.label_type', [], 'Type')) ?>,
    labelEffect:       <?= json_encode(t('itemshop.label_effect', [], 'Effect')) ?>,
    labelQuality:      <?= json_encode(t('itemshop.label_quality', [], 'Quality')) ?>,
    labelBonus:        <?= json_encode(t('itemshop.label_bonus', [], 'Bonus')) ?>,
    labelRestricted:   <?= json_encode(t('itemshop.label_restricted', [], 'Unavailable to')) ?>,
    labelMerchants:    <?= json_encode(t('itemshop.label_merchants', [], 'Game Merchants')) ?>,
    showMerchants:     <?= json_encode(t('itemshop.show_merchants', [], 'Show Merchants')) ?>,
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
let itemshopListBusy    = false;

function itemshopEsc(value) {
    return (value ?? '').toString()
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

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
        });
}

function itemshopLevelTier(level) {
    if (level >= 50) return 'epic';
    if (level >= 40) return 'high';
    if (level >= 25) return 'mid';
    if (level >= 10) return 'low';
    return 'trivial';
}

function itemshopLoadStatus() {
    itemshopPost('status').then(data => {
        const box  = document.getElementById('itemshop-status-box');
        const text = document.getElementById('itemshop-status-text');
        const icon = box.querySelector('i');
        icon.className = 'fas fa-circle';
        box.classList.remove('is-error', 'is-online', 'is-offline');

        if (!data.ok) {
            box.classList.add('is-error');
            text.textContent = data.error ? ITEMSHOP_I18N.serverUnreachable + ' (' + data.error + ')' : ITEMSHOP_I18N.serverUnreachable;
            return;
        }

        const chars = Array.isArray(data.online_chars) ? data.online_chars : (data.online_char ? [data.online_char] : []);
        if (chars.length > 0) {
            itemshopOnlineChar = chars[0].Name;
            box.classList.add('is-online');
            if (chars.length === 1) {
                text.textContent = ITEMSHOP_I18N.playingAs + ' ' + chars[0].Name + ' (Lv' + chars[0].Level + ') — ' + ITEMSHOP_I18N.readyToBuy;
            } else {
                text.textContent = ITEMSHOP_I18N.onlineLabel + ' ' + chars.map(c => c.Name + ' (Lv' + c.Level + ')').join(', ') + ' — ' + ITEMSHOP_I18N.selectCharHint;
            }
        } else {
            itemshopOnlineChar = null;
            box.classList.add('is-offline');
            text.textContent = ITEMSHOP_I18N.noCharOnline;
        }
    }).catch(e => {
        const box  = document.getElementById('itemshop-status-box');
        const text = document.getElementById('itemshop-status-text');
        box.classList.add('is-error');
        box.querySelector('i').className = 'fas fa-exclamation-triangle';
        text.textContent = ITEMSHOP_I18N.connectionError + ' ' + e.message;
    });
}

function itemshopSwitchTab(btn, source) {
    document.querySelectorAll('.shop-tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    itemshopSource = source;
    document.getElementById('itemshop-search-results').innerHTML = '';
    document.getElementById('itemshop-search').value = '';

    const catalog = document.getElementById('itemshop-system-catalog');
    if (source === 'system') {
        catalog.removeAttribute('hidden');
        itemshopPage = 1;
        itemshopLoadSystemList();
    } else {
        catalog.setAttribute('hidden', '');
    }
}

function itemshopHandleSearchInput() {
    clearTimeout(itemshopSearchTimer);
    const term = document.getElementById('itemshop-search').value.trim();
    const box  = document.getElementById('itemshop-search-results');
    if (term.length < 2) { box.innerHTML = ''; return; }

    itemshopSearchTimer = setTimeout(() => {
        itemshopPost('search', { term, source: itemshopSource }).then(data => {
            if (!data.ok || !data.results.length) {
                box.innerHTML = `<div class="valor-spk-empty itemshop-empty">${itemshopEsc(ITEMSHOP_I18N.noItemsFound)}</div>`;
                return;
            }
            box.innerHTML = data.results.map(r => `
                <button type="button" class="shop-row itemshop-result-button" data-item-id="${itemshopEsc(r.item_id)}">
                    <span class="itemshop-row-name tier-${itemshopLevelTier(r.level)}">${itemshopEsc(r.name)}</span>
                    <span class="itemshop-row-level">Lv${Number(r.level) || 0}</span>
                </button>
            `).join('');
            box.querySelectorAll('[data-item-id]').forEach(el => el.addEventListener('click', () => itemshopOpenModal(el.dataset.itemId)));
        }).catch(e => {
            box.innerHTML = `<div class="valor-msg-box error itemshop-error">${itemshopEsc(ITEMSHOP_I18N.connectionError)} ${itemshopEsc(e.message)}</div>`;
        });
    }, 200);
}

function itemshopLoadSystemList(page) {
    if (itemshopListBusy) return;
    if (page) itemshopPage = page;

    const list = document.getElementById('itemshop-system-list');
    itemshopListBusy = true;
    list.classList.add('is-loading');

    itemshopPost('system_list', { page: itemshopPage, search: document.getElementById('itemshop-search').value.trim() }).then(data => {
        itemshopListBusy = false;
        list.classList.remove('is-loading');

        if (!data.ok) {
            list.innerHTML = `<div class="valor-msg-box error itemshop-error">${itemshopEsc(data.error)}</div>`;
            document.getElementById('itemshop-pagination').innerHTML = '';
            return;
        }
        if (!data.items.length) {
            list.innerHTML = `<div class="valor-spk-empty itemshop-empty">${itemshopEsc(ITEMSHOP_I18N.noItemsAvailable)}</div>`;
            document.getElementById('itemshop-pagination').innerHTML = '';
            return;
        }
        list.innerHTML = data.items.map(it => `
            <button type="button" class="shop-row itemshop-result-button" data-item-id="${itemshopEsc(it.item_id)}">
                <span class="itemshop-row-name tier-${itemshopLevelTier(it.level)}">${itemshopEsc(it.name)} <small class="itemshop-row-level-inline">Lv${Number(it.level) || 0}</small></span>
                <span class="itemshop-row-price">${itemshopEsc(it.price_formatted)}</span>
            </button>
        `).join('');
        list.querySelectorAll('[data-item-id]').forEach(el => el.addEventListener('click', () => itemshopOpenModal(el.dataset.itemId)));
        itemshopRenderPagination(data.page, data.pages);
    }).catch(e => {
        itemshopListBusy = false;
        list.classList.remove('is-loading');
        list.innerHTML = `<div class="valor-msg-box error itemshop-error">${itemshopEsc(ITEMSHOP_I18N.connectionError)} ${itemshopEsc(e.message)}</div>`;
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

function itemshopOpenModal(itemId) {
    const backdrop = document.getElementById('itemshop-modal-backdrop');
    const modalBox = document.getElementById('itemshop-modal-box');
    const body     = document.getElementById('itemshop-modal-body');
    const listings = document.getElementById('itemshop-modal-listings');
    const resultEl = document.getElementById('itemshop-modal-result');

    resultEl.setAttribute('hidden', '');
    resultEl.className = 'itemshop-modal-result';
    body.innerHTML = `<div class="itemshop-modal-loading"><i class="fas fa-circle-notch fa-spin"></i> ${itemshopEsc(ITEMSHOP_I18N.loading)}</div>`;
    listings.innerHTML = '';
    modalBox.className = 'itemshop-modal-box';
    backdrop.removeAttribute('hidden');

    itemshopPost('item_detail', { item_id: itemId, source: itemshopSource }).then(data => {
        if (!data.ok) {
            body.innerHTML = `<span class="itemshop-error-inline">${itemshopEsc(data.error)}</span>`;
            return;
        }
        const it   = data.item;
        const tier = itemshopLevelTier(it.level);
        modalBox.classList.add('tier-' + tier);

        const rows = [
            [ITEMSHOP_I18N.labelLevel, Number(it.level) || 0],
            [ITEMSHOP_I18N.labelType, it.type_label],
        ];
        if (it.quality !== null) rows.push([ITEMSHOP_I18N.labelQuality, it.quality + '%']);
        if (it.bonus !== null) rows.push([ITEMSHOP_I18N.labelBonus, it.bonus]);
        if (it.dps !== null) rows.push(['DPS', it.dps]);
        if (it.speed !== null) rows.push(['Speed', it.speed]);
        if (it.af !== null) rows.push(['AF', it.af]);
        if (it.abs !== null) rows.push(['ABS', it.abs]);
        if (it.effect) rows.push([ITEMSHOP_I18N.labelEffect, it.effect, true]);
        if (it.class_restricted && Array.isArray(it.excluded_classes) && it.excluded_classes.length) {
            rows.push([ITEMSHOP_I18N.labelRestricted, it.excluded_classes.join(', '), true]);
        }
        if (Number(it.merchant_count) > 0) rows.push([ITEMSHOP_I18N.labelMerchants, it.merchant_count]);

        body.innerHTML = `
            <div id="itemshop-modal-name" class="itemshop-item-name tier-${tier}">${itemshopEsc(it.name)}</div>
            <div class="itemshop-item-rows">
                ${rows.map(([label, val, multiline]) => `
                    <div class="itemshop-item-row${multiline ? ' is-multiline' : ''}">
                        <span class="itemshop-item-row-label">${itemshopEsc(label)}</span>
                        <span class="itemshop-item-row-val">${itemshopEsc(val)}</span>
                    </div>
                `).join('')}
            </div>
            <a class="itemshop-detail-link" href="${itemshopEsc(it.detail_url)}">
                <i class="fas fa-map-location-dot"></i> ${itemshopEsc(Number(it.merchant_count) > 0 ? ITEMSHOP_I18N.showMerchants : it.type_label)}
            </a>
        `;

        if (!data.listings.length) {
            listings.innerHTML = `<div class="valor-spk-empty itemshop-empty">${itemshopEsc(ITEMSHOP_I18N.noOffersAvailable)}</div>`;
            return;
        }

        listings.innerHTML = data.listings.map(l => `
            <div class="shop-row itemshop-listing-row">
                <span class="itemshop-listing-seller">
                    ${l.seller_online ? '<i class="fas fa-circle itemshop-online-dot" title="Online"></i>' : '<i class="fas fa-circle itemshop-offline-dot" title="Offline"></i>'}
                    ${itemshopEsc(l.seller_label)} <small class="itemshop-listing-count">x${Number(l.count) || 0}</small>
                </span>
                <div class="itemshop-listing-right">
                    <span class="itemshop-listing-price">${itemshopEsc(l.price_formatted)}</span>
                    <button class="itemshop-buy-btn" data-buy-ref="${itemshopEsc(l.ref)}">
                        <i class="fas fa-coins"></i> ${itemshopEsc(ITEMSHOP_I18N.buy)}
                    </button>
                </div>
            </div>
        `).join('');
        listings.querySelectorAll('[data-buy-ref]').forEach(el => el.addEventListener('click', () => itemshopBuy(el.dataset.buyRef)));
    }).catch(e => {
        body.innerHTML = `<span class="itemshop-error-inline">${itemshopEsc(ITEMSHOP_I18N.connectionError)} ${itemshopEsc(e.message)}</span>`;
    });
}

function itemshopCloseModal() {
    document.getElementById('itemshop-modal-backdrop').setAttribute('hidden', '');
}

function itemshopBuy(ref) {
    if (!itemshopOnlineChar) {
        alert(ITEMSHOP_I18N.noCharOnlineAlert);
        return;
    }
    const resultEl = document.getElementById('itemshop-modal-result');
    resultEl.removeAttribute('hidden');
    resultEl.className = 'itemshop-modal-result';
    resultEl.innerHTML = `<i class="fas fa-circle-notch fa-spin"></i> ${itemshopEsc(ITEMSHOP_I18N.processingPurchase)}`;

    itemshopPost('purchase', { item_ref: ref, source: itemshopSource, count: 1 }).then(data => {
        if (data.ok) {
            resultEl.classList.add('is-success');
            resultEl.innerHTML = `<i class="fas fa-check-circle"></i> ${itemshopEsc(ITEMSHOP_I18N.purchaseSuccess)}`;
            setTimeout(() => {
                itemshopCloseModal();
                if (itemshopSource === 'system') itemshopLoadSystemList();
            }, 1500);
        } else {
            resultEl.classList.add('is-error');
            resultEl.innerHTML = '<i class="fas fa-times-circle"></i> ' + itemshopEsc(data.error || ITEMSHOP_I18N.purchaseFailed);
        }
    }).catch(e => {
        resultEl.classList.add('is-error');
        resultEl.textContent = ITEMSHOP_I18N.connectionError + ' ' + e.message;
    });
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') itemshopCloseModal(); });
document.getElementById('itemshop-modal-backdrop').addEventListener('click', e => {
    if (e.target.id === 'itemshop-modal-backdrop') itemshopCloseModal();
});

itemshopLoadStatus();
</script>
<?php endif; ?>
