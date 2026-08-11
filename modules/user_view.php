<?php
if (!defined('IN_CMS')) { exit; }

$target_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$userPriv = (int)($_SESSION['priv_level'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_block']) && $currentUserId > 0) {
    if (function_exists('checkToken')) checkToken($_POST['csrf_token'] ?? '');
    $block_target = (int)$_POST['target_id'];
    $chk = $db->prepare("SELECT priv_level FROM users WHERE id = ?");
    $chk->execute([$block_target]);
    $target_priv = (int)$chk->fetchColumn();
    if ($target_priv < 2 && $block_target !== $currentUserId) {
        $stmt_b = $db->prepare("SELECT 1 FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?");
        $stmt_b->execute([$currentUserId, $block_target]);
        if ($stmt_b->fetchColumn()) {
            $db->prepare("DELETE FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?")->execute([$currentUserId, $block_target]);
        } else {
            $db->prepare("INSERT IGNORE INTO user_blocks (blocker_id, blocked_id) VALUES (?, ?)")->execute([$currentUserId, $block_target]);
        }
    }
    header("Location: index.php?p=user&id=" . $target_id);
    exit;
}

if ($currentUserId > 0 && $userPriv < 2) {
    $stmt_check = $db->prepare("SELECT 1 FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?");
    $stmt_check->execute([$target_id, $currentUserId]);
    if ($stmt_check->fetchColumn()) {
        echo "<div class='admin-box uv-blocked-msg'>The soul you seek has departed or never existed in these chronicles.</div>";
        return;
    }
}

$stmt_u = $db->prepare("SELECT id, username, priv_level, user_title, languages, description, avatar_url FROM users WHERE id = ? LIMIT 1");
$stmt_u->execute([$target_id]);
$u = $stmt_u->fetch();

if (!$u) {
    echo "<div class='admin-box uv-blocked-msg'>The soul you seek has departed or never existed in these chronicles.</div>";
    return;
}

$stmt_realm = $db->prepare("SELECT DISTINCT Realm FROM dolcharacters WHERE AccountName = ?");
$stmt_realm->execute([$u['username']]);
$realm_data = $stmt_realm->fetchAll(PDO::FETCH_COLUMN);

$realm_map = [
    1 => "<span class='uv-realm uv-realm--alb'>Albion</span>",
    2 => "<span class='uv-realm uv-realm--mid'>Midgard</span>",
    3 => "<span class='uv-realm uv-realm--hib'>Hibernia</span>"
];

$realms = [];
foreach ($realm_data as $r_id) {
    if (isset($realm_map[$r_id])) {
        $realms[] = $realm_map[$r_id];
    }
}
$realm_list = !empty($realms) ? implode(", ", $realms) : "No realm chosen yet";

$is_blocked_by_me = false;
if ($currentUserId > 0) {
    $stmt_check_me = $db->prepare("SELECT 1 FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?");
    $stmt_check_me->execute([$currentUserId, $target_id]);
    $is_blocked_by_me = (bool)$stmt_check_me->fetchColumn();
}
?>

<div class="admin-container">
    <div class="admin-box uv-box">
        <div class="uv-layout">

            <div class="uv-avatar-col">
                <?php if (!empty($u['avatar_url'])): ?>
                    <img src="<?php echo h($u['avatar_url']); ?>" class="uv-avatar" alt="">
                <?php else: ?>
                    <div class="uv-avatar-placeholder">
                        <i class="fas fa-user-circle uv-avatar-icon"></i>
                    </div>
                <?php endif; ?>

                <?php if ($currentUserId > 0 && $currentUserId !== (int)$u['id']): ?>
                <div class="uv-actions">
                    <a href="index.php?p=private_messages&pm_action=send&to=<?= urlencode($u['username']) ?>"
                       class="uv-action-btn">
                        <i class="fas fa-envelope"></i> <?= t('user.send_pm', [], 'Message') ?>
                    </a>

                    <?php if ((int)$u['priv_level'] < 2): ?>
                    <div class="uv-action-item">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= function_exists('generateToken') ? generateToken() : ($_SESSION['csrf_token'] ?? '') ?>">
                            <input type="hidden" name="toggle_block" value="1">
                            <input type="hidden" name="target_id" value="<?= (int)$u['id'] ?>">
                            <button type="submit" class="uv-action-btn uv-action-btn--form">
                                <i class="fas <?= $is_blocked_by_me ? 'fa-user-check' : 'fa-user-slash' ?>"></i>
                                <?= $is_blocked_by_me ? t('user.unblock', [], 'Unblock') : t('user.block', [], 'Block') ?>
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="uv-info">
                <h1 class="uv-username"><?php echo h($u['username']); ?></h1>

                <?php if (!empty($u['user_title'])): ?>
                <div class="uv-title"><?php echo h($u['user_title']); ?></div>
                <?php endif; ?>

                <div class="uv-fields">
                    <div class="uv-field">
                        <label class="um-label uv-field-label">Active in Realms</label>
                        <div class="uv-field-val uv-field-val--realms"><?php echo $realm_list; ?></div>
                    </div>
                    <div class="uv-field">
                        <label class="um-label uv-field-label">Known Tongues</label>
                        <div class="uv-field-val"><?php echo !empty($u['languages']) ? h($u['languages']) : "Common Tongue"; ?></div>
                    </div>
                    <div class="uv-field uv-field--bio">
                        <label class="um-label uv-field-label">Biography</label>
                        <div class="uv-bio">
                            <?php echo !empty($u['description']) ? h($u['description']) : "This user has not yet written a biography."; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>