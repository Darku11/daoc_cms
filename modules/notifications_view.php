<?php if (!defined('IN_CMS')) { exit; } ?>

<div class="um-nexus-wrapper">
    <div class="um-internal-header">
        <h2 class="um-internal-title">
            <i class="fas fa-envelope-open-text notif-title-icon"></i>
            <?= t('notifications.title', [], 'DAoC CMS Messenger') ?>
        </h2>

        <?php if ($unread_count > 0): ?>
            <form method="POST" class="notif-markall-form">
                <input type="hidden" name="csrf_token" value="<?php echo generateToken(); ?>">
                <button type="submit" name="mark_all_read" class="btn-nexus-edit notif-markall-btn">
                    <i class="fas fa-check-double"></i> <?= t('notifications.mark_all_read', [], 'MARK ALL AS READ') ?>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <div class="admin-box notif-box">
        <?php if (!empty($notifications)): ?>
            <div class="notif-list">
                <?php foreach ($notifications as $n):
                    $is_new = ($n['is_read'] == 0);
                ?>
                    <div class="notif-item <?= $is_new ? 'notif-item--unread' : 'notif-item--read' ?>">

                        <div class="notif-item-main"
                             onclick="window.location.href='?p=viewthread&id=<?php echo (int)$n['thread_id']; ?>'">
                            <div class="notif-icon <?= $is_new ? 'notif-icon--new' : 'notif-icon--read' ?>">
                                <i class="fas <?php echo $is_new ? 'fa-comment-dots' : 'fa-comment'; ?>"></i>
                            </div>
                            <div class="notif-content">
                                <div class="notif-text">
                                    <strong class="notif-username"><?php echo h($n['username']); ?></strong>
                                    <?= t('notifications.replied_to_your_post', [], 'replied to your post') ?>
                                    <span class="notif-thread-title">"<?php echo h($n['thread_title']); ?>"</span>
                                </div>
                                <div class="notif-date">
                                    <i class="far fa-clock"></i> <?php echo date("d.m.Y - H:i", strtotime($n['created_at'])); ?>
                                </div>
                            </div>
                        </div>

                        <form method="POST" class="notif-delete-form">
                            <input type="hidden" name="csrf_token"   value="<?php echo generateToken(); ?>">
                            <input type="hidden" name="delete_notif" value="<?php echo (int)$n['id']; ?>">
                            <button type="submit" class="notif-delete-btn">
                                <i class="fas fa-times"></i>
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="notif-empty">
                <i class="fas fa-dove notif-empty-icon"></i>
                <p class="notif-empty-text"><?= t('notifications.empty', [], 'There are no notifications.') ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>
