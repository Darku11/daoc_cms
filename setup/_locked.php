<?php
// SPDX-License-Identifier: GPL-3.0-only
declare(strict_types=1);

if (!isset($lockedTitle)) {
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>DAoC CMS — Setup Closed</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=EB+Garamond:wght@400&display=swap" rel="stylesheet">
<link href="assets/cinematic.css?v=1" rel="stylesheet">
</head>
<body>

<div class="atmos" aria-hidden="true"></div>
<div class="grain" aria-hidden="true"></div>
<div class="letterbox letterbox--top" aria-hidden="true"></div>
<div class="letterbox letterbox--bottom" aria-hidden="true"></div>

<main class="stage is-in">
    <header class="reel-head">
        <p class="wordmark">DAoC <span>CMS</span></p>
        <p class="reel-eyebrow">Installation</p>
    </header>

    <div class="reel-card text-center">
        <span class="corner corner--tl" aria-hidden="true"></span>
        <span class="corner corner--tr" aria-hidden="true"></span>
        <span class="corner corner--bl" aria-hidden="true"></span>
        <span class="corner corner--br" aria-hidden="true"></span>

        <i class="fas fa-lock" style="font-size:2.6rem;color:var(--gold);opacity:.7;margin:14px 0 22px;"></i>

        <h1 class="step-title" style="justify-content:center;border:none;padding:0;margin-bottom:18px;">
            <?= htmlspecialchars($lockedTitle) ?>
        </h1>

        <p class="lead-text" style="max-width:44ch;margin:0 auto 30px;"><?= $lockedBody ?></p>

        <a href="../index.php" class="btn btn-gold px-4 py-2">Go to the site</a>
    </div>

    <p class="reel-foot">Setup sealed</p>
</main>

</body>
</html>
