<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_ACP')) exit;

$msg = '';
$csrf = generateToken();

$priv_levels = [0 => 'Everyone', 1 => 'Player', 2 => 'Associate', 3 => 'GM', 4 => 'Admin', 5 => 'Super Admin'];
$allowed_langs = $GLOBALS['cms_allowed_languages'] ?? ['en'];

$media_dir = __DIR__ . '/../assets/img/media/';
if (!is_dir($media_dir)) @mkdir($media_dir, 0755, true);

function sanitize_trumbowyg_html(string $html): string {
    if (empty(trim($html))) return '';
    $html = preg_replace('/\s+style\s*=\s*(["\'])[^"\']*\1/i', '', $html);
    $html = preg_replace('/\s+(color|face|bgcolor|size|align|valign|border|cellpadding|cellspacing)\s*=\s*(["\'])[^"\']*\2/i', '', $html);
    $html = preg_replace('/<(p|span|div|strong|em)[^>]*>\s*(<br\s*\/?>)?\s*<\/\1>/i', '', $html);
    $html = preg_replace('/(<br\s*\/?>\s*){3,}/i', '<br><br>', $html);
    $html = preg_replace('/^(&nbsp;\s*)+/m', '', $html);
    return trim($html);
}

function cm_diff_render(string $oldCss, string $newCss): array {
    return [explode("\n", $oldCss), explode("\n", $newCss)];
}

// ── AJAX: content diff ──────────────────────────────────────────
if (isset($_GET['cm_ajax']) && $_GET['cm_ajax'] === 'diff' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    checkToken($_POST['csrf_token'] ?? '');
    $hist_id = (int)($_POST['history_id'] ?? 0);
    $stmt = $db->prepare("SELECT h.content, p.content AS current_content FROM pages_history h JOIN pages p ON p.slug = h.page_slug WHERE h.id = ?");
    $stmt->execute([$hist_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['ok' => false]); exit; }
    echo json_encode(['ok' => true, 'old' => $row['content'], 'new' => $row['current_content']]);
    exit;
}

// ── AJAX: media library ─────────────────────────────────────────
if (isset($_GET['cm_ajax']) && $_GET['cm_ajax'] === 'media_list') {
    header('Content-Type: application/json');
    $rows = $db->query("SELECT id, filename, stored_name, filesize, mime_type, uploaded_at FROM pages_media ORDER BY uploaded_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['url'] = 'assets/img/media/' . $r['stored_name']; }
    echo json_encode(['ok' => true, 'media' => $rows]);
    exit;
}

if (isset($_POST['media_upload']) && $userPriv >= 4) {
    header('Content-Type: application/json');
    checkToken($_POST['csrf_token'] ?? '');
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'Upload failed']); exit;
    }
    $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $real_mime = finfo_file($finfo, $_FILES['file']['tmp_name']);
    finfo_close($finfo);
    if (!in_array($real_mime, $allowed_mimes, true)) {
        echo json_encode(['ok' => false, 'error' => 'File type not allowed']); exit;
    }
    if ($_FILES['file']['size'] > 5 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'error' => 'File too large (max 5MB)']); exit;
    }
    $orig_name = $_FILES['file']['name'];
    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext);
    $stored = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $media_dir . $stored)) {
        echo json_encode(['ok' => false, 'error' => 'Could not save file']); exit;
    }
    $db->prepare("INSERT INTO pages_media (filename, stored_name, filesize, mime_type, uploaded_by) VALUES (?, ?, ?, ?, ?)")
       ->execute([$orig_name, $stored, $_FILES['file']['size'], $real_mime, $_SESSION['user_id'] ?? 0]);
    aldhran_log("MEDIA_UPLOAD", "Uploaded {$orig_name}", $_SESSION['user_id'] ?? 0);
    echo json_encode(['ok' => true, 'url' => 'assets/img/media/' . $stored]);
    exit;
}

if (isset($_GET['media_delete']) && $userPriv >= 4) {
    checkToken($_GET['csrf'] ?? '');
    $id = (int)$_GET['media_delete'];
    $stmt = $db->prepare("SELECT stored_name FROM pages_media WHERE id = ?");
    $stmt->execute([$id]);
    $stored = $stmt->fetchColumn();
    if ($stored) {
        @unlink($media_dir . $stored);
        $db->prepare("DELETE FROM pages_media WHERE id = ?")->execute([$id]);
        aldhran_log("MEDIA_DELETE", "Deleted media #{$id}", $_SESSION['user_id'] ?? 0);
    }
    header("Location: acp.php?s=content_manager&msg=media_deleted"); exit;
}

// ── Bulk actions ─────────────────────────────────────────────────
if (isset($_POST['bulk_action']) && $userPriv >= 5) {
    checkToken($_POST['csrf_token'] ?? '');
    $slugs = array_filter((array)($_POST['bulk_slugs'] ?? []));
    $bulk  = $_POST['bulk_action'];

    if (!empty($slugs)) {
        $ph = implode(',', array_fill(0, count($slugs), '?'));
        if ($bulk === 'delete') {
            $db->prepare("DELETE FROM pages WHERE slug IN ($ph)")->execute($slugs);
            $db->prepare("DELETE FROM pages_history WHERE page_slug IN ($ph)")->execute($slugs);
            $msg = '<div class="cm-msg-error-red">' . t('acp_cm_bulk_deleted', [], count($slugs) . ' entries deleted.') . '</div>';
        } elseif ($bulk === 'publish') {
            $db->prepare("UPDATE pages SET status = 'published' WHERE slug IN ($ph)")->execute($slugs);
            $msg = '<div class="cm-msg-success-gold">' . t('acp_cm_bulk_published', [], 'Entries published.') . '</div>';
        } elseif ($bulk === 'draft') {
            $db->prepare("UPDATE pages SET status = 'draft' WHERE slug IN ($ph)")->execute($slugs);
            $msg = '<div class="cm-msg-success-gold">' . t('acp_cm_bulk_drafted', [], 'Entries set to draft.') . '</div>';
        } elseif ($bulk === 'show_in_nav') {
            $db->prepare("UPDATE pages SET menu_category = 'header' WHERE slug IN ($ph)")->execute($slugs);
            $msg = '<div class="cm-msg-success-gold">' . t('acp_cm_bulk_shown', [], 'Entries added to navigation.') . '</div>';
        } elseif ($bulk === 'hide_from_nav') {
            $db->prepare("UPDATE pages SET menu_category = 'none' WHERE slug IN ($ph)")->execute($slugs);
            $msg = '<div class="cm-msg-success-gold">' . t('acp_cm_bulk_hidden', [], 'Entries removed from navigation.') . '</div>';
        }
        aldhran_log("CM_BULK_ACTION", "$bulk on " . count($slugs) . " pages", $_SESSION['user_id'] ?? 0);
    }
}

// ── Create translation variant ───────────────────────────────────
if (isset($_POST['create_translation']) && $userPriv >= 5) {
    checkToken($_POST['csrf_token'] ?? '');
    $src_slug = $_POST['src_slug'] ?? '';
    $new_lang = preg_replace('/[^a-z-]/', '', $_POST['new_lang'] ?? '');
    $stmt = $db->prepare("SELECT * FROM pages WHERE slug = ?");
    $stmt->execute([$src_slug]);
    $src = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($src && $new_lang) {
        $new_slug = $src['slug'] . '_' . $new_lang;
        $exists = $db->prepare("SELECT 1 FROM pages WHERE slug = ?");
        $exists->execute([$new_slug]);
        if (!$exists->fetchColumn()) {
            $db->prepare("INSERT INTO pages (slug, title, content, menu_category, menu_pos, min_priv, meta_title, meta_description, hero_image, status, parent_slug, lang, translation_group, template_key, template_data)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?)")
               ->execute([
                   $new_slug, $src['title'], $src['content'], $src['menu_category'], $src['menu_pos'], $src['min_priv'],
                   $src['meta_title'], $src['meta_description'], $src['hero_image'] ?? null, $src['parent_slug'],
                   $new_lang, $src['translation_group'] ?: $src['slug'], $src['template_key'], $src['template_data'],
               ]);
            aldhran_log("CM_TRANSLATION_CREATE", "Created {$new_lang} translation of {$src_slug}", $_SESSION['user_id'] ?? 0);
            header("Location: acp.php?s=content_manager&edit=" . urlencode($new_slug)); exit;
        }
    }
}

// ── Action: restore history ──
if (isset($_GET['revert']) && $userPriv >= 5) {
    checkToken($_GET['csrf'] ?? '');
    $revert_id = (int)$_GET['revert'];

    $stmt = $db->prepare("SELECT page_slug, title, content FROM pages_history WHERE id = ?");
    $stmt->execute([$revert_id]);
    $history_data = $stmt->fetch();

    if ($history_data) {
        $stmt_current = $db->prepare("SELECT title, content FROM pages WHERE slug = ?");
        $stmt_current->execute([$history_data['page_slug']]);
        $current_data = $stmt_current->fetch();
        if ($current_data) {
            $db->prepare("INSERT INTO pages_history (page_slug, title, content, saved_by) VALUES (?, ?, ?, ?)")
               ->execute([$history_data['page_slug'], $current_data['title'], $current_data['content'], $_SESSION['user_id'] ?? 0]);
        }

        $stmt_update = $db->prepare("UPDATE pages SET title = ?, content = ? WHERE slug = ?");
        $stmt_update->execute([$history_data['title'], $history_data['content'], $history_data['page_slug']]);

        $msg = '<div class="cm-msg-success-gold">' . t('acp_cm_msg_reverted', [], 'Version restored successfully.') . '</div>';
        $_GET['edit'] = $history_data['page_slug'];
    }
}

if (isset($_GET['delete']) && $userPriv >= 5) {
    checkToken($_GET['csrf'] ?? '');
    $stmt = $db->prepare("DELETE FROM pages WHERE slug = ?");
    $stmt->execute([$_GET['delete']]);
    $db->prepare("DELETE FROM pages_history WHERE page_slug = ?")->execute([$_GET['delete']]);
    $msg = '<div class="cm-msg-error-red">' . t('acp_cm_msg_deleted', [], 'Entry removed.') . '</div>';
}

if (isset($_POST['save_content']) && $userPriv >= 5) {
    checkToken($_POST['csrf_token'] ?? '');
    $old_slug  = $_POST['old_slug'] ?? '';
    $slug      = trim($_POST['slug']);
    $title     = trim($_POST['title']);
    $content   = $_POST['content'] ?? '';
    $cat       = !empty($_POST['show_in_nav']) ? 'header' : 'none';
    $pos       = (int)$_POST['menu_pos'];
    $priv      = (int)$_POST['min_priv'];
    $type      = $_POST['link_type'] ?? 'html';
    $status    = in_array($_POST['status'] ?? '', ['draft', 'published'], true) ? $_POST['status'] : 'published';
    $published_at = trim($_POST['published_at'] ?? '');
    $published_at = $published_at !== '' ? date('Y-m-d H:i:s', strtotime($published_at)) : null;
    $parent_slug  = preg_replace('/[^a-z0-9_\-]/', '', $_POST['parent_slug'] ?? '') ?: null;
    $lang         = preg_replace('/[^a-z-]/', '', $_POST['lang'] ?? 'en') ?: 'en';
    $template_key = preg_replace('/[^a-z0-9_]/', '', $_POST['template_key'] ?? '') ?: null;

    $template_data = null;
    if ($template_key) {
        $tf = $db->prepare("SELECT fields_json FROM pages_templates WHERE tkey = ?");
        $tf->execute([$template_key]);
        $fields_json = $tf->fetchColumn();
        if ($fields_json) {
            $fields = json_decode($fields_json, true) ?: [];
            $data = [];
            foreach ($fields as $f) {
                $data[$f['key']] = trim($_POST['tf_' . $f['key']] ?? '');
            }
            $template_data = json_encode($data, JSON_UNESCAPED_UNICODE);
        }
    }

    $meta_title = trim($_POST['meta_title'] ?? '');
    $meta_desc  = trim($_POST['meta_description'] ?? '');
    $hero_image = trim($_POST['hero_image'] ?? '');

    $save_error = null;

    // The hero partial drops this straight into background-image:url('…'),
    // so anything that could terminate that url() or carry a script scheme
    // is rejected rather than silently stripped.
    if ($hero_image !== '') {
        if (mb_strlen($hero_image) > 255
            || preg_match('/[\'"()\\\\\s<>]/', $hero_image)
            || preg_match('/^\s*(javascript|data|vbscript):/i', $hero_image)) {
            $save_error = t('acp_cm_msg_hero_invalid', [], 'Invalid hero image path. Pick a file from the media library or enter a plain path/URL without quotes or spaces.');
        }
    }
    if ($type === 'module') {
        if (empty($_POST['module_select'])) {
            $save_error = t('acp_cm_msg_module_required', [], 'Please enter a module path before saving.');
        } else {
            $content = '[MODULE]:' . trim($_POST['module_select']);
        }
    } elseif ($type === 'external') {
        if (empty($_POST['external_url'])) {
            $save_error = t('acp_cm_msg_url_required', [], 'Please enter a URL before saving.');
        } else {
            $ext_url = trim($_POST['external_url']);
            if (!preg_match('#^https?://#i', $ext_url)) $ext_url = 'https://' . $ext_url;
            $content = '[EXT]:' . $ext_url;
        }
    } elseif ($type === 'html') {
        $content = sanitize_trumbowyg_html($content);
    }

    if ($save_error !== null) {
        $msg = '<div class="cm-msg-error-red">' . h($save_error) . '</div>';
        $_GET['edit'] = $old_slug ?: '0';
        goto cm_save_skip;
    }

    $check = $db->prepare("SELECT slug, title, content FROM pages WHERE slug = ?");
    $check->execute([$old_slug ?: $slug]);
    $existing = $check->fetch();

    if ($existing && !empty($old_slug)) {
        if ($type === 'html') {
            $db->prepare("INSERT INTO pages_history (page_slug, title, content, saved_by) VALUES (?, ?, ?, ?)")
               ->execute([$old_slug, $existing['title'], $existing['content'], $_SESSION['user_id'] ?? 0]);
        }

        $stmt = $db->prepare("UPDATE pages SET slug=?, title=?, content=?, menu_category=?, menu_pos=?, min_priv=?, meta_title=?, meta_description=?,
                               hero_image=?, status=?, published_at=?, parent_slug=?, lang=?, template_key=?, template_data=? WHERE slug=?");
        $stmt->execute([$slug, $title, $content, $cat, $pos, $priv, $meta_title, $meta_desc,
                         $hero_image ?: null, $status, $published_at, $parent_slug, $lang, $template_key, $template_data, $old_slug]);

        if ($old_slug !== $slug) {
            $db->prepare("UPDATE pages_history SET page_slug = ? WHERE page_slug = ?")->execute([$slug, $old_slug]);
            $db->prepare("UPDATE pages SET translation_group = ? WHERE translation_group = ?")->execute([$slug, $old_slug]);
        }

        $msg = '<div class="cm-msg-success-gold">' . t('acp_cm_msg_updated', [], 'Entry updated.') . '</div>';
        $_GET['edit'] = $slug;
    } elseif ($existing) {
        $msg = '<div class="cm-msg-error-red">' . t('acp_cm_msg_slug_taken', [], 'Slug already in use.') . '</div>';
        $_GET['edit'] = $old_slug ?: '0';
    } else {
        $stmt = $db->prepare("INSERT INTO pages (slug, title, content, menu_category, menu_pos, min_priv, meta_title, meta_description,
                               hero_image, status, published_at, parent_slug, lang, translation_group, template_key, template_data)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$slug, $title, $content, $cat, $pos, $priv, $meta_title, $meta_desc,
                         $hero_image ?: null, $status, $published_at, $parent_slug, $lang, $slug, $template_key, $template_data]);
        $msg = '<div class="cm-msg-success-gold">' . t('acp_cm_msg_created', [], 'Entry created.') . '</div>';
        $_GET['edit'] = $slug;
    }
    cm_save_skip:
}

$is_new_entry = !isset($_GET['edit']) || $_GET['edit'] === '0';
$edit = ['slug'=>'','title'=>'','content'=>'','menu_category'=>'none','menu_pos'=>0,'min_priv'=>0, 'meta_title'=>'', 'meta_description'=>'',
         'hero_image'=>'','status'=>'published','published_at'=>null,'parent_slug'=>null,'lang'=>'en','translation_group'=>null,'template_key'=>null,'template_data'=>null];
$history_list = [];
$translations = [];

if (!$is_new_entry) {
    $stmt = $db->prepare("SELECT * FROM pages WHERE slug = ?");
    $stmt->execute([$_GET['edit']]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($res) {
        $edit = $res;

        $h_stmt = $db->prepare("SELECT h.id, h.saved_at, u.username FROM pages_history h LEFT JOIN users u ON h.saved_by = u.id WHERE h.page_slug = ? ORDER BY h.saved_at DESC LIMIT 10");
        $h_stmt->execute([$edit['slug']]);
        $history_list = $h_stmt->fetchAll();

        if (!empty($edit['translation_group'])) {
            $tr_stmt = $db->prepare("SELECT slug, lang FROM pages WHERE translation_group = ? AND slug != ?");
            $tr_stmt->execute([$edit['translation_group'], $edit['slug']]);
            $translations = $tr_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        $is_new_entry = true;
    }
}

$all_pages = $db->query(
    "SELECT title, slug, menu_category, content, status, parent_slug, lang, min_priv FROM pages ORDER BY menu_category ASC, menu_pos ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$templates = $db->query("SELECT tkey, label, fields_json FROM pages_templates ORDER BY label")->fetchAll(PDO::FETCH_ASSOC);

$current_type = 'html'; $ext_val = ''; $mod_val = '';
if (strpos($edit['content'] ?? '', '[MODULE]:') === 0) {
    $current_type = 'module'; $mod_val = substr($edit['content'], 9);
} elseif (strpos($edit['content'] ?? '', '[EXT]:') === 0) {
    $current_type = 'external'; $ext_val = substr($edit['content'], 6);
}
?>

<script src="assets/js/tinymce/tinymce.min.js"></script>
<link  rel="stylesheet" href="assets/acp_content_manager.css">

<div class="cm-page-header"><i class="fas fa-file-alt"></i> Content Manager</div>
<?= $msg ?>

<div class="cm-toolbar-row">
    <div class="cm-new-wrap">
        <button class="cm-btn-new" id="cm-new-btn" type="button"><i class="fas fa-plus"></i> <?= t('acp_cm_new') ?></button>
        <div class="cm-new-dropdown" id="cm-new-dropdown">
            <div class="cm-new-option" data-type="html">
                <i class="fas fa-edit"></i>
                <span><?= t('acp_cm_create_page') ?><br><small class="acp-s-6f264ea2"><?= t('acp_cc_page_desc') ?></small></span>
            </div>
            <div class="cm-new-option" data-type="module">
                <i class="fas fa-cube"></i>
                <span><?= t('acp_cc_type_module', [], 'Module') ?></span>
            </div>
            <div class="cm-new-option" data-type="external">
                <i class="fas fa-link"></i>
                <span><?= t('acp_cc_type_external', [], 'External') ?></span>
            </div>
        </div>
    </div>
    <button class="cm-btn-new cm-btn-media" type="button" onclick="cmOpenMedia()"><i class="fas fa-images"></i> <?= t('acp_cm_media_library', [], 'Media Library') ?></button>
</div>

<div class="cm-card" id="cm-form-card" <?= $is_new_entry && empty($_GET['edit']) ? '' : '' ?>>
    <form method="POST" id="cm-form">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="old_slug" value="<?= h($edit['slug']) ?>">
        <input type="hidden" name="link_type" id="link_type_input" value="<?= $current_type ?>">

        <div class="cm-link-type-cards">
            <div class="cm-link-option-card <?= $current_type=='html' ? 'active':'' ?>" data-type="html"><i class="fas fa-edit"></i><br><?= t('acp_cc_type_html', [], 'Page') ?></div>
            <div class="cm-link-option-card <?= $current_type=='module' ? 'active':'' ?>" data-type="module"><i class="fas fa-cube"></i><br><?= t('acp_cc_type_module', [], 'Module') ?></div>
            <div class="cm-link-option-card <?= $current_type=='external' ? 'active':'' ?>" data-type="external"><i class="fas fa-link"></i><br><?= t('acp_cc_type_external', [], 'External') ?></div>
        </div>

        <div class="cm-grid-2">
            <div>
                <label class="cm-label"><?= t('acp_cc_title', [], 'Title') ?></label>
                <input type="text" name="title" id="p_title" value="<?= h($edit['title']) ?>" class="cm-input" required>
            </div>
            <div>
                <label class="cm-label"><?= t('acp_cc_slug', [], 'Slug') ?></label>
                <input type="text" name="slug" id="p_slug" value="<?= h($edit['slug']) ?>" class="cm-input" required>
            </div>
        </div>

        <div class="cm-grid-3">
            <div>
                <label class="cm-label"><?= t('acp_cm_status', [], 'Status') ?></label>
                <select name="status" class="cm-select">
                    <option value="published" <?= $edit['status']=='published' ? 'selected':'' ?>><?= t('acp_cm_status_published', [], 'Published') ?></option>
                    <option value="draft" <?= $edit['status']=='draft' ? 'selected':'' ?>><?= t('acp_cm_status_draft', [], 'Draft') ?></option>
                </select>
            </div>
            <div>
                <label class="cm-label"><?= t('acp_cm_publish_at', [], 'Publish At (optional)') ?></label>
                <input type="datetime-local" name="published_at" class="cm-input"
                       value="<?= $edit['published_at'] ? h(str_replace(' ', 'T', substr($edit['published_at'],0,16))) : '' ?>">
            </div>
            <div>
                <label class="cm-label"><?= t('acp_cc_language', [], 'Language') ?></label>
                <select name="lang" class="cm-select">
                    <?php foreach ($allowed_langs as $l): ?>
                        <option value="<?= h($l) ?>" <?= $edit['lang']==$l ? 'selected':'' ?>><?= strtoupper(h($l)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php if (!$is_new_entry && !empty($edit['slug'])): ?>
        <div class="cm-translations-row">
            <span class="cm-label" style="margin:0;"><?= t('acp_cm_translations', [], 'Translations') ?>:</span>
            <?php foreach ($translations as $tr): ?>
                <a href="acp.php?s=content_manager&edit=<?= h($tr['slug']) ?>" class="cm-lang-pill"><?= strtoupper(h($tr['lang'])) ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div id="type-html" class="cm-type-section" <?= $current_type!='html' ? 'style="display:none;"' : '' ?>>

            <div class="cm-grid-2">
                <div>
                    <label class="cm-label"><?= t('acp_cm_parent_page', [], 'Parent Page') ?></label>
                    <select name="parent_slug" class="cm-select">
                        <option value=""><?= t('acp_cm_no_parent', [], '— Top level —') ?></option>
                        <?php foreach ($all_pages as $pp): if ($pp['slug'] === $edit['slug']) continue; ?>
                            <option value="<?= h($pp['slug']) ?>" <?= $edit['parent_slug']==$pp['slug'] ? 'selected':'' ?>><?= h($pp['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="cm-label"><?= t('acp_cm_template', [], 'Content Template') ?></label>
                    <select name="template_key" id="cm-template-select" class="cm-select">
                        <option value=""><?= t('acp_cm_no_template', [], '— Freeform HTML only —') ?></option>
                        <?php foreach ($templates as $tpl): ?>
                            <option value="<?= h($tpl['tkey']) ?>" data-fields='<?= h($tpl['fields_json']) ?>' <?= $edit['template_key']==$tpl['tkey'] ? 'selected':'' ?>><?= h($tpl['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div id="cm-template-fields"></div>

            <label class="cm-label"><?= t('acp_cc_content', [], 'Content') ?></label>
            <textarea id="editor" name="content"><?= $edit['content'] ?></textarea>

            <div class="cm-hero-box">
                <label class="cm-label"><?= t('acp_cm_hero_image', [], 'Hero Image') ?></label>
                <div class="cm-hero-row">
                    <input type="text" name="hero_image" id="cm-hero-input" class="cm-input cm-hero-input"
                           value="<?= h($edit['hero_image'] ?? '') ?>"
                           placeholder="<?= t('acp_cm_hero_ph', [], 'e.g. assets/img/media/castle.jpg — leave empty for no image') ?>"
                           oninput="cmHeroPreview()">
                    <button type="button" class="cm-btn-sm" onclick="cmOpenMedia('hero')">
                        <i class="fas fa-images"></i> <?= t('acp_cm_hero_choose', [], 'Choose') ?>
                    </button>
                    <button type="button" class="cm-btn-sm cm-hero-clear" onclick="cmHeroClear()">
                        <i class="fas fa-times"></i> <?= t('acp_cm_hero_clear', [], 'Remove') ?>
                    </button>
                </div>
                <div class="cm-hero-hint"><?= t('acp_cm_hero_hint', [], 'Shown behind the page title. Empty means the plain dark page head is used.') ?></div>
                <div class="cm-hero-preview" id="cm-hero-preview"></div>
            </div>

            <div class="cm-seo-box">
                <label class="cm-label"><?= t('acp_cc_meta_title', [], 'Meta Title') ?></label>
                <input type="text" name="meta_title" id="cm-meta-title" value="<?= h($edit['meta_title']) ?>" class="cm-input"
                       placeholder="<?= t('acp_cc_meta_title_ph', [], 'Overrides the default title in the &lt;title&gt; tag') ?>">
                <label class="cm-label"><?= t('acp_cc_meta_desc', [], 'Meta Description') ?></label>
                <textarea name="meta_description" id="cm-meta-desc" class="cm-input" rows="2"
                          placeholder="<?= t('acp_cc_meta_desc_ph', [], 'Important for search engines and Discord previews') ?>"><?= h($edit['meta_description']) ?></textarea>

                <div class="cm-serp-preview">
                    <div class="cm-serp-title" id="cm-serp-title"><?= h($edit['meta_title'] ?: $edit['title'] ?: 'Page Title') ?></div>
                    <div class="cm-serp-url"><?= h(parse_url(SITE_URL, PHP_URL_HOST) ?: 'example.com') ?> › <?= h($edit['slug'] ?: 'page-slug') ?></div>
                    <div class="cm-serp-desc" id="cm-serp-desc"><?= h($edit['meta_description'] ?: t('acp_cm_serp_placeholder', [], 'Your meta description will appear here…')) ?></div>
                </div>
            </div>

            <?php if (!empty($history_list)): ?>
            <div class="cm-history-box">
                <h4 class="cm-history-title"><?= t('acp_cm_version_history', [], 'Version History (Last 10)') ?></h4>
                <ul class="cm-history-list">
                <?php foreach ($history_list as $history): ?>
                    <li class="cm-history-item">
                        <span class="cm-history-meta">
                            <?= date('d.m.Y H:i', strtotime($history['saved_at'])) ?> <?= t('acp_cm_by', [], 'by') ?> <?= h($history['username'] ?? 'System') ?>
                        </span>
                        <span class="cm-history-actions">
                            <a href="#" onclick="cmShowDiff(<?= $history['id'] ?>); return false;"><i class="fas fa-code-compare"></i> <?= t('acp_cm_compare', [], 'Compare') ?></a>
                            <a href="acp.php?s=content_manager&revert=<?= $history['id'] ?>&csrf=<?= $csrf ?>"
                               onclick="return confirm('<?= t('acp_cm_confirm_revert', [], 'Overwrite current version and load old version?') ?>');">
                               <i class="fas fa-history"></i> <?= t('acp_cm_restore', [], 'Restore') ?>
                            </a>
                        </span>
                    </li>
                <?php endforeach; ?>
                </ul>
                <div id="cm-diff-box" class="cm-diff-box acp-s-cb458930"></div>
            </div>
            <?php endif; ?>
        </div>

        <div id="type-module" class="cm-type-section acp-s-cb458930" <?= $current_type!='module' ? '' : '' ?>>
            <label class="cm-label"><?= t('acp_cc_module_path') ?></label>
            <input type="text" name="module_select" value="<?= h($mod_val) ?>" class="cm-input">
        </div>

        <div id="type-external" class="cm-type-section acp-s-cb458930" <?= $current_type!='external' ? '' : '' ?>>
            <label class="cm-label"><?= t('acp_cc_external_url') ?></label>
            <input type="text" name="external_url" value="<?= h($ext_val) ?>" placeholder="https://example.com" class="cm-input">
            <small class="cm-ext-hint"><i class="fas fa-info-circle"></i> <?= t('acp_cc_external_hint') ?></small>
        </div>

        <div class="cm-grid-3">
            <div>
                <label class="cm-label"><?= t('acp_cc_category', [], 'Navigation') ?></label>
                <label style="display:flex;align-items:center;gap:8px;height:38px;">
                    <input type="checkbox" name="show_in_nav" value="1" <?= $edit['menu_category']!=='none' ? 'checked':'' ?>>
                    <?= t('acp_cc_show_in_nav', [], 'Show in header navigation') ?>
                </label>
            </div>
            <div>
                <label class="cm-label"><?= t('acp_cc_position') ?></label>
                <input type="number" name="menu_pos" value="<?= (int)$edit['menu_pos'] ?>" class="cm-input">
            </div>
            <div>
                <label class="cm-label"><?= t('acp_cc_privilege') ?></label>
                <select name="min_priv" class="cm-select">
                    <?php foreach ($priv_levels as $lvl => $lbl): ?>
                        <option value="<?= $lvl ?>" <?= (int)$edit['min_priv']===$lvl ? 'selected':'' ?>><?= $lvl ?> – <?= h($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <button type="submit" name="save_content" class="cm-btn-save"><?= t('acp_cc_save') ?></button>
    </form>

    <?php if (!$is_new_entry && !empty($edit['slug'])): ?>
    <form method="POST" class="cm-add-translation" style="display:inline-flex;gap:6px;align-items:center;padding:0 16px 16px;">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="src_slug" value="<?= h($edit['slug']) ?>">
        <select name="new_lang" class="cm-select cm-select-sm">
            <?php foreach ($allowed_langs as $l): if ($l === $edit['lang']) continue; ?>
                <option value="<?= h($l) ?>"><?= strtoupper(h($l)) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" name="create_translation" class="cm-btn-sm"><i class="fas fa-plus"></i> <?= t('acp_cm_add_translation', [], 'Add Translation') ?></button>
    </form>
    <?php endif; ?>
</div>

<div class="cm-card">
    <div class="cm-bulk-bar" id="cm-bulk-bar" style="display:none;">
        <span id="cm-bulk-count">0 selected</span>
        <form method="POST" id="cm-bulk-form" style="display:flex;gap:8px;align-items:center;">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div id="cm-bulk-slugs-holder"></div>
            <button type="submit" name="bulk_action" value="show_in_nav" class="cm-btn-sm"><i class="fas fa-compass"></i> <?= t('acp_cm_bulk_show_nav', [], 'Show in Nav') ?></button>
            <button type="submit" name="bulk_action" value="hide_from_nav" class="cm-btn-sm"><i class="fas fa-eye-slash"></i> <?= t('acp_cm_bulk_hide_nav', [], 'Hide from Nav') ?></button>
            <button type="submit" name="bulk_action" value="publish" class="cm-btn-sm"><i class="fas fa-eye"></i> <?= t('acp_cm_status_published', [], 'Publish') ?></button>
            <button type="submit" name="bulk_action" value="draft" class="cm-btn-sm"><i class="fas fa-eye-slash"></i> <?= t('acp_cm_status_draft', [], 'Draft') ?></button>
            <button type="submit" name="bulk_action" value="delete" class="cm-btn-sm cm-btn-sm-danger"
                    onclick="return confirm('<?= t('acp_cm_confirm_bulk_delete', [], 'Delete all selected entries?') ?>');"><i class="fas fa-trash"></i></button>
        </form>
    </div>
<?php
$grouped = [];
foreach ($all_pages as $p) { $grouped[$p['menu_category'] ?: 'none'][] = $p; }
foreach ($grouped as $cat => $pages):
?>
<div class="cm-accordion">
    <div class="cm-accordion-header">
        <div class="cm-accordion-header-left">
            <i class="fas fa-chevron-right cm-accordion-chevron"></i>
            <?= $cat === 'none' ? t('acp_cc_misc', [], 'Miscellaneous') : h($cat) ?>
        </div>
        <span class="cm-accordion-count"><?= count($pages) ?></span>
    </div>
    <div class="cm-accordion-body">
        <table class="cm-list-table">
        <?php foreach ($pages as $p):
            if      (strpos($p['content'],'[MODULE]:')===0) $badge='<span class="cm-type-badge cm-type-module">MOD</span>';
            elseif  (strpos($p['content'],'[EXT]:')===0)    $badge='<span class="cm-type-badge cm-type-ext">EXT</span>';
            else                                            $badge='<span class="cm-type-badge cm-type-html">HTML</span>';
            $status_badge = ($p['status'] ?? 'published') === 'draft'
                ? '<span class="cm-status-badge cm-status-draft">DRAFT</span>'
                : '<span class="cm-status-badge cm-status-live">LIVE</span>';
            $indent = !empty($p['parent_slug']) ? 'style="padding-left:24px;"' : '';
        ?>
        <tr>
            <td class="acp-s-b45a96a7">
                <input type="checkbox" class="cm-bulk-check" value="<?= h($p['slug']) ?>">
            </td>
            <td <?= $indent ?>>
                <?= $badge ?> <?= $status_badge ?>
                <?php if (!empty($p['parent_slug'])): ?><i class="fas fa-level-up-alt fa-rotate-90 acp-s-5178ac1e"></i><?php endif; ?>
                <span class="cm-list-title"><?= h($p['title']) ?></span>
                <?php if (($p['lang'] ?? 'en') !== 'en'): ?><span class="cm-lang-tag"><?= strtoupper(h($p['lang'])) ?></span><?php endif; ?>
                <div class="cm-list-meta">/<?= h($p['slug']) ?></div>
            </td>
            <td class="cm-list-actions">
                <a href="acp.php?s=content_manager&edit=<?= h($p['slug']) ?>" class="cm-list-btn cm-list-btn-edit" title="<?= t('acp_cm_edit', [], 'Edit') ?>"><i class="fas fa-edit"></i></a>
                <a href="acp.php?s=content_manager&delete=<?= h($p['slug']) ?>&csrf=<?= $csrf ?>" class="cm-list-btn cm-list-btn-del" title="<?= t('acp_cm_delete', [], 'Delete') ?>" onclick="return confirm('<?= t('acp_cm_confirm_delete', [], 'Really delete entry') ?> \'<?= h($p['title']) ?>\'?');"><i class="fas fa-trash"></i></a>
            </td>
        </tr>
        <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endforeach; ?>
</div>

<div class="cm-media-overlay" id="cm-media-overlay">
    <div class="cm-media-modal">
        <div class="cm-media-head">
            <span><i class="fas fa-images"></i> <?= t('acp_cm_media_library', [], 'Media Library') ?></span>
            <button type="button" class="cm-media-close" onclick="cmCloseMedia()">&#x2715;</button>
        </div>
        <div class="cm-media-upload">
            <input type="file" id="cm-media-file" accept="image/*">
            <button type="button" class="cm-btn-sm" onclick="cmUploadMedia()"><i class="fas fa-upload"></i> <?= t('acp_cm_upload', [], 'Upload') ?></button>
        </div>
        <div class="cm-media-grid" id="cm-media-grid"></div>
    </div>
</div>

<script>
const CM_CSRF = '<?= $csrf ?>';
const CM_TEMPLATES = <?= json_encode($templates) ?>;
const CM_TEMPLATE_DATA = <?= json_encode($edit['template_data'] ? json_decode($edit['template_data'], true) : []) ?>;

function cmInitEditor() {
    if (window.tinymce && tinymce.get('editor')) return;
    if (!document.getElementById('type-html') || document.getElementById('type-html').style.display === 'none') return;
    tinymce.init({
        license_key: 'gpl',
        selector: '#editor',
        height: 420,
        menubar: false,
        skin: 'oxide-dark',
        content_css: 'dark',
        plugins: 'link lists table code image media',
        toolbar: 'undo redo | bold italic underline | bullist numlist | link image table | code fullscreen',
        images_upload_handler: function (blobInfo) {
            return new Promise((resolve, reject) => {
                const fd = new FormData();
                fd.append('media_upload', '1');
                fd.append('csrf_token', CM_CSRF);
                fd.append('file', blobInfo.blob(), blobInfo.filename());
                fetch('acp.php?s=content_manager', { method: 'POST', body: fd })
                    .then(r => r.json()).then(d => d.ok ? resolve(d.url) : reject(d.error))
                    .catch(reject);
            });
        }
    });
}

function cmRenderTemplateFields(prefillFromData) {
    const sel = document.getElementById('cm-template-select');
    const holder = document.getElementById('cm-template-fields');
    const opt = sel.options[sel.selectedIndex];
    const fieldsJson = opt ? opt.dataset.fields : null;
    if (!fieldsJson) { holder.innerHTML = ''; return; }
    const fields = JSON.parse(fieldsJson);
    holder.innerHTML = fields.map(f => {
        const val = (prefillFromData && CM_TEMPLATE_DATA[f.key]) ? CM_TEMPLATE_DATA[f.key] : '';
        const esc = (val + '').replace(/"/g, '&quot;');
        if (f.type === 'textarea') {
            return `<label class="cm-label">${f.label}</label><textarea name="tf_${f.key}" class="cm-input" rows="2">${val}</textarea>`;
        }
        return `<label class="cm-label">${f.label}</label><input type="text" name="tf_${f.key}" class="cm-input" value="${esc}">`;
    }).join('');
}

document.getElementById('cm-template-select')?.addEventListener('change', () => cmRenderTemplateFields(false));
cmRenderTemplateFields(true);

document.getElementById('cm-meta-title')?.addEventListener('input', function() {
    document.getElementById('cm-serp-title').textContent = this.value || document.getElementById('p_title').value || 'Page Title';
});
document.getElementById('cm-meta-desc')?.addEventListener('input', function() {
    document.getElementById('cm-serp-desc').textContent = this.value || '<?= t('acp_cm_serp_placeholder', [], 'Your meta description will appear here…') ?>';
});
document.getElementById('p_title')?.addEventListener('input', function() {
    const mt = document.getElementById('cm-meta-title');
    if (mt && !mt.value) document.getElementById('cm-serp-title').textContent = this.value || 'Page Title';
});

function cmDiffEsc(s) { return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function cmRenderDiff(oldLines, newLines) {
    const max = Math.max(oldLines.length, newLines.length);
    let html = '';
    for (let i = 0; i < max; i++) {
        const o = oldLines[i], n = newLines[i];
        if (o === n) html += `<div class="cm-diff-line same">${cmDiffEsc(o ?? '')}</div>`;
        else {
            if (o !== undefined) html += `<div class="cm-diff-line removed">- ${cmDiffEsc(o)}</div>`;
            if (n !== undefined) html += `<div class="cm-diff-line added">+ ${cmDiffEsc(n)}</div>`;
        }
    }
    return html;
}

function cmShowDiff(historyId) {
    const fd = new FormData();
    fd.append('history_id', historyId);
    fd.append('csrf_token', CM_CSRF);
    fetch('acp.php?s=content_manager&cm_ajax=diff', { method: 'POST', body: fd })
        .then(r => r.json()).then(d => {
            if (!d.ok) return;
            const box = document.getElementById('cm-diff-box');
            box.innerHTML = cmRenderDiff(d.old.split('\n'), d.new.split('\n'));
            box.style.display = 'block';
            box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

/* The library opens in two modes: without an argument it behaves as before
   (click copies the URL / inserts into the editor); with 'hero' a click
   picks the file as this page's hero image instead. */
let cmMediaMode = null;

function cmOpenMedia(mode) {
    cmMediaMode = mode || null;
    document.getElementById('cm-media-overlay').classList.add('open');
    cmLoadMedia();
}
function cmCloseMedia() {
    document.getElementById('cm-media-overlay').classList.remove('open');
    cmMediaMode = null;
}

function cmHeroPreview() {
    const val = (document.getElementById('cm-hero-input')?.value || '').trim();
    const box = document.getElementById('cm-hero-preview');
    if (!box) return;
    box.innerHTML = '';
    if (!val) return;
    const img = document.createElement('img');
    img.src = val;
    img.alt = '';
    img.onerror = () => img.classList.add('is-missing');
    img.onclick = () => cmHeroLightboxOpen(val);
    box.appendChild(img);
}
function cmHeroLightboxOpen(src) {
    let lb = document.getElementById('cm-hero-lightbox');
    if (!lb) {
        lb = document.createElement('div');
        lb.id = 'cm-hero-lightbox';
        lb.className = 'cm-hero-lightbox';
        lb.appendChild(document.createElement('img'));
        lb.addEventListener('click', () => lb.classList.remove('open'));
        document.body.appendChild(lb);
    }
    lb.querySelector('img').src = src;
    lb.classList.add('open');
}
function cmHeroClear() {
    const input = document.getElementById('cm-hero-input');
    if (input) input.value = '';
    cmHeroPreview();
}
function cmHeroPick(url) {
    const input = document.getElementById('cm-hero-input');
    if (input) input.value = url;
    cmHeroPreview();
    cmCloseMedia();
}

function cmLoadMedia() {
    const grid = document.getElementById('cm-media-grid');
    grid.innerHTML = '<?= t('general_loading', [], 'Loading…') ?>';
    fetch('acp.php?s=content_manager&cm_ajax=media_list').then(r => r.json()).then(d => {
        if (!d.ok || !d.media.length) { grid.innerHTML = '<div class="cm-media-empty"><?= t('acp_cm_media_empty', [], 'No media uploaded yet.') ?></div>'; return; }
        grid.innerHTML = d.media.map(m => `
            <div class="cm-media-item">
                <img src="${m.url}" loading="lazy" onclick="cmMediaClick('${m.url}')"
                     title="${cmMediaMode === 'hero' ? '<?= t('acp_cm_use_as_hero', [], 'Click to use as hero image') ?>' : '<?= t('acp_cm_copy_url', [], 'Click to copy URL') ?>'}">
                <div class="cm-media-item-name">${m.filename}</div>
                <a href="acp.php?s=content_manager&media_delete=${m.id}&csrf=<?= $csrf ?>" class="cm-media-item-del"
                   onclick="return confirm('<?= t('acp_cm_confirm_delete_media', [], 'Delete this file?') ?>');"><i class="fas fa-trash"></i></a>
            </div>`).join('');
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

function cmMediaClick(url) {
    if (cmMediaMode === 'hero') { cmHeroPick(url); return; }
    cmCopyMediaUrl(url);
}

function cmCopyMediaUrl(url) {
    const abs = window.location.origin + '/' + url;
    navigator.clipboard?.writeText(abs);
    if (window.tinymce && tinymce.activeEditor) {
        tinymce.activeEditor.insertContent(`<img src="${abs}" alt="">`);
    }
}

function cmUploadMedia() {
    const input = document.getElementById('cm-media-file');
    if (!input.files.length) return;
    const fd = new FormData();
    fd.append('media_upload', '1');
    fd.append('csrf_token', CM_CSRF);
    fd.append('file', input.files[0]);
    fetch('acp.php?s=content_manager', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
        if (d.ok) { input.value = ''; cmLoadMedia(); } else alert('Error: ' + d.error);
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('cm-form-card').style.display !== 'none') cmInitEditor();
    cmHeroPreview();

    document.getElementById('cm-new-btn')?.addEventListener('click', function(e) {
        e.stopPropagation();
        document.getElementById('cm-new-dropdown').classList.toggle('open');
    });
    document.addEventListener('click', () => document.getElementById('cm-new-dropdown')?.classList.remove('open'));

    document.querySelectorAll('.cm-new-option').forEach(el => el.addEventListener('click', function() {
        const type = this.dataset.type;
        document.getElementById('cm-new-dropdown').classList.remove('open');
        document.getElementById('cm-form').reset();
        document.getElementById('p_slug').value = '';
        document.getElementById('p_title').value = '';
        document.querySelector('input[name="old_slug"]').value = '';
        if (window.tinymce && tinymce.get('editor')) tinymce.get('editor').setContent('');
        document.getElementById('link_type_input').value = type;
        document.querySelectorAll('.cm-link-option-card').forEach(c => c.classList.remove('active'));
        document.querySelector(`.cm-link-option-card[data-type="${type}"]`).classList.add('active');
        document.querySelectorAll('.cm-type-section').forEach(s => s.style.display = 'none');
        document.getElementById('type-' + type).style.display = 'block';
        document.getElementById('cm-form-card').style.display = 'block';
        if (type === 'html') cmInitEditor();
        document.getElementById('p_title').focus();
    }));

    document.querySelectorAll('.cm-link-option-card').forEach(el => el.addEventListener('click', function() {
        const type = this.dataset.type;
        document.querySelectorAll('.cm-link-option-card').forEach(c => c.classList.remove('active'));
        this.classList.add('active');
        document.getElementById('link_type_input').value = type;
        document.querySelectorAll('.cm-type-section').forEach(s => s.style.display = 'none');
        document.getElementById('type-' + type).style.display = 'block';
        if (type === 'html') cmInitEditor();
    }));

    document.getElementById('p_title')?.addEventListener('blur', function() {
        const slugEl = document.getElementById('p_slug');
        if (slugEl.value === '') {
            slugEl.value = this.value.toLowerCase()
                .replace(/[äöüß]/g, c => ({ä:'ae',ö:'oe',ü:'ue',ß:'ss'}[c]))
                .replace(/[^a-z0-9 ]/g,'').replace(/\s+/g,'-')
                .replace(/-+/g,'-').replace(/^-|-$/g,'');
        }
    });

    document.querySelectorAll('.cm-accordion-header').forEach(el => el.addEventListener('click', function() {
        this.classList.toggle('is-open');
        const body = this.nextElementSibling;
        body.style.display = body.style.display === 'block' ? 'none' : 'block';
    }));

    <?php if (!$is_new_entry && !empty($edit['slug'])): ?>
    document.querySelectorAll('.cm-list-table').forEach(function(table) {
        if (table.querySelector('a[href*="edit=<?= h($edit['slug']) ?>"]')) {
            const body = table.closest('.cm-accordion-body');
            body.style.display = 'block';
            body.previousElementSibling.classList.add('is-open');
        }
    });
    <?php endif; ?>

    function cmUpdateBulkBar() {
        const checked = document.querySelectorAll('.cm-bulk-check:checked');
        const bar = document.getElementById('cm-bulk-bar');
        bar.style.display = checked.length ? 'flex' : 'none';
        document.getElementById('cm-bulk-count').textContent = checked.length + ' <?= t('acp_cm_selected', [], 'selected') ?>';
        const holder = document.getElementById('cm-bulk-slugs-holder');
        holder.innerHTML = '';
        checked.forEach(cb => {
            const i = document.createElement('input');
            i.type = 'hidden'; i.name = 'bulk_slugs[]'; i.value = cb.value;
            holder.appendChild(i);
        });
    }
    document.querySelectorAll('.cm-bulk-check').forEach(cb => cb.addEventListener('change', cmUpdateBulkBar));
});
</script>
<?php require_once __DIR__ . '/acp_all_views_ai_extensions.php'; ?>
