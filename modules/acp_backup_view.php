<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_ACP')) exit;
?>
<?php
$backup_msgs = [
    'verified'      => t('backup_msg_verified', [], 'Backup integrity check completed successfully.'),
    'restored'      => t('backup_msg_restored', [], 'Backup restored successfully.'),
    'settings_saved'=> t('backup_msg_settings_saved', [], 'Backup settings saved.'),
    'meta_updated'  => t('backup_msg_meta_updated', [], 'Backup metadata updated.'),
    'deleted'       => t('backup_msg_deleted', [], 'Backup deleted.'),
    'created'       => t('backup_msg_created', [], 'Backup created successfully.'),
];
$backup_errs = [
    'file_pinned'     => t('backup_err_file_pinned', [], 'This backup is pinned and cannot be deleted.'),
    'dir_not_writable'=> t('backup_err_dir_not_writable', [], 'Backup directory is not writable.'),
    'exe_7z_missing'  => t('backup_err_exe_7z_missing', [], '7-Zip executable not found at the configured path.'),
    'failed'          => t('backup_err_failed', [], 'Backup creation failed.'),
    'file_not_found'  => t('backup_err_file_not_found', [], 'Backup file not found.'),
];
$msg_key = $_GET['msg'] ?? '';
$err_key = $_GET['err'] ?? '';
?>
<?php if (isset($backup_msgs[$msg_key])): ?>
<div class="acp-s-d912cd33">
    <?= $backup_msgs[$msg_key] ?>
</div>
<?php endif; ?>

<?php if (isset($backup_errs[$err_key])): ?>
<div class="acp-s-166acd5e">
    <?= $backup_errs[$err_key] ?>
</div>
<?php endif; ?>

<div class="acp-s-2954ced3">
    <div class="acp-s-17281f98">
        <h3 class="acp-s-7e1e93ca"><?= t('backup_settings_hdr', [], 'Automation Settings') ?></h3>
        <form method="POST" action="acp.php?s=backup">
            <input type="hidden" name="csrf_token" value="<?= h(generateToken()) ?>">
            <input type="hidden" name="save_settings" value="1">
            
            <div class="acp-s-3bbb810b">
                <label class="acp-s-ac69bfb8"><?= t('backup_active_lbl', [], 'Enable Auto-Backup') ?></label>
                <label class="acp-s-cd3a448a">
                    <input type="checkbox" name="backup_active" value="1" <?= $backup_settings['backup_active'] == '1' ? 'checked' : '' ?>>
                    <?= t('backup_active_desc', [], 'Create backups automatically') ?>
                </label>
            </div>
            
            <div class="acp-s-3bbb810b">
                <label class="acp-s-ac69bfb8"><?= t('backup_format_lbl', [], 'Archive Format') ?></label>
                <select name="backup_format" class="acp-s-75abcd2c">
                    <option value="7z" <?= $backup_settings['backup_format'] === '7z' ? 'selected' : '' ?>>7-Zip (.7z)</option>
                    <option value="zip" <?= $backup_settings['backup_format'] === 'zip' ? 'selected' : '' ?>>ZIP (.zip)</option>
                </select>
            </div>
            
            <div class="acp-s-3bbb810b">
                <label class="acp-s-ac69bfb8"><?= t('backup_interval_lbl', [], 'Interval (Seconds)') ?></label>
                <input type="number" name="backup_interval" value="<?= h($backup_settings['backup_interval']) ?>" class="acp-s-75abcd2c" >
            </div>
            
            <div class="acp-s-3bbb810b">
                <label class="acp-s-ac69bfb8"><?= t('backup_7z_lbl', [], '7-Zip Executable Path') ?></label>
                <input type="text" name="backup_7z_path" value="<?= h($backup_settings['backup_7z_path']) ?>" class="acp-s-75abcd2c" >
            </div>
            
            <div class="acp-s-3bbb810b">
                <label class="acp-s-ac69bfb8"><?= t('backup_mysql_lbl', [], 'mysqldump Executable Path') ?></label>
                <input type="text" name="backup_mysqldump_path" value="<?= h($backup_settings['backup_mysqldump_path']) ?>" class="acp-s-75abcd2c" >
            </div>

            <div class="acp-s-57e800c6">
                <label class="acp-s-ac69bfb8"><?= t('backup_my_cli_lbl', [], 'mysql CLI Executable Path') ?></label>
                <input type="text" name="backup_mysql_path" value="<?= h($backup_settings['backup_mysql_path'] ?? '') ?>" class="acp-s-75abcd2c" >
            </div>
            
            <button type="submit" class="acp-s-344ab622">
                <i class="fas fa-save"></i> Save Configuration
            </button>
        </form>
    </div>

    <div class="acp-s-66eba501">
        <div class="acp-s-86c8e523">
            <h3 class="acp-s-fc9db6f6">Backup Archives</h3>
            <form method="POST" action="acp.php?s=backup">
                <input type="hidden" name="csrf_token" value="<?= h(generateToken()) ?>">
                <input type="hidden" name="create_backup" value="1">
                <button type="submit" class="acp-s-08f9ca07">
                    <i class="fas fa-plus"></i> Create Now
                </button>
            </form>
        </div>
        
        <table class="acp-s-614aef57">
            <thead>
                <tr class="acp-s-82284bdc">
                    <th class="acp-s-a0d118e1">Target Node</th>
                    <th class="acp-s-a0d118e1">Size</th>
                    <th class="acp-s-a0d118e1">Metadata context</th>
                    <th class="acp-s-ece64c43">Operations Execution</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($backup_files)): ?>
                    <tr>
                        <td colspan="4" class="acp-s-f71f7eae">No backup archives found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($backup_files as $bf): ?>
                        <tr class="acp-s-fd8a701c">
                            <td class="acp-s-0f8bad32">
                                <div class="acp-s-cf8cbca6">
                                    <?php if ($bf['pinned']): ?>
                                        <i class="fas fa-thumbtack acp-s-e175cd12"></i>
                                    <?php endif; ?>
                                    <strong><?= h($bf['name']) ?></strong>
                                </div>
                                <div class="acp-s-eb2e5953"><?= date('Y-m-d H:i:s', $bf['date']) ?></div>
                                
                                <div class="acp-s-6ae92d17">
                                    <?php if ($bf['status'] === 'valid'): ?>
                                        <span class="acp-s-8febb95f"><i class="fas fa-check-circle"></i> <?= t('backup_status_verified', [], 'Archive verified & manifest OK') ?></span>
                                        <span class="acp-s-8febb95f"><i class="fas fa-check-circle"></i> <?= t('backup_status_sql_found', [], 'SQL dump found') ?></span>
                                    <?php elseif ($bf['status'] === 'warning'): ?>
                                        <span class="acp-s-ffa1ea0b"><i class="fas fa-exclamation-triangle"></i> <?= t('backup_status_partial', [], 'Manifest present (partial archive)') ?></span>
                                    <?php else: ?>
                                        <span class="acp-s-6827e489"><i class="fas fa-times-circle"></i> <?= t('backup_status_broken', [], 'Incomplete / corrupted') ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="acp-s-e75148ce"><?= round($bf['size'] / 1048576, 2) ?> MB</td>
                            <td class="acp-s-a0d118e1">
                                <form method="POST" action="acp.php?s=backup" class="acp-s-1c244e6b">
                                    <input type="hidden" name="csrf_token" value="<?= h(generateToken()) ?>">
                                    <input type="hidden" name="update_meta" value="1">
                                    <input type="hidden" name="file" value="<?= h($bf['name']) ?>">
                                    <input type="text" name="comment" value="<?= h($bf['comment']) ?>" placeholder="Comment (e.g. Before major update)" class="acp-s-684eea0e" >
                                    <label class="acp-s-425c11db">
                                        <input type="checkbox" name="pinned" value="1" <?= $bf['pinned'] ? 'checked' : '' ?> onchange="this.form.submit();">
                                        Pin item protection
                                    </label>
                                </form>
                            </td>
                            <td class="acp-s-ece64c43">
                                <div class="acp-s-b7fb377c">
                                    <form method="POST" action="acp.php?s=backup" class="acp-s-1c244e6b">
                                        <input type="hidden" name="csrf_token" value="<?= h(generateToken()) ?>">
                                        <input type="hidden" name="verify_backup" value="1">
                                        <input type="hidden" name="file" value="<?= h($bf['name']) ?>">
                                        <button type="submit" class="acp-s-b6ee36c3" title="<?= t('backup_btn_verify', [], 'Verify integrity') ?>"><i class="fas fa-sync-alt"></i></button>
                                    </form>

                                    <button type="button" onclick="document.getElementById('restore-dialog-<?= md5($bf['name']) ?>').showModal();" class="acp-s-f06c776b" title="<?= t('backup_btn_configure_restore', [], 'Configure restore context') ?>"><i class="fas fa-history"></i></button>

                                    <form method="POST" action="acp.php?s=backup" class="acp-s-1c244e6b">
                                        <input type="hidden" name="csrf_token" value="<?= h(generateToken()) ?>">
                                        <input type="hidden" name="delete_backup" value="1">
                                        <input type="hidden" name="file" value="<?= h($bf['name']) ?>">
                                        <button type="submit" style="background: transparent; border: 1px solid #e07070; color: #e07070; cursor: pointer; padding: 4px 8px; font-size: 0.85em;<?= $bf['pinned'] ? ' opacity:0.3; cursor:not-allowed;' : '' ?>" <?= $bf['pinned'] ? 'disabled' : '' ?>><i class="fas fa-trash"></i></button>
                                    </form>
                                </div>

                                <dialog id="restore-dialog-<?= md5($bf['name']) ?>" class="acp-s-5e44a38a" >
                                    <h4 class="acp-s-90ee4791">Restore Backup</h4>
                                    <p class="acp-s-b87d2579">
                                        Backup:<br><strong class="acp-s-4bbb41ad"><?= !empty($bf['comment']) ? h($bf['comment']) : h($bf['name']) ?></strong>
                                    </p>
                                    <form method="POST" action="acp.php?s=backup">
                                        <input type="hidden" name="csrf_token" value="<?= h(generateToken()) ?>">
                                        <input type="hidden" name="restore_backup" value="1">
                                        <input type="hidden" name="file" value="<?= h($bf['name']) ?>">
                                        
                                        <div class="acp-s-8d248a75">
                                            <label class="acp-s-26590802"><input type="radio" name="restore_mode" value="all" checked> <?= t('backup_restore_all', [], 'Files + Database') ?></label>
                                            <label class="acp-s-26590802"><input type="radio" name="restore_mode" value="db"> <?= t('backup_restore_db', [], 'Database only') ?></label>
                                            <label class="acp-s-26590802"><input type="radio" name="restore_mode" value="acp"> <?= t('backup_restore_acp', [], 'ACP files only') ?></label>
                                            <label class="acp-s-26590802"><input type="radio" name="restore_mode" value="dol"> <?= t('backup_restore_dol', [], 'Game server data only') ?></label>
                                        </div>

                                        <div class="acp-s-22b8d13a">
                                            <button type="button" onclick="this.closest('dialog').close();" class="acp-s-b432762b"><?= t('backup_btn_cancel', [], 'Cancel') ?></button>
                                            <button type="submit" class="acp-s-734eeab4">[Restore]</button>
                                        </div>
                                    </form>
                                </dialog>

                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>