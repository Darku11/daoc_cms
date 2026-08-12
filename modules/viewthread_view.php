<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * SPIKE VIEWTHREAD VIEW - DAoC CMS
 */
if (!defined('IN_CMS')) { exit; }
if (($GLOBALS['cms_settings']['mod_forum']??'1')==='0' && ($GLOBALS['userPriv']??0)<4) {
    echo '<div class="info-msg">'.t('general.module_disabled',[],'This section is currently not available.').'</div>';
    return;
}

function renderRankStars($privLevel) {
    $privLevel=(int)$privLevel; $count=0;
    if ($privLevel===1) $count=1; elseif ($privLevel===2) $count=2;
    elseif ($privLevel===3) $count=5; elseif ($privLevel===4) $count=6; elseif ($privLevel>=5) $count=7;
    if ($count===0) return '';
    $o='<div class="vt-rank-stars"><div style="color:var(--glow-gold);font-size:0.5em;letter-spacing:1px;opacity:0.6;">';
    for ($i=0;$i<$count;$i++) $o.='<i class="fas fa-star"></i>';
    return $o.'</div></div>';
}

function spk_pagination_url(array $thread, int $page): string {
    $url = "index.php?p=viewthread&slug=" . urlencode($thread['slug']);
    if ($page > 1) $url .= "&page=$page";
    return $url;
}

$effective_min_post = ($thread['board_min_post']>0)?(int)$thread['board_min_post']:(int)$thread['cat_min_post'];
$can_actually_post  = ($myId>0 && $myStanding<3 && $userPriv>=$effective_min_post && $is_verified === 1);

$available_reactions = [
    'thanks'=>['emoji'=>'👍','label'=>'Thanks'],
    'haha'  =>['emoji'=>'😄','label'=>'Haha'],
    'love'  =>['emoji'=>'❤️', 'label'=>'Love'],
    'wow'   =>['emoji'=>'😮','label'=>'Wow'],
    'sad'   =>['emoji'=>'😢','label'=>'Sad'],
    'angry' =>['emoji'=>'😡','label'=>'Angry'],
];

$csrf_token = generateToken();
$thread_url = "index.php?p=viewthread&slug=" . urlencode($thread['slug'] ?? '');
$single_post_id = (int)($_GET['pid'] ?? 0);
?>

<div class="um-nexus-wrapper">

    <?php $can_edit_title = ($myId > 0 && ((int)$thread['author_id'] === $myId || $userPriv >= 2)); ?>
    <nav class="spk-breadcrumb" aria-label="Breadcrumb">
        <a href="index.php?p=spike"><i class="fas fa-comments" style="font-size:0.9em;"></i> <?= t('viewthread.breadcrumb_forum', [], 'Forum') ?></a>
        <span class="spk-breadcrumb-sep"><i class="fas fa-chevron-right"></i></span>
        <a href="index.php?p=viewboard&id=<?= (int)$thread['board_id'] ?>">
            <?= h($thread['board_title']) ?>
        </a>
        <span class="spk-breadcrumb-sep"><i class="fas fa-chevron-right"></i></span>
        <span class="spk-breadcrumb-current">
            <?php if (!empty($thread['prefix_label'])): ?>
                <span class="spk-prefix" id="bc-thread-prefix" style="color:<?= h($thread['prefix_color']) ?>;background:<?= h($thread['prefix_bg']) ?>;">
                    <?= h($thread['prefix_label']) ?>
                </span>
            <?php endif; ?>
            <span id="bc-thread-title" <?= $can_edit_title ? 'ondblclick="editThreadTitle(this)" data-fulltitle="'.h($thread['title']).'" data-prefixid="'.(int)$thread['prefix_id'].'" title="'.t('viewthread.edit_title_hint',[],'Double-click to edit').'" style="cursor:pointer; border-bottom:1px dashed rgba(197,160,89,0.4); padding-bottom:1px;"' : '' ?>><?= h(mb_substr($thread['title'], 0, 60)) ?><?= mb_strlen($thread['title'])>60 ? '…' : '' ?></span>
        </span>
    </nav>

    <?php if (isset($_GET['err'])): ?>
    <div style="margin-bottom:12px;padding:9px 13px;background:rgba(200,0,0,0.06);border:1px solid var(--error-red);color:#bbb;font-size:0.78em;">
        <?php
        if ($_GET['err']==='spam_cooldown'):
            echo '<i class="fas fa-clock"></i> '.t('viewthread.err_spam_cooldown',[],'Please wait').' <strong>'.(int)($_GET['wait']??0).'s</strong>.';
        elseif ($_GET['err']==='unauthorized_post'):
            echo '<i class="fas fa-exclamation-triangle"></i> '.t('viewthread.err_unauthorized',[],'You are not authorized to post here.');
        elseif ($_GET['err']==='forbidden_word'):
            echo '<i class="fas fa-ban"></i> '.t('viewthread.err_forbidden_word',[],'Your post contained a forbidden word and was not submitted.');
        elseif ($_GET['err']==='empty_post'):
            echo '<i class="fas fa-exclamation-circle"></i> '.t('viewthread.err_empty_reply',[],'Please write something before posting.');
        endif;
        ?>
    </div>
    <?php endif; ?>

    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:16px;gap:12px;flex-wrap:wrap;">
        <div style="flex:1;min-width:0;">
            <?php if ($thread['is_sticky'] || $thread['is_locked']): ?>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
                <?php if ($thread['is_sticky']): ?>
                    <span style="font-family:'Cinzel',serif;font-size:0.55em;color:var(--glow-gold);letter-spacing:1px;text-transform:uppercase;border:1px solid rgba(197,160,89,0.3);padding:2px 7px;background:rgba(197,160,89,0.06);"><i class="fas fa-thumbtack"></i> <?= t('viewthread.sticky', [], 'Pinned') ?></span>
                <?php endif; ?>
                <?php if ($thread['is_locked']): ?>
                    <span style="font-family:'Cinzel',serif;font-size:0.55em;color:#888;letter-spacing:1px;text-transform:uppercase;border:1px solid #333;padding:2px 7px;background:rgba(0,0,0,0.3);"><i class="fas fa-lock"></i> <?= t('viewthread.locked', [], 'Locked') ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <small style="color:#444;font-size:0.6em;letter-spacing:1px;text-transform:uppercase;">
                <?= t('viewthread.label_board',[],'Board') ?>:
                <a href="index.php?p=viewboard&id=<?= (int)$thread['board_id'] ?>"
                   style="color:#555;text-decoration:none;transition:color 0.2s;"
                   onmouseover="this.style.color='var(--glow-gold)'" onmouseout="this.style.color='#555'">
                    <?= h($thread['board_title']) ?>
                </a>
                · <?= $total_posts ?> <?= t('viewthread.label_posts',[],'posts') ?>
                <?php if ($total_pages>1): ?>
                    · <?= t('viewthread.page',[],'Page') ?> <?= $current_page ?>/<?= $total_pages ?>
                <?php endif; ?>
            </small>
        </div>

        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;flex-shrink:0;">
            <?php if ($myId>0): ?>
            <button class="vt-sub-btn <?= $is_subscribed?'subscribed':'' ?>" id="sub-btn" onclick="toggleSubscription()">
                <i class="fas <?= $is_subscribed?'fa-bell':'fa-bell-slash' ?>"></i>
                <span id="sub-label"><?= $is_subscribed
                    ? t('viewthread.subscribed',[],'Subscribed')
                    : t('viewthread.subscribe',[],'Subscribe') ?></span>
            </button>
            <?php endif; ?>

            <?php if ($userPriv>=2): ?>
            <form method="POST" action="<?= $thread_url ?>" style="margin:0;display:flex;gap:4px;">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="thread_id"  value="<?= (int)$thread['id'] ?>">
                <button type="submit" name="mod_action" value="toggle_lock"
                        class="btn-nexus-edit"
                        title="<?= t('viewthread.mod_lock',[],'Lock/Unlock') ?>">
                    <i class="fas <?= $thread['is_locked']?'fa-unlock':'fa-lock' ?>"></i>
                </button>
                <button type="submit" name="mod_action" value="toggle_sticky"
                        class="btn-nexus-edit"
                        title="<?= t('viewthread.mod_sticky',[],'Pin/Unpin') ?>">
                    <i class="fas fa-thumbtack"></i>
                </button>
            </form>
            <?php endif; ?>

            <?php if ($userPriv>=4): ?>
            <form method="POST" action="<?= $thread_url ?>" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="thread_id"  value="<?= (int)$thread['id'] ?>">
                <button type="submit" name="mod_action" value="delete_thread"
                        class="btn-nexus-edit"
                        style="color:var(--error-red);border-color:rgba(255,68,68,0.3);"
                        onclick="return confirm('<?= addslashes(t('viewthread.confirm_delete_thread',[],'Delete entire thread?')) ?>')">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </form>
            <?php endif; ?>

            <a href="index.php?p=viewboard&id=<?= (int)$thread['board_id'] ?>"
               class="btn-nexus-edit"
               style="text-decoration:none;">
                <i class="fas fa-chevron-left" style="font-size:0.75em;"></i>
                <span class="hide-mobile"><?= t('viewthread.btn_back',[],'Back') ?></span>
            </a>
        </div>
    </div>

    <?php if ($poll && $polls_enabled && $current_page===1 && $single_post_id === 0): ?>
    <?php
        $poll_total   = array_sum($poll_votes_by_option);
        $poll_ended   = $poll['ends_at'] && strtotime($poll['ends_at'])<time();
        $poll_voted   = !empty($my_poll_votes);
        $show_results = $poll_voted||$poll_ended;
    ?>
    <div class="vt-poll">
        <div class="vt-poll-question"><i class="fas fa-poll" style="margin-right:6px;opacity:0.35;"></i><?= h($poll['question']) ?></div>
        <?php if (!$show_results && $myId>0): ?>
        <form id="poll-form">
            <?php foreach ($poll_options as $opt): ?>
            <div class="vt-poll-option" style="display:flex;align-items:center;gap:10px;padding:6px 0;">
                <input class="vt-poll-input"
                       type="<?= $poll['multi']?'checkbox':'radio' ?>"
                       name="poll_option"
                       value="<?= (int)$opt['id'] ?>"
                       id="po<?= $opt['id'] ?>"
                       style="flex-shrink:0;accent-color:#c5a059;width:15px;height:15px;margin:0;">
                <label for="po<?= $opt['id'] ?>" style="font-size:0.82em;color:#888;cursor:pointer;flex:1;"><?= h($opt['label']) ?></label>
            </div>
            <?php endforeach; ?>
            <button type="button" onclick="submitPollVote()"
                    style="background:rgba(197,160,89,0.08);border:1px solid rgba(197,160,89,0.3);color:#c5a059;padding:7px 18px;font-family:'Cinzel',serif;font-size:0.62em;letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;margin-top:10px;transition:background 0.2s;"
                    onmouseover="this.style.background='rgba(197,160,89,0.18)'"
                    onmouseout="this.style.background='rgba(197,160,89,0.08)'">
                <i class="fas fa-vote-yea"></i> <?= t('viewthread.poll_vote',[],'Vote') ?>
            </button>
        </form>
        <?php else: ?>
        <?php foreach ($poll_options as $opt):
            $votes=$poll_votes_by_option[$opt['id']]??0;
            $pct=$poll_total>0?round(($votes/$poll_total)*100):0;
            $iv=in_array($opt['id'],$my_poll_votes);
        ?>
        <div class="vt-poll-option">
            <?php if($iv): ?><i class="fas fa-check-circle" style="color:var(--glow-gold);font-size:0.72em;width:12px;"></i><?php else: ?><span style="width:12px;display:inline-block;"></span><?php endif; ?>
            <span class="vt-poll-label"><?= h($opt['label']) ?></span>
            <div class="vt-poll-bar-wrap"><div class="vt-poll-bar-fill" style="width:<?= $pct ?>%;"></div></div>
            <span class="vt-poll-pct"><?= $pct ?>%</span>
            <span class="vt-poll-cnt"><?= $votes ?> <?= $votes===1?t('viewthread.poll_vote_singular',[],'vote'):t('viewthread.poll_vote_plural',[],'votes') ?></span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <div class="vt-poll-footer">
            <?= $poll_total ?> <?= t('viewthread.poll_total_votes',[],'total votes') ?>
            <?php if($poll['ends_at']): ?> · <?= $poll_ended?t('viewthread.poll_ended',[],'Ended'):t('viewthread.poll_ends',[],'Ends') ?> <?= date('d.m.Y H:i',strtotime($poll['ends_at'])) ?><?php endif; ?>
            <?php if($poll['multi']): ?> · <?= t('viewthread.poll_multi',[],'Multiple choice') ?><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($single_post_id > 0): ?>
    <div style="margin-bottom:20px;padding:12px 16px;background:rgba(197,160,89,0.08);border:1px solid rgba(197,160,89,0.3);text-align:center;">
        <span style="color:#c5a059;font-family:'Cinzel',serif;font-size:0.8em;letter-spacing:1px;text-transform:uppercase;">
            <i class="fas fa-eye"></i> <?= t('viewthread.single_post_view', [], 'Single Post View') ?>
        </span>
        <br>
        <a href="<?= $thread_url ?>" style="display:inline-block;margin-top:8px;color:#888;font-size:0.8em;text-decoration:none;border-bottom:1px solid #444;transition:all 0.2s;" onmouseover="this.style.color='#c5a059';this.style.borderColor='#c5a059';" onmouseout="this.style.color='#888';this.style.borderColor='#444';">
            <?= t('viewthread.view_all_posts', [], 'View all posts in this thread') ?>
        </a>
    </div>
    <?php elseif ($total_pages>1): ?>
    <div class="spk-pagination">
        <?php if ($current_page>1): ?>
            <a href="<?= spk_pagination_url($thread,1) ?>" class="spk-page-btn" title="First">«</a>
            <a href="<?= spk_pagination_url($thread,$current_page-1) ?>" class="spk-page-btn">‹</a>
        <?php endif; ?>
        <?php
        $range=2; $start=max(1,$current_page-$range); $end=min($total_pages,$current_page+$range);
        if ($start>1) echo '<span class="spk-page-info">…</span>';
        for ($i=$start;$i<=$end;$i++): ?>
            <a href="<?= spk_pagination_url($thread,$i) ?>"
               class="spk-page-btn <?= $i===$current_page?'active':'' ?>"><?= $i ?></a>
        <?php endfor;
        if ($end<$total_pages) echo '<span class="spk-page-info">…</span>';
        ?>
        <?php if ($current_page<$total_pages): ?>
            <a href="<?= spk_pagination_url($thread,$current_page+1) ?>" class="spk-page-btn">›</a>
            <a href="<?= spk_pagination_url($thread,$total_pages) ?>" class="spk-page-btn" title="Last">»</a>
        <?php endif; ?>
        <span class="spk-page-info"><?= t('viewthread.page',[],'Page') ?> <?= $current_page ?>/<?= $total_pages ?></span>
    </div>
    <?php endif; ?>

    <div id="vt-posts-container"
         data-thread-id="<?= (int)$thread['id'] ?>"
         data-current-page="<?= (int)$current_page ?>"
         data-total-pages="<?= (int)$total_pages ?>"
         data-slug="<?= h($thread['slug'] ?? '') ?>"
         data-per-page="<?= (int)($per_page ?? 20) ?>"
         data-single-post="<?= $single_post_id ?>">
    <?php if (!empty($posts)): foreach ($posts as $p):
        $is_author=$myId>0&&$myId==$p['author_id'];
        $can_edit=($can_actually_post&&$is_author)||$userPriv>=2;
        $post_reactions=$reactions_by_post[$p['id']]??[];
        $post_attachments=$attachments_by_post[$p['id']]??[];
    ?>
    <div class="vt-post" id="post-<?= (int)$p['id'] ?>">

        <?php
            $p_priv    = (int)($p['priv_level'] ?? 0);
            $p_priv_class = 'vt-username--default';
            if ($p_priv >= 5) $p_priv_class = 'vt-username--owner';
            elseif ($p_priv >= 4) $p_priv_class = 'vt-username--admin';
            elseif ($p_priv >= 3) $p_priv_class = 'vt-username--staff';
            elseif ($p_priv >= 2) $p_priv_class = 'vt-username--member';
        ?>
        <div class="vt-user">
            <?php if (!empty($p['avatar_url'])): ?>
                <img src="<?= h(ltrim($p['avatar_url'], '/')) ?>" class="vt-avatar" alt="">
            <?php else: ?>
                <div class="vt-avatar" style="background:#070707;display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-user" style="color:#252525;font-size:1.4em;"></i>
                </div>
            <?php endif; ?>
            <div class="vt-username <?= $p_priv_class ?>">
                <?= h($p['username'] ?? t('viewthread.deleted_user', [], 'Deleted User')) ?>
            </div>
            <div class="vt-usertitle"><?= h($p['user_title']??'') ?></div>
            <?= renderRankStars($p['priv_level']??0) ?>
            <div class="vt-postcount"><?= t('viewthread.label_posts',[],'Posts') ?>: <span><?= (int)($p['forum_posts']??0) ?></span></div>
        </div>

        <div class="vt-body">

            <div class="vt-meta">
                <?php if (!empty($p['is_deleted'])): ?>
                <span class="spk-prefix" style="color:#fff;background:#b03030;">
                    <i class="fas fa-trash-alt"></i> <?= t('viewthread.pending_deletion',[],'PENDING DELETION') ?>
                    <?php if (!empty($p['deleted_at'])): ?> · <?= date("d.m.Y H:i",strtotime($p['deleted_at'])) ?><?php endif; ?>
                </span>
                <?php endif; ?>
                <span class="vt-meta-date"><?= date("d.m.Y – H:i",strtotime($p['created_at'])) ?></span>

                <?php if (!empty($p['edited_at'])): ?>
                <span class="vt-edited-tag" onclick="toggleEditHistory(<?= (int)$p['id'] ?>)" title="<?= t('viewthread.show_edit_history',[],'Show edit history') ?>">
                    <i class="fas fa-pencil-alt"></i>
                    <?= t('viewthread.edited_label',[],'Edited') ?>
                    <?php if ($userPriv>=5): ?><?= (int)$p['edit_count'] ?>× <?= t('viewthread.edited_by',[],'by') ?> <?= h($p['editor_username']??'Staff') ?> · <?= date("d.m.Y H:i",strtotime($p['edited_at'])) ?><?php endif; ?>
                </span>
                <?php endif; ?>

                <?php $sp_url = "index.php?p=viewthread&slug=" . urlencode($thread['slug'] ?? '') . "&pid=" . (int)$p['id'] . "#post-" . (int)$p['id']; ?>
                <a href="<?= $sp_url ?>" class="vt-meta-btn" title="<?= t('viewthread.copy_link',[],'Copy Link to this Post') ?>" style="opacity:0.4;" onclick="spkCopyLink(event, '<?= $sp_url ?>')">
                    <i class="fas fa-link"></i>
                </a>

                <?php if ($myId>0 && !$is_author): ?>
                <button class="vt-meta-btn vt-meta-report" onclick="openReport(<?= (int)$p['id'] ?>)">
                    <i class="fas fa-flag"></i>
                    <span class="hide-mobile"><?= t('viewthread.report_post',[],'Report') ?></span>
                </button>
                <?php endif; ?>

                <?php if ($userPriv>=2 && $userPriv<5 && empty($p['is_deleted'])): ?>
                <form method="POST" action="<?= $thread_url ?>" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="mod_action" value="delete_post">
                    <input type="hidden" name="post_id"    value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="vt-meta-btn vt-meta-del"
                            onclick="return confirm('<?= addslashes(t('viewthread.confirm_delete_post',[],'Delete this post?')) ?>')">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </form>
                <?php endif; ?>

                <?php if ($userPriv>=5 && !empty($p['is_deleted'])): ?>
                <form method="POST" action="<?= $thread_url ?>" style="margin:0;display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="mod_action" value="restore_post">
                    <input type="hidden" name="post_id"    value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="vt-meta-btn"
                            onclick="return confirm('<?= addslashes(t('viewthread.confirm_restore_post',[],'Restore this post?')) ?>')">
                        <i class="fas fa-undo"></i>
                    </button>
                </form>
                <?php if ((int)$p['id'] !== (int)($first_post_id ?? 0)): ?>
                <form method="POST" action="<?= $thread_url ?>" style="margin:0;display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="mod_action" value="confirm_delete_post">
                    <input type="hidden" name="post_id"    value="<?= (int)$p['id'] ?>">
                    <button type="submit" class="vt-meta-btn vt-meta-del"
                            onclick="return confirm('<?= addslashes(t('viewthread.confirm_hard_delete_post',[],'Permanently delete this post? This cannot be undone.')) ?>')">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </form>
                <?php endif; ?>
                <?php endif; ?>

                <?php if ($can_edit): ?>
                <button class="vt-meta-btn vt-meta-edit" onclick="openEditBox(<?= (int)$p['id'] ?>)">
                    <i class="fas fa-edit"></i>
                    <span class="hide-mobile"><?= t('viewthread.btn_edit',[],'Edit') ?></span>
                </button>
                <?php endif; ?>
                
                <?php if ($ai_active && $myId > 0): ?>
                <div style="position:relative; display:inline-block;">
                    <button class="vt-meta-btn vt-meta-translate" onclick="toggleTranslatePicker(<?= (int)$p['id'] ?>)">
                        <i class="fas fa-language"></i>
                        <span class="hide-mobile"><?= t('viewthread.btn_translate',[],'Translate') ?></span>
                    </button>
                    <div class="vt-translate-picker" id="trans-picker-<?= (int)$p['id'] ?>" style="display:none; position:absolute; bottom:100%; left:0; margin-bottom:5px; background:#0a0a0a; border:1px solid #1a1a1a; padding:6px; border-radius:4px; z-index:100; gap:6px; white-space:nowrap; box-shadow:0 5px 15px rgba(0,0,0,0.6);">
                        <button type="button" onclick="executeTranslation(<?= (int)$p['id'] ?>, 'en')" style="background:transparent; border:1px solid #333; color:#ccc; cursor:pointer; padding:4px 10px; border-radius:2px; font-family:'Cinzel',serif; transition:0.2s;" onmouseover="this.style.background='rgba(197,160,89,0.1)';this.style.borderColor='#c5a059';this.style.color='#c5a059';" onmouseout="this.style.background='transparent';this.style.borderColor='#333';this.style.color='#ccc';">EN</button>
                        <button type="button" onclick="executeTranslation(<?= (int)$p['id'] ?>, 'de')" style="background:transparent; border:1px solid #333; color:#ccc; cursor:pointer; padding:4px 10px; border-radius:2px; font-family:'Cinzel',serif; transition:0.2s;" onmouseover="this.style.background='rgba(197,160,89,0.1)';this.style.borderColor='#c5a059';this.style.color='#c5a059';" onmouseout="this.style.background='transparent';this.style.borderColor='#333';this.style.color='#ccc';">DE</button>
                        <button type="button" onclick="executeTranslation(<?= (int)$p['id'] ?>, 'fr')" style="background:transparent; border:1px solid #333; color:#ccc; cursor:pointer; padding:4px 10px; border-radius:2px; font-family:'Cinzel',serif; transition:0.2s;" onmouseover="this.style.background='rgba(197,160,89,0.1)';this.style.borderColor='#c5a059';this.style.color='#c5a059';" onmouseout="this.style.background='transparent';this.style.borderColor='#333';this.style.color='#ccc';">FR</button>
                        <button type="button" onclick="executeTranslation(<?= (int)$p['id'] ?>, 'es')" style="background:transparent; border:1px solid #333; color:#ccc; cursor:pointer; padding:4px 10px; border-radius:2px; font-family:'Cinzel',serif; transition:0.2s;" onmouseover="this.style.background='rgba(197,160,89,0.1)';this.style.borderColor='#c5a059';this.style.color='#c5a059';" onmouseout="this.style.background='transparent';this.style.borderColor='#333';this.style.color='#ccc';">ES</button>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($myId>0 && $myStanding<3 && $userPriv>=$effective_min_post && !$thread['is_locked'] && $is_verified === 1): ?>
                <button class="vt-meta-btn vt-meta-quote" onclick="quotePost('<?= addslashes($p['username'] ?? t('viewthread.deleted_user', [], 'Deleted User')) ?>','post-content-<?= (int)$p['id'] ?>')">
                    <i class="fas fa-quote-left"></i>
                    <span class="hide-mobile"><?= t('viewthread.btn_quote',[],'Quote') ?></span>
                </button>
                <?php endif; ?>
            </div>

            <?php if (!empty($p['edited_at'])): ?>
            <div class="vt-history-box" id="hist-<?= (int)$p['id'] ?>">
                <div style="font-size:0.76em;color:#333;margin-bottom:3px;"><i class="fas fa-history"></i> <?= t('viewthread.edit_history',[],'Edit History') ?></div>
                <div id="hist-content-<?= (int)$p['id'] ?>"><span style="color:#282828;font-style:italic;"><?= t('viewthread.loading',[],'Loading…') ?></span></div>
            </div>
            <?php endif; ?>

            <div id="edit-box-<?= (int)$p['id'] ?>" class="vt-edit-box">
                <form method="POST" action="<?= $thread_url ?>"
                      onsubmit="if(editQuillers[<?=(int)$p['id']?>])document.getElementById('edit-content-<?=(int)$p['id']?>').value=editQuillers[<?=(int)$p['id']?>].root.innerHTML;">
                    <input type="hidden" name="csrf_token"   value="<?= $csrf_token ?>">
                    <input type="hidden" name="post_id"      value="<?= (int)$p['id'] ?>">
                    <div class="quill-editor-wrap" style="margin-bottom:6px;">
                        <div id="edit-quill-<?= (int)$p['id'] ?>"></div>
                    </div>
                    <input type="hidden" name="edit_content" id="edit-content-<?= (int)$p['id'] ?>">
                    <input type="text" name="edit_reason"
                           placeholder="<?= t('viewthread.edit_reason_placeholder',[],'Reason for edit (optional)') ?>"
                           style="width:100%;background:#040404;border:1px solid #0c0c0c;color:#666;padding:6px 8px;font-size:0.74em;margin-bottom:6px;outline:none;box-sizing:border-box;">
                    <div style="display:flex;gap:6px;">
                        <button type="submit" name="submit_edit" value="1" class="spike-editor-btn spike-editor-btn--save" style="padding:5px 12px;font-size:0.56em;">
                            <?= t('viewthread.btn_save',[],'Save') ?>
                        </button>
                        <button type="button" onclick="closeEditBox(<?= (int)$p['id'] ?>)" class="spike-editor-btn spike-editor-btn--cancel" style="padding:5px 10px;font-size:0.56em;">
                            <?= t('viewthread.btn_cancel',[],'Cancel') ?>
                        </button>
                        <button type="button" onclick="brightenDarkText(<?= (int)$p['id'] ?>)" class="spike-editor-btn" title="<?= addslashes(t('viewthread.btn_brighten_text_hint',[],'Lighten text that is too dark to read against the background')) ?>" style="padding:5px 10px;font-size:0.56em;margin-left:auto;">
                            <i class="fas fa-sun"></i> <?= t('viewthread.btn_brighten_text',[],'Brighten Dark Text') ?>
                        </button>
                    </div>
                </form>
            </div>

            <div class="vt-content" id="post-content-<?= (int)$p['id'] ?>">
                <?php $content=parseBBCode($p['content']); if($smilies_enabled) $content=spike_parse_smilies($content,$smilies); echo $content; ?>
            </div>

            <?php if (!empty($post_attachments)): ?>
            <div class="vt-attachments">
                <div class="vt-attach-label"><i class="fas fa-paperclip"></i> <?= t('viewthread.attachments',[],'Attachments') ?></div>
                <?php foreach ($post_attachments as $att): $is_img=strpos($att['mime_type'],'image')!==false; ?>
                <?php if ($is_img): ?>
                <div class="vt-attach-img-wrap">
                    <a href="index.php?p=download_attachment&id=<?= (int)$att['id'] ?>" target="_blank">
                        <img src="index.php?p=download_attachment&id=<?= (int)$att['id'] ?>" class="vt-attach-img"
                             alt="<?= h($att['filename']) ?>" title="<?= h($att['filename']) ?> (<?= round($att['filesize']/1024,1) ?> KB)">
                    </a>
                </div>
                <?php else: ?>
                <a href="index.php?p=download_attachment&id=<?= (int)$att['id'] ?>" class="vt-attach-item">
                    <i class="fas fa-file" style="opacity:0.4;"></i>
                    <?= h($att['filename']) ?>
                    <span style="font-size:0.84em;">(<?= round($att['filesize']/1024,1) ?> KB)</span>
                    <span style="font-size:0.78em;opacity:0.6;"><i class="fas fa-download"></i> <?= (int)$att['downloads'] ?></span>
                </a>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($p['forum_signature'])): ?>
            <div class="vt-sig"><?= parseBBCode($p['forum_signature']) ?></div>
            <?php endif; ?>

            <?php if ($reactions_enabled): ?>
            <div class="vt-reactions">
                <?php foreach ($post_reactions as $emoji=>$data): ?>
                <button class="vt-reaction-btn <?= $data['mine']?'mine':'' ?>"
                        onclick="toggleReaction(<?= (int)$p['id'] ?>,'<?= $emoji ?>',this)"
                        data-emoji="<?= $emoji ?>">
                    <?= $available_reactions[$emoji]['emoji']??$emoji ?>
                    <span class="cnt"><?= $data['cnt'] ?></span>
                </button>
                <?php endforeach; ?>
                <?php if ($myId>0): ?>
                <div style="position:relative;">
                    <button class="vt-reaction-add" onclick="toggleReactionPicker(<?= (int)$p['id'] ?>)"><i class="fas fa-smile-plus"></i></button>
                    <div class="vt-reaction-picker" id="picker-<?= (int)$p['id'] ?>">
                        <?php foreach ($available_reactions as $key=>$r): ?>
                        <button onclick="toggleReaction(<?=(int)$p['id']?>,'<?=$key?>',null);closeAllPickers();" title="<?=$r['label']?>"><?=$r['emoji']?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>
    </div>
    <?php endforeach; endif; ?>
    </div><?php if ($total_pages > 1 && $current_page < $total_pages): ?>
    <div id="vt-scroll-sentinel" style="height:1px;"></div>
    <div id="vt-loading-indicator" class="vt-infinite-loading" style="display:none;">
        <span class="vt-infinite-dots">
            <i class="fas fa-circle"></i><i class="fas fa-circle"></i><i class="fas fa-circle"></i>
        </span>
    </div>
    <?php endif; ?>

    <?php if ($single_post_id === 0 && $total_pages>1): ?>
    <div class="spk-pagination spk-pagination--paged-fallback">
        <?php if ($current_page>1): ?>
            <a href="<?= spk_pagination_url($thread,1) ?>" class="spk-page-btn">«</a>
            <a href="<?= spk_pagination_url($thread,$current_page-1) ?>" class="spk-page-btn">‹</a>
        <?php endif; ?>
        <?php for ($i=max(1,$current_page-2);$i<=min($total_pages,$current_page+2);$i++): ?>
            <a href="<?= spk_pagination_url($thread,$i) ?>" class="spk-page-btn <?= $i===$current_page?'active':'' ?>"><?= $i ?></a>
        <?php endfor; ?>
        <?php if ($current_page<$total_pages): ?>
            <a href="<?= spk_pagination_url($thread,$current_page+1) ?>" class="spk-page-btn">›</a>
            <a href="<?= spk_pagination_url($thread,$total_pages) ?>" class="spk-page-btn">»</a>
        <?php endif; ?>
        <span class="spk-page-info"><?= t('viewthread.page',[],'Page') ?> <?= $current_page ?>/<?= $total_pages ?></span>
    </div>
    <?php endif; ?>

    <?php if ($can_actually_post&&!$thread['is_locked']): ?>
    <div id="quick-reply-box" class="vt-reply-box">
        <div class="vt-reply-title">
            <i class="fas fa-reply" style="opacity:0.35;"></i>
            <?= t('viewthread.btn_post_reply',[],'Post Reply') ?>
        </div>
        <form method="POST" action="<?= $thread_url ?>" enctype="multipart/form-data" id="reply-form">
            <input type="hidden" name="csrf_token"    value="<?= $csrf_token ?>">
            <input type="hidden" name="reply_content" id="reply-content-hidden" value="">
            <noscript>
                <textarea name="reply_content_raw" style="width:100%;min-height:105px;background:#030303;border:1px solid #121212;color:#bbb;padding:8px;font-size:0.88em;box-sizing:border-box;"
                          placeholder="<?= t('viewthread.reply_placeholder',[],'Write your reply…') ?>"></textarea>
            </noscript>
            <div class="quill-editor-wrap"><div id="reply-quill-editor"></div></div>
            <?php if ($smilies_enabled&&!empty($smilies)): ?>
            <div class="vt-smilies-bar">
                <?php foreach ($smilies as $s): 
                    $safe_url = ltrim($s['image_url'] ?? '', '/');
                ?>
                <button type="button" onclick="insertSmiley('<?= addslashes($s['code']) ?>')" title="<?= h($s['title'] ?? $s['code']) ?>">
                    <img src="<?= h($safe_url) ?>" alt="<?= h($s['code']) ?>" style="width:18px;height:18px;vertical-align:middle;pointer-events:none;">
                </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div style="display:flex;align-items:center;gap:8px;margin:8px 0 4px;">
                <button type="button" onclick="togglePreview()"
                        class="spike-editor-btn spike-editor-btn--cancel"
                        style="padding:5px 11px;font-size:0.6em;">
                    <i class="fas fa-eye"></i> <?= t('viewthread.preview',[],'Preview') ?>
                </button>
            </div>
            <div class="vt-preview-box" id="reply-preview"></div>
            <?php if ($attachments_enabled): ?>
            <div style="margin:8px 0 0;">
                <label style="font-size:0.58em;color:#555;text-transform:uppercase;letter-spacing:1px;display:block;margin-bottom:3px;font-family:'Cinzel',serif;">
                    <i class="fas fa-paperclip"></i>
                    <?= t('viewthread.attachments',[],'Attachments') ?> (max <?= round($max_attach_size/1024/1024,1) ?> MB <?= t('viewthread.each',[],'each') ?>)
                </label>
                <input type="file" name="attachments[]" multiple accept="<?= implode(',',$allowed_mimes) ?>" style="font-size:0.74em;color:#555;">
            </div>
            <?php endif; ?>
            <button type="submit" name="submit_reply" class="spike-editor-btn spike-editor-btn--save" style="margin-top:12px;">
                <i class="fas fa-reply"></i> <?= t('viewthread.btn_post_reply',[],'Post Reply') ?>
            </button>
        </form>
    </div>
    <?php elseif ($myId>0&&!$can_actually_post): ?>
    <div class="admin-box" style="padding:11px 14px;border-left:3px solid #141414;opacity:0.55;text-align:center;margin-top:12px;">
        <i class="fas fa-lock" style="color:#2a2a2a;"></i>
        <span style="color:#555;font-size:0.8em;margin-left:7px;"><?= t('viewthread.readonly_message',[],'You cannot post in this thread.') ?></span>
    </div>
    <?php endif; ?>

</div>

<div id="spk-undo-toast" style="display:none; position:fixed; bottom:30px; left:50%; transform:translateX(-50%); background:rgba(10,10,10,0.95); border:1px solid var(--glow-gold); padding:10px 20px; color:#ccc; z-index:9999; align-items:center; gap:15px; border-radius:4px; box-shadow:0 5px 15px rgba(0,0,0,0.5); font-family:'Cinzel',serif; font-size:0.85em;">
    <span id="spk-undo-text"></span>
    <button id="spk-undo-btn" style="background:var(--glow-gold); border:none; color:#000; padding:5px 12px; cursor:pointer; font-weight:bold; border-radius:2px; transition:0.2s;" onmouseover="this.style.opacity=0.8" onmouseout="this.style.opacity=1">
        <i class="fas fa-undo"></i> <?= t('viewthread.undo', [], 'Undo') ?> (<span id="spk-undo-timer">15</span>s)
    </button>
</div>

<button id="spk-back-top" onclick="window.scrollTo({top:0,behavior:'smooth'})" title="Back to top">
    <i class="fas fa-chevron-up"></i>
</button>

<div class="vt-report-overlay" id="report-overlay">
    <div class="vt-report-box">
        <div class="vt-report-title"><i class="fas fa-flag" style="margin-right:6px;"></i><?= t('viewthread.report_title',[],'Report Post') ?></div>
        <input type="hidden" id="report-post-id" value="">
        <select class="vt-report-select" id="report-reason">
            <option value="spam"><?=          t('viewthread.report_spam',[],'Spam / Advertising') ?></option>
            <option value="offensive"><?=     t('viewthread.report_offensive',[],'Offensive / Harassment') ?></option>
            <option value="misinformation"><?= t('viewthread.report_misinfo',[],'Misinformation') ?></option>
            <option value="other"><?=         t('viewthread.report_other',[],'Other') ?></option>
        </select>
        <textarea class="vt-report-textarea" id="report-details" placeholder="<?= t('viewthread.report_details_placeholder',[],'Additional details (optional)…') ?>"></textarea>
        <div class="vt-report-btns">
            <button class="vt-report-cancel" onclick="closeReport()"><?= t('viewthread.btn_cancel',[],'Cancel') ?></button>
            <button class="vt-report-submit" onclick="submitReport()"><i class="fas fa-flag"></i> <?= t('viewthread.btn_submit_report',[],'Submit Report') ?></button>
        </div>
        <div id="report-status" style="margin-top:6px;font-size:0.74em;color:#666;display:none;"></div>
    </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
<link href="style.php?module=spike_mentions" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.min.js"></script>
<script src="assets/js/spike_mention.js"></script>
<script>
const VT_CSRF = "<?= $csrf_token ?>";
const VT_MYID = <?= (int)$myId ?>;
const SPK_MY_ID = <?= (int)$myId ?>;
const SPK_NOTIF_OPTIONS = {
    userId:       <?= (int)$myId ?>,
    fetchUrl:     'index.php?p=spike_notifications',
    markReadUrl:  'index.php?p=spike_notifications',
    csrfToken:    '<?= $csrf_token ?>',
    pollInterval: 60000,
};

const QUILL_TOOLBAR = [
    ['bold','italic','underline','strike'],
    ['blockquote','code-block'],
    [{'list':'ordered'},{'list':'bullet'},{'indent':'-1'},{'indent':'+1'}],
    ['image', 'link'],['clean']
];

window.vt_lang_ai_translation = "<?= addslashes(t('viewthread.ai_translation', [], 'AI Translation')) ?>";

function toggleTranslatePicker(postId) {
    if(typeof closeAllPickers === 'function') closeAllPickers();
    document.querySelectorAll('.vt-translate-picker').forEach(p => {
        if (p.id !== 'trans-picker-' + postId) p.style.display = 'none';
    });
    const picker = document.getElementById('trans-picker-' + postId);
    if (picker) picker.style.display = picker.style.display === 'none' ? 'flex' : 'none';
}

document.addEventListener('click', e => {
    if(!e.target.closest('.vt-reaction-add') && !e.target.closest('.vt-reaction-picker')) {
        if(typeof closeAllPickers === 'function') closeAllPickers();
    }
    if(!e.target.closest('.vt-meta-translate') && !e.target.closest('.vt-translate-picker')) {
        document.querySelectorAll('.vt-translate-picker').forEach(p => p.style.display = 'none');
    }
});

function executeTranslation(postId, lang) {
    const picker = document.getElementById('trans-picker-' + postId);
    if (picker) picker.style.display = 'none';
    
    const contentDiv = document.getElementById('post-content-' + postId);
    if (!contentDiv) return;

    const mainBtn = document.querySelector('#post-' + postId + ' .vt-meta-translate');
    const icon = mainBtn ? mainBtn.querySelector('i') : null;
    const originalIcon = icon ? icon.className : 'fas fa-language';
    if (icon) icon.className = 'fas fa-spinner fa-spin';
    if (mainBtn) mainBtn.disabled = true;

    const fd = new FormData();
    fd.append('ajax_action', 'translate_post');
    fd.append('csrf_token', VT_CSRF);
    fd.append('post_id', postId);
    fd.append('target_lang', lang);
    
    fetch(window.location.href, {method: 'POST', body: fd})
        .then(r => r.json())
        .then(d => {
            if (icon) icon.className = originalIcon;
            if (mainBtn) mainBtn.disabled = false;
            
            if (d.ok) {
                let box = contentDiv.querySelector('.vt-translated-box');
                if (!box) {
                    box = document.createElement('div');
                    box.className = 'vt-translated-box';
                    box.style.cssText = 'margin-top:15px; padding:12px; background:rgba(197,160,89,0.05); border-left:3px solid var(--glow-gold); border-radius:3px; font-size:0.95em; color:#ddd; animation:vt-post-fadein 0.3s ease forwards;';
                    contentDiv.appendChild(box);
                }
                box.style.display = 'block';
                box.innerHTML = '<div style="font-family:\'Cinzel\',serif; font-size:0.7em; color:#c5a059; margin-bottom:8px; letter-spacing:1px; text-transform:uppercase; display:flex; justify-content:space-between;"><span style="display:flex;align-items:center;gap:6px;"><i class="fas fa-robot"></i> ' + window.vt_lang_ai_translation + ' (' + lang.toUpperCase() + ')</span><button type="button" onclick="this.parentNode.parentNode.style.display=\'none\'" style="background:none;border:none;color:#888;cursor:pointer;"><i class="fas fa-times"></i></button></div>' + d.translation;
            } else {
                alert('Translation failed: ' + (d.error || 'Unknown error'));
            }
        }).catch(() => {
            if (icon) icon.className = originalIcon;
            if (mainBtn) mainBtn.disabled = false;
            alert('Network error');
        });
}

function editThreadTitle(el) {
    if (el.querySelector('input')) return;
    
    const fullTitle = el.dataset.fulltitle;
    const currentPrefixId = (el.dataset.prefixid == 0) ? '' : el.dataset.prefixid;
    
    const wrapper = document.createElement('span');
    wrapper.style.display = 'inline-flex';
    wrapper.style.gap = '5px';
    wrapper.style.alignItems = 'center';
    
    const select = document.createElement('select');
    select.className = 'um-input';
    select.style.cssText = 'font-family:"Cinzel",serif; font-size:0.8em; color:var(--glow-gold); background:rgba(0,0,0,0.8); border:1px solid #333; padding:4px 2px; outline:none; border-radius:2px; height:auto;';
    select.innerHTML = '<option value="">-</option>';
    <?php foreach ($available_prefixes as $pf): ?>
    select.innerHTML += '<option value="<?= $pf['id'] ?>"><?= addslashes($pf['label']) ?></option>';
    <?php endforeach; ?>
    select.value = currentPrefixId;
    
    const input = document.createElement('input');
    input.type = 'text';
    input.value = fullTitle;
    input.className = 'um-input';
    input.style.cssText = 'font-family:"Cinzel",serif; font-size:1em; font-weight:bold; color:var(--glow-gold); background:rgba(0,0,0,0.8); border:1px solid #333; padding:2px 6px; width:300px; max-width:100%; outline:none; border-radius:2px;';
    
    const saveFunc = function(e) {
        if (e.key === 'Escape') {
            renderTitle(fullTitle, currentPrefixId);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            saveThreadTitle(input.value.trim(), fullTitle, select.value, currentPrefixId);
        }
    };
    input.onkeydown = saveFunc;

    wrapper.appendChild(select);
    wrapper.appendChild(input);

    const prefixSpan = document.getElementById('bc-thread-prefix');
    if (prefixSpan) prefixSpan.style.display = 'none';

    el.innerHTML = '';
    el.appendChild(wrapper);
    input.focus();

    function renderTitle(text, prefId, prefData = null) {
        el.dataset.fulltitle = text;
        el.dataset.prefixid = prefId;
        const shortText = text.length > 60 ? text.substring(0, 60) + '…' : text;
        el.textContent = shortText;
        
        let pSpan = document.getElementById('bc-thread-prefix');
        if (prefData) {
            if (!pSpan) {
                pSpan = document.createElement('span');
                pSpan.id = 'bc-thread-prefix';
                pSpan.className = 'spk-prefix';
                el.parentNode.insertBefore(pSpan, el);
                el.parentNode.insertBefore(document.createTextNode(' '), el);
            }
            pSpan.style.color = prefData.color;
            pSpan.style.background = prefData.bg_color;
            pSpan.textContent = prefData.label;
            pSpan.style.display = 'inline-block';
        } else if (prefId === '' || prefId == 0) {
            if (pSpan) pSpan.remove();
        } else {
            if (pSpan) pSpan.style.display = 'inline-block';
        }
    }

    function saveThreadTitle(newTitle, oldTitle, newPref, oldPref) {
        if (!newTitle) { renderTitle(oldTitle, oldPref); return; }
        if (newTitle === oldTitle && newPref === oldPref) { renderTitle(oldTitle, oldPref); return; }
        input.disabled = true; select.disabled = true;
        input.style.opacity = '0.5'; select.style.opacity = '0.5';
        
        const fd = new FormData();
        fd.append('ajax_action', 'update_thread_title');
        fd.append('csrf_token', VT_CSRF);
        fd.append('new_title', newTitle);
        fd.append('prefix_id', newPref);
        
        fetch(window.location.href, {method: 'POST', body: fd})
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    renderTitle(d.raw_title, newPref, d.prefix);
                } else {
                    alert('Error: ' + (d.error || 'Failed to update'));
                    renderTitle(oldTitle, oldPref);
                }
            }).catch(() => renderTitle(oldTitle, oldPref));
    }
}

function spkCopyLink(e, relativeUrl) {
    e.preventDefault();
    const fullUrl = window.location.origin + window.location.pathname + '?' + relativeUrl.split('?')[1];
    navigator.clipboard?.writeText(fullUrl).then(() => {
        const btn = e.currentTarget;
        const icon = btn.querySelector('i');
        icon.className = 'fas fa-check';
        icon.style.color = 'var(--glow-gold)';
        setTimeout(() => {
            icon.className = 'fas fa-link';
            icon.style.color = '';
        }, 2000);
    });
}

let replyQuill = null;
try {
    if (document.getElementById('reply-quill-editor')) {
        replyQuill = new Quill('#reply-quill-editor', {
            theme: 'snow',
            placeholder: '<?= addslashes(t('viewthread.reply_placeholder',[],'Write your reply…')) ?>',
            modules: { toolbar: QUILL_TOOLBAR }
        });

        <?php if ($myId > 0): ?>
        try {
            const replyMentioner = new SpikeMentioner(replyQuill, {
                myId:      <?= (int)$myId ?>,
                searchUrl: 'index.php?p=spike_mention_search',
                csrfToken: VT_CSRF,
            });
        } catch(e) { console.warn('Mentioner init failed', e); }
        <?php endif; ?>
    }
} catch(e) { console.error('Reply-Quill init failed', e); }

document.getElementById('reply-form')?.addEventListener('submit', function(e) {
    const html     = replyQuill ? replyQuill.root.innerHTML : '';
    const textOnly = replyQuill ? replyQuill.getText().trim() : '';
    document.getElementById('reply-content-hidden').value = html;

    if (!textOnly || textOnly.length === 0) {
        if (!html.includes('<img')) {
            e.preventDefault();
            alert('<?= addslashes(t('viewthread.err_empty_reply',[],'Please write something before posting.')) ?>');
            if (replyQuill) replyQuill.focus();
            return false;
        }
    }

    const btn = this.querySelector('[name="submit_reply"]');
    if (btn) {
        btn.style.pointerEvents = 'none';
        btn.style.opacity = '0.5';
        btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> <?= addslashes(t('viewthread.btn_post_reply',[],'Post Reply')) ?>';
    }
});

const editQuillers = {};
function openEditBox(postId) {
    const box = document.getElementById('edit-box-'+postId);
    if (!box) return;
    box.style.display = 'block';
    if (!editQuillers[postId]) {
        try {
            editQuillers[postId] = new Quill('#edit-quill-'+postId, {
                theme: 'snow',
                modules: { toolbar: [['bold','italic','underline'],['blockquote'],[{'list':'bullet'},{'list':'ordered'},{'indent':'-1'},{'indent':'+1'}],['image', 'link'],['clean']] }
            });
            const rawHtml = document.getElementById('post-content-'+postId)?.innerHTML || '';
            editQuillers[postId].clipboard.dangerouslyPasteHTML(rawHtml.replace(/>\s+</g, '><').trim());
        } catch(e) {
            console.error('Edit-Quill init failed for post '+postId, e);
        }
    }
    box.scrollIntoView({behavior:'smooth',block:'nearest'});
}
function closeEditBox(postId) {
    const box = document.getElementById('edit-box-'+postId);
    if (box) box.style.display = 'none';
}

// ── Brighten dark text: normalizes any inline color (hex/rgb/named) to HSL
// and raises its lightness so it stays readable against the dark theme. ──
function vtColorToRgb(colorStr) {
    const el = document.createElement('span');
    el.style.color = colorStr;
    document.body.appendChild(el);
    const rgb = getComputedStyle(el).color;
    document.body.removeChild(el);
    const m = rgb.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
    return m ? [parseInt(m[1],10), parseInt(m[2],10), parseInt(m[3],10)] : null;
}
function vtRgbToHsl(r, g, b) {
    r/=255; g/=255; b/=255;
    const max = Math.max(r,g,b), min = Math.min(r,g,b);
    let h, s, l = (max+min)/2;
    if (max === min) { h = s = 0; }
    else {
        const d = max - min;
        s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
        switch (max) {
            case r: h = (g - b) / d + (g < b ? 6 : 0); break;
            case g: h = (b - r) / d + 2; break;
            default: h = (r - g) / d + 4;
        }
        h /= 6;
    }
    return [h*360, s*100, l*100];
}
function brightenDarkText(postId) {
    const quill = editQuillers[postId];
    if (!quill) return;
    const MIN_LIGHTNESS = 55;
    let changed = 0;
    quill.root.querySelectorAll('[style*="color"]').forEach(el => {
        if (!el.style.color) return;
        const rgb = vtColorToRgb(el.style.color);
        if (!rgb) return;
        const [h, s, l] = vtRgbToHsl(rgb[0], rgb[1], rgb[2]);
        if (l < MIN_LIGHTNESS) {
            el.style.color = 'hsl(' + Math.round(h) + ',' + Math.round(s) + '%,' + MIN_LIGHTNESS + '%)';
            changed++;
        }
    });
    if (changed > 0) {
        alert(changed + ' <?= addslashes(t("viewthread.brighten_done", [], "text element(s) brightened. Remember to save your edit.")) ?>');
    } else {
        alert('<?= addslashes(t("viewthread.brighten_none", [], "No dark text found - nothing to brighten.")) ?>');
    }
}

function quotePost(author, contentId) {
    const content = document.getElementById(contentId)?.innerText?.trim()||'';
    const quoteHtml = '<blockquote><strong>'+author+' wrote:</strong><br>'+content+'</blockquote><p><br></p>';
    replyQuill.clipboard.dangerouslyPasteHTML(quoteHtml);
    document.getElementById('quick-reply-box')?.scrollIntoView({behavior:'smooth'});
}

function insertSmiley(code) {
    const range = replyQuill.getSelection(true);
    replyQuill.insertText(range?range.index:replyQuill.getLength(),' '+code+' ');
}

function togglePreview() {
    const box = document.getElementById('reply-preview');
    if (!box) return;
    if (box.classList.contains('visible')) { box.classList.remove('visible'); return; }
    box.innerHTML = replyQuill.root.innerHTML || '<em style="color:#333;">Nothing to preview.</em>';
    box.classList.add('visible');
}

document.querySelectorAll('a[href^="#post-"]').forEach(function(link) {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        var target = document.querySelector(this.getAttribute('href'));
        if (target) target.scrollIntoView({behavior:'smooth', block:'start'});
        history.replaceState(null, '', this.getAttribute('href'));
    });
});

(function(){
    var btn = document.getElementById('spk-back-top');
    if (!btn) return;
    window.addEventListener('scroll', function(){
        btn.classList.toggle('visible', window.scrollY > 400);
    }, {passive:true});
})();

function toggleEditHistory(postId) {
    const box = document.getElementById('hist-'+postId);
    if (!box) return;
    if (box.classList.contains('open')) { box.classList.remove('open'); return; }
    box.classList.add('open');
    const content = document.getElementById('hist-content-'+postId);
    if (content?.dataset.loaded) return;
    const fd = new FormData();
    fd.append('ajax_action','get_edit_history');
    fd.append('csrf_token',VT_CSRF);
    fd.append('post_id',postId);
    fetch(window.location.href,{method:'POST',body:fd})
        .then(r=>r.json())
        .then(data=>{
            if (!content) return;
            content.dataset.loaded='1';
            if (!data.history?.length) { content.innerHTML='<span style="color:#333;font-style:italic;">No history.</span>'; return; }
            content.innerHTML=data.history.map(h=>'<div class="vt-history-entry"><span style="color:#444;">'+h.date+'</span> — <span style="color:#555;">'+(h.editor||'?')+'</span>'+(h.reason?' <em style="color:#333;">('+h.reason+')</em>':'')+'</div>').join('');
        }).catch(()=>{ if(content) content.innerHTML='<span style="color:#333;">Failed to load.</span>'; });
}

const emojiMap = <?= json_encode(array_map(fn($r)=>$r['emoji'],$available_reactions),JSON_UNESCAPED_UNICODE) ?>;
function toggleReaction(postId, emoji, btn) {
    if (!VT_MYID) { window.location.href='index.php?p=login'; return; }
    const fd=new FormData();
    fd.append('ajax_action','toggle_reaction');fd.append('csrf_token',VT_CSRF);
    fd.append('post_id',postId);fd.append('emoji',emoji);
    fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
        if (!data.ok) return;
        const bar=document.querySelector('#post-'+postId+' .vt-reactions');
        if (!bar) return;
        let el=bar.querySelector('[data-emoji="'+emoji+'"]');
        if (data.added) {
            if (!el) {
                el=document.createElement('button');
                el.className='vt-reaction-btn mine';el.dataset.emoji=emoji;
                el.onclick=function(){toggleReaction(postId,emoji,this);};
                el.innerHTML=(emojiMap[emoji]||emoji)+' <span class="cnt">0</span>';
                const aw=bar.querySelector('.vt-reaction-add')?.parentNode;
                bar.insertBefore(el,aw||null);
            }
            el.classList.add('mine');
        } else { if(el) el.classList.remove('mine'); }
        if (el) { const c=el.querySelector('.cnt'); if(c) c.textContent=data.count; if(data.count<=0) el.remove(); }
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}
function toggleReactionPicker(postId) { closeAllPickers(); document.getElementById('picker-'+postId)?.classList.toggle('open'); }
function closeAllPickers() { document.querySelectorAll('.vt-reaction-picker').forEach(p=>p.classList.remove('open')); }

function toggleSubscription() {
    const fd=new FormData();
    fd.append('ajax_action','toggle_subscription');fd.append('csrf_token',VT_CSRF);
    fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
        const btn=document.getElementById('sub-btn');
        const lbl=document.getElementById('sub-label');
        const icon=btn?.querySelector('i');
        if (data.subscribed) {
            btn?.classList.add('subscribed');
            if(lbl) lbl.textContent='<?= addslashes(t('viewthread.subscribed',[],'Subscribed')) ?>';
            if(icon){icon.classList.remove('fa-bell-slash');icon.classList.add('fa-bell');}
        } else {
            btn?.classList.remove('subscribed');
            if(lbl) lbl.textContent='<?= addslashes(t('viewthread.subscribe',[],'Subscribe')) ?>';
            if(icon){icon.classList.remove('fa-bell');icon.classList.add('fa-bell-slash');}
        }
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

function openReport(postId) {
    document.getElementById('report-post-id').value=postId;
    document.getElementById('report-details').value='';
    document.getElementById('report-reason').value='spam';
    document.getElementById('report-status').style.display='none';
    document.getElementById('report-overlay').classList.add('open');
}
function closeReport() { document.getElementById('report-overlay').classList.remove('open'); }
function submitReport() {
    const fd=new FormData();
    fd.append('ajax_action','report_post');fd.append('csrf_token',VT_CSRF);
    fd.append('post_id',document.getElementById('report-post-id').value);
    fd.append('reason',document.getElementById('report-reason').value);
    fd.append('details',document.getElementById('report-details').value);
    const status=document.getElementById('report-status');
    
    fetch(window.location.href,{method:'POST',body:fd})
        .then(r=>r.json())
        .then(data=>{
            status.style.display='block';
            if(data.ok){status.textContent='<?= addslashes(t('viewthread.report_success',[],'Report submitted. Thank you.')) ?>';status.style.color='#555';setTimeout(closeReport,1500);}
            else if(data.error==='already_reported'){status.textContent='<?= addslashes(t('viewthread.report_already',[],'You already reported this post.')) ?>';status.style.color='#e08040';}
            else{status.textContent='Error: '+data.error;status.style.color='var(--error-red)';}
        })
        .catch(err => {
            status.style.display='block';
            status.textContent='Critical Error: Could not process response.';
            status.style.color='var(--error-red)';
            console.error('Report submission failed:', err);
        });
}
document.getElementById('report-overlay').addEventListener('click',e=>{if(e.target===e.currentTarget)closeReport();});

function submitPollVote() {
    const form=document.getElementById('poll-form');if(!form)return;
    const inputs=form.querySelectorAll('input[name="poll_option"]:checked');if(!inputs.length)return;
    const fd=new FormData();
    fd.append('ajax_action','poll_vote');fd.append('csrf_token',VT_CSRF);
    [...inputs].forEach(i=>fd.append('option_ids[]',i.value));
    fetch(window.location.href,{method:'POST',body:fd}).then(r=>r.json()).then(data=>{if(data.ok)location.reload();}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

(function() {
    const container = document.getElementById('vt-posts-container');
    const sentinel  = document.getElementById('vt-scroll-sentinel');
    const loader    = document.getElementById('vt-loading-indicator');
    const paginFallback = document.querySelector('.spk-pagination--paged-fallback');

    if (!container) return;
    
    const singlePost = parseInt(container.dataset.singlePost || 0);
    if (singlePost > 0) {
        if (sentinel) sentinel.remove();
        return;
    }

    if (!sentinel) return;
    if (paginFallback) paginFallback.style.display = 'none';

    let currentPage  = parseInt(container.dataset.currentPage  || 1);
    const totalPages = parseInt(container.dataset.totalPages   || 1);
    const slug       = container.dataset.slug || '';
    const threadId   = container.dataset.threadId || '';
    let   loading    = false;
    let   exhausted  = currentPage >= totalPages;

    if (exhausted) { sentinel.remove(); return; }

    const observer = new IntersectionObserver((entries) => {
        if (!entries[0].isIntersecting || loading || exhausted) return;
        loadNextPage();
    }, { rootMargin: '200px' });

    observer.observe(sentinel);

    async function loadNextPage() {
        loading = true;
        if (loader) loader.style.display = 'flex';

        const nextPage = currentPage + 1;
        const url = slug
            ? `index.php?p=viewthread&slug=${encodeURIComponent(slug)}&page=${nextPage}&ajax_posts=1`
            : `index.php?p=viewthread&id=${threadId}&page=${nextPage}&ajax_posts=1`;

        try {
            const res  = await fetch(url).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
            const data = await res.json();
            if (!data.ok || !data.html) throw new Error('empty');

            container.insertAdjacentHTML('beforeend', data.html);

            if (typeof spkInitMentionDisplay !== 'undefined') {
                spkInitMentionDisplay(VT_MYID);
            }

            const newUrl = slug
                ? `index.php?p=viewthread&slug=${encodeURIComponent(slug)}&page=${nextPage}`
                : `index.php?p=viewthread&id=${threadId}&page=${nextPage}`;
            history.replaceState(null, '', newUrl);

            currentPage = nextPage;
            exhausted   = currentPage >= totalPages || data.is_last;

            if (exhausted) {
                sentinel.remove();
                observer.disconnect();
                const endEl = document.createElement('div');
                endEl.className = 'vt-infinite-end';
                endEl.innerHTML = '<i class="fas fa-scroll"></i> <?= addslashes(t('viewthread.end_of_thread',[],'End of thread')) ?>';
                container.after(endEl);
            }
        } catch(e) {
            console.error('[spike infinite]', e);
            if (paginFallback) paginFallback.style.display = '';
            sentinel.remove();
            observer.disconnect();
        } finally {
            loading = false;
            if (loader) loader.style.display = 'none';
        }
    }
})();

document.addEventListener('DOMContentLoaded', function() {
    const params = new URLSearchParams(window.location.search);
    const undoPid = params.get('undo_pid');
    const undoTid = params.get('undo_tid');
    
    if (undoPid || undoTid) {
        const toast = document.getElementById('spk-undo-toast');
        if (!toast) return;
        const timerEl = document.getElementById('spk-undo-timer');
        const textEl = document.getElementById('spk-undo-text');
        const btn = document.getElementById('spk-undo-btn');
        
        textEl.textContent = undoPid ? '<?= addslashes(t('viewthread.reply_posted', [], 'Reply posted.')) ?>' : '<?= addslashes(t('viewthread.thread_created', [], 'Thread created.')) ?>';
        toast.style.display = 'flex';
        
        let timeLeft = 15;
        const timer = setInterval(() => {
            timeLeft--;
            if (timerEl) timerEl.textContent = timeLeft;
            if (timeLeft <= 0) {
                clearInterval(timer);
                toast.style.display = 'none';
                removeUndoParams();
            }
        }, 1000);
        
        if (btn) {
            btn.onclick = function() {
                clearInterval(timer);
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                
                const fd = new FormData();
                fd.append('csrf_token', VT_CSRF);
                if (undoPid) {
                    fd.append('ajax_action', 'undo_post');
                    fd.append('post_id', undoPid);
                } else {
                    fd.append('ajax_action', 'undo_thread');
                    fd.append('thread_id', undoTid);
                }
                
                fetch(window.location.href, {method: 'POST', body: fd})
                    .then(r => r.json())
                    .then(d => {
                        if (d.ok) {
                            if (undoTid && d.board_id) {
                                window.location.href = 'index.php?p=viewboard&id=' + d.board_id + '&msg=thread_undone';
                            } else {
                                toast.style.display = 'none';
                                const postEl = document.getElementById('post-' + undoPid);
                                if (postEl) postEl.remove();
                                removeUndoParams();
                            }
                        } else {
                            toast.style.display = 'none';
                            alert('Undo failed: ' + (d.error || 'Timeout'));
                        }
                    }).catch(() => toast.style.display = 'none');
            };
        }
        
        function removeUndoParams() {
            const url = new URL(window.location);
            url.searchParams.delete('undo_pid');
            url.searchParams.delete('undo_tid');
            window.history.replaceState({}, document.title, url);
        }
    }
});
</script>
