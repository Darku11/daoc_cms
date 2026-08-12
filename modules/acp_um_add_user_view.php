<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!isset($can_edit) || !$can_edit) return;
$add_form_token = generateToken();
$userPriv = (int)($_SESSION['priv_level'] ?? 0);
$maxAssignablePriv = ($userPriv >= 5) ? 5 : 3;
?>
<form class="um-inline-editor"
      onsubmit="event.preventDefault();
                const fd=new FormData(this);
                fetch('modules/acp_um_sync_worker.php',{method:'POST',body:fd})
                .then(r=>r.text())
                .then(t=>{if(t.trim()==='SUCCESS'){alert('User created.');loadCategory('all');}else alert('Error: '+t);})
                .catch(e=>alert('Error: '+e));">
    <input type="hidden" name="um_action"  value="create_user">
    <input type="hidden" name="can_edit"   value="1">
    <input type="hidden" name="csrf_token" value="<?= $add_form_token ?>">

    <div class="um-ie-notice"><i class="fas fa-plus"></i> <?= t('um_add.title', [], 'New User Entry') ?></div>

    <div class="um-ie-row">
        <div class="um-ie-cell">
            <label class="um-ie-lbl"><?= t('acp_um_username', [], 'Username') ?></label>
            <input type="text" name="u_name" class="um-ie-field" required placeholder="Account name">
        </div>
        <div class="um-ie-cell">
            <label class="um-ie-lbl"><?= t('acp_um_email', [], 'Email') ?></label>
            <input type="email" name="u_email" class="um-ie-field" required placeholder="user@example.com">
        </div>
        <div class="um-ie-cell">
            <label class="um-ie-lbl"><?= t('acp_um_password', [], 'Password') ?></label>
            <input type="password" name="u_pass" class="um-ie-field" required placeholder="••••••••">
        </div>
        <div class="um-ie-cell">
            <label class="um-ie-lbl"><?= t('acp_um_authlvl', [], 'Access Level') ?></label>
            <select name="u_priv" class="um-ie-field">
                <?php if ($maxAssignablePriv >= 1): ?><option value="1"><?= t('acp_um_authlvl', [], 'AuthLvl') ?> 1 — <?= t('acp_um_role_player', [], 'Player') ?></option><?php endif; ?>
                <?php if ($maxAssignablePriv >= 2): ?><option value="2"><?= t('acp_um_authlvl', [], 'AuthLvl') ?> 2 — <?= t('acp_um_role_councillor', [], 'Councillor') ?></option><?php endif; ?>
                <?php if ($maxAssignablePriv >= 3): ?><option value="3"><?= t('acp_um_authlvl', [], 'AuthLvl') ?> 3 — <?= t('acp_um_role_staff', [], 'Staff') ?></option><?php endif; ?>
                <?php if ($maxAssignablePriv >= 4): ?><option value="4"><?= t('acp_um_authlvl', [], 'AuthLvl') ?> 4 — <?= t('acp_um_role_admin', [], 'Admin') ?></option><?php endif; ?>
                <?php if ($maxAssignablePriv >= 5): ?><option value="5"><?= t('acp_um_authlvl', [], 'AuthLvl') ?> 5 — <?= t('acp_um_role_superadmin', [], 'Super Admin') ?></option><?php endif; ?>
            </select>
        </div>
    </div>

    <div class="um-ie-footer">
        <div class="um-ie-footer-left"></div>
        <div class="um-ie-footer-right">
            <button type="button" class="um-ie-cancel"
                    onclick="document.getElementById('nexus-ajax-container').innerHTML=''">
                <i class="fas fa-times"></i> <?= t('acp_um_cancel.btn', [], 'Cancel') ?>
            </button>
            <button type="submit" class="um-ie-save">
                <i class="fas fa-plus"></i> <?= t('acp_um_create.btn', [], 'Create') ?>
            </button>
        </div>
    </div>
</form>
