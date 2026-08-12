<?php
// SPDX-License-Identifier: GPL-3.0-only
declare(strict_types=1);

require_once __DIR__ . '/includes/Installer.php';
require_once __DIR__ . '/includes/Britty.php';

use DAoCCMS\Setup\Installer;
use DAoCCMS\Setup\Britty;

$installer = new Installer();

/* --------------------------------------------------------------------------
   TITLE CARD TEXT — edit the copy displayed when setup starts.
   -------------------------------------------------------------------------- */
$intro = [
    'title'    => 'Welcome to the Installation',
    'subtitle' => 'DAoC CMS — Chronicle of Installation',
    'skip'     => 'Skip',
];

/* --------------------------------------------------------------------------
   The ten acts. Their order must match Installer::$steps.
   -------------------------------------------------------------------------- */
$acts = [
    'welcome'      => ['numeral' => 'I',    'name' => 'The Summons',    'realm' => null],
    'requirements' => ['numeral' => 'II',   'name' => 'The Proving',    'realm' => null],
    'permissions'  => ['numeral' => 'III',  'name' => 'The Keys',       'realm' => null],
    'database'     => ['numeral' => 'IV',   'name' => 'The Vault',      'realm' => 'albion'],
    'dol_database' => ['numeral' => 'V',    'name' => 'The Realm Gate', 'realm' => 'midgard'],
    'configuration'=> ['numeral' => 'VI',   'name' => 'The Sigils',     'realm' => 'hibernia'],
    'bridges'      => ['numeral' => 'VII',  'name' => 'The Sinews',     'realm' => null],
    'administrator'=> ['numeral' => 'VIII', 'name' => 'The Warden',     'realm' => null],
    'install'      => ['numeral' => 'IX',   'name' => 'The Forging',    'realm' => null],
    'finish'       => ['numeral' => 'X',    'name' => 'The Crowning',   'realm' => null],
];

$stepFiles = __DIR__ . '/steps/';

/* -------------------------------------------------------------------------- */

if ($installer->isInstalled()) {
    $lockedTitle = 'Already Installed';
    $lockedBody  = 'DAoC CMS is running on this server. Delete <code>install.lock</code> in the root directory to run the setup again.';
    require __DIR__ . '/_locked.php';
    exit;
}

$currentStep = $installer->getCurrentStep();
$steps       = $installer->getSteps();
$stepIndex   = (int) array_search($currentStep, $steps, true);
$progress    = $installer->getStepProgress($currentStep);
$stepFile    = $stepFiles . $currentStep . '.php';

$act = $acts[$currentStep] ?? ['numeral' => '—', 'name' => 'Unknown', 'realm' => null];

// Play the intro once per session. Use ?intro=1 to replay it.
$playIntro = empty($_SESSION['setup_intro_seen']) || isset($_GET['intro']);
$_SESSION['setup_intro_seen'] = true;
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>DAoC CMS — Setup · Chapter <?= htmlspecialchars($act['numeral']) ?></title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=EB+Garamond:ital,wght@0,400;0,500;1,400&display=swap" rel="stylesheet">
<link href="assets/cinematic.css?v=1" rel="stylesheet">
</head>
<body>

<!-- Atmosphere -->
<div class="atmos" aria-hidden="true"></div>
<canvas id="embers" aria-hidden="true"></canvas>
<div class="grain" aria-hidden="true"></div>

<?php if ($playIntro): ?>
<!-- Title card -->
<div id="cinema" role="presentation">
    <div class="cine-stack">
        <div class="cine-rule"></div>
        <h1 class="cine-title"><?= htmlspecialchars($intro['title']) ?></h1>
        <div class="cine-rule cine-rule--under"></div>
        <p class="cine-sub"><?= htmlspecialchars($intro['subtitle']) ?></p>
    </div>

    <button type="button" class="cine-skip" id="cineSkip"><?= htmlspecialchars($intro['skip']) ?></button>
</div>
<?php endif; ?>

<main class="stage<?= $playIntro ? '' : ' is-in' ?>" id="stage">

    <header class="reel-head">
        <p class="wordmark">DAoC <span>CMS</span></p>
        <p class="reel-eyebrow">Installation</p>

        <nav aria-label="Setup progress">
            <ol class="acts">
                <?php foreach ($steps as $i => $key):
                    $a = $acts[$key] ?? ['numeral' => '?', 'name' => $key, 'realm' => null];
                    $state = $i < $stepIndex ? 'is-done' : ($i === $stepIndex ? 'is-now' : '');
                ?>
                <li class="act <?= $state ?>"
                    <?= $a['realm'] ? 'data-realm="' . htmlspecialchars($a['realm']) . '"' : '' ?>
                    <?= $i === $stepIndex ? 'aria-current="step"' : '' ?>>
                    <span class="act-name"><?= htmlspecialchars($a['name']) ?></span>
                    <span class="act-dot" aria-hidden="true"></span>
                    <span class="act-label"><?= htmlspecialchars($a['numeral']) ?></span>
                </li>
                <?php endforeach; ?>
            </ol>
        </nav>
    </header>

    <!-- Chapter banner -->
    <div class="banner">
        <span class="banner-ornament" aria-hidden="true"></span>
        <div class="banner-text">
            <span class="banner-chapter">Chapter <?= htmlspecialchars($act['numeral']) ?> · <?= htmlspecialchars($act['name']) ?></span>
            <span class="banner-step">Step <?= str_pad((string)($stepIndex + 1), 2, '0', STR_PAD_LEFT) ?> of <?= count($steps) ?></span>
        </div>
        <span class="banner-ornament" aria-hidden="true"></span>
    </div>

    <div class="reel-card">
        <span class="corner corner--tl" aria-hidden="true"></span>
        <span class="corner corner--tr" aria-hidden="true"></span>
        <span class="corner corner--bl" aria-hidden="true"></span>
        <span class="corner corner--br" aria-hidden="true"></span>

        <p class="act-slug">Chapter <em><?= htmlspecialchars($act['numeral']) ?></em> — <?= htmlspecialchars($act['name']) ?></p>

        <?php
        if (is_file($stepFile)) {
            require_once $stepFile;
        } else {
            echo '<div class="alert alert-danger">Step module missing: '
               . htmlspecialchars($currentStep) . '.php</div>';
        }
        ?>
    </div>

    <p class="reel-foot">DAoC CMS · Installation <?= $progress ?>% complete</p>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/cinematic.js?v=1"></script>
</body>
</html>
