<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) { exit; }

$nt_attach_enabled = $attachments_enabled ?? false;
$nt_max_size       = $max_attach_size       ?? 2097152;
$nt_allowed_mimes  = $allowed_mimes         ?? ['image/jpeg','image/png','image/gif'];
$nt_polls_enabled  = $polls_enabled         ?? false;

$smart_keywords = [
    1 => ['announcement','ankündigung','news','update','patch','hotfix','release','wichtig','changelog'],
    2 => ['guide','tutorial','howto','how to','anleitung','leitfaden','tips','tipp','trick'],
    3 => ['bug','fehler','error','crash','broken','fix','issue','problem','defekt'],
    4 => ['wip','work in progress','in arbeit','entwurf','draft','concept','konzept','idea','idee'],
    6 => ['solved','gelöst','fixed','lösung','solution'],
];
$smart_js    = json_encode($smart_keywords, JSON_UNESCAPED_UNICODE);
$available_prefixes = $available_prefixes ?? [];
$draft_key   = 'spk_draft_board_' . (int)$board_id;
$title_max   = 120;
$csrf_token  = generateToken();
$board_title = $board['title'] ?? 'Board';
?>
<link href="assets/css/quill.snow.css" rel="stylesheet">

<div class="um-nexus-wrapper">

    <nav class="spk-breadcrumb" aria-label="Breadcrumb">
        <a href="index.php?p=spike"><i class="fas fa-comments"></i> <?= t('viewboard.breadcrumb_forum',[],'Forum') ?></a>
        <span class="spk-breadcrumb-sep"><i class="fas fa-chevron-right"></i></span>
        <a href="index.php?p=viewboard&id=<?= (int)$board_id ?>"><?= h($board_title) ?></a>
        <span class="spk-breadcrumb-sep"><i class="fas fa-chevron-right"></i></span>
        <span class="spk-breadcrumb-current"><?= t('newthread.title',[],'New Thread') ?></span>
    </nav>

    <div class="nt-wrap">

        <?php if (!empty($_GET['err'])): ?>
        <div class="nt-error">
            <i class="fas fa-exclamation-circle"></i>
            <?php
            $errs = [
                'title_required'   => t('newthread.err_title',[],'A title is required.'),
                'content_required' => t('newthread.err_content',[],'Content cannot be empty.'),
                'forbidden_word'   => t('newthread.err_forbidden',[],'Your post contains a forbidden word.'),
                'spam_cooldown'    => t('newthread.err_cooldown',[],'Please wait before posting again.'),
                'poll_min_options' => t('newthread.err_poll_min_options',[],'Please provide at least 2 poll options.'),
            ];
            echo h($errs[$_GET['err']] ?? $_GET['err']);
            ?>
        </div>
        <?php endif; ?>

        <form method="POST"
              action="index.php?p=newthread&bid=<?= (int)$board_id ?>"
              enctype="multipart/form-data"
              id="newthread-form"
              autocomplete="off">

            <input type="hidden" name="csrf_token"            value="<?= $csrf_token ?>">
            <input type="hidden" name="thread_content_input" id="thread_content_input" value="">
            <input type="hidden" name="prefix_id"            id="prefix_id_input" value="">

            <div class="nt-title-wrap">
                <input type="text"
                       name="thread_title"
                       id="thread_title"
                       class="nt-title-input"
                       placeholder="<?= t('newthread.title_placeholder',[],'Title…') ?>"
                       maxlength="<?= $title_max ?>"
                       value="<?= h($_POST['thread_title'] ?? '') ?>"
                       autocomplete="off"
                       autofocus>
                <span class="nt-char-counter" id="nt-char-counter">0&thinsp;/&thinsp;<?= $title_max ?></span>
            </div>

            <div id="nt-similar-threads" class="nt-similar-threads" style="display:none;"></div>

            <?php if (!empty($available_prefixes)): ?>
            <div class="nt-prefix-row">
                <button type="button" class="nt-prefix-pill none-pill selected" data-pid="" onclick="selectPrefix(this,'')">
                    <i class="fas fa-minus nt-prefix-none-icon"></i>
                </button>
                <?php foreach ($available_prefixes as $pf): ?>
                <button type="button"
                        class="nt-prefix-pill"
                        data-pid="<?= (int)$pf['id'] ?>"
                        style="--pf-color:<?= h($pf['color']) ?>;"
                        onclick="selectPrefix(this,'<?= (int)$pf['id'] ?>')">
                    <?= h($pf['label']) ?>
                </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="nt-editor-wrap" id="nt-editor-wrap">
                <div id="quill-editor"></div>

                <?php if (!empty($smilies ?? [])): ?>
                <div class="nt-smilies-wrap">
                    <button type="button" class="nt-smilies-toggle"
                            onclick="this.parentNode.classList.toggle('open')" title="Smilies">
                        <i class="fas fa-smile"></i>
                    </button>
                    <div class="nt-smilies">
                        <?php foreach (($smilies ?? []) as $sm):
                            $safe_url = ltrim($sm['image_url'] ?? '', '/');
                        ?>
                        <span onclick="insertSmiley('<?= addslashes($sm['code']) ?>')"
                              title="<?= h($sm['title'] ?? $sm['code']) ?>"
                              class="nt-smiley">
                            <img src="<?= h($safe_url) ?>" alt="<?= h($sm['code']) ?>" class="spike-smiley">
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($nt_attach_enabled): ?>
                <div class="nt-attach-row">
                    <label class="nt-attach-label">
                        <i class="fas fa-paperclip nt-attach-icon"></i>
                        <?= t('newthread.attach',[],'Attach files') ?>
                        <input type="file" name="attachments[]" multiple
                               accept="<?= h(implode(',',$nt_allowed_mimes)) ?>"
                               style="display:none;"
                               onchange="updateAttachLabel(this)">
                    </label>
                    <div class="nt-attach-files" id="attach-label"></div>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($nt_polls_enabled): ?>
            <div class="nt-poll-section">
                <button type="button" id="nt-poll-toggle-btn" class="nt-btn nt-btn-cancel" onclick="togglePollBuilder()">
                    <i class="fas fa-poll"></i> <?= t('newthread.add_poll',[],'Add Poll') ?>
                </button>

                <div id="nt-poll-builder" class="nt-poll-builder" style="display:none;">
                    <div class="nt-poll-header">
                        <label class="nt-poll-question-label">
                            <i class="fas fa-poll"></i><?= t('newthread.poll_question_label',[],'Poll Question') ?>
                        </label>
                        <button type="button" onclick="discardPollBuilder()" title="<?= t('newthread.poll_discard',[],'Discard poll') ?>"
                                class="nt-poll-discard-btn">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>

                    <input type="text" name="poll_question" id="poll_question" maxlength="255"
                           class="um-input nt-poll-question-input"
                           placeholder="<?= t('newthread.poll_question_placeholder',[],'e.g. What feature should we add next?') ?>">

                    <div id="nt-poll-options">
                        <div class="nt-poll-option-row">
                            <input type="text" name="poll_options[]" class="um-input poll-opt-input" maxlength="120"
                                   placeholder="<?= t('newthread.poll_option',[],'Option') ?> 1">
                            <button type="button" class="nt-poll-opt-remove" onclick="removePollOption(this)" tabindex="-1"><i class="fas fa-minus-circle"></i></button>
                        </div>
                        <div class="nt-poll-option-row">
                            <input type="text" name="poll_options[]" class="um-input poll-opt-input" maxlength="120"
                                   placeholder="<?= t('newthread.poll_option',[],'Option') ?> 2">
                            <button type="button" class="nt-poll-opt-remove" onclick="removePollOption(this)" tabindex="-1"><i class="fas fa-minus-circle"></i></button>
                        </div>
                    </div>

                    <button type="button" onclick="addPollOption()" class="nt-btn nt-btn-cancel nt-poll-add-btn">
                        <i class="fas fa-plus"></i> <?= t('newthread.poll_add_option',[],'Add Option') ?>
                    </button>

                    <div class="nt-poll-footer">
                        <label class="nt-poll-multi-label">
                            <input type="checkbox" name="poll_multi" id="poll_multi" value="1">
                            <?= t('newthread.poll_multi_label',[],'Allow multiple choices') ?>
                        </label>
                        <label class="nt-poll-ends-label">
                            <?= t('newthread.poll_ends_label',[],'Ends') ?>
                            <input type="datetime-local" name="poll_ends_at" id="poll_ends_at" class="um-input nt-poll-ends-input">
                            <span class="nt-poll-optional">(<?= t('general_optional',[],'optional') ?>)</span>
                        </label>
                    </div>

                    <div id="nt-poll-error" class="nt-poll-error" style="display:none;">
                        <i class="fas fa-exclamation-circle"></i> <span></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="nt-actions">
                <div class="nt-actions-left">
                    <button type="submit" id="nt-submit-btn" class="nt-btn nt-btn-submit">
                        <i class="fas fa-scroll"></i>
                        <?= t('newthread.btn_post',[],'Post Thread') ?>
                    </button>
                    <a href="index.php?p=viewboard&id=<?= (int)$board_id ?>" class="nt-btn nt-btn-cancel">
                        <?= t('newthread.btn_cancel',[],'Cancel') ?>
                    </a>
                </div>
                <div class="nt-draft-notice" id="nt-draft-notice">
                    <i class="fas fa-history"></i>
                    <?= t('newthread.draft_restored',[],'Draft restored') ?>
                    <button type="button" class="nt-draft-discard" onclick="discardDraft()">
                        <?= t('newthread.draft_discard',[],'Discard') ?>
                    </button>
                </div>
            </div>

        </form>
    </div>
</div>

<script src="assets/js/quill.min.js"></script>
<script src="assets/js/spike_mention.js"></script>
<script>
const SPK_MY_ID    = <?= (int)($myId ?? 0) ?>;
const NT_DRAFT_KEY = '<?= $draft_key ?>';
const NT_SMART     = <?= $smart_js ?>;
const NT_TITLE_MAX = <?= $title_max ?>;
const NT_CSRF      = '<?= $csrf_token ?>';

const quill = new Quill('#quill-editor', {
    theme: 'snow',
    placeholder: '<?= addslashes(t('newthread.content_placeholder',[],'Write something…')) ?>',
    modules: {
        toolbar: [
            ['bold', 'italic'],
            [{ list: 'ordered' }, { list: 'bullet' }],
            ['image', 'link'],
            ['clean']
        ]
    }
});

<?php if (!empty($myId)): ?>
try {
    const ntMentioner = new SpikeMentioner(quill, {
        myId:      <?= (int)$myId ?>,
        searchUrl: 'index.php?p=spike_mention_search',
        csrfToken: NT_CSRF,
    });
} catch(e) { console.warn('Mentioner init failed', e); }
<?php endif; ?>

(function() {
    try {
        const raw = localStorage.getItem(NT_DRAFT_KEY);
        if (!raw) return;
        const obj = JSON.parse(raw);
        if (!obj || Date.now() - obj.ts > 86400000) { localStorage.removeItem(NT_DRAFT_KEY); return; }
        if (obj.title)   document.getElementById('thread_title').value = obj.title;
        if (obj.content) quill.root.innerHTML = obj.content;
        document.getElementById('nt-draft-notice').classList.add('visible');
        updateCounter(obj.title?.length || 0);
    } catch(e) {}
})();

let draftTimer;
function saveDraft() {
    clearTimeout(draftTimer);
    draftTimer = setTimeout(() => {
        try {
            localStorage.setItem(NT_DRAFT_KEY, JSON.stringify({
                title:   document.getElementById('thread_title').value,
                content: quill.root.innerHTML,
                ts:      Date.now(),
            }));
        } catch(e) {}
    }, 1500);
}
quill.on('text-change', saveDraft);

let similarTimer;
document.getElementById('thread_title')?.addEventListener('input', function() {
    saveDraft();
    updateCounter(this.value.length);
    const lower = this.value.toLowerCase();
    for (const [pfxId, kws] of Object.entries(NT_SMART)) {
        if (kws.some(k => lower.includes(k))) {
            const pill = document.querySelector(`.nt-prefix-pill[data-pid="${pfxId}"]`);
            if (pill && !document.querySelector('.nt-prefix-pill.selected:not(.none-pill)')) {
                document.querySelectorAll('.nt-prefix-pill').forEach(p => p.style.opacity = '');
                pill.style.opacity = '0.65';
            }
            break;
        }
    }
    if(!Object.values(NT_SMART).flat().some(k => lower.includes(k))) {
        document.querySelectorAll('.nt-prefix-pill').forEach(p => p.style.opacity = '');
    }

    clearTimeout(similarTimer);
    const val = this.value.trim();
    const box = document.getElementById('nt-similar-threads');
    if (val.length < 4) {
        if (box) box.style.display = 'none';
        return;
    }
    similarTimer = setTimeout(() => {
        const fd = new FormData();
        fd.append('ajax_action', 'check_similar');
        fd.append('csrf_token', NT_CSRF);
        fd.append('title', val);
        fetch(window.location.href, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.ok && d.threads && d.threads.length > 0) {
                    if (!box) return;
                    let html = '<div class="nt-similar-label"><i class="fas fa-info-circle"></i> ' + '<?= addslashes(t('newthread.similar_found', [], 'Similar topics found:')) ?>' + '</div><ul class="nt-similar-list">';
                    d.threads.forEach(t => {
                        const url = t.slug ? 'index.php?p=viewthread&slug=' + encodeURIComponent(t.slug) : 'index.php?p=viewthread&id=' + t.id;
                        html += '<li><a href="' + url + '" target="_blank" class="nt-similar-link">' + t.title.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</a></li>';
                    });
                    html += '</ul>';
                    box.innerHTML = html;
                    box.style.display = 'block';
                } else if (box) {
                    box.style.display = 'none';
                }
            }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
    }, 500);
});

function discardDraft() {
    localStorage.removeItem(NT_DRAFT_KEY);
    quill.setText('');
    document.getElementById('thread_title').value = '';
    updateCounter(0);
    document.getElementById('nt-draft-notice').classList.remove('visible');
    const box = document.getElementById('nt-similar-threads');
    if(box) box.style.display = 'none';
}

function updateCounter(len) {
    const el = document.getElementById('nt-char-counter');
    if (!el) return;
    const pct = len / NT_TITLE_MAX;
    el.textContent = len + '\u2009/\u2009' + NT_TITLE_MAX;
    el.className = 'nt-char-counter' + (pct >= 1 ? ' over' : pct >= 0.85 ? ' warn' : '');
}

function selectPrefix(btn, pid) {
    document.querySelectorAll('.nt-prefix-pill').forEach(p => { p.classList.remove('selected'); p.style.opacity = ''; });
    btn.classList.add('selected');
    document.getElementById('prefix_id_input').value = pid;
}

function insertSmiley(code) {
    const range = quill.getSelection(true);
    quill.insertText(range ? range.index : quill.getLength(), ' ' + code + ' ');
}

function updateAttachLabel(input) {
    const lbl = document.getElementById('attach-label');
    if (!lbl) return;
    lbl.textContent = input.files.length ? Array.from(input.files).map(f => f.name).join(', ') : '';
}

function togglePollBuilder() {
    const box = document.getElementById('nt-poll-builder');
    const btn = document.getElementById('nt-poll-toggle-btn');
    if (!box) return;
    const opening = box.style.display === 'none';
    box.style.display = opening ? 'block' : 'none';
    if (btn) btn.style.display = opening ? 'none' : '';
    if (opening) document.getElementById('poll_question')?.focus();
}

function discardPollBuilder() {
    const box = document.getElementById('nt-poll-builder');
    const btn = document.getElementById('nt-poll-toggle-btn');
    if (!box) return;
    document.getElementById('poll_question').value = '';
    document.querySelectorAll('.poll-opt-input').forEach((el, i) => {
        if (i > 1) el.closest('.nt-poll-option-row')?.remove();
        else el.value = '';
    });
    const multiBox = document.getElementById('poll_multi'); if (multiBox) multiBox.checked = false;
    const endsAt   = document.getElementById('poll_ends_at'); if (endsAt) endsAt.value = '';
    const errBox   = document.getElementById('nt-poll-error'); if (errBox) errBox.style.display = 'none';
    box.style.display = 'none';
    if (btn) btn.style.display = '';
}

function addPollOption() {
    const wrap = document.getElementById('nt-poll-options');
    if (!wrap || wrap.children.length >= 10) return;
    const n = wrap.children.length + 1;
    const row = document.createElement('div');
    row.className = 'nt-poll-option-row';
    row.innerHTML = '<input type="text" name="poll_options[]" class="um-input poll-opt-input" maxlength="120" placeholder="<?= addslashes(t('newthread.poll_option',[],'Option')) ?> ' + n + '">'
        + '<button type="button" class="nt-poll-opt-remove" onclick="removePollOption(this)" tabindex="-1"><i class="fas fa-minus-circle"></i></button>';
    wrap.appendChild(row);
}

function removePollOption(btn) {
    const wrap = document.getElementById('nt-poll-options');
    if (!wrap || wrap.children.length <= 2) return;
    btn.closest('.nt-poll-option-row')?.remove();
}

document.getElementById('newthread-form').addEventListener('submit', function(e) {
    const title = document.getElementById('thread_title').value.trim();
    const wrap  = document.getElementById('nt-editor-wrap');
    const html       = quill.root.innerHTML;
    const textOnly = quill.getText().trim();
    document.getElementById('thread_content_input').value = html;

    if (!title) {
        e.preventDefault();
        document.getElementById('thread_title').focus();
        return;
    }
    if (!textOnly || textOnly.length === 0) {
        if (!html.includes('<img')) {
            e.preventDefault();
            wrap.classList.add('quill-error');
            quill.focus();
            return;
        }
    }
    wrap.classList.remove('quill-error');

    const pollBox = document.getElementById('nt-poll-builder');
    if (pollBox && pollBox.style.display !== 'none') {
        const pollQ = document.getElementById('poll_question')?.value.trim() || '';
        const pollOpts = [...document.querySelectorAll('.poll-opt-input')].map(i => i.value.trim()).filter(v => v.length > 0);
        const pollErr = document.getElementById('nt-poll-error');
        if (pollQ && pollOpts.length < 2) {
            e.preventDefault();
            pollErr.querySelector('span').textContent = '<?= addslashes(t('newthread.err_poll_min_options',[],'Please provide at least 2 poll options.')) ?>';
            pollErr.style.display = 'block';
            return;
        }
        if (pollErr) pollErr.style.display = 'none';
    }

    try { localStorage.removeItem(NT_DRAFT_KEY); } catch(ex) {}

    const btn = document.getElementById('nt-submit-btn');
    if (btn) {
        btn.style.pointerEvents = 'none';
        btn.style.opacity = '0.5';
        btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i>';
    }
});
</script>