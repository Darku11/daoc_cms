<?php
declare(strict_types=1);

if (!isset($installer)) {
    exit;
}

require_once __DIR__ . '/../includes/DolDatabase.php';
require_once __DIR__ . '/../includes/Security.php';

use DAoCCMS\Setup\DolDatabase;
use DAoCCMS\Setup\Security;
use DAoCCMS\Setup\Britty;

if (!function_exists('size_format_daoc')) {
    function size_format_daoc(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
        if ($bytes >= 1048576)    return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024)       return round($bytes / 1024) . ' KB';
        return $bytes . ' B';
    }
}

$security = new Security();

$error   = '';
$success = false;
$report  = null;   // Import result

// Prefer saved values, then use the CMS database values as a convenience.
$host   = $_SESSION['setup_dol']['host']   ?? $_SESSION['setup_db']['host'] ?? '127.0.0.1';
$port   = $_SESSION['setup_dol']['port']   ?? $_SESSION['setup_db']['port'] ?? 3306;
$dbName = $_SESSION['setup_dol']['name']   ?? '';
$user   = $_SESSION['setup_dol']['user']   ?? $_SESSION['setup_db']['user'] ?? '';
$pass   = $_SESSION['setup_dol']['pass']   ?? $_SESSION['setup_db']['pass'] ?? '';
$action = $_SESSION['setup_dol']['action'] ?? 'existing';
$core   = $_SESSION['setup_dol']['core']   ?? 'opendaoc';

$publicSqlPath   = realpath(__DIR__ . '/../sql/dol_public.sql');
$publicSqlExists = $publicSqlPath !== false && is_file($publicSqlPath);
$publicSqlSize   = $publicSqlExists ? size_format_daoc((int) filesize($publicSqlPath)) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup_dol'])) {

    if (!$security->validateToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security token validation failed. Reload the page and try again.';
    } else {
        $host   = trim($_POST['dol_host'] ?? '');
        $port   = (int) ($_POST['dol_port'] ?? 3306);
        $dbName = trim($_POST['dol_name'] ?? '');
        $user   = trim($_POST['dol_user'] ?? '');
        $pass   = $_POST['dol_pass'] ?? '';
        $action = $_POST['dol_action'] ?? 'existing';
        $core   = strtolower(trim((string)($_POST['game_server_core'] ?? 'opendaoc')));

        $destructive = in_array($action, ['public', 'upload'], true);
        $confirmed   = isset($_POST['dol_confirm']);

        if (!in_array($core, ['opendaoc', 'dol'], true)) {
            $error = 'Pick a supported game server core.';
        } elseif ($host === '' || $dbName === '' || $user === '') {
            $error = 'Host, database name, and username are required.';
        } elseif (!in_array($action, ['public', 'existing', 'upload'], true)) {
            $error = 'Pick one of the three options below.';
        } elseif ($core === 'opendaoc' && $action === 'public') {
            $error = 'The bundled public database is for legacy DOL only. Connect an existing OpenDAoC database or upload an OpenDAoC SQL backup.';
        } elseif ($destructive && !$confirmed) {
            $error = 'This option writes over the target database. Tick the confirmation box to continue.';
        } else {
            $result = DolDatabase::testConnection($host, $port, $dbName, $user, $pass);

            if (!$result['success']) {
                $error = 'Could not connect to the game server database: ' . $result['message'];
            } else {
                $pdo   = $result['pdo'];
                $valid = true;

                try {
                    if ($action === 'existing') {
                        $verify = DolDatabase::verifyExistingStructure($pdo);
                        if (!$verify['valid']) {
                            $error = $verify['message'] . ' — this does not look like a compatible ' . ($core === 'opendaoc' ? 'OpenDAoC' : 'Dawn of Light') . ' database. '
                                   . 'Check the database name, or pick one of the import options instead.';
                            $valid = false;
                        } else {
                            $accounts = DolDatabase::countAccounts($pdo);
                            $report = [
                                'mode'     => 'existing',
                                'tables'   => DolDatabase::countTables($pdo),
                                'accounts' => $accounts,
                            ];
                        }

                    } elseif ($action === 'public') {
                        if (!$publicSqlExists) {
                            $error = 'sql/dol_public.sql is missing from the setup package. '
                                   . 'Either re-download the release or use one of the other two options.';
                            $valid = false;
                        } else {
                            $stats  = DolDatabase::importSqlFile($pdo, $publicSqlPath);
                            $report = ['mode' => 'public', 'source' => 'dol_public.sql'] + $stats;
                        }

                    } elseif ($action === 'upload') {
                        $upload = $_FILES['dol_upload'] ?? null;

                        if ($upload === null || $upload['error'] === UPLOAD_ERR_NO_FILE) {
                            $error = 'Choose a .sql file to upload.';
                            $valid = false;
                        } elseif ($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE) {
                            $error = 'The file is larger than this server accepts. '
                                   . 'Current limit: ' . ini_get('upload_max_filesize') . '. '
                                   . 'Import it with the mysql command line instead, then use option two.';
                            $valid = false;
                        } elseif ($upload['error'] !== UPLOAD_ERR_OK) {
                            $error = 'The upload did not complete (error code ' . (int) $upload['error'] . '). Try again.';
                            $valid = false;
                        } elseif (strtolower(pathinfo((string) $upload['name'], PATHINFO_EXTENSION)) !== 'sql') {
                            $error = 'Only .sql files are accepted. Unpack .zip or .gz archives first.';
                            $valid = false;
                        } elseif (!is_uploaded_file($upload['tmp_name'])) {
                            $error = 'The uploaded file could not be read.';
                            $valid = false;
                        } else {
                            $stats  = DolDatabase::importSqlFile($pdo, $upload['tmp_name']);
                            $report = ['mode' => 'upload', 'source' => basename((string) $upload['name'])] + $stats;
                        }
                    }
                } catch (\Throwable $e) {
                    $error = 'The database operation failed: ' . $e->getMessage();
                    $valid = false;
                }

                if ($valid) {
                    $success = true;
                    $_SESSION['setup_dol'] = [
                        'host'   => $host,
                        'port'   => $port,
                        'name'   => $dbName,
                        'user'   => $user,
                        'pass'   => $pass,
                        'action' => $action,
                        'core'   => $core,
                    ];
                }
            }
        }
    }
}
?>

<h3 class="step-title"><i class="fas fa-dungeon"></i>The Realm Gate</h3>

<?php Britty::say(
    'Now the real gate — your actual shard. Choose with care: two of the three paths here write ' .
    'over whatever is already in the target database. "Leave it alone" is the safe choice if your ' .
    'server is already live.'
); ?>

<?php if (!$success && in_array($action, ['public', 'upload'], true)): ?>
<div class="danger danger--info" style="margin-bottom: 28px;">
    <p class="danger-title"><i class="fas fa-font"></i> Creating a fresh database? Use utf8mb4</p>
    <p class="danger-text">
        Only matters for a brand new database — an existing live shard already has its own charset and
        should be left as-is. For a new one, create it as <code class="inline-code">utf8mb4</code> so
        character names, guild names, and item text in every language survive the import intact.
    </p>
    <div class="cmd" style="margin-top: 10px;">
        <code id="dolCharsetCmd">CREATE DATABASE `your_database_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;</code>
        <button type="button" class="cmd-copy" data-copy-target="dolCharsetCmd">Copy</button>
    </div>
</div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger mb-4" style="word-break: break-word;">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<?php if ($success): ?>

    <div class="alert alert-success mb-4">
        <strong>The gate is open.</strong>
    </div>

    <dl class="ledger">
        <dt>Core</dt><dd><?= $core === 'opendaoc' ? 'OpenDAoC' : 'Dawn of Light (legacy)' ?></dd>
        <dt>Server</dt><dd><?= htmlspecialchars($host) ?>:<?= (int) $port ?></dd>
        <dt>Database</dt><dd><?= htmlspecialchars($dbName) ?></dd>
        <?php if (($report['mode'] ?? '') === 'existing'): ?>
            <dt>Mode</dt><dd>Connected to an existing shard — nothing was written</dd>
            <dt>Tables</dt><dd><?= (int) $report['tables'] ?></dd>
            <?php if ($report['accounts'] !== null): ?>
                <dt>Accounts</dt><dd><?= (int) $report['accounts'] ?> existing</dd>
            <?php endif; ?>
        <?php elseif ($report !== null): ?>
            <dt>Mode</dt><dd>Imported from <?= htmlspecialchars((string) $report['source']) ?></dd>
            <dt>Statements</dt><dd><?= (int) $report['executed'] ?> executed<?= $report['failed'] > 0 ? ', ' . (int) $report['failed'] . ' skipped' : '' ?></dd>
        <?php endif; ?>
    </dl>

    <?php if (!empty($report['failed'])): ?>
        <div class="alert alert-warning mt-4">
            <strong><?= (int) $report['failed'] ?> statements were skipped.</strong><br>
            Some of this is normal — <code class="inline-code">DROP TABLE IF EXISTS</code> often fails on
            restricted hosting. But check the first message below before you continue:
            <div class="console" style="margin-top: 12px; max-height: 120px;">
                <?php foreach ($report['errors'] as $msg): ?>
                    <div class="log-line log-line--bad"><?= htmlspecialchars($msg) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

<?php else: ?>

    <form method="POST" action="index.php?step=dol_database" enctype="multipart/form-data" id="dolForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($security->generateToken()) ?>">

        <p class="act-slug">Connection</p>

        <div class="field">
            <label class="form-label" for="game_server_core">Game server core</label>
            <select class="form-select" id="game_server_core" name="game_server_core">
                <option value="opendaoc" <?= $core === 'opendaoc' ? 'selected' : '' ?>>OpenDAoC</option>
                <option value="dol" <?= $core === 'dol' ? 'selected' : '' ?>>Dawn of Light (legacy)</option>
            </select>
            <span class="field-hint">OpenDAoC is the primary target for RC2. Existing DOL installations remain supported.</span>
        </div>

        <div class="field-grid">
            <div class="field field--wide">
                <label class="form-label" for="dol_host">Host</label>
                <input type="text" class="form-control" id="dol_host" name="dol_host"
                       value="<?= htmlspecialchars($host) ?>" required autocomplete="off">
            </div>
            <div class="field">
                <label class="form-label" for="dol_port">Port</label>
                <input type="number" class="form-control" id="dol_port" name="dol_port"
                       value="<?= htmlspecialchars((string) $port) ?>" required min="1" max="65535">
            </div>
        </div>

        <div class="field">
            <label class="form-label" for="dol_name">Database name</label>
            <input type="text" class="form-control" id="dol_name" name="dol_name"
                   value="<?= htmlspecialchars($dbName) ?>" required autocomplete="off">
            <span class="field-hint">The database configured by your game server. It often contains <code class="inline-code">account</code> and <code class="inline-code">dolcharacters</code>.</span>
        </div>

        <div class="field-grid">
            <div class="field">
                <label class="form-label" for="dol_user">Username</label>
                <input type="text" class="form-control" id="dol_user" name="dol_user"
                       value="<?= htmlspecialchars($user) ?>" required autocomplete="off">
            </div>
            <div class="field">
                <label class="form-label" for="dol_pass">Password</label>
                <input type="password" class="form-control" id="dol_pass" name="dol_pass"
                       value="<?= htmlspecialchars($pass) ?>" autocomplete="new-password">
            </div>
        </div>

        <p class="act-slug" style="margin-top: 34px;">What should happen to this database?</p>

        <div class="choices" id="dolChoices">

            <label class="choice<?= $action === 'existing' ? ' is-picked' : '' ?>">
                <input type="radio" name="dol_action" value="existing" <?= $action === 'existing' ? 'checked' : '' ?>>
                <span class="choice-mark" aria-hidden="true"></span>
                <span class="choice-body">
                    <b>Leave it alone</b>
                    <span>Connect to a shard that is already running. Nothing is written. The wizard
                    only checks that the <code class="inline-code">account</code> and
                    <code class="inline-code">dolcharacters</code> tables are there.</span>
                    <span class="choice-tag choice-tag--safe">Safe · recommended for live servers</span>
                </span>
            </label>

            <label class="choice<?= $action === 'public' ? ' is-picked' : '' ?><?= ($publicSqlExists && $core === 'dol') ? '' : ' is-disabled' ?>" id="dolPublicChoice">
                <input type="radio" name="dol_action" value="public"
                       <?= $action === 'public' ? 'checked' : '' ?> <?= ($publicSqlExists && $core === 'dol') ? '' : 'disabled' ?>
                       data-file-available="<?= $publicSqlExists ? '1' : '0' ?>">
                <span class="choice-mark" aria-hidden="true"></span>
                <span class="choice-body">
                    <b>Install the public database</b>
                    <span id="dolPublicDescription"><?= $publicSqlExists
                        ? ($core === 'dol'
                            ? 'Imports the bundled standard DOL database (' . htmlspecialchars((string) $publicSqlSize) . '). Use this for a brand new legacy DOL shard.'
                            : 'The bundled public database belongs to legacy DOL. OpenDAoC requires its own database or SQL backup.')
                        : 'Unavailable — sql/dol_public.sql is not in this setup package.' ?></span>
                    <span class="choice-tag choice-tag--danger">Writes over existing tables</span>
                </span>
            </label>

            <label class="choice<?= $action === 'upload' ? ' is-picked' : '' ?>">
                <input type="radio" name="dol_action" value="upload" <?= $action === 'upload' ? 'checked' : '' ?>>
                <span class="choice-mark" aria-hidden="true"></span>
                <span class="choice-body">
                    <b>Import my own backup</b>
                    <span>Upload a plain <code class="inline-code">.sql</code> file. Server limit:
                    <?= htmlspecialchars((string) ini_get('upload_max_filesize')) ?>. Larger dumps are
                    faster through the mysql command line — then pick the first option instead.</span>
                    <span class="choice-tag choice-tag--danger">Writes over existing tables</span>

                    <span class="choice-extra" id="uploadBox" hidden>
                        <input type="file" class="form-control form-control-sm" name="dol_upload" accept=".sql,application/sql,text/plain">
                    </span>
                </span>
            </label>

        </div>

        <div class="danger" id="dangerBox" hidden>
            <p class="danger-title"><i class="fas fa-triangle-exclamation"></i> This will write over data</p>
            <p class="danger-text">
                Tables in <b id="dangerDb">the target database</b> that also exist in the import file
                will be dropped and rebuilt. Characters, accounts, and items in those tables are gone.
                There is no undo from here.
            </p>
            <label class="confirm">
                <input type="checkbox" name="dol_confirm" id="dolConfirm">
                <span>I have a backup, or this database holds nothing I need.</span>
            </label>
        </div>

        <button type="submit" name="setup_dol" class="btn btn-gold w-100 py-2 mt-4" id="dolSubmit">
            <i class="fas fa-plug me-2"></i> <span id="dolSubmitLabel">Test the connection</span>
        </button>

        <p class="probe-note" style="margin-top: 12px; text-align: center;">
            Importing a large database can take a minute or two. Do not close the tab.
        </p>
    </form>

    <script>
    (function () {
        var choices = document.querySelectorAll('#dolChoices .choice');
        var radios  = document.querySelectorAll('#dolChoices input[name="dol_action"]');
        var upload  = document.getElementById('uploadBox');
        var danger  = document.getElementById('dangerBox');
        var confirm = document.getElementById('dolConfirm');
        var label   = document.getElementById('dolSubmitLabel');
        var dbField = document.getElementById('dol_name');
        var dbName  = document.getElementById('dangerDb');
        var core    = document.getElementById('game_server_core');
        var publicRadio = document.querySelector('#dolChoices input[value="public"]');
        var publicChoice = document.getElementById('dolPublicChoice');
        var publicDescription = document.getElementById('dolPublicDescription');

        function syncCore() {
            if (!core || !publicRadio) return;

            var fileAvailable = publicRadio.dataset.fileAvailable === '1';
            var canUsePublic = fileAvailable && core.value === 'dol';
            publicRadio.disabled = !canUsePublic;
            if (publicChoice) publicChoice.classList.toggle('is-disabled', !canUsePublic);

            if (publicDescription && fileAvailable) {
                publicDescription.textContent = canUsePublic
                    ? 'Imports the bundled standard DOL database. Use this for a brand new legacy DOL shard.'
                    : 'The bundled public database belongs to legacy DOL. OpenDAoC requires its own database or SQL backup.';
            }

            if (!canUsePublic && publicRadio.checked) {
                var existing = document.querySelector('#dolChoices input[value="existing"]');
                if (existing) existing.checked = true;
            }
        }

        function sync() {
            var picked = document.querySelector('#dolChoices input[name="dol_action"]:checked');
            var value  = picked ? picked.value : 'existing';

            choices.forEach(function (c) {
                c.classList.toggle('is-picked', c.contains(picked));
            });

            upload.hidden = value !== 'upload';

            var destructive = (value === 'public' || value === 'upload');
            danger.hidden = !destructive;
            if (!destructive && confirm) confirm.checked = false;

            label.textContent = destructive ? 'Import and overwrite' : 'Test the connection';
        }

        function syncName() {
            dbName.textContent = dbField.value.trim() || 'the target database';
        }

        radios.forEach(function (r) { r.addEventListener('change', sync); });
        if (core) core.addEventListener('change', function () { syncCore(); sync(); });
        if (dbField) dbField.addEventListener('input', syncName);

        syncCore();
        sync();
        syncName();
    })();
    </script>

<?php endif; ?>

<div class="mt-5 d-flex justify-content-between border-top pt-4">
    <?php if ($success): ?>
        <a href="index.php?step=dol_database" class="btn btn-outline-secondary">
            <i class="fas fa-pen me-2"></i> Change these details
        </a>
    <?php else: ?>
        <a href="index.php?step=database" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i> Back
        </a>
    <?php endif; ?>

    <?php if ($success): ?>
        <a href="index.php?step=configuration" class="btn btn-gold px-4 py-2">
            Configure the CMS <i class="fas fa-arrow-right ms-2"></i>
        </a>
    <?php else: ?>
        <button class="btn btn-gold px-4 py-2 disabled" disabled>
            Connect DOL first <i class="fas fa-lock ms-2"></i>
        </button>
    <?php endif; ?>
</div>
