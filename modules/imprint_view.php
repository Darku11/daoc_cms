<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) exit;

if (($GLOBALS['cms_settings']['mod_imprint'] ?? '1') === '0') {
    echo '<div class="info-msg">' . t('general.module_disabled', [], 'This section is currently disabled by the administrator.') . '</div>';
    return;
}
?>
<div class="um-nexus-wrapper">
    <div class="admin-box" style="padding:30px;">
        <h2 style="color:var(--glow-gold); font-family:'Cinzel',serif; margin-top:0;">Legal Notice</h2>

        <p style="color:#bbb; line-height:1.6;">
            <strong>Website Owner:</strong><br>
            Your Name or Company<br>
            Street Address<br>
            ZIP Code, City<br>
            Country
        </p>

        <p style="color:#bbb; line-height:1.6;">
            <strong>Contact:</strong><br>
            Email: admin@example.com
        </p>

        <p style="color:#888; line-height:1.6; margin-top:30px;">
            <strong>Disclaimer:</strong><br>
            The content of this website has been created with the greatest possible care. However, no guarantee is given for the accuracy, completeness, or timeliness of the information provided. The website owner reserves the right to modify, update, or remove content at any time without prior notice.
        </p>
    </div>
</div>