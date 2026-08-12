<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_ACP')) exit;
?>
<div class="gc-wrap">
    <!-- Mobile Select Dropdown -->
    <select id="gc-mobile-select" class="gc-mobile-select">
        <option value="weapons" selected><?= t('gc.weapons', [], 'Weapon Types') ?></option>
        <option value="wslots"><?= t('gc.wslots', [], 'Weapon Slots') ?></option>
        <option value="jewelry"><?= t('gc.jewelry', [], 'Jewelry') ?></option>
        <option value="classes"><?= t('gc.classes', [], 'Character Classes') ?></option>
        <option value="damage"><?= t('gc.damage', [], 'Damage Types') ?></option>
        <option value="armorslots"><?= t('gc.armorslots', [], 'Armor Slots') ?></option>
        <option value="resists"><?= t('gc.resists', [], 'Resistances') ?></option>
        <option value="stats"><?= t('gc.stats', [], 'Stats') ?></option>
        <option value="colors"><?= t('gc.colors', [], 'Colors') ?></option>
        <option value="instruments"><?= t('gc.instruments', [], 'Instrument Types') ?></option>
        <option value="speclines"><?= t('gc.speclines', [], 'Speclines') ?></option>
    </select>

    <!-- Desktop Sidebar -->
    <div class="gc-sidebar">
        <div class="gc-tab active" data-cat="weapons">
            <div class="gc-tab-icon"><i class="fas fa-sword"></i></div>
            <?= t('gc.weapons', [], 'Weapon Types') ?>
        </div>
        <div class="gc-tab" data-cat="wslots">
            <div class="gc-tab-icon"><i class="fas fa-hand-paper"></i></div>
            <?= t('gc.wslots', [], 'Weapon Slots') ?>
        </div>
        <div class="gc-tab" data-cat="jewelry">
            <div class="gc-tab-icon"><i class="fas fa-gem"></i></div>
            <?= t('gc.jewelry', [], 'Jewelry') ?>
        </div>
        <div class="gc-tab" data-cat="classes">
            <div class="gc-tab-icon"><i class="fas fa-user-ninja"></i></div>
            <?= t('gc.classes', [], 'Character Classes') ?>
        </div>
        <div class="gc-tab" data-cat="damage">
            <div class="gc-tab-icon"><i class="fas fa-fire"></i></div>
            <?= t('gc.damage', [], 'Damage Types') ?>
        </div>
        <div class="gc-tab" data-cat="armorslots">
            <div class="gc-tab-icon"><i class="fas fa-shield-halved"></i></div>
            <?= t('gc.armorslots', [], 'Armor Slots') ?>
        </div>
        <div class="gc-tab" data-cat="resists">
            <div class="gc-tab-icon"><i class="fas fa-shield-virus"></i></div>
            <?= t('gc.resists', [], 'Resistances') ?>
        </div>
        <div class="gc-tab" data-cat="stats">
            <div class="gc-tab-icon"><i class="fas fa-chart-simple"></i></div>
            <?= t('gc.stats', [], 'Stats') ?>
        </div>
        <div class="gc-tab" data-cat="colors">
            <div class="gc-tab-icon"><i class="fas fa-palette"></i></div>
            <?= t('gc.colors', [], 'Colors') ?>
        </div>
        <div class="gc-tab" data-cat="instruments">
            <div class="gc-tab-icon"><i class="fas fa-guitar"></i></div>
            <?= t('gc.instruments', [], 'Instrument Types') ?>
        </div>
        <div class="gc-tab" data-cat="speclines">
            <div class="gc-tab-icon"><i class="fas fa-book-sparkles"></i></div>
            <?= t('gc.speclines', [], 'Speclines') ?>
        </div>
    </div>
    
    <div class="gc-main">
        <div class="gc-card">
            <div class="gc-search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="gc-search" placeholder="<?= t('gc.search_placeholder', [], 'Search in current category...') ?>" autocomplete="off">
            </div>
            
            <div class="gc-table-responsive acp-s-235969fe">
                <table class="gc-table">
                    <thead>
                        <tr>
                            <th class="acp-s-ee0435f7">ID</th>
                            <th><?= t('gc.name_identifier', [], 'Name / Identifier') ?></th>
                        </tr>
                    </thead>
                    <tbody id="gc-tbody">
                    </tbody>
                </table>
            </div>

            <div class="gc-pagination">
                <div class="gc-page-info" id="gc-page-info"></div>
                <div class="gc-page-controls">
                    <button class="gc-page-btn" id="gc-prev"><i class="fas fa-chevron-left"></i></button>
                    <button class="gc-page-btn" id="gc-next"><i class="fas fa-chevron-right"></i></button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const gcData = <?= json_encode($gcData) ?>;
    let currentCat = 'weapons';
    let searchQuery = '';
    let currentPage = 1;
    const perPage = 15;
    
    const tabs = document.querySelectorAll('.gc-tab');
    const mobileSelect = document.getElementById('gc-mobile-select');
    const searchInput = document.getElementById('gc-search');
    const tbody = document.getElementById('gc-tbody');
    const prevBtn = document.getElementById('gc-prev');
    const nextBtn = document.getElementById('gc-next');
    const pageInfo = document.getElementById('gc-page-info');

    function render() {
        const data = gcData[currentCat] || [];
        const filtered = data.filter(item => 
            item.name.toLowerCase().includes(searchQuery.toLowerCase()) || 
            item.id.toString().includes(searchQuery)
        );
        
        const totalPages = Math.ceil(filtered.length / perPage) || 1;
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;
        
        const start = (currentPage - 1) * perPage;
        const paginated = filtered.slice(start, start + perPage);
        
        tbody.innerHTML = '';
        if (paginated.length === 0) {
            tbody.innerHTML = '<tr><td colspan="2" class="acp-s-ca0c5b75"><?= t("gc.no_entries_found", [], "No entries found.") ?></td></tr>';
        } else {
            paginated.forEach(item => {
                const tr = document.createElement('tr');
                tr.innerHTML = `<td class="acp-s-f433f38c">${item.id}</td><td>${item.name}</td>`;
                tbody.appendChild(tr);
            });
        }
        
        pageInfo.textContent = `<?= t("gc.page", [], "Page") ?> ${currentPage} <?= t("gc.of", [], "of") ?> ${totalPages} (${filtered.length} <?= t("gc.entries", [], "entries") ?>)`;
        prevBtn.disabled = currentPage === 1;
        nextBtn.disabled = currentPage === totalPages;
    }

    function changeCategory(cat) {
        currentCat = cat;
        currentPage = 1;
        searchQuery = '';
        searchInput.value = '';
        
        tabs.forEach(t => {
            if (t.dataset.cat === cat) {
                t.classList.add('active');
            } else {
                t.classList.remove('active');
            }
        });
        if (mobileSelect) {
            mobileSelect.value = cat;
        }
        render();
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            changeCategory(tab.dataset.cat);
        });
    });

    if (mobileSelect) {
        mobileSelect.addEventListener('change', (e) => {
            changeCategory(e.target.value);
        });
    }

    searchInput.addEventListener('input', (e) => {
        searchQuery = e.target.value;
        currentPage = 1;
        render();
    });

    prevBtn.addEventListener('click', () => {
        if (currentPage > 1) {
            currentPage--;
            render();
        }
    });

    nextBtn.addEventListener('click', () => {
        currentPage++;
        render();
    });

    render();
});
</script>