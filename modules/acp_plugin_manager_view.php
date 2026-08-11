<?php
if (!defined('IN_CMS')) exit;
if ((int)($userPriv ?? 0) < 5) return;

$pm_token = generateToken();
?>

<div class="pm-page-header">
    <span class="pm-page-title"><i class="fas fa-puzzle-piece"></i> Plugin Manager</span>
    <span class="pm-page-meta">DAOC CMS</span>
</div>

<?php if ($pm_msg): ?>
    <div class="acp-msg-success acp-s-3019698e"><?= h($pm_msg) ?></div>
<?php endif; ?>
<?php if ($pm_error): ?>
    <div class="acp-msg-error acp-s-3019698e"><?= h($pm_error) ?></div>
<?php endif; ?>

<!-- Installed Plugins -->
<div class="pm-section">
    <div class="pm-section-title"><i class="fas fa-puzzle-piece"></i> Installed Plugins</div>
    <?php if (empty($plugins)): ?>
        <div class="pm-empty">No installed plugins.</div>
    <?php else: foreach ($plugins as $pl):
        $deps   = json_decode($pl['dependencies'], true) ?: [];
        $sizeKb = round($pl['filesize'] / 1024, 1);
    ?>
    <div class="pm-plugin-card <?= $pl['is_active'] ? '' : 'pm-inactive' ?>">
        <div class="pm-plugin-info">
            <div class="pm-plugin-name">
                <?= h($pl['name']) ?>
                <span class="pm-version-badge">v<?= h($pl['version']) ?></span>
                <span class="<?= $pl['is_active'] ? 'pm-status-on' : 'pm-status-off' ?>">● <?= $pl['is_active'] ? 'ACTIVE' : 'INACTIVE' ?></span>
            </div>
            <div class="pm-plugin-desc"><?= h($pl['description']) ?></div>
            <div class="pm-plugin-meta">
                <span><i class="fas fa-user"></i> <?= h($pl['author']) ?></span>
                <span><i class="fas fa-hdd"></i> <?= $sizeKb ?> KB</span>
                <span><i class="fas fa-link"></i>
                    <?php if ($pl['website']): ?>
                        <a href="<?= h($pl['website']) ?>" target="_blank" class="pm-meta-link"><?= h($pl['website']) ?></a>
                    <?php else: ?>—<?php endif; ?>
                </span>
                <span><i class="fas fa-calendar"></i> <?= substr($pl['installed_at'], 0, 10) ?></span>
                <span><i class="fas fa-route"></i> ?p=<?= h($pl['slug']) ?></span>
            </div>
            <?php if (!empty($deps)): ?>
            <div class="pm-deps">
                <?php foreach ($deps as $dep): ?>
                    <span class="pm-plugin-dep"><?= h($dep) ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="pm-plugin-actions">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $pm_token ?>">
                <input type="hidden" name="pm_action" value="toggle">
                <input type="hidden" name="plugin_slug" value="<?= h($pl['slug']) ?>">
                <button type="submit" class="pm-btn-ghost"><?= $pl['is_active'] ? 'DISABLE' : 'ENABLE' ?></button>
            </form>
            <form method="POST" onsubmit="return confirm('Really delete plugin \'<?= addslashes($pl['name']) ?>\'?');">
                <input type="hidden" name="csrf_token" value="<?= $pm_token ?>">
                <input type="hidden" name="pm_action" value="delete">
                <input type="hidden" name="plugin_slug" value="<?= h($pl['slug']) ?>">
                <button type="submit" class="pm-btn-danger"><i class="fas fa-trash"></i> DELETE</button>
            </form>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div>

<!-- Upload -->
<div class="pm-section">
    <div class="pm-section-title"><i class="fas fa-upload"></i> Install Plugin</div>
    <form method="POST" enctype="multipart/form-data" id="plugin-upload-form">
        <input type="hidden" name="csrf_token" value="<?= $pm_token ?>">
        <input type="hidden" name="pm_action" value="install">
        <div class="pm-upload-box" id="plugin-drop-zone">
            <input type="file" name="plugin_file" id="plugin-file-input" accept=".php">
            <label for="plugin-file-input" class="pm-upload-file-btn">
                <i class="fas fa-puzzle-piece"></i> Select plugin (.php)
            </label>
            <div id="plugin-file-name" class="pm-upload-filename">No file selected</div>
        </div>
        <button type="submit" class="pm-btn-nexus pm-btn-full" id="plugin-install-btn" disabled>
            <i class="fas fa-download"></i> INSTALL
        </button>
    </form>
</div>

<script>
document.getElementById('plugin-file-input').addEventListener('change', function () {
    document.getElementById('plugin-file-name').textContent = this.files[0]?.name ?? 'No file';
    document.getElementById('plugin-install-btn').disabled = !this.files.length;
});

const zone  = document.getElementById('plugin-drop-zone');
const input = document.getElementById('plugin-file-input');
const label = document.getElementById('plugin-file-name');
const btn   = document.getElementById('plugin-install-btn');

zone.addEventListener('dragover',  e => { e.preventDefault(); zone.style.borderColor = 'var(--border-gold)'; });
zone.addEventListener('dragleave', ()  => { zone.style.borderColor = ''; });
zone.addEventListener('drop', e => {
    e.preventDefault();
    zone.style.borderColor = '';
    const file = e.dataTransfer.files[0];
    if (!file) return;
    const dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    label.textContent = file.name;
    btn.disabled = false;
});
</script>