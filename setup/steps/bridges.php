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

        $validHost = $consoleHost !== ''
            && !preg_match('/[\s\/?#]/', $consoleHost)
            && (filter_var(trim($consoleHost, '[]'), FILTER_VALIDATE_IP) !== false
                || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $consoleHost));

        if (!$validHost) {
            $error = 'Enter a valid AldhranConsole host name or IP address without http:// or a path.';
        } elseif ($consolePort < 1 || $consolePort > 65535 || $bridgePort < 1 || $bridgePort > 65535) {
            $error = 'Console and bridge ports must be between 1 and 65535.';
        } else {
            $_SESSION['setup_console'] = [
                'host' => $consoleHost,
                'port' => $consolePort,
                'bridge_port' => $bridgePort,
            ];
            $success = true;
        }
    }
}

$downloads = [
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
    'Three pieces, three different jobs. You only need the ones behind the features you actually use.',
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
            <b>DOL / OpenDAoC <code class="inline-code">scripts/</code> folder — drop in, no build required.</b>
            <code class="inline-code">AldhranBridge.cs</code>, <code class="inline-code">CMSLiveEvents.cs</code>,
            and <code class="inline-code">GuildChatBridge.cs</code> all belong here and work unchanged on
            either core. Both compile this folder themselves when the server starts — restart the server
            after adding or changing a file, no separate build step.
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
            and use that same value in <code class="inline-code">AldhranBridge.cs</code>. Build, publish and
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
    <dd>Port 2000 by default. Use the same shared secret as the <code class="inline-code">BRIDGE_SECRET</code> value in the .cs file below.</dd>
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
        <label class="form-label" for="bridge_shared_secret">Shared secret</label>
        <div class="secret">
            <input type="text" class="form-control" id="bridge_shared_secret"
                   value="<?= htmlspecialchars($sharedSecret) ?>" readonly>
            <button type="button" class="cmd-copy" data-copy-target="bridge_shared_secret">Copy</button>
        </div>
        <span class="field-hint">
            Generated in the previous step. Use this exact value for
            <code class="inline-code">Console:SharedSecret</code> and every
            <code class="inline-code">BRIDGE_SECRET</code> constant.
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

<p class="probe-note" style="margin-bottom: 16px;">
    Every secret and URL inside the downloadable source files is a placeholder —
    <code class="inline-code">CHANGE_ME_BRIDGE_SECRET</code> and <code class="inline-code">YOUR-SITE.example</code>.
    Edit them before the game server compiles the scripts, using the shared secret shown above.
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
