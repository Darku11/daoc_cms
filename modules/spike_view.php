<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) { exit; }
if (($GLOBALS['cms_settings']['mod_forum'] ?? '1') === '0' && ($GLOBALS['userPriv'] ?? 0) < 4) {
    echo '<div class="info-msg">' . t('general.module_disabled', [], 'This section is currently not available.') . '</div>';
    return;
}
if (!isset($forum_structure)) {
    echo "<div class='info-msg'>Forum data not found.</div>";
    return;
}
$forum_stats  = $forum_stats  ?? ['total_threads'=>0,'total_posts'=>0,'total_members'=>0,'newest_member'=>''];
$latest_posts   = $latest_posts   ?? [];
$recent_members = $recent_members ?? [];
$online_users   = $online_users   ?? [];

$show_stats_strip  = ($spike_settings['stats_strip_enabled']  ?? '1') === '1';
$show_latest_posts = ($spike_settings['latest_posts_enabled'] ?? '1') === '1';
?>
<style>.spk-board-graphic{width:48px;height:48px;object-fit:cover;border-radius:5px;display:block;box-shadow:0 0 0 1px rgba(197,160,89,.22);}</style>

<div class="spk-wrap">

    <?php if ($show_stats_strip): ?>
    <div class="spk-stats-strip">
        <div class="spk-stat">
            <div class="spk-stat-num"><?= number_format($forum_stats['total_threads']) ?></div>
            <div class="spk-stat-lbl"><?= t('spike.label_threads', [], 'Threads') ?></div>
        </div>
        <div class="spk-stat">
            <div class="spk-stat-num"><?= number_format($forum_stats['total_posts']) ?></div>
            <div class="spk-stat-lbl"><?= t('spike.label_posts', [], 'Posts') ?></div>
        </div>
        <div class="spk-stat">
            <div class="spk-stat-num"><?= number_format($forum_stats['total_members']) ?></div>
            <div class="spk-stat-lbl"><?= t('spike.label_members', [], 'Members') ?></div>
        </div>
        <div class="spk-stat">
            <div class="spk-stat-num spk-stat-num--member"><?= h($forum_stats['newest_member']) ?: '—' ?></div>
            <div class="spk-stat-lbl"><?= t('spike.newest_member', [], 'Newest Member') ?></div>
        </div>
    </div>
    <?php endif; ?>

    <div class="spk-layout">

        <div>
        <?php if (empty($forum_structure)): ?>
            <div class="spk-forums-closed"><?= t('spike.forums_closed', [], 'The forums are currently closed.') ?></div>
        <?php else: ?>
        <?php foreach ($forum_structure as $cat):
            $cat_info     = $cat['info'];
            $boards       = $cat['boards'];
        ?>
        <div class="spk-cat">
            <div class="spk-cat-title">
                <i class="fas fa-dungeon"></i>
                <?= h($cat_info['title'] ?? 'Unknown') ?>
            </div>

            <?php if (empty($boards)): ?>
            <div class="vb-empty"><?= t('spike.no_boards', [], 'No boards in this category yet.') ?></div>
            <?php else: ?>
            <?php foreach ($boards as $board):
                $required_post_priv = (int)($board['min_priv_post'] ?? 0);
                $can_post     = ($userPriv >= $required_post_priv);
                $thread_count = (int)($board['thread_count'] ?? 0);
                $post_count   = (int)($board['post_count']   ?? 0);
                $subboards    = $board['subboards'] ?? [];
            ?>
            <div class="spk-board" onclick="window.location.href='?p=viewboard&id=<?= (int)$board['id'] ?>'">
                <div class="spk-board-icon">
                    <?php if (!empty($board['graphic_url'])): ?>
                        <img src="<?= h($board['graphic_url']) ?>" alt="" loading="lazy" style="width:46px;height:46px;object-fit:cover;border-radius:4px;display:block;">
                    <?php else: ?>
                        <?php if (!empty($board['graphic_url'])): ?>
                    <img src="<?= h($board['graphic_url']) ?>" class="spk-board-graphic" alt="" loading="lazy">
                    <?php else: ?>
                    <i class="fas <?= $can_post ? 'fa-scroll' : 'fa-book-reader' ?>"></i>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="spk-board-info">
                    <div class="spk-board-name">
                        <?= h($board['title'] ?? 'Untitled') ?>
                        <?php if (!empty($board['last_post_date']) && strtotime($board['last_post_date']) > time() - 86400): ?>
                        <span class="spk-new-dot" title="New activity in last 24h"></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($board['description'])): ?>
                    <div class="spk-board-desc"><?= h($board['description']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($subboards)): ?>
                    <div class="spk-subboard-links" onclick="event.stopPropagation()">
                        <span class="spk-subboard-label"><?= t('spike.subforums', [], 'Subforums') ?>:</span>
                        <?php foreach ($subboards as $subboard): ?>
                        <a class="spk-subboard-link" href="?p=viewboard&id=<?= (int)$subboard['id'] ?>">
                            <i class="fas fa-level-down-alt"></i><?= h($subboard['title'] ?? 'Untitled') ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="spk-board-stat">
                    <strong><?= $thread_count ?></strong>
                    <?= t('spike.label_threads', [], 'Threads') ?>
                </div>
                <div class="spk-board-stat">
                    <strong><?= $post_count ?></strong>
                    <?= t('spike.label_posts', [], 'Posts') ?>
                </div>
                <div class="spk-board-last">
                    <?php if (!empty($board['last_post_date'])): ?>
                    <div class="spk-board-last-date"><?= date("d.m.Y H:i", strtotime($board['last_post_date'])) ?></div>
                    <span class="spk-board-last-user"><?= t('spike.latest_by', [], 'by') ?> <?= h($board['last_post_user'] ?? '?') ?></span>
                    <?php else: ?>
                    <span class="spk-board-empty"><?= t('spike.board_empty', [], 'No posts yet') ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        </div>

        <div class="spk-sidebar">

            <div class="spk-sidebar-block">
                <div class="spk-sidebar-head"><i class="fas fa-info-circle"></i><?= t('spike_legend', [], 'Legend'); ?></div>
                <div class="spk-legend-body">
                    <div class="spk-legend-row">
                        <span class="spk-new-dot"></span>
                        <span class="spk-legend-text"><?= t('new_activity_24h', [], 'New activity in last 24h') ?></span>
                    </div>
                    <div class="spk-legend-row">
                        <i class="fas fa-scroll spk-legend-icon spk-legend-icon--open"></i>
                        <span class="spk-legend-text"><?= t('spike_open_for_posting', [], 'Open for posting'); ?></span>
                    </div>
                    <div class="spk-legend-row">
                        <i class="fas fa-book-reader spk-legend-icon spk-legend-icon--read"></i>
                        <span class="spk-legend-text"><?= t('spike_read_only', [], 'Read only'); ?></span>
                    </div>
                </div>
            </div>

            <?php if ($show_latest_posts && !empty($latest_posts)): ?>
            <div class="spk-sidebar-block">
                <div class="spk-sidebar-head"><i class="fas fa-bolt"></i><?= t('spike.latest_posts', [], 'Latest Posts') ?></div>
                <?php foreach ($latest_posts as $lp): ?>
                <div class="spk-latest-item" onclick="window.location.href='?p=viewthread&id=<?= (int)$lp['thread_id'] ?>'">
                    <?php if (!empty($lp['avatar_url'])): ?>
                        <img src="<?= h($lp['avatar_url']) ?>" class="spk-latest-avatar" alt="" onclick="event.stopPropagation(); window.location.href='?p=user&id=<?= (int)$lp['author_id'] ?>'">
                    <?php else: ?>
                        <div class="spk-latest-avatar-placeholder" onclick="event.stopPropagation(); window.location.href='?p=user&id=<?= (int)$lp['author_id'] ?>'"><i class="fas fa-user"></i></div>
                    <?php endif; ?>
                    <div class="spk-latest-info">
                        <div class="spk-latest-thread"><?= h(mb_substr($lp['thread_title'], 0, 35)) ?><?= strlen($lp['thread_title']) > 35 ? '…' : '' ?></div>
                        <div class="spk-latest-by">
                            <span class="spk-latest-by-user" onclick="event.stopPropagation(); window.location.href='?p=user&id=<?= (int)$lp['author_id'] ?>'"><?= h($lp['username']) ?></span> · <?= date("d.m H:i", strtotime($lp['created_at'])) ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($recent_members)): ?>
            <div class="spk-sidebar-block">
                <div class="spk-sidebar-head"><i class="fas fa-user-plus"></i><?= t('spike.new_members', [], 'New Members') ?></div>
                <?php foreach ($recent_members as $member): ?>
                <div class="spk-latest-item" onclick="window.location.href='?p=user&id=<?= (int)$member['id'] ?>'">
                    <?php if (!empty($member['avatar_url'])): ?>
                        <img src="<?= h($member['avatar_url']) ?>" class="spk-latest-avatar" alt="" loading="lazy">
                    <?php else: ?>
                        <div class="spk-latest-avatar-placeholder"><i class="fas fa-user"></i></div>
                    <?php endif; ?>
                    <div class="spk-latest-info">
                        <div class="spk-latest-thread"><?= h($member['username']) ?></div>
                        <div class="spk-latest-by"><?= t('spike.registered', [], 'Registered') ?> · <?= date('d.m.Y', strtotime($member['created_at'])) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="spk-sidebar-block">
                <div class="spk-sidebar-head spk-sidebar-head--online">
                    <div>
                        <i class="fas fa-circle spk-online-dot-icon"></i>
                        <?= t('spike.online_label', [], 'Online') ?> (<?= count($online_users) ?>)
                    </div>
                    <?php if (($_SESSION['user_id'] ?? 0) > 0): ?>
                    <form method="POST" class="spk-anon-form">
                        <input type="hidden" name="csrf_token" value="<?= function_exists('generateToken') ? generateToken() : ($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="toggle_anon" value="1">
                        <button type="submit" title="<?= t('spike.toggle_anon', [], 'Toggle Visibility') ?>" class="spk-anon-btn <?= !empty($is_my_anon_status) ? 'spk-anon-btn--active' : '' ?>">
                            <i class="fas <?= !empty($is_my_anon_status) ? 'fa-ghost' : 'fa-eye' ?>"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="spk-online-wrap">
                    <?php if (empty($online_users)): ?>
                        <span class="spk-online-none"><?= t('spike.online_none', [], 'No one online') ?></span>
                    <?php else: ?>
                    <div class="spk-online-list">
                        <?php foreach ($online_users as $ou):
                            $priv_class = 'spk-online-user--normal';
                            if ((int)$ou['priv_level'] >= 5)     $priv_class = 'spk-online-user--owner';
                            elseif ((int)$ou['priv_level'] >= 4) $priv_class = 'spk-online-user--admin';
                            elseif ((int)$ou['priv_level'] >= 3) $priv_class = 'spk-online-user--staff';
                            elseif ((int)$ou['priv_level'] >= 2) $priv_class = 'spk-online-user--member';
                            $anon_icon = !empty($ou['is_anonymous']) ? ' <i class="fas fa-ghost spk-anon-ghost" title="Anonymous"></i>' : '';
                        ?>
                        <span class="spk-online-user <?= $priv_class ?>" onclick="window.location.href='?p=user&id=<?= (int)$ou['id'] ?>'">
                            <?= h($ou['username']) ?><?= $anon_icon ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

</div>

<button id="spk-back-top" onclick="window.scrollTo({top:0,behavior:'smooth'})" title="Back to top">
    <i class="fas fa-chevron-up"></i>
</button>

<script>
(function(){
    var btn = document.getElementById('spk-back-top');
    if (!btn) return;
    window.addEventListener('scroll', function(){
        btn.classList.toggle('visible', window.scrollY > 300);
    }, {passive:true});
})();
</script>