<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) { exit; }
if (($GLOBALS['cms_settings']['mod_forum']??'1')==='0' && ($GLOBALS['userPriv']??0)<4) {
    echo '<div class="info-msg">'.t('general.module_disabled',[],'This section is currently not available.').'</div>';
    return;
}

$threads_per_page = 20;
$current_page     = max(1, (int)($_GET['page'] ?? 1));
$total_threads    = count($threads);
$total_pages      = max(1, (int)ceil($total_threads / $threads_per_page));
$current_page     = min($current_page, $total_pages);
$paged_threads    = array_slice($threads, ($current_page-1)*$threads_per_page, $threads_per_page);
$board_page_url   = "index.php?p=viewboard&id=" . (int)$board_id;
$has_mod          = $userPriv >= 2;

function vb_thread_url(array $row): string {
    if ((int)$row['is_approved'] === 0 && $GLOBALS['userPriv'] < 2 && $GLOBALS['myId'] !== (int)$row['author_id']) return '#';
    if (!empty($row['op_deleted']) && $GLOBALS['userPriv'] < 5) return '#';
    return !empty($row['slug'])
        ? 'index.php?p=viewthread&slug=' . urlencode($row['slug'])
        : 'index.php?p=viewthread&id='   . (int)$row['id'];
}
?>
<div class="um-nexus-wrapper">

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'pending_approval'): ?>
    <div class="vb-pending-msg">
        <i class="fas fa-clock"></i> <?= t('viewboard.pending_approval_msg', [], 'Your thread has been submitted and is currently pending approval by a moderator.') ?>
    </div>
    <?php endif; ?>

    <nav class="spk-breadcrumb">
        <a href="index.php?p=spike"><?= t('viewboard.breadcrumb_forum',[],'Forum') ?></a>
        <span class="spk-breadcrumb-sep"><i class="fas fa-chevron-right"></i></span>
        <span class="spk-breadcrumb-current"><?= h($board_info['title']??'Board') ?></span>
    </nav>

    <div class="vb-board-header">
        <div class="vb-board-header-info">
            <h2 class="um-internal-title"><?= h($board_info['title']??'Unknown Board') ?></h2>
            <?php if (!empty($board_info['description'])): ?>
            <div class="vb-board-desc"><?= h($board_info['description']) ?></div>
            <?php endif; ?>
            <?php if (!empty($subboards)): ?>
            <div class="spk-subboard-links">
                <span class="spk-subboard-label"><?= t('spike.subforums', [], 'Subforums') ?>:</span>
                <?php foreach ($subboards as $subboard): ?>
                <a class="spk-subboard-link" href="index.php?p=viewboard&id=<?= (int)$subboard['id'] ?>">
                    <i class="fas fa-level-down-alt"></i><?= h($subboard['title'] ?? 'Untitled') ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <small class="vb-board-meta">
                <?= $total_threads ?> <?= t('viewboard.col_topic',[],'Threads') ?>
                <?php if ($total_pages>1): ?> · <?= t('viewthread.page',[],'Page') ?> <?= $current_page ?>/<?= $total_pages ?><?php endif; ?>
            </small>
        </div>
        <?php
        $req = (isset($board_info['min_priv_post'])&&(int)$board_info['min_priv_post']>0)
            ? (int)$board_info['min_priv_post'] : (int)($board_info['cat_min_post']??1);
        if ($myId>0 && $myStanding<3 && $userPriv>=$req): ?>
        <a href="index.php?p=newthread&bid=<?= (int)$board_id ?>" class="vb-new-thread-btn">
            <i class="fas fa-plus"></i> <?= t('viewboard.btn_new_thread',[],'New Thread') ?>
        </a>
        <?php endif; ?>
    </div>

    <?php if ($total_pages>1): ?>
    <div class="spk-pagination vb-pagination-top">
        <?php if ($current_page>1): ?>
            <a href="<?= $board_page_url ?>&page=1" class="spk-page-btn">«</a>
            <a href="<?= $board_page_url ?>&page=<?= $current_page-1 ?>" class="spk-page-btn">‹</a>
        <?php endif; ?>
        <?php for ($i=max(1,$current_page-2);$i<=min($total_pages,$current_page+2);$i++): ?>
            <a href="<?= $board_page_url ?>&page=<?= $i ?>" class="spk-page-btn <?= $i===$current_page?'active':'' ?>"><?= $i ?></a>
        <?php endfor; ?>
        <?php if ($current_page<$total_pages): ?>
            <a href="<?= $board_page_url ?>&page=<?= $current_page+1 ?>" class="spk-page-btn">›</a>
            <a href="<?= $board_page_url ?>&page=<?= $total_pages ?>" class="spk-page-btn">»</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="vb-form">
        <input type="hidden" name="csrf_token" value="<?= generateToken() ?>">

        <div class="vb-list <?= $has_mod ? 'has-mod' : '' ?>">

            <div class="vb-list-header">
                <?php if ($has_mod): ?><div></div><?php endif; ?>
                <div><?= t('viewboard.col_topic',[],'Topic') ?></div>
                <div class="vb-col-center"><?= t('viewboard.col_author',[],'Author') ?></div>
                <div class="vb-col-right"><?= t('viewboard.col_created',[],'Created') ?></div>
            </div>

            <?php if (empty($paged_threads)): ?>
            <div class="vb-empty"><?= t('viewboard.no_posts',[],'No threads yet.') ?></div>
            <?php else: ?>
            <?php foreach ($paged_threads as $row):
                $row_classes = 'vb-row';
                if ($row['is_sticky']) $row_classes .= ' is-sticky';
                if ($row['is_locked']) $row_classes .= ' is-locked';
                $row_blocked = !empty($row['op_deleted']) && $userPriv < 5;
            ?>
            <div class="<?= $row_classes ?>" <?= $row_blocked ? '' : "onclick=\"window.location.href='".vb_thread_url($row)."'\"" ?> <?= (!(int)$row['is_approved']||$row_blocked) ? 'data-unapproved="1"' : '' ?> <?= $row_blocked ? 'data-blocked="1"' : '' ?>>

                <?php if ($has_mod): ?>
                <div class="vb-cell-check" onclick="event.stopPropagation()">
                    <input type="checkbox" name="selected_threads[]" value="<?= (int)$row['id'] ?>"
                           class="thread-checkbox">
                </div>
                <?php endif; ?>

                <div class="vb-cell-title">
                    <div class="vb-title-line">
                        <span class="vb-title-icons">
                            <?php if ($row['is_sticky']): ?><i class="fas fa-thumbtack vb-title-sticky"></i><?php endif; ?>
                            <?php if ($row['is_locked']): ?><i class="fas fa-lock vb-title-locked"></i><?php endif; ?>
                        </span>
                        <?php if (!(int)$row['is_approved']): ?>
                            <span class="spk-prefix spk-prefix--pending"><i class="fas fa-clock"></i> PENDING</span>
                        <?php endif; ?>
                        <?php if (!empty($row['prefix_label'])): ?>
                            <span class="spk-prefix" style="color:<?= h($row['prefix_color']) ?>;background:<?= h($row['prefix_bg']) ?>;"><?= h($row['prefix_label']) ?></span>
                        <?php endif; ?>
                        <span class="vb-title-text"><?= h($row['title']) ?></span>
                        <?php if ((int)($row['reply_count']??0)>0): ?>
                            <span class="vb-reply-count"><?= (int)$row['reply_count'] ?> <?= ((int)$row['reply_count'] === 1) ? t('viewboard.post',[],'post') : t('viewboard.posts',[],'posts') ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($row['last_post_date'])): ?>
                    <div class="vb-last-post">
                        <?= t('viewboard.last_post',[],'Last post') ?>: <?= date("d.m.Y H:i",strtotime($row['last_post_date'])) ?>
                        <?php if (!empty($row['last_post_user'])): ?>
                            <?= t('spike.latest_by',[],'by') ?> <span><?= h($row['last_post_user'] ?? t('viewthread.deleted_user', [], 'Deleted User')) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="vb-cell-author">
                    <span class="vb-author-name"><?= h($row['username'] ?? t('viewthread.deleted_user', [], 'Deleted User')) ?></span>
                </div>

                <div class="vb-cell-date">
                    <span class="vb-date-day"><?= date("d.m.Y",strtotime($row['created_at'])) ?></span>
                    <span class="vb-date-time"><?= date("H:i",strtotime($row['created_at'])) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($has_mod && !empty($paged_threads)): ?>
        <div class="vb-mod-bar">
            <i class="fas fa-shield-alt vb-mod-icon"></i>
            <select name="mod_batch_action" class="vb-mod-bar select">
                <option value="approve"><?= t('viewboard.mod_approve',[],'Approve') ?></option>
                <option value="toggle_lock"><?= t('viewboard.mod_lock',[],'Toggle Lock') ?></option>
                <option value="toggle_sticky"><?= t('viewboard.mod_sticky',[],'Toggle Sticky') ?></option>
                <?php if ($userPriv >= 2): ?>
                <option value="move"><?= t('viewboard.mod_move',[],'Move') ?></option>
                <?php endif; ?>
                <?php if ($userPriv >= 4): ?>
                <option value="delete"><?= t('viewboard.mod_delete',[],'Delete') ?></option>
                <?php endif; ?>
            </select>
            <?php if ($userPriv >= 2): ?>
            <select name="target_board" class="vb-mod-bar select">
                <option value="0"><?= t('viewboard.mod_target_board',[],'Select board…') ?></option>
                <?php
                $ab_cur_cat = null;
                foreach ($all_boards as $ab):
                    if ($ab['cat_title'] !== $ab_cur_cat) {
                        if ($ab_cur_cat !== null) echo '</optgroup>';
                        echo '<optgroup label="' . h($ab['cat_title']) . '">';
                        $ab_cur_cat = $ab['cat_title'];
                    }
                ?>
                    <option value="<?= (int)$ab['id'] ?>"><?= h($ab['title']) ?></option>
                <?php endforeach; ?>
                <?php if ($ab_cur_cat !== null) echo '</optgroup>'; ?>
            </select>
            <?php endif; ?>
            <button type="submit" name="execute_mod_action" class="vb-mod-btn"
                    onclick="return confirm('<?= addslashes(t('viewboard.mod_confirm',[],'Apply to selected threads?')) ?>')">
                <?= t('viewboard.mod_btn_apply',[],'Apply') ?>
            </button>
            <label class="vb-select-all-label">
                <input type="checkbox" onclick="document.querySelectorAll('.thread-checkbox').forEach(c=>c.checked=this.checked)">
                <?= t('viewboard.select_all',[],'Select all') ?>
            </label>
        </div>
        <?php endif; ?>
    </form>

    <?php if ($total_pages>1): ?>
    <div class="spk-pagination vb-pagination-bottom">
        <?php if ($current_page>1): ?>
            <a href="<?= $board_page_url ?>&page=1" class="spk-page-btn">«</a>
            <a href="<?= $board_page_url ?>&page=<?= $current_page-1 ?>" class="spk-page-btn">‹</a>
        <?php endif; ?>
        <?php for ($i=max(1,$current_page-2);$i<=min($total_pages,$current_page+2);$i++): ?>
            <a href="<?= $board_page_url ?>&page=<?= $i ?>" class="spk-page-btn <?= $i===$current_page?'active':'' ?>"><?= $i ?></a>
        <?php endfor; ?>
        <?php if ($current_page<$total_pages): ?>
            <a href="<?= $board_page_url ?>&page=<?= $current_page+1 ?>" class="spk-page-btn">›</a>
            <a href="<?= $board_page_url ?>&page=<?= $total_pages ?>" class="spk-page-btn">»</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="vb-online-users">
        <i class="fas fa-eye vb-online-eye"></i><?= t('viewboard.users_viewing', [], 'Viewing this board:') ?>
        <?php if (empty($board_online_users)): ?>
            <span class="vb-online-none-text"><?= t('spike.online_none', [], 'No one') ?></span>
        <?php else: ?>
            <?php
            $ol_list = [];
            foreach ($board_online_users as $ou) {
                $priv_class = 'vb-online-normal';
                if ((int)$ou['priv_level'] >= 5)     $priv_class = 'vb-online-owner';
                elseif ((int)$ou['priv_level'] >= 4) $priv_class = 'vb-online-admin';
                elseif ((int)$ou['priv_level'] >= 3) $priv_class = 'vb-online-staff';
                $ol_list[] = '<span class="'.$priv_class.'">'.h($ou['username']).'</span>';
            }
            echo implode(', ', $ol_list);
            ?>
        <?php endif; ?>
    </div>

    <div class="vb-back-wrap">
        <a href="index.php?p=spike" class="vb-back-link">
            <i class="fas fa-chevron-left vb-back-icon"></i>
            <?= t('viewboard.back_to_forums',[],'Back to Forums') ?>
        </a>
    </div>
</div>