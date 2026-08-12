<?php
// SPDX-License-Identifier: GPL-3.0-only
declare(strict_types=1);

if (!isset($installer)) {
    exit;
}

require_once __DIR__ . '/../includes/Security.php';
require_once __DIR__ . '/../includes/Runner.php';

use DAoCCMS\Setup\Runner;
use DAoCCMS\Setup\Security;
use DAoCCMS\Setup\Britty;

$security = new Security();
$token    = $security->generateToken();

$error = '';

$serverCore = $_SESSION['setup_dol']['core'] ?? '';
$serverCoreLabel = match ($serverCore) {
    'opendaoc' => 'OpenDAoC',
    'dol'      => 'Dawn of Light',
    default    => 'Not selected',
};

// If the install already completed once in this session (e.g. the browser's
// Back button after a successful run), show the finished state instead of
// letting the phases execute a second time — schema() would drop and rebuild
// tables that already hold the administrator account this same session just
// created, and admin() would try to insert a duplicate account.
$success = !empty($_SESSION['setup_install_done']);

/* --------------------------------------------------------------------------
   Without JavaScript, run every phase sequentially on the server.
   With JavaScript enabled, intercept the form and run each phase through
   api/install.php so progress remains visible.
   -------------------------------------------------------------------------- */
if (!$success && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_install'])) {
    if (!$security->validateToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security token validation failed. Reload the page and try again.';
    } else {
        try {
            @set_time_limit(300);
            foreach (array_keys(Runner::PHASES) as $phase) {
                Runner::run($phase);
            }
            $_SESSION['setup_install_done'] = true;
            $success = true;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

/* Summary of the values that will be written. */
$summary = [
    'CMS database'  => $_SESSION['setup_db']['name']       ?? '—',
    'Game database' => $_SESSION['setup_dol']['name']      ?? '—',
    'Server core'   => $serverCoreLabel,
    'Site URL'      => $_SESSION['setup_config']['base_url'] ?? '—',
    'Administrator' => $_SESSION['setup_admin']['username'] ?? '—',
];

$phaseList = Runner::PHASES;
?>

<h3 class="step-title"><i class="fas fa-hammer"></i>The Forging</h3>

<?php if (!$success): Britty::say([
    'Everything you\'ve told me is ready to become real. This writes ' .
    '<code class="inline-code">includes/config.php</code>, builds the database structure, and creates ' .
    'your administrator account.',
    'Stand back — once this starts, stay on this tab until it\'s done. It cannot be undone from in here.',
]); endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger mb-4" style="word-break: break-word;">
        <strong>Installation stopped.</strong><br><?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<?php if ($success): ?>

    <div class="forge-done">
        <i class="fas fa-check"></i>
        <h4>Installation complete</h4>
        <p>The database is populated and your configuration is saved.</p>
    </div>

<?php else: ?>

    <!-- Values to be written -->
    <dl class="ledger" id="forgeLedger">
        <?php foreach ($summary as $label => $value): ?>
            <dt><?= htmlspecialchars($label) ?></dt>
            <dd><?= htmlspecialchars((string) $value) ?></dd>
        <?php endforeach; ?>
    </dl>

    <!-- Installation sequence -->
    <div class="forge" id="forge" hidden>
        <div class="forge-head">
            <span class="forge-pct" id="forgePct">0<i>%</i></span>
            <span class="forge-now" id="forgeNow">Standing by</span>
        </div>

        <div class="forge-rail"><div class="forge-heat" id="forgeHeat"></div></div>

        <ol class="phases" id="forgePhases">
            <?php $n = 1; foreach ($phaseList as $key => $label): ?>
                <li class="phase" data-phase="<?= htmlspecialchars($key) ?>">
                    <span class="phase-num"><?= str_pad((string) $n++, 2, '0', STR_PAD_LEFT) ?></span>
                    <span class="phase-label"><?= htmlspecialchars($label) ?></span>
                    <span class="phase-state" aria-hidden="true"></span>
                </li>
            <?php endforeach; ?>
        </ol>

        <div class="console" id="forgeConsole" role="log" aria-live="polite"></div>
    </div>

    <form method="POST" action="index.php?step=install" id="installForm" class="mt-4">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">
        <button type="submit" name="execute_install" class="btn btn-gold w-100 py-3" id="runBtn">
            <i class="fas fa-fire me-2"></i> Run the installer
        </button>
    </form>

    <noscript>
        <p class="reel-foot" style="text-align:left;margin-top:14px;">
            JavaScript is off — the installer will run in one request and report at the end.
        </p>
    </noscript>

<?php endif; ?>

<div class="mt-5 d-flex justify-content-between border-top pt-4" id="forgeNav">
    <?php if (!$success): ?>
        <a href="index.php?step=administrator" class="btn btn-outline-secondary" id="backBtn">
            <i class="fas fa-arrow-left me-2"></i> Back
        </a>
    <?php else: ?>
        <span></span>
    <?php endif; ?>

    <?php if ($success): ?>
        <a href="index.php?step=finish" class="btn btn-gold px-4 py-2">
            Finish setup <i class="fas fa-arrow-right ms-2"></i>
        </a>
    <?php else: ?>
        <button class="btn btn-gold px-4 py-2 disabled" disabled id="nextBtn">
            Finish setup <i class="fas fa-lock ms-2"></i>
        </button>
    <?php endif; ?>
</div>

<?php if (!$success): ?>
<script>
(function () {
    'use strict';

    var form    = document.getElementById('installForm');
    if (!form) return;

    var runBtn  = document.getElementById('runBtn');
    var backBtn = document.getElementById('backBtn');
    var nextBtn = document.getElementById('nextBtn');
    var forge   = document.getElementById('forge');
    var ledger  = document.getElementById('forgeLedger');
    var heat    = document.getElementById('forgeHeat');
    var pctEl   = document.getElementById('forgePct');
    var nowEl   = document.getElementById('forgeNow');
    var consEl  = document.getElementById('forgeConsole');
    var rows    = Array.prototype.slice.call(document.querySelectorAll('#forgePhases .phase'));

    // The schema import takes the longest, so allocate it the
    // largest share of the progress bar.
    var WEIGHT = { config: 5, connect: 5, schema: 55, rename: 10, admin: 15, settings: 10 };

    var token   = form.querySelector('[name="csrf_token"]').value;
    var cursor  = 0;   // Next phase to run
    var shown   = 0;   // Percentage currently displayed
    var target  = 0;
    var running = false;

    function log(text, kind) {
        var line = document.createElement('div');
        line.className = 'log-line' + (kind ? ' log-line--' + kind : '');
        line.textContent = text;
        consEl.appendChild(line);
        consEl.scrollTop = consEl.scrollHeight;
    }

    /* Animate progress smoothly instead of jumping. */
    function tick() {
        if (shown < target) {
            shown = Math.min(target, shown + Math.max(0.4, (target - shown) * 0.08));
            pctEl.firstChild.nodeValue = String(Math.round(shown));
            heat.style.width = shown + '%';
        }
        if (running || shown < target) requestAnimationFrame(tick);
    }

    function setPhaseState(i, state) {
        rows[i].classList.remove('is-run', 'is-ok', 'is-bad');
        if (state) rows[i].classList.add(state);
    }

    function fail(i, message) {
        running = false;
        setPhaseState(i, 'bad');
        nowEl.textContent = 'Stopped';
        nowEl.classList.add('is-bad');
        log(message, 'bad');

        runBtn.disabled = false;
        runBtn.innerHTML = '<i class="fas fa-rotate-right me-2"></i> Retry from step ' +
                           String(i + 1).padStart(2, '0');
        runBtn.hidden = false;
        if (backBtn) backBtn.style.visibility = '';
    }

    function finish() {
        running = false;
        target = 100;
        nowEl.textContent = 'Complete';
        nowEl.classList.add('is-ok');
        log('Installation complete.', 'ok');

        forge.classList.add('is-done');
        if (nextBtn) {
            var link = document.createElement('a');
            link.href = 'index.php?step=finish';
            link.className = 'btn btn-gold px-4 py-2';
            link.innerHTML = 'Finish setup <i class="fas fa-arrow-right ms-2"></i>';
            nextBtn.replaceWith(link);
            link.focus();
        }
        if (backBtn) backBtn.remove();
    }

    function step() {
        if (cursor >= rows.length) { finish(); return; }

        var i     = cursor;
        var key   = rows[i].dataset.phase;
        var label = rows[i].querySelector('.phase-label').textContent;

        setPhaseState(i, 'run');
        nowEl.textContent = label + '…';
        log('› ' + label);

        var body = new URLSearchParams();
        body.set('phase', key);
        body.set('csrf_token', token);

        fetch('api/install.php', {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'fetch' }
        })
        .then(function (r) {
            return r.text().then(function (t) {
                try { return JSON.parse(t); }
                catch (e) { throw new Error('Unexpected server response (' + r.status + '). ' + t.slice(0, 200)); }
            });
        })
        .then(function (data) {
            if (!data.ok) { fail(i, data.error || 'Unknown error.'); return; }

            setPhaseState(i, 'ok');
            log('  ' + (data.detail || 'done'), 'ok');
            target += WEIGHT[key] || 0;
            cursor++;
            setTimeout(step, 180);
        })
        .catch(function (err) {
            fail(i, err.message || String(err));
        });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (running) return;

        running = true;
        forge.hidden = false;
        if (ledger) ledger.classList.add('is-folded');
        runBtn.hidden = true;
        runBtn.disabled = true;
        if (backBtn) backBtn.style.visibility = 'hidden';
        nowEl.classList.remove('is-bad', 'is-ok');

        requestAnimationFrame(tick);
        step();
    });

    // Warn before closing the page while installation is running.
    window.addEventListener('beforeunload', function (e) {
        if (!running) return;
        e.preventDefault();
        e.returnValue = '';
    });
})();
</script>
<?php endif; ?>
