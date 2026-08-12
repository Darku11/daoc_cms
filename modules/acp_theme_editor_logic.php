<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_ACP')) exit;
if ($userPriv < 4) exit;
require_once __DIR__ . '/../includes/theme_chain.php';

function te_css_braces_balanced(string $css): bool {
    return substr_count($css, '{') === substr_count($css, '}');
}

function te_save_history(PDO $db, string $module, string $theme, string $old_css, int $uid): void {
    if (trim($old_css) === '') return;
    try {
        $db->prepare("INSERT INTO aldhran_styles_history (module_key, theme_slug, css_content, changed_by, changed_at) VALUES (?, ?, ?, ?, NOW())")
           ->execute([$module, $theme, $old_css, $uid]);
        $db->prepare("DELETE FROM aldhran_styles_history WHERE module_key = ? AND theme_slug = ? AND id NOT IN (
            SELECT id FROM (SELECT id FROM aldhran_styles_history WHERE module_key = ? AND theme_slug = ? ORDER BY changed_at DESC LIMIT 30) x
        )")->execute([$module, $theme, $module, $theme]);
    } catch (\Throwable $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    checkToken($_POST['csrf_token'] ?? '');
    $action = $_POST['ajax_action'];

    // Theme the action refers to – default: currently active theme
    $theme_slug = preg_replace('/[^a-z0-9_-]/', '', $_POST['theme'] ?? ($GLOBALS['cms_settings']['active_theme'] ?? 'default'));
    if ($theme_slug === '') $theme_slug = 'default';

    // ── Load module ────────────────────────────────────────────
    if ($action === 'load_module') {
        $module = preg_replace('/[^a-z0-9_]/', '', $_POST['module'] ?? '');
        $chain  = array_reverse(aldhran_resolve_theme_chain($db, $theme_slug));
        $stmt   = $db->prepare("SELECT css_content FROM aldhran_styles WHERE module_key = ? AND theme_slug = ?");
        $resolved_from = $theme_slug;
        $css = null;
        foreach ($chain as $t) {
            $stmt->execute([$module, $t]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) { $css = $row['css_content']; $resolved_from = $t; break; }
        }
        if ($css === null) { echo json_encode(['ok' => false, 'error' => t('te_err_not_found', [], 'Module not found')]); exit; }
        echo json_encode(['ok' => true, 'css' => $css, 'inherited' => $resolved_from !== $theme_slug, 'inherited_from' => $resolved_from]);
        exit;
    }

    // ── Save module (real upsert, theme-scoped) ──────────
    if ($action === 'save_module') {
        $module = preg_replace('/[^a-z0-9_]/', '', $_POST['module'] ?? '');
        $css    = $_POST['css'] ?? '';

        if (preg_match('/<script/i', $css)) {
            echo json_encode(['ok' => false, 'error' => t('te_err_script_tags', [], 'Script tags not allowed in CSS')]); exit;
        }
        if (!te_css_braces_balanced($css)) {
            echo json_encode(['ok' => false, 'error' => t('te_err_unbalanced', [], 'Unbalanced braces - check your CSS before saving.')]); exit;
        }

        $old = $db->prepare("SELECT css_content FROM aldhran_styles WHERE module_key = ? AND theme_slug = ?");
        $old->execute([$module, $theme_slug]);
        $old_css = $old->fetchColumn();
        if ($old_css !== false) te_save_history($db, $module, $theme_slug, $old_css, $currentUserId);

        $db->prepare("
            INSERT INTO aldhran_styles (module_key, theme_slug, css_content, last_updated)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE css_content = VALUES(css_content), last_updated = NOW()
        ")->execute([$module, $theme_slug, $css]);

        aldhran_bump_css_version();
        aldhran_log("THEME_EDIT", "Updated module {$module} ({$theme_slug})", $currentUserId);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Create new module (theme-scoped) ─────────────────────
    if ($action === 'create_module') {
        $module = preg_replace('/[^a-z0-9_]/', '', $_POST['module'] ?? '');
        if (!$module) { echo json_encode(['ok' => false, 'error' => t('te_err_invalid_name', [], 'Invalid module name')]); exit; }
        try {
            $db->prepare("INSERT INTO aldhran_styles (module_key, theme_slug, css_content) VALUES (?, ?, '')")
               ->execute([$module, $theme_slug]);
            aldhran_bump_css_version();
            aldhran_log("THEME_CREATE", "Created module {$module} ({$theme_slug})", $currentUserId);
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => t('te_err_already_exists', [], 'Module already exists for this theme')]);
        }
        exit;
    }

    // ── Clone / inherit theme ────────────────────────────────
    if ($action === 'clone_theme') {
        $base = preg_replace('/[^a-z0-9_-]/', '', $_POST['base_theme'] ?? '');
        $new  = preg_replace('/[^a-z0-9_-]/', '', $_POST['new_theme'] ?? '');
        $mode = ($_POST['mode'] ?? 'duplicate') === 'inherit' ? 'inherit' : 'duplicate';

        if (!$base || !$new) {
            echo json_encode(['ok' => false, 'error' => t('te_err_missing_slugs', [], 'Missing theme slugs')]); exit;
        }

        if ($mode === 'inherit') {
            try {
                $db->prepare("INSERT INTO aldhran_themes (slug, label, parent_slug) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE parent_slug = VALUES(parent_slug)")
                   ->execute([$new, $new, $base]);
                aldhran_bump_css_version();
                aldhran_log("THEME_INHERIT", "Created child theme {$new} inheriting from {$base}", $currentUserId);
                echo json_encode(['ok' => true]);
            } catch (\Throwable $e) {
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
            exit;
        }

        try {
            $stmt = $db->prepare("SELECT module_key, css_content, description FROM aldhran_styles WHERE theme_slug = ?");
            $stmt->execute([$base]);
            $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($modules)) {
                echo json_encode(['ok' => false, 'error' => t('te_err_base_empty', [], 'Base theme not found or empty')]); exit;
            }

            $ins = $db->prepare("INSERT IGNORE INTO aldhran_styles (module_key, theme_slug, css_content, description) VALUES (?, ?, ?, ?)");
            foreach($modules as $m) {
                $ins->execute([$m['module_key'], $new, $m['css_content'], $m['description']]);
            }
            $db->prepare("INSERT INTO aldhran_themes (slug, label, parent_slug) VALUES (?, ?, NULL) ON DUPLICATE KEY UPDATE label = VALUES(label)")
               ->execute([$new, $new]);

            aldhran_bump_css_version();
            aldhran_log("THEME_CLONE", "Cloned theme {$base} to {$new}", $currentUserId);
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ── Export theme (as SQL string) ─────────────────────
    if ($action === 'export_theme') {
        $stmt = $db->prepare("SELECT module_key, css_content, description FROM aldhran_styles WHERE theme_slug = ?");
        $stmt->execute([$theme_slug]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!$rows) {
            echo json_encode(['ok' => false, 'error' => t('te_err_theme_empty', [], 'Theme is empty or not found.')]); exit;
        }

        $sql = "-- DAoC CMS Theme Export\n";
        $sql .= "-- Theme Slug: {$theme_slug}\n";
        $sql .= "-- Exported at: " . date('Y-m-d H:i:s') . "\n\n";
        $sql .= "DELETE FROM `aldhran_styles` WHERE `theme_slug` = " . $db->quote($theme_slug) . ";\n\n";
        
        foreach ($rows as $r) {
            $desc = $r['description'] ?? '';
            $sql .= "INSERT INTO `aldhran_styles` (`module_key`, `theme_slug`, `css_content`, `description`) VALUES (";
            $sql .= $db->quote($r['module_key']) . ", ";
            $sql .= $db->quote($theme_slug) . ", ";
            $sql .= $db->quote($r['css_content']) . ", ";
            $sql .= $db->quote($desc) . ");\n";
        }

        echo json_encode(['ok' => true, 'sql' => $sql]);
        exit;
    }

    // ── Upload theme SQL (strict security) ──────────────
    if ($action === 'upload_theme') {
        if (!isset($_FILES['theme_sql']) || $_FILES['theme_sql']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['ok' => false, 'error' => t('te_err_upload_failed', [], 'Upload failed or no file selected')]); exit;
        }
        
        $sql = file_get_contents($_FILES['theme_sql']['tmp_name']);
        if (empty(trim($sql))) {
            echo json_encode(['ok' => false, 'error' => t('te_err_file_empty', [], 'File is empty')]); exit;
        }

        $statements = explode(';', $sql);
        $valid_queries = 0;

        try {
            $db->beginTransaction();
            foreach ($statements as $query) {
                $q = trim($query);
                if (empty($q)) continue;

                if (preg_match('/^(INSERT\s+(IGNORE\s+)?INTO|DELETE\s+FROM)\s+`?aldhran_styles`?/i', $q)) {
                    $db->exec($q);
                    $valid_queries++;
                }
            }
            $db->commit();

            if ($valid_queries === 0) {
                echo json_encode(['ok' => false, 'error' => t('te_err_no_valid_queries', [], 'Security block: No valid queries for aldhran_styles found.')]); exit;
            }

            aldhran_bump_css_version();
            aldhran_log("THEME_UPLOAD", "Uploaded theme SQL ({$valid_queries} secure queries executed)", $currentUserId);
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(['ok' => false, 'error' => t('te_err_db', [], 'Database error: ') . $e->getMessage()]);
        }
        exit;
    }

    // ── Extract CSS variables with global fallback ──────────
    if ($action === 'list_variables') {
        $module = preg_replace('/[^a-z0-9_]/', '', $_POST['module'] ?? '');
        
        $stmt = $db->prepare("SELECT css_content FROM aldhran_styles WHERE module_key = ? AND theme_slug = ?");
        $stmt->execute([$module, $theme_slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $content = $row['css_content'] ?? '';
        $vars = [];
        
        // Search explicitly for variable declarations and assigned values.
        preg_match_all('/(-{2}[\w-]+)\s*:\s*([^;}\r\n]+);/', $content, $matches, PREG_SET_ORDER);
        
        // FALLBACK: if the current module defines NO variables, use main
        if (empty($matches) && $module !== 'main') {
            $fallback_mod = 'main';
            $stmt = $db->prepare("SELECT css_content FROM aldhran_styles WHERE module_key = ? AND theme_slug = ?");
            $stmt->execute([$fallback_mod, $theme_slug]);
            $fb_row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($fb_row) {
                $content = $fb_row['css_content'];
                $module = $fallback_mod; // update the source module for the JS
                preg_match_all('/(-{2}[\w-]+)\s*:\s*([^;}\r\n]+);/', $content, $matches, PREG_SET_ORDER);
            }
        }
        
        foreach ($matches as $m) {
            $vars[] = ['name' => trim($m[1]), 'value' => trim($m[2])];
        }
        
        echo json_encode(['ok' => true, 'variables' => $vars, 'source_module' => $module]);
        exit;
    }

    // ── Update a single variable (master-module-aware) ──────────
    if ($action === 'update_variable') {
        $module     = preg_replace('/[^a-z0-9_]/', '', $_POST['module'] ?? '');
        $var_name   = preg_replace('/[^a-z0-9_\-]/', '', $_POST['var_name'] ?? '');
        $var_val    = trim($_POST['var_value'] ?? '');
        $source_mod = preg_replace('/[^a-z0-9_]/', '', $_POST['source_module'] ?? $module);

        $stmt = $db->prepare("SELECT css_content FROM aldhran_styles WHERE module_key = ? AND theme_slug = ?");
        $stmt->execute([$source_mod, $theme_slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok' => false]); exit; }

        // Escape the replacement string because a literal $ or \ in $var_val
        // would otherwise be interpreted by preg_replace as a backreference.
        $new_css = preg_replace(
            '/' . preg_quote($var_name, '/') . '\s*:\s*[^;]+;/',
            preg_replace('/[\\\\$]/', '\\\\$0', $var_name . ': ' . $var_val . ';'),
            $row['css_content']
        );
        $db->prepare("UPDATE aldhran_styles SET css_content = ?, last_updated = NOW() WHERE module_key = ? AND theme_slug = ?")
           ->execute([$new_css, $source_mod, $theme_slug]);
        aldhran_bump_css_version();
        aldhran_log("THEME_VAR_UPDATE", "Updated {$var_name} in {$source_mod} ({$theme_slug})", $currentUserId);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Version history ───────────────────────────────────────
    if ($action === 'list_history') {
        $module = preg_replace('/[^a-z0-9_]/', '', $_POST['module'] ?? '');
        $stmt = $db->prepare("
            SELECT h.id, h.changed_at, u.username
            FROM aldhran_styles_history h
            LEFT JOIN users u ON u.id = h.changed_by
            WHERE h.module_key = ? AND h.theme_slug = ?
            ORDER BY h.changed_at DESC LIMIT 30
        ");
        $stmt->execute([$module, $theme_slug]);
        echo json_encode(['ok' => true, 'history' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'get_history_version') {
        $id = (int)($_POST['history_id'] ?? 0);
        $stmt = $db->prepare("SELECT css_content FROM aldhran_styles_history WHERE id = ? AND theme_slug = ?");
        $stmt->execute([$id, $theme_slug]);
        $css = $stmt->fetchColumn();
        if ($css === false) { echo json_encode(['ok' => false]); exit; }
        echo json_encode(['ok' => true, 'css' => $css]);
        exit;
    }

    if ($action === 'rollback_history') {
        $id     = (int)($_POST['history_id'] ?? 0);
        $module = preg_replace('/[^a-z0-9_]/', '', $_POST['module'] ?? '');

        $stmt = $db->prepare("SELECT css_content FROM aldhran_styles_history WHERE id = ? AND module_key = ? AND theme_slug = ?");
        $stmt->execute([$id, $module, $theme_slug]);
        $target_css = $stmt->fetchColumn();
        if ($target_css === false) { echo json_encode(['ok' => false, 'error' => t('te_err_not_found', [], 'Version not found')]); exit; }

        $cur = $db->prepare("SELECT css_content FROM aldhran_styles WHERE module_key = ? AND theme_slug = ?");
        $cur->execute([$module, $theme_slug]);
        $current_css = $cur->fetchColumn();
        if ($current_css !== false) te_save_history($db, $module, $theme_slug, $current_css, $currentUserId);

        $db->prepare("UPDATE aldhran_styles SET css_content = ?, last_updated = NOW() WHERE module_key = ? AND theme_slug = ?")
           ->execute([$target_css, $module, $theme_slug]);

        aldhran_bump_css_version();
        aldhran_log("THEME_ROLLBACK", "Rolled back {$module} ({$theme_slug}) to history #{$id}", $currentUserId);
        echo json_encode(['ok' => true, 'css' => $target_css]);
        exit;
    }

    // ── Theme registry / inheritance ────────────────────────────
    if ($action === 'list_themes') {
        try {
            $rows = $db->query("SELECT slug, label, parent_slug FROM aldhran_themes ORDER BY slug")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { $rows = []; }
        echo json_encode(['ok' => true, 'themes' => $rows]);
        exit;
    }

    if ($action === 'save_theme_meta') {
        $slug   = preg_replace('/[^a-z0-9_-]/', '', $_POST['slug'] ?? '');
        $label  = trim(substr($_POST['label'] ?? '', 0, 100));
        $parent = preg_replace('/[^a-z0-9_-]/', '', $_POST['parent_slug'] ?? '');
        if (!$slug) { echo json_encode(['ok' => false]); exit; }
        if ($parent === $slug) $parent = '';
        $db->prepare("
            INSERT INTO aldhran_themes (slug, label, parent_slug) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE label = VALUES(label), parent_slug = VALUES(parent_slug)
        ")->execute([$slug, $label ?: $slug, $parent ?: null]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete_theme') {
        $slug = preg_replace('/[^a-z0-9_-]/', '', $_POST['slug'] ?? '');

        if (!$slug) { echo json_encode(['ok' => false, 'error' => t('te_err_missing_slugs', [], 'Missing theme slug')]); exit; }
        if ($slug === 'default') {
            echo json_encode(['ok' => false, 'error' => t('te_err_delete_default', [], 'The default theme cannot be deleted.')]); exit;
        }
        if ($slug === ($GLOBALS['cms_settings']['active_theme'] ?? 'default')) {
            echo json_encode(['ok' => false, 'error' => t('te_err_delete_active', [], 'This theme is currently active on the live site and cannot be deleted. Switch the active theme first.')]); exit;
        }

        try {
            $stmt = $db->prepare("SELECT slug FROM aldhran_themes WHERE parent_slug = ?");
            $stmt->execute([$slug]);
            $children = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($children)) {
                echo json_encode(['ok' => false, 'error' => t('te_err_delete_has_children', [], 'Other themes inherit from this one: ') . implode(', ', $children)]); exit;
            }

            $db->beginTransaction();
            $db->prepare("DELETE FROM aldhran_styles WHERE theme_slug = ?")->execute([$slug]);
            $db->prepare("DELETE FROM aldhran_styles_history WHERE theme_slug = ?")->execute([$slug]);
            $db->prepare("DELETE FROM aldhran_themes WHERE slug = ?")->execute([$slug]);
            $db->commit();

            aldhran_bump_css_version();
            aldhran_log("THEME_DELETE", "Deleted theme {$slug}", $currentUserId);
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ── Contrast checker (WCAG) ──────────────────────────────────
    if ($action === 'check_contrast') {
        $fg = trim($_POST['fg'] ?? '');
        $bg = trim($_POST['bg'] ?? '');

        $toRgb = function (string $c): ?array {
            $c = trim($c);
            if (preg_match('/^#([0-9a-f]{3})$/i', $c, $m)) {
                $h = $m[1];
                return [hexdec($h[0].$h[0]), hexdec($h[1].$h[1]), hexdec($h[2].$h[2])];
            }
            if (preg_match('/^#([0-9a-f]{6})$/i', $c, $m)) {
                $h = $m[1];
                return [hexdec(substr($h,0,2)), hexdec(substr($h,2,2)), hexdec(substr($h,4,2))];
            }
            if (preg_match('/^rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/i', $c, $m)) {
                return [(int)$m[1], (int)$m[2], (int)$m[3]];
            }
            return null;
        };
        $luminance = function (array $rgb): float {
            $chan = array_map(function ($v) {
                $v /= 255;
                return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
            }, $rgb);
            return 0.2126 * $chan[0] + 0.7152 * $chan[1] + 0.0722 * $chan[2];
        };

        $fgRgb = $toRgb($fg);
        $bgRgb = $toRgb($bg);
        if (!$fgRgb || !$bgRgb) { echo json_encode(['ok' => false, 'error' => t('te_err_bad_color', [], 'Could not parse one of the colors')]); exit; }

        $l1 = $luminance($fgRgb) + 0.05;
        $l2 = $luminance($bgRgb) + 0.05;
        $ratio = round(max($l1, $l2) / min($l1, $l2), 2);

        echo json_encode([
            'ok'          => true,
            'ratio'       => $ratio,
            'aa_normal'   => $ratio >= 4.5,
            'aa_large'    => $ratio >= 3.0,
            'aaa_normal'  => $ratio >= 7.0,
        ]);
        exit;
    }

    // ── Style Guide: resolved CSS for the whole chain ───────────
    if ($action === 'get_style_guide_css') {
        $chain = aldhran_resolve_theme_chain($db, $theme_slug);
        $ph    = implode(',', array_fill(0, count($chain), '?'));
        try {
            $stmt = $db->prepare("
                SELECT module_key, css_content, theme_slug FROM aldhran_styles
                WHERE theme_slug IN ($ph) AND module_key NOT LIKE 'acp\\_%' AND is_active = 1
                ORDER BY FIELD(theme_slug, " . implode(',', array_map(fn($t) => $db->quote($t), $chain)) . ")
            ");
            $stmt->execute($chain);
            $css = '';
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $css .= $r['css_content'] . "\n";
            }
            echo json_encode(['ok' => true, 'css' => $css]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // AI ACTIONS
    if (str_starts_with($action, 'ai_') && isset($botSettings) && $botSettings->isActive() && class_exists('AiManager')) {
        $ai = new AiManager($db, $botSettings, $currentUserId, $userPriv);

        if ($action === 'ai_suggest_css') {
            $current_css = trim($_POST['current_css'] ?? '');
            $module      = preg_replace('/[^a-z0-9_]/', '', $_POST['module'] ?? '');
            $request     = trim($_POST['request']     ?? '');
            $result = $ai->request('theme_editor', 'suggest_css', [
                'module'      => $module,
                'current_css' => substr($current_css, 0, 3000),
                'request'     => $request,
                'instruction' => "Suggest CSS improvements for this theme module. Use CSS custom properties (--variables). Request: {$request}. Return only valid CSS, no JavaScript, no inline styles.",
            ], ['save_suggestion' => true]);
            echo json_encode($result); exit;
        }

        if ($action === 'ai_explain_variable') {
            $css_var = trim($_POST['css_variable'] ?? '');
            $value   = trim($_POST['current_value'] ?? '');
            $result = $ai->request('theme_editor', 'explain_variable', [
                'css_variable'  => $css_var,
                'current_value' => $value,
                'instruction'   => "Explain what this CSS variable does in a dark-themed game server CMS. Current value: {$value}. Where is it used? What visual effect does changing it have? Suggest 2-3 alternative values.",
            ]);
            echo json_encode($result); exit;
        }
    }

    echo json_encode(['ok' => false, 'error' => t('te_err_unknown', [], 'Unknown action')]);
    exit;
}
