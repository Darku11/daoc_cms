<?php
// SPDX-License-Identifier: GPL-3.0-only
declare(strict_types=1);

if (!isset($installer)) {
    exit;
}

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Security.php';

use DAoCCMS\Setup\Database;
use DAoCCMS\Setup\Security;
use DAoCCMS\Setup\Britty;

$security = new Security();

$error          = '';
$success        = false;
$existingTables = false;
$serverVersion  = '';

$host   = $_SESSION['setup_db']['host'] ?? '127.0.0.1';
$port   = $_SESSION['setup_db']['port'] ?? 3306;
$dbName = $_SESSION['setup_db']['name'] ?? '';
$user   = $_SESSION['setup_db']['user'] ?? '';
$pass   = $_SESSION['setup_db']['pass'] ?? '';

/** Convert MySQL errors into actionable messages. */
function daoc_db_hint(string $raw): string
{
    if (str_contains($raw, '1045') || stripos($raw, 'access denied') !== false) {
        return 'The username or password was rejected. Check both, and make sure the user is allowed to connect from this host.';
    }
    if (str_contains($raw, '1049') || stripos($raw, 'unknown database') !== false) {
        return 'The database does not exist. Create an empty database with this name first — the wizard connects, it does not create.';
    }
    if (str_contains($raw, '2005') || stripos($raw, 'unknown server host') !== false || stripos($raw, 'getaddrinfo') !== false) {
        return 'That host name could not be resolved. Check it for typos — on most servers the answer is 127.0.0.1.';
    }
    if (str_contains($raw, '2002') || stripos($raw, 'connection refused') !== false) {
        return 'Nothing answered on that host and port. If MySQL runs on the same machine, try 127.0.0.1 or localhost.';
    }
    if (str_contains($raw, '1044') || str_contains($raw, 'CREATE command denied')) {
        return 'The user can connect but is not allowed to create tables. Grant it full privileges on this database.';
    }
    if (stripos($raw, 'could not find driver') !== false) {
        return 'The pdo_mysql extension is not loaded on this server, so PHP cannot talk to MySQL at all. Go back to the system check.';
    }
    return 'The server rejected the connection. The exact message is above.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_db'])) {
    if (!$security->validateToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security token validation failed. Reload the page and try again.';
    } else {
        $host   = trim($_POST['db_host'] ?? '');
        $port   = (int) ($_POST['db_port'] ?? 3306);
        $dbName = trim($_POST['db_name'] ?? '');
        $user   = trim($_POST['db_user'] ?? '');
        $pass   = $_POST['db_pass'] ?? '';

        if ($host === '' || $dbName === '' || $user === '') {
            $error = 'Host, database name, and username are required.';
        } else {
            $result = Database::testConnection($host, $port, $dbName, $user, $pass);

            if (!$result['success']) {
                $raw   = (string) $result['message'];
                $error = daoc_db_hint($raw) . "\n" . $raw;
            } else {
                $success = true;
                $pdo     = $result['pdo'];

                $serverVersion  = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
                $existingTables = Database::checkExistingTables($pdo);

                $_SESSION['setup_db'] = [
                    'host'   => $host,
                    'port'   => $port,
                    'name'   => $dbName,
                    'user'   => $user,
                    'pass'   => $pass,
                    'prefix' => '',
                ];
            }
        }
    }
}
?>

<h3 class="step-title"><i class="fas fa-vault"></i>The Vault</h3>

<?php Britty::say(
    'This one\'s the CMS\'s own vault — accounts, forum posts, settings. Point me at an empty ' .
    'database and I\'ll take it from there. I don\'t create databases myself, so it needs to exist already.'
); ?>

<div class="danger danger--info" style="margin-bottom: 28px;">
    <p class="danger-title"><i class="fas fa-font"></i> Create it with the right character set</p>
    <p class="danger-text">
        The CMS schema is written for <code class="inline-code">utf8mb4</code> /
        <code class="inline-code">utf8mb4_general_ci</code> — the only charset that stores full Unicode
        correctly, including emoji in posts and non-Latin usernames. Creating the database with anything
        else (many hosting panels still default to <code class="inline-code">latin1</code>) causes
        <code class="inline-code">Incorrect string value</code> errors later, sometimes not until someone
        actually types an emoji.
    </p>
    <div class="cmd" style="margin-top: 10px;">
        <code id="cmsCharsetCmd">CREATE DATABASE `your_database_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;</code>
        <button type="button" class="cmd-copy" data-copy-target="cmsCharsetCmd">Copy</button>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger mb-4" style="white-space: pre-line; word-break: break-word;">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<?php if ($success): ?>

    <div class="alert alert-success mb-4">
        <strong>Connected.</strong> The user can create and drop tables here.
    </div>

    <dl class="ledger">
        <dt>Server</dt><dd><?= htmlspecialchars($host) ?>:<?= (int) $port ?> · MySQL <?= htmlspecialchars($serverVersion) ?></dd>
        <dt>Database</dt><dd><?= htmlspecialchars($dbName) ?></dd>
        <dt>User</dt><dd><?= htmlspecialchars($user) ?></dd>
    </dl>

    <?php if ($existingTables): ?>
        <div class="alert alert-warning mt-4">
            <strong>This database is not empty.</strong><br>
            A <code class="inline-code">users</code> table is already here. The installation in step
            eight will write over the CMS tables. If this is a live site, stop and back it up first.
        </div>
    <?php endif; ?>

<?php else: ?>

    <form method="POST" action="index.php?step=database" id="dbForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($security->generateToken()) ?>">

        <div class="field-grid">
            <div class="field field--wide">
                <label class="form-label" for="db_host">Host</label>
                <input type="text" class="form-control" id="db_host" name="db_host"
                       value="<?= htmlspecialchars($host) ?>" required autocomplete="off">
                <span class="field-hint">Usually 127.0.0.1 when MySQL runs on this server.</span>
            </div>
            <div class="field">
                <label class="form-label" for="db_port">Port</label>
                <input type="number" class="form-control" id="db_port" name="db_port"
                       value="<?= htmlspecialchars((string) $port) ?>" required min="1" max="65535">
            </div>
        </div>

        <div class="field">
            <label class="form-label" for="db_name">Database name</label>
            <input type="text" class="form-control" id="db_name" name="db_name"
                   value="<?= htmlspecialchars($dbName) ?>" required autocomplete="off">
            <span class="field-hint">Must already exist and should be empty.</span>
        </div>

        <div class="field-grid">
            <div class="field">
                <label class="form-label" for="db_user">Username</label>
                <input type="text" class="form-control" id="db_user" name="db_user"
                       value="<?= htmlspecialchars($user) ?>" required autocomplete="off">
            </div>
            <div class="field">
                <label class="form-label" for="db_pass">Password</label>
                <input type="password" class="form-control" id="db_pass" name="db_pass"
                       value="<?= htmlspecialchars($pass) ?>" autocomplete="new-password">
            </div>
        </div>

        <button type="submit" name="test_db" class="btn btn-gold w-100 py-2 mt-3">
            <i class="fas fa-plug me-2"></i> Test the connection
        </button>
    </form>

<?php endif; ?>

<div class="mt-5 d-flex justify-content-between border-top pt-4">
    <?php if ($success): ?>
        <a href="index.php?step=database" class="btn btn-outline-secondary">
            <i class="fas fa-pen me-2"></i> Change these details
        </a>
    <?php else: ?>
        <a href="index.php?step=permissions" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i> Back
        </a>
    <?php endif; ?>

    <?php if ($success): ?>
        <a href="index.php?step=dol_database" class="btn btn-gold px-4 py-2">
            Connect Dawn of Light <i class="fas fa-arrow-right ms-2"></i>
        </a>
    <?php else: ?>
        <button class="btn btn-gold px-4 py-2 disabled" disabled>
            Test the connection first <i class="fas fa-lock ms-2"></i>
        </button>
    <?php endif; ?>
</div>
