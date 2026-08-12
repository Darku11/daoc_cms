<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) exit;
if (($GLOBALS['cms_settings']['mod_register'] ?? '1') === '0' && ($GLOBALS['userPriv'] ?? 0) < 4) {
    echo '<div class="info-msg">' . t('general.module_disabled', [], 'This section is currently not available.') . '</div>';
    return;
}
if (isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }

$turnstile_enabled = ($GLOBALS['cms_settings']['turnstile_enabled'] ?? '0') === '1';
?>
<?php if ($turnstile_enabled): ?>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
<?php endif; ?>

<div class="reg-page">
    <div class="reg-box">

        <p class="reg-instructions"><?= t('register_form_instructions', [], 'Fill in the fields below to create your account.') ?></p>

        <?php if (!empty($register_errors)): ?>
            <div class="reg-errors">
                <?php foreach ($register_errors as $e): ?>
                    <div class="reg-errors-item"><i class="fas fa-minus"></i> <?php echo h($e); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off" id="reg-form">
            <input type="hidden" name="csrf_token" value="<?php echo generateToken(); ?>">
            
            <!-- Honeypot -->
            <div style="display:none;" aria-hidden="true">
                <input type="text" name="website" value="" tabindex="-1" autocomplete="off">
            </div>

            <div class="reg-field">
                <label class="reg-label"><?= t('register.label_username', [], 'Username') ?></label>
                <input type="text" name="username" class="reg-input" required
                       placeholder="<?= t('register.placeholder_username', [], 'Enter username…') ?>" autocomplete="off" maxlength="20" minlength="3">
            </div>

            <div class="reg-field">
                <label class="reg-label"><?= t('register.label_email', [], 'Email Address') ?></label>
                <input type="email" name="email" class="reg-input" required placeholder="<?= t('register.placeholder_email', [], 'Enter email…') ?>">
            </div>

            <div class="reg-field">
                <label class="reg-label"><?= t('register.label_password', [], 'Password') ?></label>
                <input type="password" name="password" class="reg-input" required
                       placeholder="<?= t('register.placeholder_password', [], 'Enter password…') ?>" minlength="8">
                <span class="reg-hint"><?= t('register.hint_password', [], 'min. 8 characters &middot; 1 uppercase &middot; 1 number') ?></span>
            </div>

            <div class="reg-field">
                <label class="reg-label"><?= t('register.label_confirm_password', [], 'Confirm Password') ?></label>
                <input type="password" name="confirm_pw" class="reg-input" required
                       placeholder="<?= t('register.placeholder_confirm_password', [], 'Repeat password…') ?>">
            </div>

            <?php if ($turnstile_enabled): ?>
            <!-- Cloudflare Turnstile Widget -->
            <div class="reg-field" style="margin-top: 15px;">
                <div class="cf-turnstile" data-sitekey="<?= h($GLOBALS['cms_settings']['turnstile_sitekey'] ?? '') ?>" data-theme="dark"></div>
            </div>
            <?php endif; ?>

            <div class="reg-field" style="display:flex; align-items:flex-start; gap:8px;">
                <input type="checkbox" name="privacy_accepted" id="privacy_accepted" required value="1" style="margin-top:3px;">
                <label for="privacy_accepted" class="reg-hint" style="margin:0; font-size:0.85em;">
                    <?= t('register_privacy_accept', [], 'I have read and accept the') ?> <a href="index.php?p=privacy" target="_blank" style="color:#c5a059; text-decoration:none;"><?= t('register_privacy_link', [], 'Privacy Policy') ?></a>.
                </label>
            </div>

            <button type="submit" name="register_submit" class="reg-submit"><?= t('register.btn_submit', [], 'Register') ?></button>

            <div class="reg-footer">
                <a href="index.php?p=login" class="reg-footer-link"><?= t('register.link_login', [], 'Already registered? Log in here') ?></a>
            </div>
        </form>

    </div>
</div>