<?php
if (!defined('IN_CMS') && !defined('IN_ACP')) exit;

if (!isset($userPriv))      $userPriv      = (int)($_SESSION['priv_level'] ?? 0);
if (!isset($currentUserId)) $currentUserId = (int)($_SESSION['user_id']    ?? 0);


$_acp_auth = (defined('IN_ACP') && isset($userPriv) && $userPriv >= 3);
$_cms_auth = (isset($can_edit) && $can_edit);
if (!$_acp_auth && !$_cms_auth) {
    echo "<div class='acp-empty'>" . t('faq_admin.access_denied', [], 'Access Denied. Insufficient Privileges.') . "</div>";
    return;
}

$form_action = defined('IN_ACP') ? 'acp.php?s=faq_admin' : 'index.php?p=faq_admin';
$edit_base   = defined('IN_ACP') ? 'acp.php?s=faq_admin' : 'index.php?p=faq_admin';

$faq_message  = '';
$faq_msg_type = 'success';

if (isset($_POST['save_faq'])) {
    checkToken($_POST['csrf_token'] ?? '');
    $id   = (int)$_POST['id'];
    $cat  = trim($_POST['category']   ?? '');
    $ques = trim($_POST['question']   ?? '');
    $ans  = trim($_POST['answer']     ?? '');
    $sort = (int)$_POST['sort_order'];

    if ($id > 0) {
        $db->prepare("UPDATE faq SET category = ?, question = ?, answer = ?, sort_order = ? WHERE id = ?")
           ->execute([$cat, $ques, $ans, $sort, $id]);
        aldhran_log("FAQ_UPDATE", "Updated FAQ entry #$id", $_SESSION['user_id']);
        $faq_message = t('faq_admin.msg_updated', [], 'Entry updated successfully.');
    } else {
        $db->prepare("INSERT INTO faq (category, question, answer, sort_order) VALUES (?, ?, ?, ?)")
           ->execute([$cat, $ques, $ans, $sort]);
        $new_id = $db->lastInsertId();
        aldhran_log("FAQ_CREATE", "Created new FAQ entry #$new_id", $_SESSION['user_id']);
        $faq_message = t('faq_admin.msg_created', [], 'New FAQ created successfully.');
    }
}

if (isset($_POST['delete_faq'])) {
    checkToken($_POST['csrf_token'] ?? '');
    $del_id = (int)$_POST['delete_faq'];
    $db->prepare("DELETE FROM faq WHERE id = ?")->execute([$del_id]);
    aldhran_log("FAQ_DELETE", "Deleted FAQ entry #$del_id", $_SESSION['user_id']);
    $faq_message  = t('faq_admin.msg_deleted', [], 'Entry deleted.');
    $faq_msg_type = 'warn';
}

$edit_data = ['id' => 0, 'category' => '', 'question' => '', 'answer' => '', 'sort_order' => 0];
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM faq WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $res = $stmt->fetch();
    if ($res) $edit_data = $res;
}

$all_faqs = $db->query("SELECT * FROM faq ORDER BY category, sort_order ASC")->fetchAll();
$is_edit  = $edit_data['id'] > 0;
?>

<?php if ($faq_message): ?>
    <div class="faq-msg <?php echo $faq_msg_type; ?>">
        <i class="fas fa-<?php echo $faq_msg_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
        <?php echo h($faq_message); ?>
    </div>
<?php endif; ?>

<div class="faq-layout">

    <!-- ── Form Panel ── -->
    <div class="faq-panel">
        <div class="faq-panel-header">
            <span>
                <i class="fas fa-<?php echo $is_edit ? 'edit' : 'plus'; ?>"></i>
                <?php echo $is_edit
                    ? t('faq_admin.form_title_edit', ['{id}' => $edit_data['id']], 'Edit FAQ Entry #{id}')
                    : t('faq_admin.form_title_new', [], 'Create New FAQ Scroll'); ?>
            </span>
            <?php if ($is_edit): ?>
                <a href="<?php echo $edit_base; ?>" class="faq-cancel">
                    <?php echo t('faq_admin.btn_cancel_edit', [], 'Cancel Edit'); ?>
                </a>
            <?php endif; ?>
        </div>
        <form method="POST" action="<?php echo $form_action; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo generateToken(); ?>">
            <input type="hidden" name="id"         value="<?php echo (int)$edit_data['id']; ?>">
            <div class="faq-panel-body">
                <div class="faq-grid-2 faq-field">
                    <div>
                        <label class="faq-label"><?php echo t('faq_admin.label_category', [], 'Category'); ?></label>
                        <input type="text" name="category" class="faq-input"
                               required value="<?php echo h($edit_data['category']); ?>">
                    </div>
                    <div>
                        <label class="faq-label"><?php echo t('faq_admin.label_sort_order', [], 'Sort Order'); ?></label>
                        <input type="number" name="sort_order" class="faq-input"
                               value="<?php echo (int)$edit_data['sort_order']; ?>">
                    </div>
                </div>
                <div class="faq-field">
                    <label class="faq-label"><?php echo t('faq_admin.label_question', [], 'Question'); ?></label>
                    <input type="text" name="question" class="faq-input"
                           required value="<?php echo h($edit_data['question']); ?>">
                </div>
                <div class="faq-field">
                    <label class="faq-label"><?php echo t('faq_admin.label_answer', [], 'Answer'); ?></label>
                    <textarea name="answer" class="faq-input faq-textarea"
                              required><?php echo h($edit_data['answer']); ?></textarea>
                </div>
            </div>
            <div class="faq-actions">
                <button type="submit" name="save_faq" class="faq-btn-save">
                    <i class="fas fa-save"></i> <?php echo t('faq_admin.btn_save', [], 'Save Scroll'); ?>
                </button>
            </div>
        </form>
    </div>

    <!-- ── List Panel ── -->
    <div class="faq-panel">
        <div class="faq-panel-header">
            <span><i class="fas fa-list"></i> <?php echo t('faq_admin.existing_title', [], 'Existing Library Entries'); ?></span>
            <span style="color:#2a2a2a; font-size:0.85em;"><?php echo count($all_faqs); ?> entries</span>
        </div>
        <table class="faq-table">
            <thead>
                <tr>
                    <th style="width:130px;"><?php echo t('faq_admin.col_category', [], 'Category'); ?></th>
                    <th><?php echo t('faq_admin.col_question', [], 'Question'); ?></th>
                    <th style="width:70px; text-align:right; padding-right:14px;"><?php echo t('faq_admin.col_actions', [], 'Actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($all_faqs): foreach ($all_faqs as $row): ?>
                <tr>
                    <td><span class="faq-cat-badge"><?php echo h($row['category']); ?></span></td>
                    <td><span class="faq-question"><?php echo h($row['question']); ?></span></td>
                    <td style="text-align:right; padding-right:10px; white-space:nowrap;">
                        <a href="<?php echo $edit_base; ?>&edit=<?php echo (int)$row['id']; ?>"
                           class="faq-btn-icon" title="Edit">
                            <i class="fas fa-edit"></i>
                        </a>
                        <form method="POST" action="<?php echo $form_action; ?>" style="display:inline;"
                              onsubmit="return confirm('<?php echo t('faq_admin.confirm_delete', [], 'Do you want to delete this entry?'); ?>')">
                            <input type="hidden" name="csrf_token" value="<?php echo generateToken(); ?>">
                            <input type="hidden" name="delete_faq" value="<?php echo (int)$row['id']; ?>">
                            <button type="submit" class="faq-btn-icon del" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr>
                    <td colspan="3" style="padding:40px; text-align:center; color:#222; font-family:'Cinzel',serif; font-size:0.7em; letter-spacing:2px;">
                        <?php echo t('faq_admin.no_entries', [], 'No entries found.'); ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>
<?php require_once __DIR__ . '/acp_faq_admin_ai_extension.php'; ?>