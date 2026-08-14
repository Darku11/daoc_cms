<?php
// SPDX-License-Identifier: GPL-3.0-only
return static function (PDO $db): void {
    $db->exec("DROP TABLE IF EXISTS `cms_live_events`");
    $db->exec("DROP TABLE IF EXISTS `live_events`");

    $stmt = $db->prepare(
        "INSERT INTO settings (setting_key, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)"
    );
    $stmt->execute(['header_search_enabled', '1']);

    $pageTable = $db->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'pages' LIMIT 1"
    )->fetchColumn();
    if ($pageTable !== false) {
        $db->exec(
            "UPDATE `pages`
             SET content = REPLACE(content,
                 'make sure your Dawn of Light connection is configured.',
                 'make sure your Dawn of Light or OpenDAoC connection is configured.')
             WHERE slug = 'home'"
        );
        $db->exec(
            "UPDATE `pages`
             SET content = REPLACE(content,
                 '<b>2. DOL Integration</b>',
                 '<b>2. DOL / OpenDAoC Integration</b>')
             WHERE slug = 'home'"
        );
        $db->exec(
            "UPDATE `pages`
             SET content = REPLACE(content,
                 'https://aldhran-server.eu/index.php?p=spike',
                 'https://aldhran-server.eu/cms_doc.html')
             WHERE slug = 'home'"
        );
    }

    $appendStyle = static function (string $module, string $marker, string $css, string $description) use ($db): void {
        $stmt = $db->prepare(
            "SELECT id, css_content FROM aldhran_styles
             WHERE module_key = ? AND theme_slug = 'default' LIMIT 1"
        );
        $stmt->execute([$module]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $block = "/* {$marker} */\n" . trim($css) . "\n";

        if ($row) {
            $existing = (string)$row['css_content'];
            if (strpos($existing, "/* {$marker} */") === false) {
                $update = $db->prepare(
                    "UPDATE aldhran_styles
                     SET css_content = CONCAT(RTRIM(css_content), ?), description = ?
                     WHERE id = ?"
                );
                $update->execute(["\n\n" . $block, $description, (int)$row['id']]);
            }
            return;
        }

        $insert = $db->prepare(
            "INSERT INTO aldhran_styles (module_key, theme_slug, css_content, description, is_active)
             VALUES (?, 'default', ?, ?, 1)"
        );
        $insert->execute([$module, $block, $description]);
    };

    $appendStyle('team', 'DAOC-CMS-1.0-TEAM-COMPACT', <<<'CSS'
.team-grid {
    grid-template-columns: repeat(auto-fit, minmax(220px, 280px));
    justify-content: center;
    gap: 16px;
    padding: 12px 0;
    background: transparent;
}
.team-card {
    display: grid;
    grid-template-columns: 68px minmax(0, 1fr);
    grid-template-rows: auto auto 1fr;
    column-gap: 15px;
    align-items: center;
    min-height: 104px;
    padding: 17px 18px;
    text-align: left;
    background: rgba(8,8,9,0.72);
    border: 1px solid rgba(197,160,89,0.12);
    box-shadow: 0 10px 28px rgba(0,0,0,0.22);
}
.team-card:hover {
    transform: translateY(-2px);
    border-color: rgba(197,160,89,0.28);
    box-shadow: 0 14px 34px rgba(0,0,0,0.3);
}
.team-card::before {
    height: 1px;
    opacity: .42;
}
.team-avatar-wrap {
    grid-column: 1;
    grid-row: 1 / 4;
    width: 64px;
    height: 64px;
    margin: 0;
}
.team-avatar-wrap::before {
    inset: -2px;
    clip-path: none;
    border-radius: 50%;
    background: rgba(197,160,89,0.24);
    animation: none !important;
}
.team-avatar,
.team-avatar-placeholder {
    width: 60px;
    height: 60px;
    clip-path: none;
    border-radius: 50%;
}
.team-name {
    grid-column: 2;
    margin: 0 0 2px;
    font-size: 1rem;
    line-height: 1.25;
}
.team-role {
    grid-column: 2;
    margin: 0;
    font-size: .7rem;
    letter-spacing: 1.1px;
}
.team-lang-row {
    grid-column: 2;
    justify-content: flex-start;
    margin-top: 8px;
    gap: 5px;
}
.team-lang-tag {
    padding: 2px 7px;
    font-size: .62rem;
}
@media screen and (max-width: 560px) {
    .team-grid { grid-template-columns: 1fr; }
    .team-card { max-width: 100%; }
}
CSS, 'Compact team cards');

    $appendStyle('header_nav', 'DAOC-CMS-1.0-HEADER-SEARCH', <<<'CSS'
.header-search-form {
    position: relative;
    display: flex;
    align-items: center;
}
.header-search-input {
    width: 140px;
    padding: 6px 12px 6px 30px;
    border: 1px solid rgba(197,160,89,0.3);
    border-radius: 20px;
    outline: none;
    background: rgba(0,0,0,0.5);
    color: #ccc;
    font-family: sans-serif;
    font-size: 11px;
    transition: width .25s ease, background .25s ease, border-color .25s ease;
}
.header-search-input:focus {
    width: 200px;
    background: rgba(0,0,0,0.8);
    border-color: rgba(197,160,89,0.8);
}
.header-search-icon {
    position: absolute;
    left: 12px;
    color: rgba(197,160,89,0.6);
    font-size: 11px;
    pointer-events: none;
}
@media (max-width: 768px) {
    #headerNav,
    .header-logo-container .hide-mobile,
    .header-search-form {
        display: none !important;
    }
}
CSS, 'Header navigation and optional search');

    $appendStyle('header_user_dropdown', 'DAOC-CMS-1.0-HEADER-COUNT', <<<'CSS'
.user-dropdown-count {
    margin-left: 6px;
    padding: 1px 6px;
    border-radius: 8px;
    background: var(--gold, #c5a059);
    color: #000;
    font-family: sans-serif;
    font-size: 9px;
    font-weight: 700;
}
CSS, 'Header user dropdown');

    $appendStyle('pve_bestiary', 'DAOC-CMS-1.0-PVE-BESTIARY', <<<'CSS'
.loc-row,
.loot-row { display: none; }
.bestiary-item-link {
    color: var(--gold, #c5a059);
    text-decoration: none;
}
.bestiary-item-link:hover { text-decoration: underline; }
.bestiary-item-meta {
    display: block;
    margin-top: 3px;
    color: #666;
    font-size: .72em;
}
.mob-card[role="button"]:focus-visible {
    outline: 1px solid var(--gold, #c5a059);
    outline-offset: 3px;
}
CSS, 'PvE bestiary and loot details');

    $appendStyle('pve_item', 'DAOC-CMS-1.0-PVE-ITEM', <<<'CSS'
.pve-item-detail-page { max-width: 1040px; }
.pve-item-back {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    margin-bottom: 24px;
    color: #777;
    text-decoration: none;
    font-size: .72em;
    letter-spacing: 1px;
    text-transform: uppercase;
}
.pve-item-back:hover { color: var(--gold, #c5a059); }
.pve-item-empty {
    padding: 28px;
    border: 1px solid #171717;
    color: #777;
    text-align: center;
}
.pve-item-card {
    padding: 28px;
    border: 1px solid rgba(197,160,89,.16);
    background: rgba(7,7,8,.7);
    box-shadow: 0 18px 50px rgba(0,0,0,.28);
}
.pve-item-heading {
    display: flex;
    justify-content: space-between;
    gap: 24px;
    align-items: flex-start;
    padding-bottom: 22px;
    border-bottom: 1px solid rgba(197,160,89,.12);
}
.pve-item-heading h2 {
    margin: 4px 0;
    color: #e7e1d5;
    font-family: 'Cinzel', serif;
    font-size: 1.7rem;
    font-weight: 500;
}
.pve-item-kicker,
.pve-item-id {
    color: #6f685c;
    font-size: .7rem;
    letter-spacing: 1.3px;
    text-transform: uppercase;
}
.pve-item-icon {
    width: 64px;
    height: 64px;
    object-fit: contain;
    image-rendering: auto;
}
.pve-item-stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
    gap: 10px;
    margin: 20px 0;
}
.pve-item-stat {
    padding: 12px 14px;
    border: 1px solid #171717;
    background: rgba(255,255,255,.015);
}
.pve-item-stat span {
    display: block;
    margin-bottom: 5px;
    color: #625d55;
    font-size: .66rem;
    letter-spacing: 1px;
    text-transform: uppercase;
}
.pve-item-stat strong { color: #d6c18c; font-size: 1.05rem; }
.pve-item-description { color: #8f8a82; line-height: 1.65; }
.pve-item-section {
    margin-top: 26px;
    padding-top: 20px;
    border-top: 1px solid #151515;
}
.pve-item-section h3,
.pve-item-section h4 {
    margin: 0;
    color: #b9aa84;
    font-family: 'Cinzel', serif;
    font-weight: 500;
}
.pve-item-section-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.pve-item-merchant-count {
    min-width: 28px;
    padding: 3px 8px;
    border: 1px solid rgba(197,160,89,.2);
    color: var(--gold, #c5a059);
    text-align: center;
}
.pve-item-access-note,
.pve-item-access-ok { color: #777; line-height: 1.5; }
.pve-item-class-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.pve-item-class-list span {
    padding: 4px 8px;
    border: 1px solid rgba(180,80,80,.22);
    background: rgba(180,80,80,.05);
    color: #b98383;
    font-size: .72rem;
}
.pve-merchant-toggle {
    margin-top: 12px;
    padding: 8px 14px;
    border: 1px solid rgba(197,160,89,.35);
    background: transparent;
    color: var(--gold, #c5a059);
    cursor: pointer;
}
.pve-merchant-panel { margin-top: 18px; }
.pve-merchant-zone + .pve-merchant-zone { margin-top: 24px; }
.pve-merchant-zone h4 { margin-bottom: 10px; }
.pve-merchant-map-wrap {
    overflow: hidden;
    border: 1px solid #1b1b1b;
    background: #050505;
}
.pve-merchant-map {
    display: block;
    width: 100%;
    min-height: 300px;
}
.pve-merchant-marker {
    fill: #d8b65f;
    stroke: #120e06;
    stroke-width: .55;
    vector-effect: non-scaling-stroke;
}
.pve-merchant-list {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 8px;
    margin-top: 10px;
}
.pve-merchant-entry {
    padding: 9px 11px;
    border: 1px solid #171717;
}
.pve-merchant-entry strong,
.pve-merchant-entry span { display: block; }
.pve-merchant-entry strong { color: #aaa; }
.pve-merchant-entry span { margin-top: 3px; color: #555; font-size: .7rem; }
@media (max-width: 640px) {
    .pve-item-card { padding: 20px 16px; }
    .pve-merchant-map { min-height: 220px; }
}
CSS, 'PvE item details and merchant maps');

    $appendStyle('itemshop', 'DAOC-CMS-1.0-ITEMSHOP', <<<'CSS'
.itemshop-back-wrap { margin-bottom: 25px; }
.itemshop-title { margin-bottom: 15px; }
.itemshop-intro {
    max-width: 720px;
    margin-bottom: 20px;
    line-height: 1.7;
    white-space: normal;
    overflow: visible;
    text-overflow: clip;
}
.itemshop-pagination { justify-content: center; margin-top: 20px; }
.itemshop-result-button {
    width: 100%;
    border: 0;
    border-bottom: 1px solid #111;
    border-radius: 0;
    background: transparent;
    text-align: left;
}
.itemshop-result-button:last-child { border-bottom: 0; }
.itemshop-detail-link {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    margin-top: 14px;
    color: var(--gold, #c5a059);
    font-size: .72rem;
    text-decoration: none;
    text-transform: uppercase;
    letter-spacing: 1px;
}
.itemshop-detail-link:hover { text-decoration: underline; }
.itemshop-modal-overlay[hidden],
#itemshop-system-catalog[hidden],
.itemshop-modal-result[hidden] { display: none !important; }
CSS, 'Itemshop and PvE item detail links');

    $stmt->execute(['settings_version', (string)time()]);
};
