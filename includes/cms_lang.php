<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * CMS Language Parser - DAoC CMS
 */

// ============================================================
// 1. DETERMINE LANGUAGE
// Priority: Session → DB default → 'en' emergency fallback
// Cache invalidation in db.php clears $_SESSION['lang'],
// so the language can be loaded from the database again.
// ============================================================
$allowed_langs = $GLOBALS['cms_allowed_languages'] ?? ['en'];
$default_lang  = $GLOBALS['cms_settings']['default_language'] ?? 'en';

if (!isset($_SESSION['lang']) || !in_array($_SESSION['lang'], $allowed_langs, true)) {
    $_SESSION['lang'] = $default_lang;
}
$cms_lang = $_SESSION['lang'];

// Global runtime cache (this request only, no session overhead)
$GLOBALS['_cms_lang_cache'] = [];

// ============================================================
// 2. LOAD CONTEXT
// Loads translations from the session (cached) or freshly from
// the DB. The session cache is invalidated in db.php when
// settings_version changes.
// ============================================================
function cms_load_language_context(string $context): void
{
    global $db;
    $lang = $_SESSION['lang'];

    if (!isset($_SESSION['cms_cache']['lang'][$lang][$context])) {
        $stmt = $db->prepare("
            SELECT var_key, var_value 
            FROM cms_translations 
            WHERE lang_code = ? AND var_context = ?
        ");
        $stmt->execute([$lang, $context]);
        $_SESSION['cms_cache']['lang'][$lang][$context] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    }

    // Merge translations into the global runtime cache.
    $GLOBALS['_cms_lang_cache'] = array_merge(
        $GLOBALS['_cms_lang_cache'],
        $_SESSION['cms_cache']['lang'][$lang][$context]
    );
}

// ============================================================
// 3. PARSER FUNCTIONS
// ============================================================
function cms_t(string $key, array $replacements = [], string $fallback = ''): string
{
    $value = $GLOBALS['_cms_lang_cache'][$key] ?? null;

    if ($value === null) {
        $output = ($fallback !== '') ? $fallback : "[MISSING: $key]";
        return (defined('CMS_DEBUG') && CMS_DEBUG)
            ? "<span class=\"cms-debug-missing\">$output</span>"
            : $output;
    }

    if (!empty($replacements)) {
        $value = strtr($value, $replacements);
    }

    if (defined('CMS_DEBUG') && CMS_DEBUG) {
        return "<span class=\"cms-debug-known\" title='Key: $key'>$value</span>";
    }

    return $value;
}

// Short alias.
function t(string $key, array $replacements = [], string $fallback = ''): string {
    return cms_t($key, $replacements, $fallback);
}

// Direct output helper.
function te(string $key, array $replacements = [], string $fallback = ''): void {
    echo cms_t($key, $replacements, $fallback);
}

// ============================================================
// 4. AUTOMATIC START
// Always load the core context for the header, footer, and sidebar.
// ============================================================
cms_load_language_context('core');

$page_context = preg_replace('/[^a-z0-9_]/', '', strtolower($_GET['p'] ?? ''));
$valid_contexts = ['quests', 'items', 'admin', 'profile', 'search'];
if (in_array($page_context, $valid_contexts)) {
    cms_load_language_context($page_context);
}
