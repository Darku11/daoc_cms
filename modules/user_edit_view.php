<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) { exit; }

if (!isset($_SESSION['user_id']) || (int)$_SESSION['priv_level'] < 4) {
    header("Location: index.php?p=login");
    exit;
}

$target_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($target_id <= 0) {
    header("Location: index.php?p=um");
    exit;
}

$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$target_id]);
$udata = $stmt->fetch();

if (!$udata) {
    echo "<div class='ue-not-found'>" . t('user_edit.not_found', ['id' => $target_id], "User #{$target_id} not found.") . "</div>";
    return;
}
?>

<div class="ue-container">

    <div class="ue-header">
        <h2 class="ue-title">Edit User Account</h2>
        <a href="index.php?p=um" class="ue-back-link">Back to List</a>
    </div>

    <form method="POST" action="index.php?p=um">
        <input type="hidden" name="csrf_token" value="<?php echo generateToken(); ?>">
        <input type="hidden" name="target_id" value="<?php echo $target_id; ?>">
        <input type="hidden" name="um_action" value="update_full">

        <div class="ue-field">
            <label class="ue-label">Username</label>
            <input type="text" value="<?php echo h($udata['username']); ?>" disabled class="ue-input ue-input--disabled">
        </div>

        <div class="ue-field">
            <label class="ue-label">User Title</label>
            <input type="text" name="user_title" value="<?php echo h($udata['user_title'] ?? ''); ?>"
                   placeholder="e.g. Guardian, Merchant..."
                   class="ue-input">
        </div>

        <div class="ue-grid-2">
            <div>
                <label class="ue-label">Privilege Level</label>
                <select name="priv_level" class="ue-select">
                    <option value="1" <?php echo ($udata['priv_level'] == 1) ? 'selected' : ''; ?>>Level 1 (User)</option>
                    <option value="2" <?php echo ($udata['priv_level'] == 2) ? 'selected' : ''; ?>>Level 2 (Support)</option>
                    <option value="3" <?php echo ($udata['priv_level'] == 3) ? 'selected' : ''; ?>>Level 3 (GM)</option>
                    <option value="4" <?php echo ($udata['priv_level'] == 4) ? 'selected' : ''; ?>>Level 4 (Admin)</option>
                </select>
            </div>
            <div>
                <label class="ue-label">Standing (0-4)</label>
                <input type="number" name="standing" min="0" max="4" value="<?php echo (int)$udata['standing']; ?>"
                       class="ue-input">
            </div>
        </div>

        <div class="ue-verify-box">
            <label class="ue-verify-label">
                <input type="checkbox" name="is_verified" <?php echo $udata['is_verified'] ? 'checked' : ''; ?>>
                <span>Account Email Verified</span>
            </label>
        </div>

        <button type="submit" class="ue-submit">Save Account Changes</button>
    </form>

    <div class="ue-pw-section">
        <h3 class="ue-pw-title">Reset User Password</h3>
        <form method="POST" action="modules/um_sync_worker.php">
            <input type="hidden" name="csrf_token" value="<?php echo generateToken(); ?>">
            <input type="hidden" name="target_id" value="<?php echo $target_id; ?>">
            <input type="hidden" name="um_action" value="update_full">
            <div class="ue-pw-row">
                <input type="password" name="u_new_password" placeholder="Enter new password" required class="ue-input">
                <button type="submit" class="ue-pw-btn">Update</button>
            </div>
        </form>
    </div>
</div>