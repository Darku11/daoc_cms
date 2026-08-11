<?php
declare(strict_types=1);

if (!isset($installer)) {
    exit;
}

use DAoCCMS\Setup\Britty;
?>

<h3 class="step-title"><i class="fas fa-door-open"></i>Welcome</h3>

<?php Britty::say([
    'Well met. I\'m Britty — I\'ll be with you for all ten chapters, explaining each as we reach it.',
    'Along the way we\'ll check your server, connect your CMS and game server databases, write the ' .
    'configuration, and create your administrator account. About three minutes, start to finish.',
]); ?>

<div class="realms" aria-hidden="true">
    <div class="realm realm--albion">
        <svg viewBox="0 0 40 46" class="realm-shield"><path d="M20 1 L38 7 V24 C38 34 30 41 20 45 C10 41 2 34 2 24 V7 Z"/></svg>
        <span class="realm-name">Albion</span>
    </div>
    <div class="realm realm--midgard">
        <svg viewBox="0 0 40 46" class="realm-shield"><path d="M20 1 L38 7 V24 C38 34 30 41 20 45 C10 41 2 34 2 24 V7 Z"/></svg>
        <span class="realm-name">Midgard</span>
    </div>
    <div class="realm realm--hibernia">
        <svg viewBox="0 0 40 46" class="realm-shield"><path d="M20 1 L38 7 V24 C38 34 30 41 20 45 C10 41 2 34 2 24 V7 Z"/></svg>
        <span class="realm-name">Hibernia</span>
    </div>
</div>

<p class="act-slug" style="margin-bottom: 18px;">Before you begin</p>

<ul class="manifest">
    <li class="manifest-item">
        <span class="m-num">01</span>
        <span class="m-body">
            <b>An empty MySQL database for the CMS.</b>
            Users, forum, and settings live here. Create it first — the wizard connects, it does not create.
        </span>
    </li>
    <li class="manifest-item">
        <span class="m-num">02</span>
        <span class="m-body">
            <b>Access to your OpenDAoC or Dawn of Light database.</b>
            Use your live shard, import the bundled legacy public database, or upload your own <code>.sql</code> backup.
        </span>
    </li>
    <li class="manifest-item">
        <span class="m-num">03</span>
        <span class="m-body">
            <b>Write permission on <code>includes/</code>.</b>
            The wizard writes <code>config.php</code> there and checks this in step three.
        </span>
    </li>
    <li class="manifest-item">
        <span class="m-num">04</span>
        <span class="m-body">
            <b>Keep this tab open.</b>
            Your answers are held in the session until the final step runs the installation.
        </span>
    </li>
</ul>

<div class="mt-5 d-flex justify-content-between align-items-center border-top pt-4">
    <span class="reel-foot" style="margin: 0; text-align: left;">Est. 3 min</span>
    <a href="index.php?step=requirements" class="btn btn-gold px-4 py-2">
        Start the system check <i class="fas fa-arrow-right ms-2"></i>
    </a>
</div>
