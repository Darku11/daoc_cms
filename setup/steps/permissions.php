<?php
// SPDX-License-Identifier: GPL-3.0-only
declare(strict_types=1);

if (!isset($installer)) {
    exit;
}

use DAoCCMS\Setup\Britty;

$basePath = realpath(__DIR__ . '/../../') . '/';

$directories = [
    'uploads' => 'Avatars, screenshots, and attachments',
    'backups' => 'Database exports created from the control panel',
    'plugins' => 'Installed plugin packages',
];

$results    = [];
$canProceed = true;

foreach ($directories as $dir => $purpose) {
    $path  = $basePath . $dir;
    $label = $dir . '/';

    if (!file_exists($path)) {
        if (@mkdir($path, 0755, true)) {
            $results[] = ['label' => $label, 'status' => 'ok', 'value' => 'created', 'note' => $purpose . '. Created just now.'];
        } else {
            $results[] = ['label' => $label, 'status' => 'bad', 'value' => 'missing', 'note' => $purpose . '. Does not exist and could not be created.'];
            $canProceed = false;
        }
    } elseif (is_writable($path)) {
        $results[] = ['label' => $label, 'status' => 'ok', 'value' => 'writable', 'note' => $purpose];
    } else {
        $results[] = ['label' => $label, 'status' => 'bad', 'value' => 'read only', 'note' => $purpose . '. The web server cannot write here.'];
        $canProceed = false;
    }
}

// includes/config.php is written during the final installation step.
$configPath   = $basePath . 'includes/config.php';
$includesPath = $basePath . 'includes';

if (file_exists($configPath)) {
    if (is_writable($configPath)) {
        $results[] = ['label' => 'includes/config.php', 'status' => 'ok', 'value' => 'writable', 'note' => 'An existing configuration will be overwritten.'];
    } else {
        $results[] = ['label' => 'includes/config.php', 'status' => 'bad', 'value' => 'read only', 'note' => 'The file exists but cannot be overwritten.'];
        $canProceed = false;
    }
} elseif (is_dir($includesPath) && is_writable($includesPath)) {
    $results[] = ['label' => 'includes/config.php', 'status' => 'warn', 'value' => 'will be created', 'note' => 'Written in the final step from your answers.'];
} else {
    $results[] = ['label' => 'includes/', 'status' => 'bad', 'value' => 'not writable', 'note' => 'The configuration file cannot be created in this directory.'];
    $canProceed = false;
}

// Show the account running PHP to help with chown commands.
$runAs = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
    ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown')
    : (getenv('USER') ?: 'unknown');

$failed = array_values(array_filter($results, fn ($r) => $r['status'] === 'bad'));
?>

<h3 class="step-title"><i class="fas fa-key"></i>The Keys</h3>

<?php Britty::say(
    'A few doors need to be unlocked before I can write anything. Just these four folders — ' .
    'everything else on this server stays exactly as it is, read only.'
); ?>

<ul class="probes">
    <?php foreach ($results as $r): ?>
        <li class="probe is-<?= $r['status'] ?>">
            <span class="mark mark--<?= $r['status'] ?>" aria-hidden="true"></span>
            <span class="probe-body">
                <b class="is-path"><?= htmlspecialchars($r['label']) ?></b>
                <span class="probe-note"><?= htmlspecialchars($r['note']) ?></span>
            </span>
            <span class="probe-value"><?= htmlspecialchars($r['value']) ?></span>
        </li>
    <?php endforeach; ?>
</ul>

<?php if (!$canProceed): ?>

    <div class="alert alert-danger mt-4">
        <strong>The web server cannot write where it needs to.</strong><br>
        PHP is running as <code class="inline-code"><?= htmlspecialchars($runAs) ?></code> on this server.
    </div>

    <p class="act-slug" style="margin: 26px 0 12px;">Run this over SSH</p>

    <div class="cmd">
        <code id="fixCmd"><?php
            $lines = [];
            foreach ($failed as $r) {
                $target = rtrim($r['label'], '/');
                if ($target === 'includes/config.php') {
                    $lines[] = 'chmod 644 includes/config.php';
                } else {
                    $lines[] = 'mkdir -p ' . $target . ' && chmod 755 ' . $target;
                }
            }
            echo htmlspecialchars(implode("\n", $lines));
        ?></code>
        <button type="button" class="cmd-copy" data-copy-target="fixCmd">Copy</button>
    </div>

    <p class="probe-note" style="margin-top: 14px;">
        If that is not enough, the directories belong to the wrong user. Then it is
        <code class="inline-code">chown -R <?= htmlspecialchars($runAs) ?>: &lt;directory&gt;</code>,
        or you set the permissions to 775 and add your user to the web server's group.
        On shared hosting without SSH, use the file manager in your hosting panel and set
        the permissions to 755 there.
    </p>

<?php else: ?>

    <div class="alert alert-success mt-4">
        <strong>All four locations are writable.</strong>
    </div>

<?php endif; ?>

<div class="mt-5 d-flex justify-content-between border-top pt-4">
    <a href="index.php?step=requirements" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-2"></i> Back
    </a>

    <?php if ($canProceed): ?>
        <a href="index.php?step=database" class="btn btn-gold px-4 py-2">
            Connect the CMS database <i class="fas fa-arrow-right ms-2"></i>
        </a>
    <?php else: ?>
        <a href="index.php?step=permissions" class="btn btn-gold px-4 py-2">
            <i class="fas fa-rotate-right me-2"></i> Check again
        </a>
    <?php endif; ?>
</div>
