<?php
// SPDX-License-Identifier: GPL-3.0-only
declare(strict_types=1);

if (!isset($installer)) {
    exit;
}

use DAoCCMS\Setup\Britty;

if (!function_exists('daoc_bytes')) {
    /** Convert PHP size values such as "128M" to bytes. */
    function daoc_bytes(string $val): int
    {
        $val = trim($val);
        if ($val === '') {
            return 0;
        }
        if ($val === '-1') {
            return -1;
        }

        $last = strtolower($val[strlen($val) - 1]);
        $num  = (int) $val;

        switch ($last) {
            case 'g': $num *= 1024;
            // Deliberately cascade without a break.
            case 'm': $num *= 1024;
            case 'k': $num *= 1024;
        }

        return $num;
    }
}

/* --------------------------------------------------------------------------
   Collect checks. Each row contains a label, status, current value, and note.
   Status: ok | warn | bad
   -------------------------------------------------------------------------- */

$probes = [];

// PHP version
$phpVersion = PHP_VERSION;
$phpOk      = version_compare($phpVersion, '8.2.0', '>=');
$probes[] = [
    'label'  => 'PHP version',
    'status' => $phpOk ? 'ok' : 'bad',
    'value'  => $phpVersion,
    'note'   => $phpOk ? null : 'DAoC CMS needs PHP 8.2 or newer. Ask your host to switch the PHP version for this domain.',
];

// Required extensions
$required = [
    'pdo'       => 'Database access layer',
    'pdo_mysql' => 'MySQL driver',
    'json'      => 'Settings and API payloads',
    'curl'      => 'Outgoing requests (mail, Discord)',
    'zip'       => 'Backup archive creation and restoration',
    'fileinfo'  => 'Upload type detection',
    'zlib'      => 'Compressed OpenDAoC database installation',
];

foreach ($required as $ext => $why) {
    $loaded = extension_loaded($ext);
    $probes[] = [
        'label'  => 'Extension: ' . $ext,
        'status' => $loaded ? 'ok' : 'bad',
        'value'  => $loaded ? 'loaded' : 'missing',
        'note'   => $loaded ? $why : $why . '. Enable it in php.ini or through your hosting panel.',
    ];
}

// Recommended extensions do not block installation.
$recommended = [
    'mbstring' => 'Improved multibyte and Unicode string handling',
    'openssl'  => 'TLS and cryptographic support for PHP integrations',
];

foreach ($recommended as $ext => $why) {
    $loaded = extension_loaded($ext);
    $probes[] = [
        'label'  => 'Extension: ' . $ext,
        'status' => $loaded ? 'ok' : 'warn',
        'value'  => $loaded ? 'loaded' : 'not loaded',
        'note'   => $loaded ? $why . ' (optional)' : $why . '. Optional, but recommended.',
    ];
}

// Runtime values.
$memoryLimit = (string) ini_get('memory_limit');
$memoryBytes = daoc_bytes($memoryLimit);
$memoryOk    = ($memoryBytes === -1 || $memoryBytes >= 134217728);
$probes[] = [
    'label'  => 'memory_limit',
    'status' => $memoryOk ? 'ok' : 'warn',
    'value'  => $memoryLimit,
    'note'   => $memoryOk ? 'At least 128 MB available' : 'Below 128 MB. Large database imports may run out of memory.',
];

$uploadMax = (string) ini_get('upload_max_filesize');
$uploadOk  = daoc_bytes($uploadMax) >= 33554432;
$probes[] = [
    'label'  => 'upload_max_filesize',
    'status' => $uploadOk ? 'ok' : 'warn',
    'value'  => $uploadMax,
    'note'   => $uploadOk ? 'At least 32 MB' : 'Below 32 MB. You will not be able to upload a large .sql backup in step five.',
];

$postMax = (string) ini_get('post_max_size');
$postOk  = daoc_bytes($postMax) >= 33554432;
$probes[] = [
    'label'  => 'post_max_size',
    'status' => $postOk ? 'ok' : 'warn',
    'value'  => $postMax,
    'note'   => $postOk ? 'At least 32 MB' : 'Below 32 MB. Must be at least as large as upload_max_filesize.',
];

$maxExecution = (int) ini_get('max_execution_time');
$execOk       = ($maxExecution === 0 || $maxExecution >= 60);
$probes[] = [
    'label'  => 'max_execution_time',
    'status' => $execOk ? 'ok' : 'warn',
    'value'  => $maxExecution === 0 ? 'unlimited' : $maxExecution . 's',
    'note'   => $execOk ? 'Enough headroom for the database import' : 'Below 60 seconds. The import in step nine may be cut off mid-way.',
];

$errorCount   = count(array_filter($probes, fn ($p) => $p['status'] === 'bad'));
$warningCount = count(array_filter($probes, fn ($p) => $p['status'] === 'warn'));
$canProceed   = $errorCount === 0;

$facts = [
    'Operating system' => PHP_OS,
    'Web server'       => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'PHP interface'    => PHP_SAPI,
];
?>

<h3 class="step-title"><i class="fas fa-microscope"></i>The Proving</h3>

<?php Britty::say(
    'Let\'s see what this server is carrying. Red marks are hard stops — clear those before you ' .
    'continue. Amber ones won\'t block you, but read them anyway; they tend to come back later.'
); ?>

<dl class="ledger">
    <?php foreach ($facts as $label => $value): ?>
        <dt><?= htmlspecialchars($label) ?></dt>
        <dd><?= htmlspecialchars((string) $value) ?></dd>
    <?php endforeach; ?>
</dl>

<p class="tally">
    <span class="tally-item is-ok"><?= count($probes) - $errorCount - $warningCount ?> passed</span>
    <span class="tally-item is-warn"><?= $warningCount ?> warnings</span>
    <span class="tally-item is-bad"><?= $errorCount ?> blocking</span>
</p>

<ul class="probes">
    <?php foreach ($probes as $p): ?>
        <li class="probe is-<?= $p['status'] ?>">
            <span class="mark mark--<?= $p['status'] ?>" aria-hidden="true"></span>
            <span class="probe-body">
                <b><?= htmlspecialchars($p['label']) ?></b>
                <?php if ($p['note'] !== null): ?>
                    <span class="probe-note"><?= htmlspecialchars($p['note']) ?></span>
                <?php endif; ?>
            </span>
            <span class="probe-value"><?= htmlspecialchars((string) $p['value']) ?></span>
        </li>
    <?php endforeach; ?>
</ul>

<?php if (!$canProceed): ?>
    <div class="alert alert-danger mt-4">
        <strong><?= $errorCount ?> <?= $errorCount === 1 ? 'requirement is' : 'requirements are' ?> not met.</strong><br>
        Fix the red entries above, then reload this page.
    </div>
<?php elseif ($warningCount > 0): ?>
    <div class="alert alert-warning mt-4">
        <strong>You can continue.</strong><br>
        The amber entries are not blocking, but read the notes — they describe what will go wrong and when.
    </div>
<?php else: ?>
    <div class="alert alert-success mt-4">
        <strong>Every check passed.</strong> This server is ready.
    </div>
<?php endif; ?>

<div class="mt-5 d-flex justify-content-between border-top pt-4">
    <a href="index.php?step=welcome" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-2"></i> Back
    </a>

    <?php if ($canProceed): ?>
        <a href="index.php?step=permissions" class="btn btn-gold px-4 py-2">
            Check permissions <i class="fas fa-arrow-right ms-2"></i>
        </a>
    <?php else: ?>
        <a href="index.php?step=requirements" class="btn btn-gold px-4 py-2">
            <i class="fas fa-rotate-right me-2"></i> Run the checks again
        </a>
    <?php endif; ?>
</div>
