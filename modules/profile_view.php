<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!isset($_SESSION['user_id'])) return;

$is_verified = (int)($_SESSION['is_verified'] ?? 0);

if ($is_verified === 0) {
    echo '<div class="admin-container profile-verify-required">';
    echo '<i class="fas fa-envelope-open-text profile-verify-icon"></i>';
    echo '<h2 class="profile-verify-title">' . t('profile_verification_req_title', [], 'Verification Required') . '</h2>';
    echo '<p class="profile-verify-desc">' . t('profile_verification_req_desc', [], 'You must verify your email address to access and edit your profile settings.') . '</p>';
    echo '</div>';
    return;
}

$uid = (int)$_SESSION['user_id'];

$stmt_me = $db->prepare("SELECT u.* FROM users u WHERE u.id = ?");
$stmt_me->execute([$uid]);
$me_view = $stmt_me->fetch();

if (!$me_view) return;

$me_view['ingame_priv'] = daoc_game_account_privilege($db, (string)$me_view['username']);

$standing_map = [
    0 => ['label' => t('acp_um_standing_0', [], 'Good'), 'color' => '#00ff00'],
    1 => ['label' => t('acp_um_standing_1', [], 'Warning I'), 'color' => '#ffff00'],
    2 => ['label' => t('acp_um_standing_2', [], 'Warning II'), 'color' => '#ffaa00'],
    3 => ['label' => t('acp_um_standing_3', [], 'Restricted'), 'color' => '#ff6600'],
    4 => ['label' => t('acp_um_standing_4', [], 'Suspended'), 'color' => '#ff0000'],
    5 => ['label' => t('acp_um_standing_5', [], 'Banned'), 'color' => '#440000'],
];

$s_val         = (int)$me_view['standing'];
$cur_std       = $standing_map[$s_val] ?? $standing_map[0];
$is_restricted = ($s_val >= 3);
$profile_token = generateToken();

$ALDHRAN_REALM_INFO = [
    1 => ['name' => 'Albion',   'color' => '#c0392b'],
    2 => ['name' => 'Midgard',  'color' => '#2980b9'],
    3 => ['name' => 'Hibernia', 'color' => '#27ae60'],
];

$ALDHRAN_CLASS_NAMES = [
    1  => 'Paladin', 2 => 'Armsman', 3 => 'Scout', 4 => 'Minstrel', 5 => 'Theurgist',
    6  => 'Cleric', 7 => 'Wizard', 8 => 'Sorcerer', 9 => 'Infiltrator', 10 => 'Friar',
    11 => 'Mercenary', 12 => 'Necromancer', 13 => 'Cabalist', 19 => 'Reaver',
    33 => 'Heretic', 60 => 'Mauler (Alb)',
    21 => 'Thane', 22 => 'Warrior', 23 => 'Shadowblade', 24 => 'Skald', 25 => 'Hunter',
    26 => 'Healer', 27 => 'Spiritmaster', 28 => 'Shaman', 29 => 'Runemaster',
    30 => 'Bonedancer', 31 => 'Berserker', 32 => 'Savage', 34 => 'Valkyrie',
    59 => 'Warlock', 61 => 'Mauler (Mid)',
    39 => 'Bainshee', 40 => 'Eldritch', 41 => 'Enchanter', 42 => 'Mentalist',
    43 => 'Blademaster', 44 => 'Hero', 45 => 'Champion', 46 => 'Warden', 47 => 'Druid',
    48 => 'Bard', 49 => 'Nightshade', 50 => 'Ranger', 55 => 'Animist',
    56 => 'Valewalker', 62 => 'Mauler (Hib)',
    58 => 'Vampiir',
];

if (!isset($my_chars)) {
    $my_chars = daoc_game_characters_for_account($db, (string)($_SESSION['username'] ?? ''));
}
?>

<?php if (!empty($me_view['avatar_url']) && !$is_restricted): ?>
<form method="POST" action="index.php?p=profile" id="delete_av_form" class="profile-hidden-form">
    <input type="hidden" name="csrf_token"       value="<?php echo $profile_token; ?>">
    <input type="hidden" name="delete_my_avatar" value="1">
</form>
<?php endif; ?>

<div class="admin-container">

    <?php
    $msg = $_GET['msg'] ?? '';
    if ($msg === 'pw_too_short'): ?>
        <div class="profile-msg profile-msg--error">
            <i class="fas fa-exclamation-triangle"></i> <?= t('profile_pw_too_short', [], 'Password too short! It must be at least 8 characters.') ?>
        </div>
    <?php elseif ($msg === 'move_failed'): ?>
        <div class="profile-msg profile-msg--error">
            <i class="fas fa-exclamation-triangle"></i> <?= t('profile_move_failed', [], "Upload failed. The folder 'assets/img/avatars/' needs write permissions (CHMOD 777).") ?>
        </div>
    <?php elseif (str_starts_with($msg, 'upload_error_')): ?>
        <div class="profile-msg profile-msg--error">
            <i class="fas fa-exclamation-triangle"></i> <?= t('profile_upload_error', [], 'File too large or blocked by server.') ?> (PHP Error Code: <?= (int)str_replace('upload_error_', '', $msg) ?>)
        </div>
    <?php elseif ($msg === 'invalid_file'): ?>
        <div class="profile-msg profile-msg--error">
            <i class="fas fa-exclamation-triangle"></i> <?= t('profile_invalid_file', [], 'Invalid file format. Please use JPG, PNG, GIF or WEBP only.') ?>
        </div>
    <?php endif; ?>

    <div class="profile-standing" <?php if ($s_val >= 1 && !empty($me_view['standing_reason'])) echo 'style="cursor:pointer;" onclick="document.getElementById(\'standing_reason_box\').style.display = document.getElementById(\'standing_reason_box\').style.display === \'none\' ? \'block\' : \'none\';" title="' . t('profile_click_for_reason', [], 'Click for details') . '"'; ?>>
        <div class="profile-standing-dot" style="background:<?php echo $cur_std['color']; ?>; box-shadow:0 0 6px <?php echo $cur_std['color']; ?>;"></div>
        <div>
            <span class="profile-standing-label"><?= t('profile_view_standing', [], 'Account Standing') ?></span>
            <span class="profile-standing-value" style="color:<?php echo $cur_std['color']; ?>;">
                <?php echo h($cur_std['label']); ?>
                <?php if ($s_val >= 1 && !empty($me_view['standing_reason'])): ?>
                    <i class="fas fa-info-circle profile-standing-info"></i>
                <?php endif; ?>
            </span>
        </div>
    </div>

    <?php if ($s_val >= 1 && !empty($me_view['standing_reason'])): ?>
    <div id="standing_reason_box" class="profile-standing-reason" style="border-left-color:<?php echo $cur_std['color']; ?>; display:none;">
        <strong style="color:<?php echo $cur_std['color']; ?>"><?= t('profile_reason', [], 'Reason:') ?></strong><br>
        <?php echo nl2br(h($me_view['standing_reason'])); ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($my_chars)): ?>
    <details class="profile-chars-details">
        <summary class="profile-chars-summary">
            <i class="fas fa-chevron-right profile-chars-chevron"></i>
            <?= t('profile_view_my_characters', [], 'My ingame characters') ?> <span class="profile-chars-count">(<?php echo count($my_chars); ?>)</span>
        </summary>

        <div class="profile-chars-list">
            <?php foreach ($my_chars as $char):
                $cls_id   = (int)($char['Class'] ?? 0);
                $cls_name = $ALDHRAN_CLASS_NAMES[$cls_id] ?? ('Class #' . $cls_id);
                $realm_id = (int)($char['Realm'] ?? 0);
                $realm    = $ALDHRAN_REALM_INFO[$realm_id] ?? ['name' => t('profile_realm_unknown', [], 'Unknown Realm'), 'color' => '#555'];
            ?>
                <div class="profile-chars-row">
                    <span class="profile-chars-dot" style="background:<?php echo $realm['color']; ?>;" title="<?php echo h($realm['name']); ?>"></span>
                    <span class="profile-chars-name"><?php echo h($char['Name']); ?></span>
                    <span class="profile-chars-class"><?php echo h($cls_name); ?></span>
                    <span class="profile-chars-level">Lvl <?php echo (int)($char['Level'] ?? 0); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </details>
    <?php endif; ?>

    <details class="profile-chars-details">
        <summary class="profile-chars-summary">
            <i class="fas fa-chevron-right profile-chars-chevron"></i>
            <?= t('profile_edit_settings', [], 'Edit Profile Settings') ?>
        </summary>

        <div class="admin-box profile-settings-box">
            <div class="profile-layout-grid">

                <div class="profile-avatar-container">
                    <form action="index.php?p=profile" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo $profile_token; ?>">

                        <div class="profile-avatar-wrap">
                            <?php if (!empty($me_view['avatar_url'])): ?>
                                <img src="<?php echo h(ltrim($me_view['avatar_url'], '/')); ?>"
                                     class="team-avatar <?= $is_restricted ? 'profile-avatar--restricted' : '' ?>">
                                <?php if (!$is_restricted): ?>
                                    <button type="button"
                                            class="btn-avatar-delete"
                                            onclick="if(confirm('Remove your avatar?')) document.getElementById('delete_av_form').submit();">
                                        <i class="fas fa-trash-alt profile-avatar-trash"></i>
                                    </button>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="team-avatar-placeholder">
                                    <i class="fas fa-user-circle profile-avatar-placeholder-icon"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if (!$is_restricted): ?>
                            <input type="file" name="avatar" id="av_upload" class="profile-avatar-input" onchange="this.form.submit();">
                            <label for="av_upload" class="btn-gold profile-avatar-label">
                                <?= t('profile_lable_change_image', [], 'Change Image') ?>
                            </label>
                        <?php endif; ?>
                    </form>
                </div>

                <div>
                    <form action="index.php?p=profile" method="POST">
                        <input type="hidden" name="csrf_token"     value="<?php echo $profile_token; ?>">
                        <input type="hidden" name="update_profile" value="1">

                        <?php if ((int)$me_view['priv_level'] >= 5): ?>
                        <div class="profile-field">
                            <label class="um-label"><?= t('acp_um_staff_title', [], 'CMS Rank Name') ?></label>
                            <input type="text" name="u_title"
                                   value="<?php echo h($me_view['user_title'] ?? ''); ?>"
                                   <?php echo $is_restricted ? 'readonly' : ''; ?>
                                   class="um-input">
                        </div>
                        <div class="profile-field">
                            <label class="um-label">Ingame PrivLevel</label>
                            <select name="u_ingame_priv" class="um-input" <?php echo $is_restricted ? 'disabled' : ''; ?>>
                                <option value="1" <?= ((int)($me_view['ingame_priv'] ?? 1) === 1) ? 'selected' : '' ?>>1 — <?= t('acp_um_player', [], 'Player') ?></option>
                                <option value="2" <?= ((int)($me_view['ingame_priv'] ?? 1) === 2) ? 'selected' : '' ?>>2 — GM</option>
                                <option value="3" <?= ((int)($me_view['ingame_priv'] ?? 1) === 3) ? 'selected' : '' ?>>3 — Admin</option>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="profile-field">
                            <label class="um-label"><?= t('profile.label_languages', [], 'Languages') ?></label>
                            <input type="text" name="u_langs"
                                   value="<?php echo h($me_view['languages'] ?? ''); ?>"
                                   <?php echo $is_restricted ? 'readonly' : ''; ?>
                                   class="um-input">
                        </div>
                        <div class="profile-field">
                            <label class="um-label"><?= t('profile.label_biography', [], 'Biography') ?></label>
                            <textarea name="u_desc"
                                      <?php echo $is_restricted ? 'readonly' : ''; ?>
                                      class="um-input profile-textarea"
                                      style="height:80px; resize:none;"><?php echo h($me_view['description'] ?? ''); ?></textarea>
                        </div>
                        <div class="profile-sig-field profile-field">
                            <label class="um-label"><?= t('profile.label_signature', [], 'Forum Signature') ?></label>
                            <textarea name="u_sig"
                                      <?php echo $is_restricted ? 'readonly' : ''; ?>
                                      class="um-input profile-textarea"
                                      style="height:60px; resize:none;"><?php echo h($me_view['forum_signature'] ?? ''); ?></textarea>
                        </div>
                        <div class="profile-field profile-pw-field">
                            <label class="um-label"><?= t('profile.label_password', [], 'Change Password (Updates Game & Forum)') ?></label>
                            <input type="password" name="new_pw"
                                   <?php echo $is_restricted ? 'readonly' : ''; ?>
                                   class="um-input"
                                   autocomplete="new-password"
                                   value=""
                                   minlength="8"
                                   placeholder="<?= t('profile_leave_empty', [], 'Leave empty to keep current') ?>">
                        </div>
                        <?php if (!$is_restricted): ?>
                            <button type="submit" class="btn-gold profile-save-btn"><?= t('profile_lable_update', [], 'Update Profil') ?></button>
                        <?php endif; ?>
                    </form>
                </div>

            </div>
        </div>
    </details>

    <details class="profile-chars-details">
        <summary class="profile-chars-summary">
            <i class="fas fa-chevron-right profile-chars-chevron"></i>
            <i class="fas fa-shield-alt"></i> <?= t('profile_2fa_title', [], 'Two-Factor Authentication (2FA)') ?>
        </summary>

        <div class="admin-box profile-settings-box">
            <?php if ((int)$me_view['is_2fa_enabled'] === 1): ?>
                <div class="profile-2fa-active">
                    <i class="fas fa-check-circle"></i> <?= t('profile_2fa_active', [], '2FA is currently active on your account.') ?>
                </div>
                <form method="POST" action="index.php?p=profile">
                    <input type="hidden" name="csrf_token" value="<?php echo $profile_token; ?>">
                    <div class="profile-2fa-disable-row">
                        <input type="password" name="confirm_pw" class="um-input profile-2fa-pw-input" placeholder="<?= t('profile_label_password', [], 'Confirm Password') ?>" required>
                        <button type="submit" name="disable_2fa" class="btn-gold profile-2fa-disable-btn">
                            <?= t('profile_2fa_btn_disable', [], 'Disable 2FA') ?>
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <?php
                if (empty($_SESSION['totp_setup_secret'])) {
                    $_SESSION['totp_setup_secret'] = TOTP::generateSecret();
                }
                $setup_secret = $_SESSION['totp_setup_secret'];
                $otp_uri = TOTP::getProvisioningUri($me_view['username'], $setup_secret, 'DAoC CMS');
                $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . urlencode($otp_uri);
                ?>
                <p class="profile-2fa-desc">
                    <?= t('profile_2fa_setup_desc', [], 'Scan the QR code with your authenticator app (e.g. Google Authenticator) and enter the 6-digit code to enable 2FA.') ?>
                </p>
                <div class="profile-2fa-setup">
                    <img src="<?php echo h($qr_url); ?>" alt="2FA QR Code" class="profile-2fa-qr">
                    <div>
                        <div class="profile-2fa-secret-label"><?= t('profile_2fa_secret_key', [], 'Secret Key:') ?></div>
                        <code class="profile-2fa-secret"><?php echo h($setup_secret); ?></code>
                        <form method="POST" action="index.php?p=profile">
                            <input type="hidden" name="csrf_token" value="<?php echo $profile_token; ?>">
                            <div class="profile-2fa-enable-row">
                                <input type="text" name="totp_code" class="um-input profile-2fa-code-input" placeholder="123456" maxlength="6" pattern="\d{6}" required>
                                <button type="submit" name="enable_2fa" class="btn-gold">
                                    <?= t('profile_2fa_btn_enable', [], 'Activate 2FA') ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </details>

    <details class="profile-chars-details">
        <summary class="profile-chars-summary profile-gdpr-summary">
            <i class="fas fa-chevron-right profile-chars-chevron"></i>
            <i class="fas fa-user-shield"></i> <?= t('profile_gdpr_title', [], 'Privacy & Data') ?>
        </summary>

        <div class="admin-box profile-settings-box profile-settings-box--danger">

            <div class="profile-gdpr-export">
                <p class="profile-gdpr-desc">
                    <?= t('profile_gdpr_export_desc', [], 'Download a copy of your account and character data as a JSON file.') ?>
                </p>
                <form method="POST" action="index.php?p=profile">
                    <input type="hidden" name="csrf_token" value="<?php echo $profile_token; ?>">
                    <button type="submit" name="export_my_data" class="btn-gold profile-gdpr-btn">
                        <i class="fas fa-file-download"></i> <?= t('profile_gdpr_btn_export', [], 'Export Data') ?>
                    </button>
                </form>
            </div>

            <?php if ((int)$me_view['priv_level'] < 5): ?>
            <div class="profile-gdpr-delete">
                <p class="profile-gdpr-desc">
                    <?= t('profile_gdpr_delete_desc', [], 'Permanently delete your CMS account. Your game characters will be anonymized to preserve server history.') ?>
                </p>
                <form method="POST" action="index.php?p=profile" onsubmit="return confirm('<?= t('profile_gdpr_confirm', [], 'Are you absolutely sure? This cannot be undone!') ?>');">
                    <input type="hidden" name="csrf_token" value="<?php echo $profile_token; ?>">
                    <div class="profile-del-flex">
                        <input type="password" name="del_pw" class="um-input profile-del-pw-input" placeholder="<?= t('profile_label_password', [], 'Confirm Password') ?>" required>
                        <button type="submit" name="delete_my_account" class="spike-editor-btn spike-editor-btn--cancel profile-del-btn">
                            <i class="fas fa-skull"></i> <?= t('profile_gdpr_btn_delete', [], 'Delete Account') ?>
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

        </div>
    </details>

</div>
