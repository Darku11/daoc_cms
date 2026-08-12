<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) { exit; }

$_ep_thread_slug = $post_data['thread_slug'] ?? ('thread-' . (int)$post_data['thread_id']);
$_ep_thread_url  = "index.php?p=viewthread&slug=" . urlencode($_ep_thread_slug);

$polls_enabled        = $polls_enabled        ?? false;
$is_first_post        = $is_first_post        ?? false;
$poll                 = $poll                 ?? null;
$poll_options         = $poll_options         ?? [];
$poll_locked          = $poll_locked          ?? false;
$can_force_edit_poll  = $can_force_edit_poll  ?? false;
$ep_show_poll_box     = $polls_enabled && $is_first_post;
$ep_poll_readonly     = $ep_show_poll_box && $poll && $poll_locked && !$can_force_edit_poll;
$ep_poll_editable     = $ep_show_poll_box && !$ep_poll_readonly;
?>
<link href="assets/css/quill.snow.css" rel="stylesheet">

<div class="um-nexus-wrapper">

    <nav class="spk-breadcrumb" aria-label="Breadcrumb">
        <a href="index.php?p=spike">
            <i class="fas fa-comments"></i>
            <?= t('viewboard.breadcrumb_forum', [], 'Forum') ?>
        </a>
        <span class="spk-breadcrumb-sep"><i class="fas fa-chevron-right"></i></span>
        <a href="<?= h($_ep_thread_url) ?>">
            <?= t('editpost.breadcrumb_thread', [], 'Thread') ?>
        </a>
        <span class="spk-breadcrumb-sep"><i class="fas fa-chevron-right"></i></span>
        <span class="spk-breadcrumb-current">
            <?= t('editpost.breadcrumb_edit', [], 'Edit Post') ?> #<?= (int)$post_id ?>
        </span>
    </nav>

    <?php if (!empty($_GET['err'])): ?>
    <div class="nt-error">
        <i class="fas fa-exclamation-circle"></i>
        <?php
        $ep_errs = [
            'empty'                 => t('editpost.err_empty',                 [], 'Content cannot be empty.'),
            'forbidden_word'        => t('editpost.err_forbidden',             [], 'Your post contains a forbidden word.'),
            'db_error'              => t('editpost.err_db',                    [], 'A database error occurred. Please try again.'),
            'poll_min_options'      => t('editpost.err_poll_min_options',      [], 'Please provide at least 2 poll options.'),
            'poll_confirm_required' => t('editpost.err_poll_confirm_required', [], 'Please confirm that you understand existing votes will be deleted.'),
        ];
        echo h($ep_errs[$_GET['err']] ?? $_GET['err']);
        ?>
    </div>
    <?php endif; ?>

    <div class="spike-editor-box">
        <form method="POST"
              action="index.php?p=editpost&id=<?= (int)$post_id ?>"
              id="editpost-form">

            <input type="hidden" name="csrf_token" value="<?= generateToken() ?>">
            <input type="hidden" name="content"    id="content_input">

            <div class="spike-editor-field">
                <label class="spike-editor-label">
                    <i class="fas fa-edit ep-label-icon"></i>
                    <?= t('editpost.label_editor', [], 'Edit Content') ?>
                </label>
                <div id="quill-editor"></div>

                <?php if (!empty($smilies)): ?>
                <div class="vt-smilies-bar">
                    <?php foreach ($smilies as $s): ?>
                    <button type="button"
                            onclick="insertSmileyEP('<?= addslashes($s['code']) ?>')"
                            title="<?= h($s['title']) ?>">
                        <?= !empty($s['emoji']) ? $s['emoji'] : h($s['code']) ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="spike-editor-field ep-reason-field">
                <label class="spike-editor-label">
                    <i class="fas fa-pencil-alt ep-label-icon ep-label-icon--faint"></i>
                    <?= t('editpost.label_reason', [], 'Reason for edit') ?>
                    <span class="ep-optional">(<?= t('general_optional', [], 'optional') ?>)</span>
                </label>
                <input type="text"
                       name="edit_reason"
                       class="um-input ep-reason-input"
                       placeholder="<?= t('editpost.reason_placeholder', [], 'e.g. Fixed typo') ?>"
                       maxlength="255">
            </div>

            <?php if ($ep_show_poll_box): ?>
            <div class="spike-editor-field">

                <?php if ($ep_poll_readonly): ?>
                <label class="spike-editor-label">
                    <i class="fas fa-poll ep-label-icon ep-label-icon--faint"></i>
                    <?= t('editpost.poll_label', [], 'Poll') ?>
                </label>
                <div class="ep-poll-readonly-box">
                    <div class="ep-poll-readonly-question"><?= h($poll['question']) ?></div>
                    <?php foreach ($poll_options as $opt): ?>
                    <div class="ep-poll-readonly-option">
                        <i class="fas fa-circle ep-poll-readonly-dot"></i><?= h($opt['label']) ?>
                    </div>
                    <?php endforeach; ?>
                    <div class="ep-poll-locked-msg">
                        <i class="fas fa-lock"></i>
                        <?= t('editpost.poll_locked_msg', [], 'This poll already has votes and can no longer be edited.') ?>
                    </div>
                </div>

                <?php else: ?>

                <?php if (!$poll): ?>
                <button type="button" id="ep-poll-toggle-btn" class="spike-editor-btn spike-editor-btn--cancel" onclick="togglePollBuilderEP()">
                    <i class="fas fa-poll"></i> <?= t('editpost.add_poll', [], 'Add Poll') ?>
                </button>
                <?php endif; ?>

                <div id="ep-poll-builder" class="nt-poll-builder" style="<?= $poll ? '' : 'display:none;' ?>">

                    <?php if ($poll && $poll_locked && $can_force_edit_poll): ?>
                    <div class="ep-poll-admin-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?= t('editpost.poll_admin_warning', [], 'This poll already has votes. Saving changes will permanently delete all existing votes.') ?>
                        <label class="ep-poll-confirm-label">
                            <input type="checkbox" name="poll_confirm_reset" id="poll_confirm_reset" value="1">
                            <?= t('editpost.poll_confirm_reset', [], 'I understand, reset all votes') ?>
                        </label>
                    </div>
                    <?php endif; ?>

                    <div class="nt-poll-header">
                        <label class="nt-poll-question-label">
                            <i class="fas fa-poll"></i><?= t('editpost.poll_question_label', [], 'Poll Question') ?>
                        </label>
                        <?php if ($poll): ?>
                        <label class="ep-poll-remove-label">
                            <input type="checkbox" name="poll_remove" id="poll_remove" value="1" onchange="onPollRemoveToggleEP(this)">
                            <?= t('editpost.poll_remove', [], 'Remove poll') ?>
                        </label>
                        <?php else: ?>
                        <button type="button" onclick="discardPollBuilderEP()" title="<?= t('editpost.poll_discard', [], 'Discard') ?>"
                                class="nt-poll-discard-btn">
                            <i class="fas fa-times"></i>
                        </button>
                        <?php endif; ?>
                    </div>

                    <div id="ep-poll-fields">
                        <input type="text" name="poll_question" id="poll_question" maxlength="255"
                               class="um-input nt-poll-question-input"
                               value="<?= h($poll['question'] ?? '') ?>"
                               placeholder="<?= t('editpost.poll_question_placeholder', [], 'e.g. What feature should we add next?') ?>">

                        <div id="ep-poll-options">
                            <?php if (!empty($poll_options)): foreach ($poll_options as $i => $opt): ?>
                            <div class="nt-poll-option-row">
                                <input type="text" name="poll_options[]" class="um-input poll-opt-input-ep" maxlength="120"
                                       value="<?= h($opt['label']) ?>"
                                       placeholder="<?= t('editpost.poll_option', [], 'Option') ?> <?= $i + 1 ?>">
                                <button type="button" class="nt-poll-opt-remove" onclick="removePollOptionEP(this)" tabindex="-1"><i class="fas fa-minus-circle"></i></button>
                            </div>
                            <?php endforeach; else: ?>
                            <div class="nt-poll-option-row">
                                <input type="text" name="poll_options[]" class="um-input poll-opt-input-ep" maxlength="120"
                                       placeholder="<?= t('editpost.poll_option', [], 'Option') ?> 1">
                                <button type="button" class="nt-poll-opt-remove" onclick="removePollOptionEP(this)" tabindex="-1"><i class="fas fa-minus-circle"></i></button>
                            </div>
                            <div class="nt-poll-option-row">
                                <input type="text" name="poll_options[]" class="um-input poll-opt-input-ep" maxlength="120"
                                       placeholder="<?= t('editpost.poll_option', [], 'Option') ?> 2">
                                <button type="button" class="nt-poll-opt-remove" onclick="removePollOptionEP(this)" tabindex="-1"><i class="fas fa-minus-circle"></i></button>
                            </div>
                            <?php endif; ?>
                        </div>

                        <button type="button" onclick="addPollOptionEP()" class="spike-editor-btn spike-editor-btn--cancel nt-poll-add-btn">
                            <i class="fas fa-plus"></i> <?= t('editpost.poll_add_option', [], 'Add Option') ?>
                        </button>

                        <div class="nt-poll-footer">
                            <label class="nt-poll-multi-label">
                                <input type="checkbox" name="poll_multi" id="poll_multi" value="1" <?= !empty($poll['multi']) ? 'checked' : '' ?>>
                                <?= t('editpost.poll_multi_label', [], 'Allow multiple choices') ?>
                            </label>
                            <label class="nt-poll-ends-label">
                                <?= t('editpost.poll_ends_label', [], 'Ends') ?>
                                <input type="datetime-local" name="poll_ends_at" id="poll_ends_at" class="um-input nt-poll-ends-input"
                                       value="<?= !empty($poll['ends_at']) ? date('Y-m-d\TH:i', strtotime($poll['ends_at'])) : '' ?>">
                                <span class="nt-poll-optional">(<?= t('general_optional', [], 'optional') ?>)</span>
                            </label>
                        </div>

                        <div id="ep-poll-error" class="nt-poll-error" style="display:none;">
                            <i class="fas fa-exclamation-circle"></i> <span></span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="spike-editor-actions">
                <button type="submit" name="save_edit" class="spike-editor-btn spike-editor-btn--save">
                    <i class="fas fa-save"></i>
                    <?= t('editpost.btn_save', [], 'Save Changes') ?>
                </button>
                <a href="<?= h($_ep_thread_url) ?>#post-<?= (int)$post_id ?>"
                   class="spike-editor-btn spike-editor-btn--cancel">
                    <?= t('editpost.btn_cancel', [], 'Cancel') ?>
                </a>
            </div>
        </form>
    </div>
</div>

<script src="assets/js/quill.min.js"></script>
<script>
var epQuill = new Quill('#quill-editor', {
    theme: 'snow',
    modules: {
        toolbar: [
            ['bold', 'italic', 'underline', 'strike'],
            [{ list: 'ordered' }, { list: 'bullet' }, { indent: '-1' }, { indent: '+1' }],
            ['blockquote', 'code-block'],
            ['link'],
            ['clean']
        ]
    }
});

epQuill.clipboard.dangerouslyPasteHTML(<?= json_encode($post_data['content'] ?? '') ?>);

document.getElementById('editpost-form').addEventListener('submit', function(e) {
    var text = epQuill.getText().trim();
    if (!text || text.length === 0) {
        e.preventDefault();
        document.getElementById('quill-editor').classList.add('quill-error');
        epQuill.focus();
        return;
    }
    document.getElementById('quill-editor').classList.remove('quill-error');
    document.getElementById('content_input').value = epQuill.root.innerHTML;

    const pollBox    = document.getElementById('ep-poll-builder');
    const pollRemove = document.getElementById('poll_remove');
    if (pollBox && pollBox.style.display !== 'none' && !(pollRemove && pollRemove.checked)) {
        const pollQ    = document.getElementById('poll_question')?.value.trim() || '';
        const pollOpts = [...document.querySelectorAll('.poll-opt-input-ep')].map(i => i.value.trim()).filter(v => v.length > 0);
        const pollErr  = document.getElementById('ep-poll-error');
        if (pollQ && pollOpts.length < 2) {
            e.preventDefault();
            pollErr.querySelector('span').textContent = '<?= addslashes(t('editpost.err_poll_min_options',[],'Please provide at least 2 poll options.')) ?>';
            pollErr.style.display = 'block';
            return;
        }
        if (pollErr) pollErr.style.display = 'none';
    }

    const confirmReset = document.getElementById('poll_confirm_reset');
    if (confirmReset && !confirmReset.checked) {
        const wantsPollChange = (document.getElementById('poll_question')?.value.trim() !== '') || (pollRemove && pollRemove.checked);
        if (wantsPollChange) {
            e.preventDefault();
            alert('<?= addslashes(t('editpost.err_poll_confirm_required',[],'Please confirm that you understand existing votes will be deleted.')) ?>');
            return;
        }
    }
});

epQuill.on('text-change', function() {
    document.getElementById('quill-editor').classList.remove('quill-error');
});

function insertSmileyEP(code) {
    var range = epQuill.getSelection(true);
    epQuill.insertText(range ? range.index : epQuill.getLength(), ' ' + code + ' ');
}

function togglePollBuilderEP() {
    const box = document.getElementById('ep-poll-builder');
    const btn = document.getElementById('ep-poll-toggle-btn');
    if (!box) return;
    const opening = box.style.display === 'none';
    box.style.display = opening ? 'block' : 'none';
    if (btn) btn.style.display = opening ? 'none' : '';
    if (opening) document.getElementById('poll_question')?.focus();
}

function discardPollBuilderEP() {
    const box = document.getElementById('ep-poll-builder');
    const btn = document.getElementById('ep-poll-toggle-btn');
    if (!box) return;
    document.getElementById('poll_question').value = '';
    document.querySelectorAll('.poll-opt-input-ep').forEach((el, i) => {
        if (i > 1) el.closest('.nt-poll-option-row')?.remove();
        else el.value = '';
    });
    const multiBox = document.getElementById('poll_multi'); if (multiBox) multiBox.checked = false;
    const endsAt   = document.getElementById('poll_ends_at'); if (endsAt) endsAt.value = '';
    const errBox   = document.getElementById('ep-poll-error'); if (errBox) errBox.style.display = 'none';
    box.style.display = 'none';
    if (btn) btn.style.display = '';
}

function addPollOptionEP() {
    const wrap = document.getElementById('ep-poll-options');
    if (!wrap || wrap.children.length >= 10) return;
    const n = wrap.children.length + 1;
    const row = document.createElement('div');
    row.className = 'nt-poll-option-row';
    row.innerHTML = '<input type="text" name="poll_options[]" class="um-input poll-opt-input-ep" maxlength="120" placeholder="<?= addslashes(t('editpost.poll_option',[],'Option')) ?> ' + n + '">'
        + '<button type="button" class="nt-poll-opt-remove" onclick="removePollOptionEP(this)" tabindex="-1"><i class="fas fa-minus-circle"></i></button>';
    wrap.appendChild(row);
}

function removePollOptionEP(btn) {
    const wrap = document.getElementById('ep-poll-options');
    if (!wrap || wrap.children.length <= 2) return;
    btn.closest('.nt-poll-option-row')?.remove();
}

function onPollRemoveToggleEP(checkbox) {
    const fields = document.getElementById('ep-poll-fields');
    if (!fields) return;
    fields.style.opacity = checkbox.checked ? '0.35' : '';
    fields.querySelectorAll('input').forEach(i => { i.disabled = checkbox.checked; });
}
</script>