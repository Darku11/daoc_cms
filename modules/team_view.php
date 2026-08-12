<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) { exit; }
// ── Module guard ──────────────────────────────────────────────
if (($GLOBALS['cms_settings']['mod_team'] ?? '1') === '0' && ($GLOBALS['userPriv'] ?? 0) < 4) {
    echo '<div class="info-msg">' . t('general.module_disabled', [], 'This section is currently not available.') . '</div>';
    return;
}
global $db;
cms_load_language_context('core');
?>
<div class="team-grid">
    <?php
    $stmt = $db->query("SELECT * FROM users WHERE priv_level >= 3 ORDER BY priv_level DESC, username ASC");
    $team_members = $stmt->fetchAll();
    if ($team_members):
        foreach ($team_members as $m):
            $role_name = !empty($m['user_title'])
                ? $m['user_title']
                : (function_exists('getRoleName') ? getRoleName($m['priv_level']) : 'Staff');

            // Rank tier -> visual accent class (owner / admin / staff)
            $priv = (int)($m['priv_level'] ?? 0);
            $tier_class = $priv >= 5 ? 'team-card--owner'
                        : ($priv >= 4 ? 'team-card--admin' : 'team-card--staff');

            // Avatar: check relative path (prevents bugs on subfolder installs)
            $has_avatar = false;
            $safe_avatar_url = '';
            if (!empty($m['avatar_url'])) {
                $safe_avatar_url = ltrim($m['avatar_url'], '/');
                $has_avatar = file_exists($safe_avatar_url);
            }
    ?>
    <div class="team-card <?php echo $tier_class; ?>">
        <div class="team-avatar-wrap">
            <?php if ($has_avatar): ?>
                <img src="<?php echo h($safe_avatar_url); ?>" class="team-avatar" alt="<?php echo h($m['username']); ?>">
            <?php else: ?>
                <div class="team-avatar-placeholder">
                    <i class="fas fa-user-shield"></i>
                </div>
            <?php endif; ?>
        </div>
        <h2 class="team-name"><?php echo h($m['username'] ?? 'Unknown'); ?></h2>
        <div class="team-role"><?php echo $role_name; ?></div>
        <?php if (!empty($m['languages'])): ?>
            <div class="team-lang-row">
                <?php foreach (explode(',', $m['languages']) as $l):
                    $l = trim($l);
                    if (!$l) continue; ?>
                    <span class="team-lang-tag"><?php echo h($l); ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
        endforeach;
    endif;
    ?>
</div>