<?php
if (!function_exists('aldhran_resolve_theme_chain')) {
function aldhran_resolve_theme_chain(PDO $db, string $theme_slug): array {
    $theme_slug = preg_replace('/[^a-z0-9_-]/', '', $theme_slug);
    if ($theme_slug === '' || $theme_slug === 'default') return ['default'];

    $chain = [];
    $current = $theme_slug;
    $seen = [];

    try {
        $stmt = $db->prepare("SELECT parent_slug FROM aldhran_themes WHERE slug = ?");
        while ($current && !isset($seen[$current])) {
            $seen[$current] = true;
            array_unshift($chain, $current);
            $stmt->execute([$current]);
            $parent = $stmt->fetchColumn();
            $current = $parent ?: null;
        }
    } catch (\Throwable $e) {
        return ['default', $theme_slug];
    }

    if (!in_array('default', $chain, true)) {
        array_unshift($chain, 'default');
    }
    return $chain;
}
}
