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

$guessedUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://')
            . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . str_replace('/setup/index.php', '', $_SERVER['SCRIPT_NAME'] ?? '');

$cmsName      = $_SESSION['setup_config']['cms_name']      ?? 'DAoC CMS';
$serverName   = $_SESSION['setup_config']['server_name']   ?? 'My Freeshard';
$baseUrl      = $_SESSION['setup_config']['base_url']      ?? $guessedUrl;
$language     = $_SESSION['setup_config']['language']      ?? 'en';
$timezone     = $_SESSION['setup_config']['timezone']      ?? 'Europe/Berlin';
$senderName   = $_SESSION['setup_config']['sender_name']   ?? 'DAoC Server';
$senderEmail  = $_SESSION['setup_config']['sender_email']  ?? 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$resendKey    = $_SESSION['setup_config']['resend_key']    ?? '';
$discordToken = $_SESSION['setup_config']['discord_token'] ?? '';
$discordGuild = $_SESSION['setup_config']['discord_guild'] ?? '';

// Generate keys once and keep them in the session.
if (empty($_SESSION['setup_crypto']['instance_id'])) {
    $_SESSION['setup_crypto']['instance_id'] = $security->generateInstanceId();
}
if (empty($_SESSION['setup_crypto']['pepper'])) {
    $_SESSION['setup_crypto']['pepper'] = $security->generatePepper();
}
if (empty($_SESSION['setup_crypto']['asp_key'])) {
    $_SESSION['setup_crypto']['asp_key'] = $security->generateAspKey();
}
if (empty($_SESSION['setup_crypto']['bot_bootstrap_secret'])) {
    $_SESSION['setup_crypto']['bot_bootstrap_secret'] = $security->generateBotBootstrapSecret();
}

$instanceId        = $_SESSION['setup_crypto']['instance_id'];
$pepper            = $_SESSION['setup_crypto']['pepper'];
$botBootstrapSecret = $_SESSION['setup_crypto']['bot_bootstrap_secret'];
$aspKey            = $_SESSION['setup_config']['asp_key'] ?? $_SESSION['setup_crypto']['asp_key'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    if (!$security->validateToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security token validation failed. Reload the page and try again.';
    } else {
        $cmsName      = $validator->sanitizeString($_POST['cms_name'] ?? '');
        $serverName   = $validator->sanitizeString($_POST['server_name'] ?? '');
        $baseUrl      = rtrim($validator->sanitizeString($_POST['base_url'] ?? ''), '/');
        $language     = $validator->sanitizeString($_POST['language'] ?? 'en');
        $timezone     = $validator->sanitizeString($_POST['timezone'] ?? 'UTC');
        $senderName   = $validator->sanitizeString($_POST['sender_name'] ?? '');
        $senderEmail  = $validator->sanitizeString($_POST['sender_email'] ?? '');
        $resendKey    = $validator->sanitizeString($_POST['resend_key'] ?? '');
        $discordToken = $validator->sanitizeString($_POST['discord_token'] ?? '');
        $discordGuild = $validator->sanitizeString($_POST['discord_guild'] ?? '');
        $aspKey       = $validator->sanitizeString($_POST['asp_key'] ?? '');

        if ($cmsName === '' || $serverName === '' || $baseUrl === '' || $senderName === '' || $senderEmail === '' || $aspKey === '') {
            $error = 'Fill in every field marked with an asterisk.';
        } elseif (!$validator->isEmail($senderEmail)) {
            $error = 'The sender address is not a valid email address.';
        } elseif (!in_array($timezone, timezone_identifiers_list(), true)) {
            $error = 'Pick a timezone from the list.';
        } else {
            $_SESSION['setup_config'] = [
                'cms_name'      => $cmsName,
                'server_name'   => $serverName,
                'base_url'      => $baseUrl,
                'language'      => $language,
                'timezone'      => $timezone,
                'sender_name'   => $senderName,
                'sender_email'  => $senderEmail,
                'resend_key'    => $resendKey,
                'discord_token' => $discordToken,
                'discord_guild' => $discordGuild,
                'asp_key'       => $aspKey,
            ];
            $success = true;
        }
    }
}

// Group timezones by region so the full list remains usable.
$zonesByRegion = [];
foreach (timezone_identifiers_list() as $tz) {
    $slash  = strpos($tz, '/');
    $region = $slash === false ? 'Other' : substr($tz, 0, $slash);
    $zonesByRegion[$region][] = $tz;
}
ksort($zonesByRegion);
?>

<h3 class="step-title"><i class="fas fa-scroll"></i>The Sigils</h3>

<?php Britty::say(
    'The site\'s own name, its address, and the keys I\'ve already generated for you. Write the ' .
    'pepper down somewhere safe before you move on — I mean that literally, it\'s further down this page.'
); ?>

<?php if ($error): ?>
    <div class="alert alert-danger mb-4"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>

    <div class="alert alert-success mb-4"><strong>Configuration saved.</strong></div>

    <dl class="ledger">
        <dt>Site</dt><dd><?= htmlspecialchars($cmsName) ?> — <?= htmlspecialchars($serverName) ?></dd>
        <dt>URL</dt><dd><?= htmlspecialchars($baseUrl) ?></dd>
        <dt>Language</dt><dd><?= htmlspecialchars($language) ?></dd>
        <dt>Timezone</dt><dd><?= htmlspecialchars($timezone) ?></dd>
        <dt>Mail from</dt><dd><?= htmlspecialchars($senderName) ?> &lt;<?= htmlspecialchars($senderEmail) ?>&gt;</dd>
    </dl>

<?php else: ?>

    <form method="POST" action="index.php?step=configuration" id="configForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($security->generateToken()) ?>">

        <p class="act-slug">General</p>

        <div class="field-grid">
            <div class="field">
                <label class="form-label" for="cms_name">Site name *</label>
                <input type="text" class="form-control" id="cms_name" name="cms_name"
                       value="<?= htmlspecialchars($cmsName) ?>" required>
                <span class="field-hint">Shown in the browser tab and in emails.</span>
            </div>
            <div class="field">
                <label class="form-label" for="server_name">Shard name *</label>
                <input type="text" class="form-control" id="server_name" name="server_name"
                       value="<?= htmlspecialchars($serverName) ?>" required>
                <span class="field-hint">The name players know your server by.</span>
            </div>
        </div>

        <div class="field">
            <label class="form-label" for="base_url">Site URL *</label>
            <input type="url" class="form-control" id="base_url" name="base_url"
                   value="<?= htmlspecialchars($baseUrl) ?>" required>
            <span class="field-hint">
                No trailing slash. Every link in outgoing email is built from this, so get it right —
                changing it later means editing <code class="inline-code">includes/config.php</code>.
            </span>
        </div>

        <div class="field-grid">
            <div class="field">
                <label class="form-label" for="language">Language</label>
                <select class="form-select" id="language" name="language">
                    <option value="en" <?= $language === 'en' ? 'selected' : '' ?>>English</option>
                    <option value="de" <?= $language === 'de' ? 'selected' : '' ?>>Deutsch</option>
                    <option value="fr" <?= $language === 'fr' ? 'selected' : '' ?>>Français</option>
                </select>
            </div>
            <div class="field">
                <label class="form-label" for="timezone">Timezone</label>
                <select class="form-select" id="timezone" name="timezone">
                    <?php foreach ($zonesByRegion as $region => $zones): ?>
                        <optgroup label="<?= htmlspecialchars($region) ?>">
                            <?php foreach ($zones as $tz): ?>
                                <option value="<?= htmlspecialchars($tz) ?>" <?= $timezone === $tz ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(str_replace('_', ' ', $tz)) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <span class="field-hint">Used for timestamps on the site.</span>
            </div>
        </div>

        <p class="act-slug" style="margin-top: 34px;">Mail and integrations</p>

        <div class="field-grid">
            <div class="field">
                <label class="form-label" for="sender_name">Sender name *</label>
                <input type="text" class="form-control" id="sender_name" name="sender_name"
                       value="<?= htmlspecialchars($senderName) ?>" required>
            </div>
            <div class="field">
                <label class="form-label" for="sender_email">Sender address *</label>
                <input type="email" class="form-control" id="sender_email" name="sender_email"
                       value="<?= htmlspecialchars($senderEmail) ?>" required>
                <span class="field-hint">Must be a domain you can send from.</span>
            </div>
        </div>

        <div class="field">
            <label class="form-label" for="resend_key">Resend API key</label>
            <input type="password" class="form-control" id="resend_key" name="resend_key"
                   value="<?= htmlspecialchars($resendKey) ?>" autocomplete="off">
            <span class="field-hint">Optional. Without it, account verification emails will not go out.</span>
        </div>

        <div class="field-grid">
            <div class="field">
                <label class="form-label" for="discord_token">Discord bot token</label>
                <input type="password" class="form-control" id="discord_token" name="discord_token"
                       value="<?= htmlspecialchars($discordToken) ?>" autocomplete="off">
                <span class="field-hint">Optional. Can be added later.</span>
            </div>
            <div class="field">
                <label class="form-label" for="discord_guild">Discord server ID</label>
                <input type="text" class="form-control" id="discord_guild" name="discord_guild"
                       value="<?= htmlspecialchars($discordGuild) ?>" autocomplete="off">
                <span class="field-hint">Optional.</span>
            </div>
        </div>

        <p class="act-slug" style="margin-top: 34px;">Keys</p>

        <div class="danger danger--info">
            <p class="danger-title"><i class="fas fa-shield-halved"></i> Write the pepper down before you continue</p>
            <p class="danger-text">
                The pepper is mixed into every password hash. It is generated once and stored in
                <code class="inline-code">includes/config.php</code>. If that file is ever lost or the value
                changes, <b>every account password on your site stops working</b> and cannot be recovered —
                not by you, not by a reset. Keep a copy somewhere outside this server.
            </p>
        </div>

        <div class="field">
            <label class="form-label" for="f_pepper">Password pepper</label>
            <div class="secret">
                <input type="text" class="form-control" id="f_pepper" value="<?= htmlspecialchars($pepper) ?>" readonly>
                <button type="button" class="cmd-copy" data-copy-target="f_pepper">Copy</button>
            </div>
        </div>

        <div class="field">
            <label class="form-label" for="f_instance">Instance ID</label>
            <div class="secret">
                <input type="text" class="form-control" id="f_instance" value="<?= htmlspecialchars($instanceId) ?>" readonly>
                <button type="button" class="cmd-copy" data-copy-target="f_instance">Copy</button>
            </div>
            <span class="field-hint">Identifies this installation. Not secret.</span>
        </div>

        <div class="field">
            <label class="form-label" for="f_bot_bootstrap">Bot bootstrap secret</label>
            <div class="secret">
                <input type="text" class="form-control" id="f_bot_bootstrap" value="<?= htmlspecialchars($botBootstrapSecret) ?>" readonly>
                <button type="button" class="cmd-copy" data-copy-target="f_bot_bootstrap">Copy</button>
            </div>
            <span class="field-hint">Authenticates the Discord bot when it loads its CMS configuration.</span>
        </div>

        <div class="field">
            <label class="form-label" for="asp_key">Game server integration secret *</label>
            <div class="secret">
                <input type="text" class="form-control" id="asp_key" name="asp_key"
                       value="<?= htmlspecialchars($aspKey) ?>" required autocomplete="off">
                <button type="button" class="cmd-copy" id="aspRoll" title="Generate a new key">New</button>
                <button type="button" class="cmd-copy" data-copy-target="asp_key">Copy</button>
            </div>
            <span class="field-hint">
                Shared secret between the CMS, AldhranConsole and the game server. The Bridges step generates
                the ready-to-use game-server configuration file; no C# source needs to be edited.
            </span>
        </div>

        <button type="submit" name="save_config" class="btn btn-gold w-100 py-2 mt-4">
            <i class="fas fa-save me-2"></i> Save the configuration
        </button>
    </form>

    <script>
    (function () {
        var roll  = document.getElementById('aspRoll');
        var field = document.getElementById('asp_key');
        if (!roll || !field) return;

        roll.addEventListener('click', function () {
            var bytes = new Uint8Array(16);
            (window.crypto || window.msCrypto).getRandomValues(bytes);
            field.value = Array.from(bytes).map(function (b) {
                return b.toString(16).padStart(2, '0');
            }).join('');
            field.focus();
        });
    })();
    </script>

<?php endif; ?>

<div class="mt-5 d-flex justify-content-between border-top pt-4">
    <?php if ($success): ?>
        <a href="index.php?step=configuration" class="btn btn-outline-secondary">
            <i class="fas fa-pen me-2"></i> Change these details
        </a>
    <?php else: ?>
        <a href="index.php?step=dol_database" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i> Back
        </a>
    <?php endif; ?>

    <?php if ($success): ?>
        <a href="index.php?step=bridges" class="btn btn-gold px-4 py-2">
            Connect the game server tools <i class="fas fa-arrow-right ms-2"></i>
        </a>
    <?php else: ?>
        <button class="btn btn-gold px-4 py-2 disabled" disabled>
            Save to continue <i class="fas fa-lock ms-2"></i>
        </button>
    <?php endif; ?>
</div>
