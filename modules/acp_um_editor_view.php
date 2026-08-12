<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!isset($u_data)) return;

$userPriv   = (int)($_SESSION['priv_level'] ?? 0);
$myUserId   = (int)($_SESSION['user_id']    ?? 0);
$targetPriv = (int)$u_data['priv_level'];
$targetId   = (int)$u_data['id'];
$view_token = generateToken();

$isAuth3        = ($userPriv === 3);
$isAuth3onAuth2 = ($isAuth3 && $targetPriv === 2);
$isSuperadminTarget = ($targetPriv >= 5);
$maxAssignablePriv  = ($userPriv >= 5) ? 5 : (($userPriv === 4) ? 3 : 2);
$canManageTarget    = ($userPriv >= 5) || ($userPriv === 4 && $targetPriv <= 3) || ($userPriv === 3 && $targetPriv <= 2);
$canDelete          = ($userPriv >= 4 && $canManageTarget) && !($userPriv >= 5 && $targetId === $myUserId);
?>
<div class="um-editor-container">

    <div class="um-ed-header">
        <div class="um-ed-profile">
            <div class="um-avatar-wrap acp-s-6a19fc17">
                <div class="um-avatar-circle <?= ($userPriv >= 4) ? 'um-avatar-clickable' : '' ?>"
                     <?= ($userPriv >= 4) ? 'onclick="document.getElementById(\'u_avatar_'.$targetId.'\').click();"' : '' ?>>
                    <?php if (!empty($u_data['avatar_url'])): ?>
                        <img src="<?= h($u_data['avatar_url']) ?>?t=<?= time() ?>">
                    <?php else: ?>
                        <i class="fas fa-user"></i>
                    <?php endif; ?>
                </div>
                <?php if ($userPriv >= 4 && !empty($u_data['avatar_url'])): ?>
                <button type="button" class="um-avatar-remove"
                    onclick="if(confirm('<?= addslashes(t('um_editor.confirm_remove_avatar', [], 'Remove avatar?')) ?>')){
                        const fd=new URLSearchParams();
                        fd.append('um_action','delete_avatar');fd.append('target_id','<?= $targetId ?>');
                        fd.append('csrf_token','<?= $view_token ?>');fd.append('can_edit','1');
                        fetch('modules/acp_um_sync_worker.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:fd})
                        .then(r=>r.text()).then(t=>{if(t.trim()==='SUCCESS') loadUserEditor(<?= $targetId ?>);});
                    }">✕</button>
                <?php endif; ?>
            </div>
            <?php if ($userPriv >= 4): ?>
            <input type="file" id="u_avatar_<?= $targetId ?>" style="display:none;" accept="image/*"
                   onchange="
                       const fd=new FormData();
                       fd.append('u_avatar',this.files[0]);
                       fd.append('um_action','update_full');
                       fd.append('target_id','<?= $targetId ?>');
                       fd.append('csrf_token','<?= $view_token ?>');
                       fd.append('can_edit','1');
                       fetch('modules/acp_um_sync_worker.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
                       .then(r=>r.text()).then(()=>loadUserEditor(<?= $targetId ?>));
                   ">
            <?php endif; ?>
            <div class="um-ed-profile-info">
                <span class="um-ed-name"><?= h($u_data['username']) ?></span>
                <span class="um-ed-meta">
                    ID:<?= $u_data['id'] ?> &nbsp;·&nbsp; <?= t('acp_um_authlvl', [], 'AuthLvl') ?><?= $targetPriv ?>
                    <?php if ($isSuperadminTarget): ?>
                    <span class="um-ed-protected"><i class="fas fa-shield-alt"></i> <?= t('acp_um_super_admin_prot', [], 'Super Admin - Protected') ?></span>
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <div class="um-ed-actions">
            <?php if ($targetPriv <= 3 && $userPriv >= 4): ?>
                <?php if ((int)($u_data['is_verified'] ?? 0) === 0): ?>
                    <button type="button" class="um-btn-ghost um-btn-sm" style="color:var(--gold); border-color:var(--gold);"
                        onclick="if(confirm('<?= addslashes(t('um_editor.confirm_verify', [], 'Verify this account?')) ?>')){
                            const fd=new URLSearchParams();
                            fd.append('um_action','toggle_verify');fd.append('target_id','<?= $targetId ?>');
                            fd.append('status','1');fd.append('csrf_token','<?= $view_token ?>');fd.append('can_edit','1');
                            fetch('modules/acp_um_sync_worker.php',{method:'POST',body:fd}).then(r=>r.text()).then(t=>{if(t.trim()==='SUCCESS') loadUserEditor(<?= $targetId ?>); else alert(t);}).catch(e=>alert('Error: '+e));
                        }"><i class="fas fa-check-circle"></i> <?= t('acp_um_verify_account', [], 'Verify Account') ?></button>
                <?php else: ?>
                    <button type="button" class="um-btn-ghost um-btn-sm" style="color:var(--amber-warn); border-color:var(--amber-warn);"
                        onclick="if(confirm('<?= addslashes(t('um_editor.confirm_unverify', [], 'Set this account to unverified?')) ?>')){
                            const fd=new URLSearchParams();
                            fd.append('um_action','toggle_verify');fd.append('target_id','<?= $targetId ?>');
                            fd.append('status','0');fd.append('csrf_token','<?= $view_token ?>');fd.append('can_edit','1');
                            fetch('modules/acp_um_sync_worker.php',{method:'POST',body:fd}).then(r=>r.text()).then(t=>{if(t.trim()==='SUCCESS') loadUserEditor(<?= $targetId ?>); else alert(t);}).catch(e=>alert('Error: '+e));
                        }"><i class="fas fa-times-circle"></i> <?= t('acp_um_unverify_account', [], 'Unverify Account') ?></button>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($canDelete): ?>
            <button type="button" class="um-btn-danger um-btn-sm"
                onclick="if(confirm('DELETE <?= addslashes($u_data['username']) ?>?')){
                    const fd=new URLSearchParams();
                    fd.append('um_action','delete_user');fd.append('target_id','<?= $targetId ?>');
                    fd.append('csrf_token','<?= $view_token ?>');fd.append('can_edit','1');
                    fetch('modules/acp_um_sync_worker.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:fd})
                    .then(r=>r.text()).then(t=>{if(t.trim()==='SUCCESS'){alert('Purged.');loadCategory('all');}else alert(t);});
                }"><i class="fas fa-trash"></i> <?= t('btn_delete', [], 'Delete') ?></button>
            <?php endif; ?>
        </div>
    </div>

    <div class="um-acc-section" id="um-acc-cms-<?= $targetId ?>">
        <div class="um-acc-toggle" onclick="umAccToggle('um-acc-cms-<?= $targetId ?>')">
            <i class="fas fa-chevron-right um-acc-icon"></i>
            <i class="fas fa-shield-halved" style="opacity:0.5;margin-right:6px;"></i>
            <?= t('acp_um_cms_settings', [], 'CMS Settings') ?>
            <span class="um-acc-meta"><?= t('acp_um_authlvl', [], 'AuthLvl') ?><?= $targetPriv ?><?= !empty($u_data['user_title']) ? ' · ' . h($u_data['user_title']) : '' ?></span>
        </div>
        <div class="um-acc-body">
            <form class="um-acc-form"
                  onsubmit="event.preventDefault();umAccSave(this,<?= $targetId ?>,'<?= $view_token ?>');"
                  enctype="multipart/form-data">
                <input type="hidden" name="target_id" value="<?= $targetId ?>">
                <input type="hidden" name="um_action" value="update_full">
                <div class="um-acc-fields">
                    <div class="um-acc-field">
                        <label class="um-ed-section-title"><?= t('acp_um_privilege_cms', [], 'Privilege Level (CMS)') ?></label>
                        <select name="u_priv" class="um-field" <?= ($userPriv < 4) ? 'disabled' : '' ?>>
                            <?php for ($i = 1; $i <= $maxAssignablePriv; $i++): ?>
                            <option value="<?= $i ?>" <?= ($u_data['priv_level'] == $i) ? 'selected' : '' ?>>Level <?= $i ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="um-acc-field">
                        <label class="um-ed-section-title"><?= t('acp_um_staff_title', [], 'CMS Staff Title') ?></label>
                        <input type="text" name="u_title" value="<?= h($u_data['user_title'] ?? '') ?>"
                               class="um-field" <?= ($userPriv < 4) ? 'disabled' : '' ?>
                               placeholder="e.g. Lead Developer">
                    </div>
                    <div class="um-acc-field">
                        <label class="um-ed-section-title">
                            <?= t('acp_um_standing', [], 'Standing') ?>
                            <?php if ($isAuth3onAuth2): ?><span class="um-ed-hint-inline">(n/a)</span>
                            <?php elseif ($isAuth3): ?><span class="um-ed-hint-inline">(max 3)</span><?php endif; ?>
                        </label>
                        <select name="u_stand" class="um-field" <?= $isAuth3onAuth2 ? 'disabled' : '' ?>
                                onchange="
                                    const r=document.getElementById('reason_row_<?= $targetId ?>');
                                    const f=document.getElementById('reason_field_<?= $targetId ?>');
                                    if(this.value>0){r.style.display='flex';f.required=true;}
                                    else{r.style.display='none';f.required=false;}">
                            <?php
                            $maxStanding = ($isAuth3) ? 3 : 4;
                            for ($i = 0; $i <= $maxStanding; $i++) {
                                echo "<option value='$i'" . ($u_data['standing'] == $i ? ' selected' : '') . ">$i — " . getStandingText($i) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="um-acc-field" id="reason_row_<?= $targetId ?>"
                         style="display:<?= ($u_data['standing'] > 0 && !$isAuth3onAuth2) ? 'flex' : 'none' ?>;flex-direction:column;gap:3px;">
                        <label class="um-ed-section-title"><?= t('um_editor.label_standing_reason', [], 'Standing Reason (Mandatory)') ?> <span class="um-ed-required">*</span></label>
                        <textarea name="u_reason" id="reason_field_<?= $targetId ?>"
                                  class="um-field um-field--ta" style="height:44px;"
                                  <?= ($u_data['standing'] > 0) ? 'required' : '' ?>><?= h($u_data['standing_reason'] ?? '') ?></textarea>
                    </div>
                </div>
                <input type="hidden" name="u_ingame_priv" value="<?= (int)($u_data['ingame_priv'] ?? 1) ?>">
                <input type="hidden" name="u_bio"         value="<?= h($u_data['description'] ?? '') ?>">
                <input type="hidden" name="forum_signature" value="<?= h($u_data['forum_signature'] ?? '') ?>">
                <div class="um-acc-footer">
                    <button type="submit" class="um-btn-save"><i class="fas fa-check"></i> <?= t('acp_save_changes', [], 'Save Changes') ?></button>
                </div>
            </form>
        </div>
    </div>

    <div class="um-acc-section" id="um-acc-ingame-<?= $targetId ?>">
        <div class="um-acc-toggle" onclick="umAccToggle('um-acc-ingame-<?= $targetId ?>')">
            <i class="fas fa-chevron-right um-acc-icon"></i>
            <i class="fas fa-gamepad" style="opacity:0.5;margin-right:6px;"></i>
            <?= t('acp_um_ingame_settings', [], 'Ingame Settings') ?>
            <span class="um-acc-meta">Game Priv <?= (int)($u_data['ingame_priv'] ?? 1) ?></span>
        </div>
        <div class="um-acc-body">
            <form class="um-acc-form"
                  onsubmit="event.preventDefault();umAccSave(this,<?= $targetId ?>,'<?= $view_token ?>');"
                  enctype="multipart/form-data">
                <input type="hidden" name="target_id" value="<?= $targetId ?>">
                <input type="hidden" name="um_action" value="update_full">
                <div class="um-acc-fields">
                    <div class="um-acc-field">
                        <label class="um-ed-section-title">Ingame PrivLevel</label>
                        <select name="u_ingame_priv" class="um-field" <?= ($userPriv < 4) ? 'disabled' : '' ?>>
                            <option value="1" <?= ((int)($u_data['ingame_priv'] ?? 1) === 1) ? 'selected' : '' ?>>1 — <?= t('acp_um_player', [], 'Player') ?></option>
                            <option value="2" <?= ((int)($u_data['ingame_priv'] ?? 1) === 2) ? 'selected' : '' ?>>2 — GM</option>
                            <option value="3" <?= ((int)($u_data['ingame_priv'] ?? 1) === 3) ? 'selected' : '' ?>>3 — Admin</option>
                        </select>
                    </div>
                    <div class="um-acc-field" style="opacity:0.3;pointer-events:none;">
                        <label class="um-ed-section-title"><?= t('acp_more_options', [], 'More options') ?></label>
                        <input type="text" class="um-field" placeholder="Coming soon…" disabled>
                    </div>
                </div>
                <input type="hidden" name="u_priv"    value="<?= $targetPriv ?>">
                <input type="hidden" name="u_title"   value="<?= h($u_data['user_title'] ?? '') ?>">
                <input type="hidden" name="u_stand"   value="<?= (int)$u_data['standing'] ?>">
                <input type="hidden" name="u_reason"  value="<?= h($u_data['standing_reason'] ?? '') ?>">
                <input type="hidden" name="u_bio"     value="<?= h($u_data['description'] ?? '') ?>">
                <input type="hidden" name="forum_signature" value="<?= h($u_data['forum_signature'] ?? '') ?>">
                <div class="um-acc-footer">
                    <button type="submit" class="um-btn-save"><i class="fas fa-check"></i> <?= t('acp_save_changes', [], 'Save Changes') ?></button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($userPriv >= 4 && !$isAuth3): ?>
    <div class="um-acc-section" id="um-acc-pw-<?= $targetId ?>">
        <div class="um-acc-toggle" onclick="umAccToggle('um-acc-pw-<?= $targetId ?>')">
            <i class="fas fa-chevron-right um-acc-icon"></i>
            <i class="fas fa-lock" style="opacity:0.5;margin-right:6px;"></i>
            <?= t('acp_um_password', [], 'Password') ?>
            <span class="um-acc-meta">Bridge Sync</span>
        </div>
        <div class="um-acc-body">
            <form class="um-acc-form"
                  onsubmit="event.preventDefault();umAccSave(this,<?= $targetId ?>,'<?= $view_token ?>');"
                  enctype="multipart/form-data">
                <input type="hidden" name="target_id" value="<?= $targetId ?>">
                <input type="hidden" name="um_action" value="update_full">
                <div class="um-acc-fields">
                    <div class="um-acc-field">
                        <label class="um-ed-section-title"><?= t('acp_um_new_password', [], 'New Password') ?></label>
                        <input type="password" name="u_new_password" class="um-field"
                               placeholder="Leave empty to keep current">
                    </div>
                </div>
                <input type="hidden" name="u_priv"       value="<?= $targetPriv ?>">
                <input type="hidden" name="u_title"      value="<?= h($u_data['user_title'] ?? '') ?>">
                <input type="hidden" name="u_stand"      value="<?= (int)$u_data['standing'] ?>">
                <input type="hidden" name="u_reason"     value="<?= h($u_data['standing_reason'] ?? '') ?>">
                <input type="hidden" name="u_ingame_priv" value="<?= (int)($u_data['ingame_priv'] ?? 1) ?>">
                <input type="hidden" name="u_bio"        value="<?= h($u_data['description'] ?? '') ?>">
                <input type="hidden" name="forum_signature" value="<?= h($u_data['forum_signature'] ?? '') ?>">
                <div class="um-acc-footer">
                    <button type="submit" class="um-btn-save"><i class="fas fa-check"></i> <?= t('acp_save_changes', [], 'Save Changes') ?></button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="um-acc-section" id="um-acc-profile-<?= $targetId ?>">
        <div class="um-acc-toggle" onclick="umAccToggle('um-acc-profile-<?= $targetId ?>')">
            <i class="fas fa-chevron-right um-acc-icon"></i>
            <i class="fas fa-scroll" style="opacity:0.5;margin-right:6px;"></i>
            <?= t('acp_um_profile', [], 'Profile') ?>
            <span class="um-acc-meta"><?= t('acp_um_signature', [], 'Forum Signature') ?> &amp; <?= t('acp_um_biography', [], 'User Biography') ?></span>
        </div>
        <div class="um-acc-body">
            <form class="um-acc-form"
                  onsubmit="event.preventDefault();umAccSave(this,<?= $targetId ?>,'<?= $view_token ?>');"
                  enctype="multipart/form-data">
                <input type="hidden" name="target_id" value="<?= $targetId ?>">
                <input type="hidden" name="um_action" value="update_full">
                <div class="um-acc-fields um-acc-fields--wide">
                    <div class="um-acc-field">
                        <label class="um-ed-section-title"><?= t('acp_um_signature', [], 'Forum Signature') ?></label>
                        <textarea name="forum_signature" class="um-field um-field--ta"><?= h($u_data['forum_signature'] ?? '') ?></textarea>
                    </div>
                    <div class="um-acc-field">
                        <label class="um-ed-section-title"><?= t('acp_um_biography', [], 'User Biography') ?></label>
                        <textarea name="u_bio" class="um-field um-field--ta" placeholder="Character lore..."><?= h($u_data['description'] ?? '') ?></textarea>
                    </div>
                </div>
                <input type="hidden" name="u_priv"       value="<?= $targetPriv ?>">
                <input type="hidden" name="u_title"      value="<?= h($u_data['user_title'] ?? '') ?>">
                <input type="hidden" name="u_stand"      value="<?= (int)$u_data['standing'] ?>">
                <input type="hidden" name="u_reason"     value="<?= h($u_data['standing_reason'] ?? '') ?>">
                <input type="hidden" name="u_ingame_priv" value="<?= (int)($u_data['ingame_priv'] ?? 1) ?>">
                <div class="um-acc-footer">
                    <button type="submit" class="um-btn-save"><i class="fas fa-check"></i> <?= t('acp_save_changes', [], 'Save Changes') ?></button>
                </div>
            </form>
        </div>
    </div>

</div><script>
function umAccToggle(id) {
    var sec = document.getElementById(id);
    if (!sec) return;
    sec.classList.toggle('um-acc-open');
}
function umAccSave(form, targetId, token) {
    var fd = new FormData(form);
    fd.append('csrf_token', token);
    fd.append('can_edit', '1');
    var btn = form.querySelector('.um-btn-save');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }
    fetch('modules/acp_um_sync_worker.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.text())
    .then(t => {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Saved'; setTimeout(() => { btn.innerHTML = '<i class="fas fa-check"></i> Save'; }, 1500); }
        loadUserEditor(targetId);
    })
    .catch(err => {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Save'; }
        alert('Error: ' + err);
    });
}

document.querySelectorAll('.um-editor-container script').forEach(function(s) {
    var sc = document.createElement('script');
    sc.textContent = s.textContent;
    document.head.appendChild(sc);
});
</script>
