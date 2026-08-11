<?php if (!defined('IN_CMS')) { exit; } ?>
<?php require_once __DIR__ . '/../header.php'; ?>

<?php if (file_exists(__DIR__ . '/default/partials/hero.php')) require_once __DIR__ . '/default/partials/hero.php'; ?>

<div class="main-container">
    <?php require_once __DIR__ . '/../sidebar.php'; ?>

    <main class="content-area<?= empty($hide_sidebar) ? '' : ' full-width' ?>">
        <div class="content-body">
            <?= $module_content ?? '' ?>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../footer.php'; ?>