<?php
// SPDX-License-Identifier: GPL-3.0-only
declare(strict_types=1);

if (!isset($installer)) {
    exit;
}

use DAoCCMS\Setup\Britty;

$root     = realpath(__DIR__ . '/../../');
$lockFile = $root . '/install.lock';
$serverCore = $_SESSION['setup_dol']['core'] ?? '';
$serverCoreLabel = match ($serverCore) {
    'opendaoc' => 'OpenDAoC',
    'dol'      => 'Dawn of Light',
    default    => 'Not selected',
};

// Read the values once more before clearing the session.
$summary = [
    'Site'          => $_SESSION['setup_config']['cms_name'] ?? null,
    'Shard'         => $_SESSION['setup_config']['server_name'] ?? null,
    'Address'       => $_SESSION['setup_config']['base_url'] ?? null,
    'CMS database'  => $_SESSION['setup_db']['name'] ?? null,
    'Game database' => $_SESSION['setup_dol']['name'] ?? null,
    'Server core'   => $serverCoreLabel,
    'Administrator' => $_SESSION['setup_admin']['username'] ?? null,
];
$summary = array_filter($summary, fn ($v) => $v !== null && $v !== '');

$loginUrl = ($_SESSION['setup_config']['base_url'] ?? '') !== ''
    ? rtrim($_SESSION['setup_config']['base_url'], '/') . '/index.php'
    : '../index.php';

// Build the backup containing generated keys before clearing the session below.
// Database and administrator passwords are intentionally excluded because they are
// already stored safely in includes/config.php or hashed.
$backupLines = [
    'DAoC CMS — Setup Summary',
    'Generated: ' . date('c'),
    str_repeat('-', 40),
    'Site:            ' . ($_SESSION['setup_config']['cms_name']    ?? ''),
    'Shard:           ' . ($_SESSION['setup_config']['server_name'] ?? ''),
    'URL:             ' . ($_SESSION['setup_config']['base_url']    ?? ''),
    'CMS database:    ' . ($_SESSION['setup_db']['name']  ?? ''),
    'Game database:   ' . ($_SESSION['setup_dol']['name'] ?? ''),
    'Server core:     ' . $serverCoreLabel,
    'Administrator:   ' . ($_SESSION['setup_admin']['username'] ?? ''),
    str_repeat('-', 40),
    'PASSWORD_PEPPER=' . ($_SESSION['setup_crypto']['pepper']  ?? ''),
    'INSTANCE_ID='     . ($_SESSION['setup_crypto']['instance_id'] ?? ''),
    'ASP_KEY='         . ($_SESSION['setup_config']['asp_key'] ?? $_SESSION['setup_crypto']['asp_key'] ?? ''),
    'BOT_BOOTSTRAP_SECRET=' . ($_SESSION['setup_crypto']['bot_bootstrap_secret'] ?? ''),
    str_repeat('-', 40),
    'PASSWORD_PEPPER is mixed into every password hash on this site.',
    'If it is ever lost, every account password becomes unrecoverable — not',
    'by you, not by a password reset. Keep this file somewhere off this server.',
];
$backupContent = implode("\n", $backupLines) . "\n";
$backupDataUri = 'data:text/plain;charset=utf-8;base64,' . base64_encode($backupContent);

// Lock the completed installation.
$lockCreated = file_exists($lockFile)
    ? true
    : (@file_put_contents($lockFile, date('c') . " — installed by DAoC CMS setup wizard\n") !== false);

$setupDir = realpath(__DIR__ . '/../');

foreach (['setup_db', 'setup_dol', 'setup_config', 'setup_crypto', 'setup_admin', 'setup_csrf_token', 'setup_intro_seen', 'setup_install_done'] as $key) {
    unset($_SESSION[$key]);
}
?>

<div class="curtain">
    <div class="curtain-rule"></div>
    <h3 class="curtain-title">Installation Complete</h3>
    <div class="curtain-rule"></div>
    <p class="curtain-sub">DAoC CMS is live on this server</p>
</div>

<?php Britty::say(
    'It\'s done. Three things before you go — they matter more than anything you\'ll configure next, so read them.'
); ?>

<?php if (!empty($summary)): ?>
    <dl class="ledger" style="margin-top: 36px;">
        <?php foreach ($summary as $label => $value): ?>
            <dt><?= htmlspecialchars($label) ?></dt>
            <dd><?= htmlspecialchars((string) $value) ?></dd>
        <?php endforeach; ?>
    </dl>
<?php endif; ?>

<div style="margin-top: 20px;">
    <a href="<?= htmlspecialchars($backupDataUri) ?>" download="daoc-cms-setup-summary.txt"
       class="btn btn-outline-secondary w-100 py-2">
        <i class="fas fa-download me-2"></i> Download setup summary (includes the pepper, instance ID, ASP key)
    </a>
    <p class="probe-note" style="margin-top: 8px; text-align: center;">
        This link only works on this page, right now — the keys are cleared from the session the moment
        you leave. Grab it before you go.
    </p>
</div>

<p class="act-slug" style="margin: 34px 0 14px;">Three things before you walk away</p>

<ul class="manifest">
    <li class="manifest-item">
        <span class="m-num">01</span>
        <span class="m-body">
            <b>Back up <code>includes/config.php</code>.</b>
            It holds the password pepper. Lose that value and every account password on your site
            becomes unrecoverable — a password reset will not save you. Copy the file somewhere off
            this server, today.
        </span>
    </li>
    <li class="manifest-item">
        <span class="m-num">02</span>
        <span class="m-body">
            <b>Delete the <code>setup/</code> directory.</b>
            <?php if ($lockCreated): ?>
                The wizard is sealed by <code>install.lock</code>, so it cannot be run again — but the
                code is still reachable. Removing the directory is the clean fix.
            <?php else: ?>
                This is not optional right now: the lock file could not be written, so anyone who finds
                this URL can run the wizard again and take over your site.
            <?php endif; ?>
        </span>
    </li>
    <li class="manifest-item">
        <span class="m-num">03</span>
        <span class="m-body">
            <b>Log in and change nothing yet.</b>
            Check that the administrator account works and that the site can reach your game server database
            before you start configuring. If something is wrong, it is easier to find now than after
            fifty settings have changed.
        </span>
    </li>
</ul>

<?php if (!$lockCreated): ?>
    <div class="danger" style="margin-top: 26px;">
        <p class="danger-title"><i class="fas fa-triangle-exclamation"></i> The installation is not sealed</p>
        <p class="danger-text">
            <code class="inline-code">install.lock</code> could not be created in
            <code class="inline-code"><?= htmlspecialchars((string) $root) ?></code> because the directory
            is not writable. Create an empty file with that name there yourself, or delete
            <code class="inline-code">setup/</code> — until then the wizard can be run again by anyone.
        </p>
    </div>
<?php endif; ?>

<p class="act-slug" style="margin: 30px 0 12px;">Remove the wizard</p>

<div class="cmd">
    <code id="rmCmd"><?= htmlspecialchars('rm -rf ' . ($setupDir !== false ? $setupDir : 'setup')) ?></code>
    <button type="button" class="cmd-copy" data-copy-target="rmCmd">Copy</button>
</div>

<p class="probe-note" style="margin-top: 12px;">
    On shared hosting without SSH, delete the folder in your hosting file manager.
</p>

<div class="mt-5 text-center border-top pt-4">
    <a href="<?= htmlspecialchars($loginUrl) ?>" class="btn btn-gold px-5 py-3" style="font-size: 1rem;">
        Log in to your site <i class="fas fa-arrow-right ms-2"></i>
    </a>
</div>
