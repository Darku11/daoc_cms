<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Hero / page-header partial
 * - all pages (incl. home) -> compact hero head (image/gradient + title + breadcrumb)
 * - $hero_disabled_slugs lets a page skip the hero entirely
 */
if (!defined('IN_CMS')) { exit; }

$hero_disabled_slugs = ['login', 'register', 'acp'];

if (in_array($page_slug, $hero_disabled_slugs, true)) {
    return;
}

// Per-page hero image, maintained in the Content Manager. When a page has
// none, no background-image is emitted at all so the theme's own page head
// shows through instead of a request for a file that may not exist.
$hero_bg    = !empty($data['hero_image']) ? h($data['hero_image']) : '';
$hero_style = $hero_bg !== '' ? " style=\"background-image:url('{$hero_bg}');\"" : '';
$hero_title = $article_title ?? h(ucwords(str_replace('_', ' ', $page_slug)));
?>

<section class="cms-hero cms-hero--compact<?= $hero_bg !== '' ? ' cms-hero--has-image' : '' ?>"<?= $hero_style ?>>
    <div class="cms-hero-overlay cms-hero-overlay--compact"></div>
    <div class="cms-hero-embers" aria-hidden="true">
        <span></span><span></span><span></span><span></span><span></span>
    </div>
    <div class="cms-hero-frame" aria-hidden="true"></div>
    <div class="cms-hero-compact-content">
        <nav class="cms-hero-breadcrumb">
            <a href="index.php?p=home"><?= t('sidebar.nav_home', [], 'Home') ?></a>
            <span class="cms-hero-breadcrumb-sep">/</span>
            <span><?= $hero_title ?></span>
        </nav>
        <h1 class="cms-hero-compact-title"><?= $hero_title ?></h1>
        <div class="cms-hero-realm-bar" aria-hidden="true"></div>
    </div>
</section>