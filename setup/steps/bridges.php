<?php
// SPDX-License-Identifier: GPL-3.0-only
declare(strict_types=1);

if (!isset($installer)) {
    exit;
}

require_once __DIR__ . '/../includes/Security.php';

use DAoCCMS\Setup\Britty;
use DAoCCMS\Setup\Security;

$security = new Security();
$error = '';
$success = !empty($_SESSION['setup_console']);
$consoleHost = (string)($_SESSION['setup_console']['host'] ?? '127.0.0.1');
$consolePort = (int)($_SESSION['setup_console']['port'] ?? 5100);
$bridgePort = (int)($_SESSION['setup_console']['bridge_port'] ?? 2000);
$defaultCmsApiUrl = rtrim((string)($_SESSION['setup_config']['base_url'] ?? ''), '/') . '/api_events.php';
$cmsApiUrl = (string)($_SESSION['setup_console']['cms_api_url'] ?? $defaultCmsApiUrl);
$sharedSecret = (string)(
    $_SESSION['setup_config']['asp_key']
    ?? $_SESSION['setup_crypto']['asp_key']
    ?? ''
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_bridge_config'])) {
    if (!$security->validateToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security token validation failed. Reload the page and try again.';
    } else {
        $consoleHost = trim((string)($_POST['console_host'] ?? ''));
        $consolePort = (int)($_POST['console_port'] ?? 5100);
        $bridgePort = (int)($_POST['bridge_port'] ?? 2000);
        $cmsApiUrl = trim((string)($_POST['cms_api_url'] ?? ''));

        $validHost = $consoleHost !== ''
            && !preg_match('/[\s\/?#]/', $consoleHost)
            && (filter_var(trim($consoleHost, '[]'), FILTER_VALIDATE_IP) !== false
                || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $consoleHost));

        $cmsApiScheme = strtolower((string)parse_url($cmsApiUrl, PHP_URL_SCHEME));
        $validCmsApiUrl = !preg_match('/[\r\n]/', $cmsApiUrl)
            && filter_var($cmsApiUrl, FILTER_VALIDATE_URL) !== false
            && in_array($cmsApiScheme, ['http', 'https'], true);

        if (!$validHost) {
            $error = 'Enter a valid AldhranConsole host name or IP address without http:// or a path.';
        } elseif (!$validCmsApiUrl) {
            $error = 'Enter an absolute HTTP or HTTPS URL for the CMS event API.';
        } elseif ($consolePort < 1 || $consolePort > 65535 || $bridgePort < 1 || $bridgePort > 65535) {
            $error = 'Console and bridge ports must be between 1 and 65535.';
        } else {
            $_SESSION['setup_console'] = [
                'host' => $consoleHost,
                'port' => $consolePort,
                'bridge_port' => $bridgePort,
                'cms_api_url' => $cmsApiUrl,
            ];
            $success = true;
        }
    }
}

$downloads = [
    'DAoCCmsBridgeConfig.cs' => 'Required shared configuration reader. All three bridge scripts obtain their URL, secret and TCP port through this file.',
    'AldhranBridge.cs'  => 'The console bridge — status, kick, teleport, item delivery, guild chat relay, restart and more. This is what actually talks to your running game world.',
    'CMSLiveEvents.cs'  => 'Pushes PvP kill and keep-capture events from the game to your site\'s live event feed.',
    'GuildChatBridge.cs'=> 'Relays in-game guild chat to Discord by re-registering the &gu command in the scripts folder.',
];
?>

<h3 class="step-title"><i class="fas fa-link"></i>The Sinews</h3>

<?php Britty::say([
    'Some of what your site can do reaches straight into the running game — kicking a player, ' .
    'handing over a purchased item, echoing guild chat into Discord. None of that travels through ' .
    'the CMS database. It needs its own connective tissue between the site and the server.',
    'This step is entirely optional — skip it if you only want the website and forum. Come back ' .
    'whenever you decide to wire up the console, the itemshop, or live event announcements.',
    'The three feature scripts share one configuration reader and one generated configuration file. No URL, secret or port needs to be edited in C#.',
]); ?>

<p class="act-slug" style="margin-bottom: 14px;">How a request actually travels</p>

<dl class="ledger">
    <dt>1. CMS → AldhranConsole</dt>
    <dd>HTTP, port 5100, header <code class="inline-code">X-Aldhran-Secret</code>. The CMS never talks to the game server directly.</dd>
    <dt>2. AldhranConsole → AldhranBridge.cs</dt>
    <dd>Raw TCP, port 2000, shared secret. AldhranConsole is a separate .NET service — it can run anywhere as long as it can reach both the CMS and your game server.</dd>
    <dt>3. AldhranBridge.cs → the game world</dt>
    <dd>In-process — this script runs inside your DOL or OpenDAoC server itself and is the only thing that actually touches players, items, and the world.</dd>
</dl>

<p class="act-slug" style="margin: 34px 0 14px;">Where each file goes</p>

<ul class="manifest">
    <li class="manifest-item">
        <span class="m-num">01</span>
        <span class="m-body">
            <b>DOL / OpenDAoC <code class="inline-code">scripts/</code> folder.</b>
            <code class="inline-code">DAoCCmsBridgeConfig.cs</code>, <code class="inline-code">AldhranBridge.cs</code>,
            <code class="inline-code">CMSLiveEvents.cs</code>, and <code class="inline-code">GuildChatBridge.cs</code>
            all belong here and work unchanged on
            either core. DOL normally compiles them when the server starts. OpenDAoC builds affected by the
            <code class="inline-code">Bad IL format</code> compiler failure can use
            <code class="inline-code">tools/Build-OpenDAoCScriptAssembly.ps1</code> from the utilities repository.
            The builder targets the exact installed release and writes OpenDAoC's script-cache metadata, so
            <code class="inline-code">EnableCompilation=True</code> can remain set while the scripts are unchanged.
            Run the builder again after every script change, then restart the server.
            Put the generated <code class="inline-code">daoc_cms_bridge.conf</code> in the game server's
            <code class="inline-code">config/</code> folder. Configuration changes only require replacing
            that file and restarting the server; the C# sources do not need to be edited.
        </span>
    </li>
    <li class="manifest-item">
        <span class="m-num">02</span>
        <span class="m-body">
            <b>AldhranConsole — runs as its own service, anywhere.</b>
            This is a separate .NET application, not a scripts-folder script. It can sit on the same machine
            as your game server or somewhere else entirely, as long as it can reach your DOL or OpenDAoC
            server on port 2000 and your site can reach it on port 5100. Set <code class="inline-code">Console:SharedSecret</code>
            in its <code class="inline-code">appsettings.json</code> to the integration secret shown below,
            and set its bridge port to the same value as the generated game-server configuration. Build, publish and
            service instructions are in the
            <a href="https://github.com/Darku11/daoc_cms_utilities/tree/main/AldhranConsole" target="_blank" rel="noopener noreferrer">AldhranConsole guide</a>.
        </span>
    </li>
    <li class="manifest-item">
        <span class="m-num">03</span>
        <span class="m-body">
            <b>DAoCTerrainServices &amp; DAoCLib — only if a tool you're using needs them.</b>
            These are supporting libraries some admin tools rely on for map/terrain data. Like
            AldhranConsole, they don't need to live in any particular folder — just somewhere reachable
            by whatever depends on them. If nothing you're running mentions them, you don't need them yet.
        </span>
    </li>
</ul>

<p class="act-slug" style="margin: 34px 0 14px;">Ports and secrets, at a glance</p>

<dl class="ledger">
    <dt>AldhranConsole (HTTP)</dt>
    <dd>Port 5100 by default. The shared secret is stored in ACP → General Settings → Bridge Connection and mirrored in AldhranConsole's own config.</dd>
    <dt>AldhranBridge.cs (TCP)</dt>
    <dd>Port 2000 by default. The port and secret come from <code class="inline-code">config/daoc_cms_bridge.conf</code>.</dd>
    <dt>Discord bot socket</dt>
    <dd>Port 15000 by default. Unrelated to the two above — configured separately under ACP → Bot Settings. Covered when you set up the bot, not here.</dd>
</dl>

<p class="act-slug" style="margin: 34px 0 14px;">CMS connection settings</p>

<?php if ($error): ?>
    <div class="alert alert-danger mb-4"><?= htmlspecialchars($error) ?></div>
<?php elseif ($success): ?>
    <div class="alert alert-success mb-4"><strong>Console connection saved.</strong></div>
<?php endif; ?>

<form method="POST" action="index.php?step=bridges">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($security->generateToken()) ?>">

    <div class="field-grid">
        <div class="field field--wide">
            <label class="form-label" for="console_host">AldhranConsole host</label>
            <input type="text" class="form-control" id="console_host" name="console_host"
                   value="<?= htmlspecialchars($consoleHost) ?>" required autocomplete="off">
            <span class="field-hint">Host name or IP as seen by the web server. Do not include <code class="inline-code">http://</code>.</span>
        </div>
        <div class="field">
            <label class="form-label" for="console_port">HTTP port</label>
            <input type="number" class="form-control" id="console_port" name="console_port"
                   value="<?= $consolePort ?>" min="1" max="65535" required>
        </div>
    </div>

    <div class="field">
        <label class="form-label" for="bridge_port">AldhranBridge TCP port</label>
        <input type="number" class="form-control" id="bridge_port" name="bridge_port"
               value="<?= $bridgePort ?>" min="1" max="65535" required>
    </div>

    <div class="field">
        <label class="form-label" for="cms_api_url">CMS event API URL</label>
        <input type="url" class="form-control" id="cms_api_url" name="cms_api_url"
               value="<?= htmlspecialchars($cmsApiUrl) ?>" required autocomplete="off">
        <span class="field-hint">
            Usually your public CMS URL followed by <code class="inline-code">/api_events.php</code>.
            The game server must be able to reach it.
        </span>
    </div>

    <div class="field">
        <label class="form-label" for="bridge_shared_secret">Shared secret</label>
        <div class="secret">
            <input type="text" class="form-control" id="bridge_shared_secret"
                   value="<?= htmlspecialchars($sharedSecret) ?>" readonly>
            <button type="button" class="cmd-copy" data-copy-target="bridge_shared_secret">Copy</button>
        </div>
        <span class="field-hint">
            Generated in the previous step. The configuration download below writes it once for every
            game-server script. Use the same value for <code class="inline-code">Console:SharedSecret</code>
            in AldhranConsole.
        </span>
    </div>

    <button type="submit" name="save_bridge_config" class="btn btn-gold w-100 py-2 mt-3">
        <i class="fas fa-save me-2"></i> Save the connection values
    </button>
</form>

<p class="probe-note" style="margin-top: 14px;">
    Saving these values configures the CMS only. AldhranConsole remains optional and must still be
    published, configured and started separately before live administration features become available.
</p>

<p class="act-slug" style="margin: 34px 0 14px;">Downloads</p>

<?php if ($success): ?>
    <div class="alert alert-success mb-4">
        <strong>1. Download the configured bridge file.</strong><br>
        Save it as <code class="inline-code">config/daoc_cms_bridge.conf</code> below your DOL/OpenDAoC
        server root. It supplies the same URL, secret and TCP port to all three feature scripts.
        Restrict read access to the game-server account because the file contains the shared secret.
        <div class="mt-3">
            <a href="download_bridge_config.php?csrf_token=<?= rawurlencode($security->generateToken()) ?>"
               class="btn btn-gold" download>
                <i class="fas fa-download me-2"></i> Download daoc_cms_bridge.conf
            </a>
        </div>
    </div>
<?php else: ?>
    <div class="alert alert-warning mb-4">
        Save the connection values first. The setup will then generate the configured bridge file for you.
    </div>
<?php endif; ?>

<p class="probe-note" style="margin-bottom: 16px;">
    <strong>2. Download the scripts.</strong> Put the shared configuration reader and the feature scripts you
    need in the game server's <code class="inline-code">scripts/</code> folder. The source files contain no
    site-specific URL, secret or port and do not need to be edited.
</p>

<ul class="probes">
    <?php foreach ($downloads as $file => $why): ?>
        <li class="probe is-ok">
            <span class="mark mark--ok" aria-hidden="true"></span>
            <span class="probe-body">
                <b class="is-path"><?= htmlspecialchars($file) ?></b>
                <span class="probe-note"><?= htmlspecialchars($why) ?></span>
            </span>
            <span class="probe-value">
                <a href="downloads/<?= rawurlencode($file) ?>" download class="text-link">
                    <i class="fas fa-download"></i> Download
                </a>
            </span>
        </li>
    <?php endforeach; ?>
</ul>

<p class="probe-note" style="margin-top: 16px;">
    AldhranConsole itself isn't a DOL/OpenDAoC script, so it isn't bundled as a download here — it's a
    separate ASP.NET project you build and run on its own. Its source lives in the
    <a href="https://github.com/Darku11/daoc_cms_utilities/tree/main/AldhranConsole" class="text-link" target="_blank" rel="noopener">
        <code class="inline-code">AldhranConsole/</code> folder of the daoc_cms_utilities repository</a>.
    It runs unmodified on both DOL and OpenDAoC.
</p>

<div class="mt-5 d-flex justify-content-between border-top pt-4">
    <a href="index.php?step=configuration" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-2"></i> Back
    </a>
    <a href="index.php?step=administrator" class="btn btn-gold px-4 py-2">
        Create the administrator <i class="fas fa-arrow-right ms-2"></i>
    </a>
</div>
