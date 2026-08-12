<?php
// SPDX-License-Identifier: GPL-3.0-only
declare(strict_types=1);

if (!isset($installer)) {
    exit;
}

use DAoCCMS\Setup\Britty;

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
            server on port 2000 and your site can reach it on port 5100. Set <code class="inline-code">Console:BridgeSecret</code>
            in its <code class="inline-code">appsettings.json</code> to match the secret you put in
            <code class="inline-code">AldhranBridge.cs</code>.
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
    <dd>Port 5100 by default. Secret set once in ACP → General Settings → Bridge Connection → Console Secret, and mirrored in AldhranConsole's own config.</dd>
    <dt>AldhranBridge.cs (TCP)</dt>
    <dd>Port 2000 by default. Secret set in ACP → General Settings → Bridge Connection → Bridge Secret, and mirrored as <code class="inline-code">BRIDGE_SECRET</code> in the .cs file below.</dd>
    <dt>Discord bot socket</dt>
    <dd>Port 15000 by default. Unrelated to the two above — configured separately under ACP → Bot Settings. Covered when you set up the bot, not here.</dd>
</dl>

<p class="act-slug" style="margin: 34px 0 14px;">Downloads</p>

<p class="probe-note" style="margin-bottom: 16px;">
    Every secret and URL below is a placeholder — <code class="inline-code">CHANGE_ME_BRIDGE_SECRET</code>
    and <code class="inline-code">YOUR-SITE.example</code>. Edit them before you compile, and make sure the
    bridge secret matches what you save in ACP → General Settings once the CMS is live.
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
