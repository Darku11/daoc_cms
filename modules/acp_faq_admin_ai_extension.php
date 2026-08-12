<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS') && !defined('IN_ACP')) exit;

// Fallback: pull $userPriv/$currentUserId from session if not already in scope
if (!isset($userPriv))      $userPriv      = (int)($_SESSION['priv_level'] ?? 0);
if (!isset($currentUserId)) $currentUserId = (int)($_SESSION['user_id']    ?? 0);

$ai_active = isset($botSettings) && $botSettings->isActive() && $botSettings->hasAiConfigured();

// ── AJAX AI handler — must run BEFORE any HTML output ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && str_starts_with($_POST['ajax_action'], 'ai_')) {
    header('Content-Type: application/json');
    if ($userPriv < 4) { echo json_encode(['error' => 'permission_denied']); exit; }
    if (!$ai_active) { echo json_encode(['error' => 'AI not configured']); exit; }
    checkToken($_POST['csrf_token'] ?? '');
    if (!class_exists('AiManager')) { echo json_encode(['error'=>'AiManager not available']); exit; }

    $action = $_POST['ajax_action'];
    global $botSettings;
    $ai = new AiManager($db, $botSettings, $currentUserId, $userPriv);

    // ── Complete Entry ─────────────────────────────────────────
    if ($action === 'ai_complete_entry') {
        $question = trim($_POST['question'] ?? '');
        $partial  = trim($_POST['partial_answer'] ?? '');
        $category = trim($_POST['category'] ?? '');

        // Load existing FAQs as context
        $existing = $db->query("SELECT category, question FROM faq ORDER BY category LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);

        $result = $ai->request('translation_editor', 'suggest_text', [
            'question'        => $question,
            'partial_answer'  => $partial,
            'category'        => $category,
            'existing_faqs'   => $existing,
            'server_name'     => $GLOBALS['cms_settings']['site_name'] ?? 'DAoC CMS',
            'instruction'     => 'Complete this FAQ entry for a Dark Age of Camelot private server. Write a clear, helpful answer that a new player can understand. Keep it concise (2-4 sentences max). If a partial answer exists, improve and complete it. Match the tone of a helpful game guide.',
        ], ['save_suggestion' => true]);

        echo json_encode($result);
        exit;
    }

    // ── Suggest New FAQs ──────────────────────────────────────
    if ($action === 'ai_suggest_new') {
        // Existing FAQs
        $existing = $db->query("SELECT category, question FROM faq ORDER BY category")->fetchAll(PDO::FETCH_ASSOC);

        // Popular topics from forum threads (if Spike is present)
        $forum_topics = [];
        try {
            $forum_topics = $db->query("
                SELECT t.title, COUNT(p.id) replies
                FROM spike_threads t
                LEFT JOIN spike_posts p ON p.thread_id = t.id
                WHERE t.created_at > DATE_SUB(NOW(), INTERVAL 90 DAY)
                GROUP BY t.id
                ORDER BY replies DESC
                LIMIT 25
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {}

        $result = $ai->request('translation_editor', 'suggest_text', [
            'existing_faqs'  => $existing,
            'popular_threads'=> $forum_topics,
            'server_name'    => $GLOBALS['cms_settings']['site_name'] ?? 'DAoC CMS',
            'instruction'    => 'Based on the existing FAQ entries and popular forum threads, suggest 3-5 new FAQ entries that are missing but frequently needed. For each suggestion provide: category, question, and a complete answer. Focus on questions that new players of a DAoC private server would ask. Return as JSON array: [{"category":"...","question":"...","answer":"..."}]',
        ], ['save_suggestion' => true]);

        echo json_encode($result);
        exit;
    }

    // ── Improve Answer ────────────────────────────────────────
    if ($action === 'ai_improve_answer') {
        $faq_id  = (int)($_POST['faq_id'] ?? 0);
        $question = trim($_POST['question'] ?? '');
        $answer   = trim($_POST['answer']   ?? '');

        if (!$answer) {
            $stmt = $db->prepare("SELECT question, answer, category FROM faq WHERE id = ?");
            $stmt->execute([$faq_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) { $question = $row['question']; $answer = $row['answer']; }
        }

        $result = $ai->request('translation_editor', 'improve_text', [
            'question'        => $question,
            'current_answer'  => $answer,
            'instruction'     => 'Improve this FAQ answer for a DAoC private server. Make it clearer, more helpful, and better structured. Fix any grammar issues. Keep the same core information but make it easier to understand. If the answer is too short, expand it. If too long, condense it. Return only the improved answer text.',
        ], ['save_suggestion' => true, 'target_id' => $faq_id]);

        echo json_encode($result);
        exit;
    }

    // ── Detect Duplicates ─────────────────────────────────────
    if ($action === 'ai_detect_duplicate') {
        $all_faqs = $db->query("SELECT id, category, question FROM faq ORDER BY category")->fetchAll(PDO::FETCH_ASSOC);

        if (count($all_faqs) < 2) {
            echo json_encode(['status'=>'ok','result'=>['suggestion'=>'Not enough FAQ entries to detect duplicates (need at least 2).']]);
            exit;
        }

        $result = $ai->request('translation_editor', 'improve_text', [
            'faq_entries'   => $all_faqs,
            'instruction'   => 'Analyze these FAQ entries and find any that answer the same question (duplicates or very similar entries). For each duplicate group found, list the IDs and explain why they overlap. Also suggest which one to keep. If no duplicates found, say so clearly.',
        ]);

        echo json_encode($result);
        exit;
    }

    echo json_encode(['error'=>'Unknown AI action']);
    exit;
}

// AI inactive → don't render HTML
if (!$ai_active) return;

$faq_csrf = generateToken();
?>


<div class="faq-ai-panel">
    <div class="faq-ai-title"><i class="fas fa-robot"></i> AI Assistant
        <span class="acp-s-03274235">
            <?= h(ucfirst($botSettings->getProvider())) ?>
        </span>
    </div>

    <div class="faq-ai-tabs">
        <a class="faq-ai-tab active" href="#" data-pane="complete">Complete Entry</a>
        <a class="faq-ai-tab" href="#" data-pane="suggest">Suggest New</a>
        <a class="faq-ai-tab" href="#" data-pane="improve">Improve Answer</a>
        <a class="faq-ai-tab" href="#" data-pane="duplicates">Detect Duplicates</a>
    </div>

    <!-- Complete Entry -->
    <div class="faq-ai-pane active" id="faq-pane-complete">
        <label class="faq-ai-label">Question</label>
        <input type="text" class="faq-ai-input" id="faq-ai-question" placeholder="e.g. How do I find my realm trainer?">
        <label class="faq-ai-label">Category</label>
        <input type="text" class="faq-ai-input" id="faq-ai-category" placeholder="e.g. Getting Started">
        <label class="faq-ai-label">Partial Answer (optional)</label>
        <textarea class="faq-ai-textarea" id="faq-ai-partial" placeholder="Start your answer here… or leave empty for a full suggestion"></textarea>
        <button class="faq-ai-btn" id="faq-ai-complete-btn" onclick="faq_ai_complete()">
            <i class="fas fa-magic"></i> Complete Entry
        </button>
        <div id="faq-ai-result-complete" class="faq-ai-result"></div>
        <button class="faq-ai-apply-btn" id="faq-ai-apply-complete" onclick="faq_ai_apply_complete()">
            <i class="fas fa-check"></i> Apply to Form
        </button>
    </div>

    <!-- Suggest New -->
    <div class="faq-ai-pane" id="faq-pane-suggest">
        <p class="acp-s-7f3bfdeb">
            Analyzes your existing FAQ entries and popular forum topics to suggest missing entries.
        </p>
        <button class="faq-ai-btn" id="faq-ai-suggest-btn" onclick="faq_ai_suggest_new()">
            <i class="fas fa-lightbulb"></i> Suggest Missing FAQs
        </button>
        <div id="faq-ai-result-suggest" class="faq-ai-result"></div>
        <button class="faq-ai-apply-btn" id="faq-ai-apply-suggest" onclick="faq_ai_apply_suggestions()">
            <i class="fas fa-plus"></i> Pre-fill Form with First Suggestion
        </button>
    </div>

    <!-- Improve Answer -->
    <div class="faq-ai-pane" id="faq-pane-improve">
        <p class="acp-s-7f3bfdeb">
            Select a FAQ entry from the list above and click Edit, then use this to improve its answer.
        </p>
        <label class="faq-ai-label">Current Answer (paste or auto-fill from edit form)</label>
        <textarea class="faq-ai-textarea" id="faq-ai-current-answer" placeholder="Paste the current answer here…"></textarea>
        <label class="faq-ai-label">Question</label>
        <input type="text" class="faq-ai-input" id="faq-ai-improve-question" placeholder="Paste the question here…">
        <button class="faq-ai-btn" id="faq-ai-improve-btn" onclick="faq_ai_improve()">
            <i class="fas fa-pen"></i> Improve Answer
        </button>
        <div id="faq-ai-result-improve" class="faq-ai-result"></div>
        <button class="faq-ai-apply-btn" id="faq-ai-apply-improve" onclick="faq_ai_apply_improve()">
            <i class="fas fa-check"></i> Apply to Answer Field
        </button>
    </div>

    <!-- Detect Duplicates -->
    <div class="faq-ai-pane" id="faq-pane-duplicates">
        <p class="acp-s-7f3bfdeb">
            Checks all FAQ entries for questions that overlap or answer the same thing.
        </p>
        <button class="faq-ai-btn" id="faq-ai-dup-btn" onclick="faq_ai_detect_duplicates()">
            <i class="fas fa-search"></i> Scan for Duplicates
        </button>
        <div id="faq-ai-result-duplicates" class="faq-ai-result"></div>
    </div>
</div>

<script>
const FAQ_AI_CSRF = '<?= $faq_csrf ?>';
const FAQ_AI_URL  = '<?= defined('IN_ACP') ? 'acp.php?s=faq_admin' : 'index.php?p=faq_admin' ?>';
let faq_ai_last = {};

// ── Tab switching ─────────────────────────────────────────────
document.querySelectorAll('.faq-ai-tab').forEach(tab => {
    tab.addEventListener('click', function(e) {
        e.preventDefault();
        const pane = this.dataset.pane;
        document.querySelectorAll('.faq-ai-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.faq-ai-pane').forEach(p => p.classList.remove('active'));
        this.classList.add('active');
        const el = document.getElementById('faq-pane-' + pane);
        if (el) el.classList.add('active');
    });
});

// Sync with edit form: copy fields if available
function faq_ai_sync_from_form() {
    const q = document.querySelector('input[name="question"]');
    const a = document.querySelector('textarea[name="answer"]');
    if (q && document.getElementById('faq-ai-improve-question')) {
        document.getElementById('faq-ai-improve-question').value = q.value;
    }
    if (a && document.getElementById('faq-ai-current-answer')) {
        document.getElementById('faq-ai-current-answer').value = a.value;
    }
}
// Sync button in the Improve tab
document.getElementById('faq-pane-improve').insertAdjacentHTML('afterbegin',
    '<button class="faq-ai-btn acp-s-d51702bf" onclick="faq_ai_sync_from_form()"><i class="fas fa-sync"></i> Sync from Edit Form</button>');

function faq_ai_show(resultId, text, state='ok') {
    const el = document.getElementById(resultId);
    if (!el) return;
    el.textContent = text;
    el.className = 'faq-ai-result visible ' + state;
}

function faq_ai_post(action, extra={}) {
    const fd = new FormData();
    fd.append('ajax_action', action);
    fd.append('csrf_token', FAQ_AI_CSRF);
    Object.entries(extra).forEach(([k,v]) => fd.append(k,v));
    return fetch(FAQ_AI_URL, { method:'POST', body:fd }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

function faq_ai_complete() {
    const question = document.getElementById('faq-ai-question').value.trim();
    const partial  = document.getElementById('faq-ai-partial').value.trim();
    const category = document.getElementById('faq-ai-category').value.trim();
    if (!question) { faq_ai_show('faq-ai-result-complete','Please enter a question.','err'); return; }
    const btn = document.getElementById('faq-ai-complete-btn');
    if (btn) btn.disabled = true;
    faq_ai_show('faq-ai-result-complete','Completing entry…','loading');
    faq_ai_post('ai_complete_entry', {question, partial_answer: partial, category})
        .then(r=>r.json())
        .then(data => {
            if (btn) btn.disabled = false;
            if (data.status==='ok') {
                faq_ai_last.complete = data.result?.suggestion || '';
                faq_ai_show('faq-ai-result-complete', faq_ai_last.complete, 'ok');
                const applyBtn = document.getElementById('faq-ai-apply-complete');
                if (applyBtn && faq_ai_last.complete) applyBtn.className = 'faq-ai-apply-btn visible';
            } else faq_ai_show('faq-ai-result-complete','Error: '+(data.message||'?'),'err');
        })
        .catch(e => { if(btn) btn.disabled=false; faq_ai_show('faq-ai-result-complete','Request failed: '+e,'err'); });
}

function faq_ai_suggest_new() {
    const btn = document.getElementById('faq-ai-suggest-btn');
    if (btn) btn.disabled = true;
    faq_ai_show('faq-ai-result-suggest','Analyzing FAQ gaps…','loading');
    faq_ai_post('ai_suggest_new')
        .then(r=>r.json())
        .then(data => {
            if (btn) btn.disabled = false;
            if (data.status==='ok') {
                const suggestion = data.result?.suggestion || '';
                faq_ai_show('faq-ai-result-suggest', suggestion, 'ok');
                try {
                    const match = suggestion.match(/\[[\s\S]*\]/);
                    if (match) {
                        faq_ai_last.suggestions = JSON.parse(match[0]);
                        const applyBtn = document.getElementById('faq-ai-apply-suggest');
                        if (applyBtn && faq_ai_last.suggestions?.length) applyBtn.className = 'faq-ai-apply-btn visible';
                    }
                } catch(e) {}
            } else faq_ai_show('faq-ai-result-suggest','Error: '+(data.message||'?'),'err');
        })
        .catch(e => { if(btn) btn.disabled=false; faq_ai_show('faq-ai-result-suggest','Request failed: '+e,'err'); });
}

function faq_ai_improve() {
    const question = document.getElementById('faq-ai-improve-question').value.trim();
    const answer   = document.getElementById('faq-ai-current-answer').value.trim();
    if (!answer) { faq_ai_show('faq-ai-result-improve','Please paste or sync the current answer.','err'); return; }
    const btn = document.getElementById('faq-ai-improve-btn');
    if (btn) btn.disabled = true;
    faq_ai_show('faq-ai-result-improve','Improving answer…','loading');
    faq_ai_post('ai_improve_answer', {question, answer})
        .then(r=>r.json())
        .then(data => {
            if (btn) btn.disabled = false;
            if (data.status==='ok') {
                faq_ai_last.improved = data.result?.suggestion || '';
                faq_ai_show('faq-ai-result-improve', faq_ai_last.improved, 'ok');
                const applyBtn = document.getElementById('faq-ai-apply-improve');
                if (applyBtn && faq_ai_last.improved) applyBtn.className = 'faq-ai-apply-btn visible';
            } else faq_ai_show('faq-ai-result-improve','Error: '+(data.message||'?'),'err');
        })
        .catch(e => { if(btn) btn.disabled=false; faq_ai_show('faq-ai-result-improve','Request failed: '+e,'err'); });
}

function faq_ai_detect_duplicates() {
    const btn = document.getElementById('faq-ai-dup-btn');
    if (btn) btn.disabled = true;
    faq_ai_show('faq-ai-result-duplicates','Scanning for duplicates…','loading');
    faq_ai_post('ai_detect_duplicate')
        .then(r=>r.json())
        .then(data => {
            if (btn) btn.disabled = false;
            if (data.status==='ok') faq_ai_show('faq-ai-result-duplicates', data.result?.suggestion||'No duplicates found.', 'ok');
            else faq_ai_show('faq-ai-result-duplicates','Error: '+(data.message||'?'),'err');
        })
        .catch(e => { if(btn) btn.disabled=false; faq_ai_show('faq-ai-result-duplicates','Request failed: '+e,'err'); });
}

// ── Apply Functions ────────────────────────────────────────────
function faq_ai_apply_complete() {
    if (!faq_ai_last.complete) return;
    const answerField = document.querySelector('textarea[name="answer"]');
    const questionField = document.querySelector('input[name="question"]');
    const categoryField = document.querySelector('input[name="category"]');
    if (answerField) answerField.value = faq_ai_last.complete;
    if (questionField && document.getElementById('faq-ai-question').value) {
        questionField.value = document.getElementById('faq-ai-question').value;
    }
    if (categoryField && document.getElementById('faq-ai-category').value) {
        categoryField.value = document.getElementById('faq-ai-category').value;
    }
    faq_ai_show('faq-ai-result-complete','✓ Applied to form. Review and save.','ok');
    document.getElementById('faq-ai-apply-complete').className = 'faq-ai-apply-btn';
}

function faq_ai_apply_suggestions() {
    if (!faq_ai_last.suggestions?.length) return;
    const first = faq_ai_last.suggestions[0];
    const qField = document.querySelector('input[name="question"]');
    const aField = document.querySelector('textarea[name="answer"]');
    const cField = document.querySelector('input[name="category"]');
    if (qField && first.question) qField.value = first.question;
    if (aField && first.answer)   aField.value = first.answer;
    if (cField && first.category) cField.value = first.category;
    faq_ai_show('faq-ai-result-suggest', '✓ First suggestion pre-filled in form. ' + (faq_ai_last.suggestions.length - 1) + ' more available in the AI result above.', 'ok');
    document.getElementById('faq-ai-apply-suggest').className = 'faq-ai-apply-btn';
}

function faq_ai_apply_improve() {
    if (!faq_ai_last.improved) return;
    const answerField = document.querySelector('textarea[name="answer"]');
    if (answerField) answerField.value = faq_ai_last.improved;
    faq_ai_show('faq-ai-result-improve','✓ Improved answer applied to form. Review and save.','ok');
    document.getElementById('faq-ai-apply-improve').className = 'faq-ai-apply-btn';
}
</script>
