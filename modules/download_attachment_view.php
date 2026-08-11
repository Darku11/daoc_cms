<?php
if (!defined('IN_CMS')) { exit; }
?>
<div class="um-nexus-wrapper">
    <div class="dav-not-found">
        <i class="fas fa-exclamation-triangle dav-icon"></i>
        <p class="dav-text"><?= t('download_attachment.not_found', [], 'Attachment not found or no longer available.') ?></p>
        <a href="javascript:history.back()" class="dav-back">
            <i class="fas fa-chevron-left"></i> <?= t('download_attachment.go_back', [], 'Go back') ?>
        </a>
    </div>
</div>