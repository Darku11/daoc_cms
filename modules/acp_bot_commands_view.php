<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_ACP')) exit;
if ($userPriv < 5) { echo '<div class="acp-empty">Access denied. Super Admin required.</div>'; return; }

$msg_ok  = '';
$msg_err = '';

// ── POST: save command settings ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_commands'])) {
    checkToken($_POST['csrf_token'] ?? '');

    $commands = $_POST['commands'] ?? [];
    $stmt = $db->prepare("
        UPDATE cms_bot_commands
        SET is_enabled       = :enabled,
            min_authlevel    = :min_al,
            cooldown_seconds = :cooldown,
            requires_confirm = :confirm
        WHERE id = :id
    ");

    foreach ($commands as $id => $cfg) {
        $id = (int)$id;
        if (!$id) continue;
        $stmt->execute([
            ':enabled'  => isset($cfg['enabled']) ? 1 : 0,
            ':min_al'   => max(1, min(5, (int)($cfg['min_authlevel'] ?? 1))),
            ':cooldown' => max(0, (int)($cfg['cooldown'] ?? 0)),
            ':confirm'  => isset($cfg['requires_confirm']) ? 1 : 0,
            ':id'       => $id,
        ]);
    }

    aldhran_log("BOT_COMMANDS_UPDATE", "Bot command settings updated.", $currentUserId);
    $msg_ok = "Command settings saved.";

    // ── BOT TRIGGER: reload commands ──────────────────────────
    if (isset($GLOBALS['botDispatcher'])) {
        try {
            $GLOBALS['botDispatcher']->onCommandsUpdated($currentUserId);
        } catch (\Throwable $e) {
            error_log("BotDispatcher commands reload trigger failed: " . $e->getMessage());
        }
    }
}

// ── POST: add override ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_override'])) {
    checkToken($_POST['csrf_token'] ?? '');

    $commandId  = (int)($_POST['override_command_id'] ?? 0);
    $scope      = in_array($_POST['override_scope'] ?? '', ['user','authlevel']) ? $_POST['override_scope'] : null;
    $scopeValue = (int)($_POST['override_scope_value'] ?? 0);
    $isAllowed  = ($_POST['override_allowed'] ?? '') === '1' ? 1 : 0;

    if ($commandId && $scope && $scopeValue > 0) {
        $stmt = $db->prepare("
            INSERT INTO cms_bot_command_permissions
                (command_id, scope, scope_value, is_allowed, set_by)
            VALUES (:cid, :scope, :val, :allowed, :by)
            ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed), set_by = VALUES(set_by)
        ");
        $ok = $stmt->execute([
            ':cid'     => $commandId,
            ':scope'   => $scope,
            ':val'     => $scopeValue,
            ':allowed' => $isAllowed,
            ':by'      => $currentUserId,
        ]);

        if ($ok) {
            $msg_ok = 'Override added.';
            // ── BOT TRIGGER: permissions changed → reload ─────
            if (isset($GLOBALS['botDispatcher'])) {
                try { $GLOBALS['botDispatcher']->onCommandsUpdated($currentUserId); }
                catch (\Throwable $e) { error_log("BotDispatcher override trigger: " . $e->getMessage()); }
            }
        } else {
            $msg_err = 'Failed to add override.';
        }
    } else {
        $msg_err = 'Invalid override parameters.';
    }
}

// ── POST: delete override ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_override'])) {
    checkToken($_POST['csrf_token'] ?? '');
    $oid = (int)$_POST['override_id'];
    if ($oid) {
        $db->prepare("DELETE FROM cms_bot_command_permissions WHERE id = ?")->execute([$oid]);
        $msg_ok = 'Override removed.';
        // ── BOT TRIGGER ───────────────────────────────────────
        if (isset($GLOBALS['botDispatcher'])) {
            try { $GLOBALS['botDispatcher']->onCommandsUpdated($currentUserId); }
            catch (\Throwable $e) { error_log("BotDispatcher delete-override trigger: " . $e->getMessage()); }
        }
    }
}

// ── Load data ───────────────────────────────────────────────────
$commands = $db->query("SELECT * FROM cms_bot_commands ORDER BY category, command")->fetchAll();

$overrides_raw = $db->query("
    SELECT p.*, c.command, c.label AS cmd_label, u.username AS set_by_name
    FROM   cms_bot_command_permissions p
    JOIN   cms_bot_commands c ON c.id = p.command_id
    LEFT JOIN users u ON u.id = p.set_by
    ORDER  BY c.category, c.command, p.scope, p.scope_value
")->fetchAll();

$overrides   = [];
foreach ($overrides_raw as $o) {
    $overrides[$o['command_id']][] = $o;
}

$by_category = [];
foreach ($commands as $cmd) {
    $by_category[$cmd['category']][] = $cmd;
}

$csrf        = generateToken();
$priv_levels = [1 => 'Player', 2 => 'Associate', 3 => 'GM', 4 => 'Admin', 5 => 'Super Admin'];
$categories  = ['server' => 'Server Commands', 'player' => 'Player Commands', 'ai' => 'AI Commands'];
?>

<div class="acp-s-decf5232">

<?php if ($msg_ok): ?>
    <div class="bc-msg-ok"><i class="fas fa-check-circle"></i> <?= h($msg_ok) ?></div>
<?php endif; ?>
<?php if ($msg_err): ?>
    <div class="bc-msg-err"><i class="fas fa-exclamation-circle"></i> <?= h($msg_err) ?></div>
<?php endif; ?>

<form method="POST" action="acp.php?s=bot_commands">
<input type="hidden" name="csrf_token"    value="<?= $csrf ?>">
<input type="hidden" name="save_commands" value="1">

<?php
$cat_icons = ['server' => 'fa-server', 'player' => 'fa-user', 'ai' => 'fa-robot'];
foreach ($categories as $catKey => $catLabel):
    if (empty($by_category[$catKey])) continue;
?>
    <div class="bc-section-title">
        <i class="fas <?= $cat_icons[$catKey] ?? 'fa-circle' ?> bc-cat-icon"></i>
        <?= h($catLabel) ?>
    </div>
    <div class="acp-s-df74e8d8">
    <table class="bc-table">
        <thead>
            <tr>
                <th class="acp-s-df7d1005">Command</th>
                <th>Description</th>
                <th class="acp-s-1cec9531">Active</th>
                <th class="acp-s-088cf005">Min AuthLevel</th>
                <th class="acp-s-c78f4943">Cooldown</th>
                <th class="acp-s-b82fad01">Confirm</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($by_category[$catKey] as $cmd): ?>
            <tr>
                <td><div class="bc-cmd-name">/<?= h($cmd['command']) ?></div></td>
                <td><div class="bc-cmd-desc"><?= h($cmd['description'] ?? '') ?></div></td>
                <td class="acp-s-13076d95">
                    <label class="bc-toggle"><div class="bc-toggle-track">
                        <input type="checkbox" name="commands[<?= $cmd['id'] ?>][enabled]" value="1" <?= $cmd['is_enabled'] ? 'checked' : '' ?>>
                        <span class="bc-toggle-slider"></span>
                    </div></label>
                </td>
                <td>
                    <select name="commands[<?= $cmd['id'] ?>][min_authlevel]" class="bc-select-sm">
                        <?php foreach ($priv_levels as $lvl => $lbl): ?>
                            <option value="<?= $lvl ?>" <?= (int)$cmd['min_authlevel'] === $lvl ? 'selected' : '' ?>><?= $lvl ?> – <?= h($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <input type="number" name="commands[<?= $cmd['id'] ?>][cooldown]" class="bc-input-sm"
                           value="<?= (int)$cmd['cooldown_seconds'] ?>" min="0" max="3600"> s
                </td>
                <td class="acp-s-13076d95">
                    <label class="bc-toggle"><div class="bc-toggle-track">
                        <input type="checkbox" name="commands[<?= $cmd['id'] ?>][requires_confirm]" value="1" <?= $cmd['requires_confirm'] ? 'checked' : '' ?>>
                        <span class="bc-toggle-slider"></span>
                    </div></label>
                </td>
            </tr>
            <tr>
                <td colspan="6" class="acp-s-f3afcb2d">
                    <div class="bc-overrides">
                        <div class="bc-overrides-title"><i class="fas fa-user-shield acp-s-e5876b3f"></i>Permission Overrides</div>
                        <?php if (!empty($overrides[$cmd['id']])): ?>
                            <?php foreach ($overrides[$cmd['id']] as $ov): ?>
                                <span class="bc-override-pill <?= $ov['is_allowed'] ? 'allowed' : 'denied' ?>">
                                    <i class="fas <?= $ov['is_allowed'] ? 'fa-check' : 'fa-ban' ?>"></i>
                                    <?= $ov['scope'] === 'user'
                                        ? 'User: ' . h($ov['scope_value'])
                                        : 'Level ' . h($ov['scope_value']) . ' (' . h($priv_levels[$ov['scope_value']] ?? '?') . ')' ?>
                                    <button type="button" class="bc-override-del" title="Remove override" onclick="bcSubmitDeleteOverride(<?= (int)$ov['id'] ?>)">×</button>
                                </span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="acp-s-b2564a30">No overrides – default min_authlevel applies.</span>
                        <?php endif; ?>

                        <div class="bc-add-override">
                            <label>Scope</label>
                            <select id="ov-scope-<?= (int)$cmd['id'] ?>">
                                <option value="authlevel">AuthLevel</option>
                                <option value="user">User ID</option>
                            </select>
                            <label>Value</label>
                            <input type="number" id="ov-value-<?= (int)$cmd['id'] ?>" placeholder="Level or User ID" min="1" class="acp-s-729192c0" >
                            <label><input type="checkbox" id="ov-allowed-<?= (int)$cmd['id'] ?>" checked> Allowed</label>
                            <button type="button" class="bc-btn-add" onclick="bcSubmitAddOverride(<?= (int)$cmd['id'] ?>)"><i class="fas fa-plus"></i> Add</button>
                        </div>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endforeach; ?>

<div class="bc-save-bar">
    <button type="submit" class="bc-btn-save"><i class="fas fa-save"></i> Save Command Settings</button>
</div>
</form>

<!-- Shared out-of-band form for override actions (kept outside the main form
     above to avoid invalid nested <form> elements) -->
<form method="POST" action="acp.php?s=bot_commands" id="bc-override-form" class="acp-s-cb458930">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="add_override" id="ov-f-add" value="">
    <input type="hidden" name="delete_override" id="ov-f-delete" value="">
    <input type="hidden" name="override_id" id="ov-f-id" value="">
    <input type="hidden" name="override_command_id" id="ov-f-cmdid" value="">
    <input type="hidden" name="override_scope" id="ov-f-scope" value="">
    <input type="hidden" name="override_scope_value" id="ov-f-scopevalue" value="">
    <input type="hidden" name="override_allowed" id="ov-f-allowed" value="">
</form>
<script>
function bcSubmitDeleteOverride(id) {
    document.getElementById('ov-f-delete').value = '1';
    document.getElementById('ov-f-id').value = id;
    document.getElementById('bc-override-form').submit();
}
function bcSubmitAddOverride(cmdId) {
    const scope   = document.getElementById('ov-scope-' + cmdId).value;
    const value   = document.getElementById('ov-value-' + cmdId).value;
    const allowed = document.getElementById('ov-allowed-' + cmdId).checked;
    if (!value || value < 1) { alert('Enter a valid Level or User ID.'); return; }
    document.getElementById('ov-f-add').value = '1';
    document.getElementById('ov-f-cmdid').value = cmdId;
    document.getElementById('ov-f-scope').value = scope;
    document.getElementById('ov-f-scopevalue').value = value;
    document.getElementById('ov-f-allowed').value = allowed ? '1' : '';
    document.getElementById('bc-override-form').submit();
}
</script>

</div>