<?php
// SPDX-License-Identifier: GPL-3.0-only
declare(strict_types=1);

if (!isset($installer)) {
    exit;
}

require_once __DIR__ . '/../includes/Security.php';
require_once __DIR__ . '/../includes/Validator.php';

use DAoCCMS\Setup\Security;
use DAoCCMS\Setup\Validator;
use DAoCCMS\Setup\Britty;

$security  = new Security();
$validator = new Validator();

$error   = '';
$success = false;

$adminUser  = $_SESSION['setup_admin']['username'] ?? '';
$adminEmail = $_SESSION['setup_admin']['email']    ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup_admin'])) {
    if (!$security->validateToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security token validation failed. Reload the page and try again.';
    } else {
        $adminUser  = $validator->sanitizeString($_POST['admin_user'] ?? '');
        $adminEmail = $validator->sanitizeString($_POST['admin_email'] ?? '');
        $adminPass  = $_POST['admin_pass'] ?? '';
        $adminPass2 = $_POST['admin_pass2'] ?? '';

        if ($adminUser === '' || $adminEmail === '' || $adminPass === '' || $adminPass2 === '') {
            $error = 'All four fields are required.';
        } elseif (!preg_match('/^[a-zA-Z0-9_-]{3,20}$/', $adminUser)) {
            $error = 'The username must be 3 to 20 characters, using letters, numbers, underscores, or dashes.';
        } elseif (!$validator->isEmail($adminEmail)) {
            $error = 'That is not a valid email address.';
        } elseif ($adminPass !== $adminPass2) {
            $error = 'The two passwords do not match.';
        } elseif (!$validator->isStrongPassword($adminPass)) {
            $error = 'The password must be at least 8 characters long.';
        } else {
            // Hash this during installation, after the pepper is available.
            $_SESSION['setup_admin'] = [
                'username' => $adminUser,
                'email'    => $adminEmail,
                'password' => $adminPass,
            ];
            $success = true;
        }
    }
}
?>

<h3 class="step-title"><i class="fas fa-user-shield"></i>The Warden</h3>

<?php Britty::say(
    'Every realm needs someone holding every key. This account gets full access from the first ' .
    'moment it exists — pick a password you mean it with, and an email you actually read. There\'s no ' .
    'way to create a second one from in here, so this is the one that matters.'
); ?>

<?php if ($error): ?>
    <div class="alert alert-danger mb-4"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>

    <div class="alert alert-success mb-4">
        <strong>Details accepted.</strong> The account is created in the final step.
    </div>

    <dl class="ledger">
        <dt>Username</dt><dd><?= htmlspecialchars($adminUser) ?></dd>
        <dt>Email</dt><dd><?= htmlspecialchars($adminEmail) ?></dd>
        <dt>Privilege</dt><dd>5 — full administrator</dd>
    </dl>

<?php else: ?>

    <form method="POST" action="index.php?step=administrator" id="adminForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($security->generateToken()) ?>">

        <div class="field-grid">
            <div class="field">
                <label class="form-label" for="admin_user">Username</label>
                <input type="text" class="form-control" id="admin_user" name="admin_user"
                       value="<?= htmlspecialchars($adminUser) ?>" required
                       pattern="[a-zA-Z0-9_-]{3,20}" autocomplete="username">
                <span class="field-hint">3–20 characters. Letters, numbers, underscore, dash.</span>
            </div>
            <div class="field">
                <label class="form-label" for="admin_email">Email address</label>
                <input type="email" class="form-control" id="admin_email" name="admin_email"
                       value="<?= htmlspecialchars($adminEmail) ?>" required autocomplete="email">
                <span class="field-hint">Used for password resets.</span>
            </div>
        </div>

        <div class="field-grid">
            <div class="field">
                <label class="form-label" for="admin_pass">Password</label>
                <input type="password" class="form-control" id="admin_pass" name="admin_pass"
                       required autocomplete="new-password">
            </div>
            <div class="field">
                <label class="form-label" for="admin_pass2">Repeat password</label>
                <input type="password" class="form-control" id="admin_pass2" name="admin_pass2"
                       required autocomplete="new-password">
            </div>
        </div>

        <div class="pw">
            <div class="pw-bar"><div class="pw-fill" id="pwFill"></div></div>
            <ul class="rules" id="pwRules">
                <li class="rule" data-rule="len"><span class="mark mark--todo"></span>At least 8 characters</li>
                <li class="rule" data-rule="long"><span class="mark mark--todo"></span>12 or more is noticeably better</li>
                <li class="rule" data-rule="mix"><span class="mark mark--todo"></span>Mixes letters with numbers or symbols</li>
                <li class="rule" data-rule="match"><span class="mark mark--todo"></span>Both fields match</li>
            </ul>
        </div>

        <p class="probe-note" style="margin-top: 18px;">
            Stored as a bcrypt hash with a server-side pepper. Neither the wizard nor the database
            ever holds the password in readable form after this step.
        </p>

        <button type="submit" name="setup_admin" class="btn btn-gold w-100 py-2 mt-4">
            <i class="fas fa-user-check me-2"></i> Check these details
        </button>
    </form>

    <script>
    (function () {
        var pass  = document.getElementById('admin_pass');
        var pass2 = document.getElementById('admin_pass2');
        var fill  = document.getElementById('pwFill');
        var rules = document.getElementById('pwRules');
        if (!pass || !rules) return;

        function set(name, met) {
            var li = rules.querySelector('[data-rule="' + name + '"]');
            if (!li) return;
            li.classList.toggle('is-met', met);
            li.querySelector('.mark').className = 'mark mark--' + (met ? 'ok' : 'todo');
        }

        function check() {
            var v = pass.value;
            var hasLetter = /[a-zA-Z]/.test(v);
            var hasOther  = /[^a-zA-Z]/.test(v);

            var met = {
                len:   v.length >= 8,
                long:  v.length >= 12,
                mix:   hasLetter && hasOther,
                match: v.length > 0 && v === pass2.value
            };

            Object.keys(met).forEach(function (k) { set(k, met[k]); });

            var score = Object.keys(met).filter(function (k) { return met[k]; }).length;
            fill.style.width = (score / 4 * 100) + '%';
            fill.className = 'pw-fill' + (score >= 4 ? ' is-strong' : score >= 2 ? ' is-fair' : ' is-weak');
        }

        pass.addEventListener('input', check);
        pass2.addEventListener('input', check);
        check();
    })();
    </script>

<?php endif; ?>

<div class="mt-5 d-flex justify-content-between border-top pt-4">
    <?php if ($success): ?>
        <a href="index.php?step=administrator" class="btn btn-outline-secondary">
            <i class="fas fa-pen me-2"></i> Change these details
        </a>
    <?php else: ?>
        <a href="index.php?step=bridges" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i> Back
        </a>
    <?php endif; ?>

    <?php if ($success): ?>
        <a href="index.php?step=install" class="btn btn-gold px-4 py-2">
            Go to the installation <i class="fas fa-arrow-right ms-2"></i>
        </a>
    <?php else: ?>
        <button class="btn btn-gold px-4 py-2 disabled" disabled>
            Check the details first <i class="fas fa-lock ms-2"></i>
        </button>
    <?php endif; ?>
</div>
