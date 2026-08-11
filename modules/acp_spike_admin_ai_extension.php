<?php
if (!defined('IN_CMS')) exit;

$ai_active = isset($botSettings) && $botSettings->isActive() && $botSettings->hasAiConfigured();
if (!$ai_active) return;

// ── AJAX AI handler ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && str_starts_with($_POST['ajax_action'], 'ai_')) {
    header('Content-Type: application/json');
    checkToken($_POST['csrf_token'] ?? '');
    if (!class_exists('AiManager')) { echo json_encode(['error'=>'AiManager not available']); exit; }

    $action = $_POST['ajax_action'];
    global $botSettings;
    $ai = new AiManager($db, $botSettings, $currentUserId, $userPriv);

    // ── Suggest Board ──────────────────────────────────────────
    if ($action === 'ai_suggest_board') {
        $boards = $db->query("
            SELECT b.title, b.description, c.title as cat
            FROM spike_boards b
            JOIN spike_categories c ON c.id = b.cat_id
            ORDER BY c.title, b.title LIMIT 50
        ")->fetchAll(PDO::FETCH_ASSOC);

        $topics = [];
        try {
            $topics = $db->query("
                SELECT t.title, COUNT(p.id) reply_count
                FROM spike_threads t
                LEFT JOIN spike_posts p ON p.thread_id = t.id
                WHERE t.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY t.id
                ORDER BY reply_count DESC
                LIMIT 20
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {}

        $result = $ai->request('discord', 'answer_question', [
            'existing_boards' => $boards,
            'popular_topics'  => $topics,
            'instruction'     => 'Analyze the existing forum boards and recent popular topics for a Dark Age of Camelot private server. Suggest 2-3 new boards that are missing but would benefit the community. For each suggestion provide: board name, category, and description. Be specific to DAoC server communities.',
        ], ['save_suggestion' => true]);

        echo json_encode($result);
        exit;
    }

    // ── Generate Announcement ─────────────────────────────────
    if ($action === 'ai_generate_announcement') {
        $points = trim($_POST['bullet_points']      ?? '');
        $type   = trim($_POST['announcement_type']  ?? 'general');

        $result = $ai->request('discord', 'answer_question', [
            'bullet_points'     => $points,
            'announcement_type' => $type,
            'server_name'       => $GLOBALS['cms_settings']['site_name'] ?? 'DAoC CMS',
            'instruction'       => "Write a forum announcement post for a DAoC private server. Type: {$type}. Use the bullet points as the key information. Write a compelling title and body text. Format with [b] bold tags and proper paragraphs. Keep it engaging but professional. Return JSON: {\"title\":\"...\",\"body\":\"...\"}",
        ], ['save_suggestion' => true]);

        echo json_encode($result);
        exit;
    }

    // ── Moderate Post ─────────────────────────────────────────
    // Load the configured site and server names.
    if ($action === 'ai_moderate_post') {
        $post_id   = (int)($_POST['post_id']   ?? 0);
        $post_text = trim($_POST['post_text']  ?? '');
        $username  = '';

        if ($post_id) {
            try {
                // Join reports to their source records.
                $stmt = $db->prepare("
                    SELECT p.content, u.username
                    FROM spike_posts p
                    LEFT JOIN users u ON u.id = p.author_id
                    WHERE p.id = ? LIMIT 1
                ");
                $stmt->execute([$post_id]);
                $row       = $stmt->fetch(PDO::FETCH_ASSOC);
                $post_text = $row['content']  ?? '';
                $username  = $row['username'] ?? '';
            } catch (\Throwable $e) {
                error_log("[AI Extension] moderate_post DB error: " . $e->getMessage());
            }
        }

        if (!$post_text) { echo json_encode(['error'=>'No post content']); exit; }

        $result = $ai->request('discord', 'answer_question', [
            'post_content' => strip_tags($post_text),
            'post_author'  => $username,
            'instruction'  => 'Analyze this forum post for a gaming community. Check for: spam, toxicity, personal attacks, rule violations, off-topic content. Rate severity: CLEAN/WARNING/VIOLATION. Return JSON: {"verdict":"CLEAN|WARNING|VIOLATION","reason":"...","suggested_action":"none|warn|delete|ban","confidence":0-100}',
        ]);

        echo json_encode($result);
        exit;
    }

    // ── Analyze Open Reports ──────────────────────────────────
    if ($action === 'ai_analyze_reports') {
        $reports = [];
        try {
            $reports = $db->query("
                SELECT r.reason, r.details, p.content as post_content,
                       u.username as reporter, a.username as post_author,
                       t.title as thread_title
                FROM spike_reports r
                JOIN spike_posts p   ON r.post_id    = p.id
                JOIN spike_threads t ON r.thread_id  = t.id
                JOIN users u         ON r.reporter_id = u.id
                LEFT JOIN users a    ON p.author_id   = a.id
                WHERE r.status IN ('open','reviewing')
                ORDER BY r.created_at DESC
                LIMIT 20
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {}

        if (empty($reports)) {
            echo json_encode(['status'=>'ok','result'=>['suggestion'=>'No open reports to analyze.']]);
            exit;
        }

        // Truncate post content so we don't blow the token limit
        foreach ($reports as &$r) {
            $r['post_content'] = mb_substr(strip_tags($r['post_content'] ?? ''), 0, 200);
        }
        unset($r);

        $result = $ai->request('discord', 'answer_question', [
            'open_reports' => $reports,
            'instruction'  => 'Analyze these forum reports for a DAoC gaming community. Find: 1) Any patterns suggesting coordinated harassment or trolling, 2) The most severe report to handle first, 3) Reports that seem frivolous/false. Give a concise summary with priority recommendations.',
        ], ['save_suggestion' => true]);

        echo json_encode($result);
        exit;
    }

    // ── Suggest Forbidden Words ───────────────────────────────
    if ($action === 'ai_check_forbidden_words') {
        // Load current forbidden words + recent post content
        $current_words = [];
        try {
            $current_words = $db->query("SELECT word, scope FROM spike_forbidden_words ORDER BY created_at DESC LIMIT 50")
                               ->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {}

        $recent_posts = [];
        try {
            $recent_posts = $db->query("
                SELECT p.content FROM spike_posts p
                ORDER BY p.created_at DESC LIMIT 30
            ")->fetchAll(PDO::FETCH_COLUMN);
            $recent_posts = array_map(fn($c) => mb_substr(strip_tags($c), 0, 150), $recent_posts);
        } catch (\Throwable $e) {}

        $result = $ai->request('discord', 'answer_question', [
            'current_forbidden_words' => $current_words,
            'recent_post_samples'     => $recent_posts,
            'instruction'             => 'Review these recent forum posts and the current forbidden words list for a DAoC gaming server. Suggest additional words or phrases that should be added to the forbidden list based on what you see. Also flag any posts that seem problematic. Format: list of suggested words with recommended action (block/replace/flag) and reason.',
        ], ['save_suggestion' => true]);

        echo json_encode($result);
        exit;
    }

    echo json_encode(['error'=>'Unknown AI action']);
    exit;
}
?>


<script>
document.addEventListener('DOMContentLoaded', function() {

    // ── AI Panel in Tools-Tab ─────────────────────────────────
    const toolsPanel = document.getElementById('sa-tools');
    if (toolsPanel) {
        const aiDiv = document.createElement('div');
        aiDiv.innerHTML = `
            <div class="sa-ai-panel" id="sa-tools-ai">
                <div class="sa-ai-title">
                    <i class="fas fa-robot"></i> AI Assistant
                    <span class="acp-s-f1d90be9">
                        <?= h(ucfirst($botSettings->getProvider())) ?>
                    </span>
                </div>

                <button class="sa-ai-btn" id="sa-ai-suggest-btn" onclick="sa_ai_suggest_board()">
                    <i class="fas fa-plus-circle"></i> Suggest Missing Boards
                </button>

                <div class="acp-s-be8735bf">
                    <div class="acp-s-b7de97e4">
                        <i class="fas fa-bullhorn acp-s-e5876b3f"></i> Generate Announcement
                    </div>
                    <select class="sa-ai-select" id="sa-ai-announce-type">
                        <option value="patch_notes">Release Announcement</option>
                        <option value="event">Server Event</option>
                        <option value="maintenance">Maintenance</option>
                        <option value="general">General Announcement</option>
                        <option value="rule_update">Rule Update</option>
                    </select>
                    <textarea class="sa-ai-textarea" id="sa-ai-announce-points"
                        placeholder="Key points (one per line):&#10;- New items added&#10;- Bug fix for crafting&#10;- Double XP weekend"></textarea>
                    <button class="sa-ai-btn" id="sa-ai-announce-btn" onclick="sa_ai_generate_announcement()">
                        <i class="fas fa-magic"></i> Generate
                    </button>
                </div>

                <div id="sa-ai-result-tools" class="sa-ai-result"></div>
                <button class="sa-ai-apply-btn" id="sa-ai-apply-announce" onclick="sa_ai_apply_announcement()">
                    <i class="fas fa-copy"></i> Copy to Clipboard
                </button>
            </div>`;
        toolsPanel.appendChild(aiDiv);
    }

    // ── AI Panel in Reports-Tab ───────────────────────────────
    const reportsPanel = document.getElementById('sa-reports');
    if (reportsPanel) {
        const repAiDiv = document.createElement('div');
        repAiDiv.innerHTML = `
            <div class="sa-ai-panel acp-s-8e86974b">
                <div class="sa-ai-title"><i class="fas fa-robot"></i> AI Report Analysis</div>
                <button class="sa-ai-btn" id="sa-ai-reports-btn" onclick="sa_ai_analyze_reports()">
                    <i class="fas fa-search"></i> Analyze Open Reports
                </button>
                <div id="sa-ai-result-reports" class="sa-ai-result"></div>
            </div>`;
        reportsPanel.insertBefore(repAiDiv, reportsPanel.firstChild);
    }

    // ── AI Panel in Forbidden Words-Tab ───────────────────────
    const wordsPanel = document.getElementById('sa-words');
    if (wordsPanel) {
        const fwAiDiv = document.createElement('div');
        fwAiDiv.innerHTML = `
            <div class="sa-ai-panel acp-s-8e86974b">
                <div class="sa-ai-title"><i class="fas fa-robot"></i> AI Word Suggestions</div>
                <button class="sa-ai-btn" id="sa-ai-fw-btn" onclick="sa_ai_check_forbidden_words()">
                    <i class="fas fa-magic"></i> Suggest Additional Words
                </button>
                <div id="sa-ai-result-fw" class="sa-ai-result"></div>
            </div>`;
        wordsPanel.insertBefore(fwAiDiv, wordsPanel.firstChild);
    }

});

const SA_AI_CSRF = spikeToken;
let sa_ai_last_announcement = null;

function sa_ai_post(action, extra, resultEl) {
    const fd = new FormData();
    fd.append('ajax_action', action);
    fd.append('csrf_token', SA_AI_CSRF);
    if (extra) Object.entries(extra).forEach(([k,v]) => fd.append(k, v));
    return fetch('index.php?p=spike_admin', { method:'POST', body:fd })
        .then(r => {
            const ct = r.headers.get('content-type') || '';
            if (!ct.includes('application/json')) return r.text().then(t => { throw new Error('Non-JSON: ' + t.substring(0,100)); });
            return r.json();
        }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

function sa_ai_show(elId, text, state='ok') {
    const el = document.getElementById(elId);
    if (!el) return;
    el.textContent = text;
    el.className = 'sa-ai-result visible ' + state;
}

function sa_ai_suggest_board() {
    const btn = document.getElementById('sa-ai-suggest-btn');
    if (btn) btn.disabled = true;
    sa_ai_show('sa-ai-result-tools', 'Analyzing forum structure…', 'loading');
    sa_ai_post('ai_suggest_board').then(data => {
        if (btn) btn.disabled = false;
        if (data.status==='ok') sa_ai_show('sa-ai-result-tools', data.result?.suggestion||'No suggestions.','ok');
        else sa_ai_show('sa-ai-result-tools','Error: '+(data.message||'?'),'err');
    }).catch(e => { if(btn) btn.disabled=false; sa_ai_show('sa-ai-result-tools','Request failed: '+e,'err'); });
}

function sa_ai_generate_announcement() {
    const btn    = document.getElementById('sa-ai-announce-btn');
    const points = document.getElementById('sa-ai-announce-points')?.value.trim();
    const type   = document.getElementById('sa-ai-announce-type')?.value;
    if (!points) { sa_ai_show('sa-ai-result-tools','Please enter key points.','err'); return; }
    if (btn) btn.disabled = true;
    sa_ai_show('sa-ai-result-tools','Generating announcement…','loading');
    sa_ai_post('ai_generate_announcement', { bullet_points:points, announcement_type:type })
        .then(data => {
            if (btn) btn.disabled = false;
            if (data.status==='ok') {
                const sug = data.result?.suggestion || '';
                sa_ai_show('sa-ai-result-tools', sug, 'ok');
                try {
                    const match = sug.match(/\{[\s\S]*\}/);
                    if (match) {
                        sa_ai_last_announcement = JSON.parse(match[0]);
                        const applyBtn = document.getElementById('sa-ai-apply-announce');
                        if (applyBtn) applyBtn.className = 'sa-ai-apply-btn visible';
                    }
                } catch(e) {}
            } else sa_ai_show('sa-ai-result-tools','Error: '+(data.message||'?'),'err');
        }).catch(e=>{ if(btn) btn.disabled=false; sa_ai_show('sa-ai-result-tools','Request failed: '+e,'err'); });
}

function sa_ai_apply_announcement() {
    if (!sa_ai_last_announcement) return;
    const text = 'Title: '+(sa_ai_last_announcement.title||'')+'\n\n'+(sa_ai_last_announcement.body||'');
    navigator.clipboard?.writeText(text).then(() => {
        sa_ai_show('sa-ai-result-tools','✓ Copied to clipboard!','ok');
        const applyBtn = document.getElementById('sa-ai-apply-announce');
        if (applyBtn) applyBtn.className = 'sa-ai-apply-btn';
    });
}

function sa_ai_analyze_reports() {
    const btn = document.getElementById('sa-ai-reports-btn');
    if (btn) btn.disabled = true;
    sa_ai_show('sa-ai-result-reports','Analyzing reports…','loading');
    sa_ai_post('ai_analyze_reports').then(data => {
        if (btn) btn.disabled = false;
        if (data.status==='ok') sa_ai_show('sa-ai-result-reports', data.result?.suggestion||'No analysis.','ok');
        else sa_ai_show('sa-ai-result-reports','Error: '+(data.message||'?'),'err');
    }).catch(e=>{ if(btn) btn.disabled=false; sa_ai_show('sa-ai-result-reports','Request failed: '+e,'err'); });
}

function sa_ai_check_forbidden_words() {
    const btn = document.getElementById('sa-ai-fw-btn');
    if (btn) btn.disabled = true;
    sa_ai_show('sa-ai-result-fw','Analyzing posts for word suggestions…','loading');
    sa_ai_post('ai_check_forbidden_words').then(data => {
        if (btn) btn.disabled = false;
        if (data.status==='ok') sa_ai_show('sa-ai-result-fw', data.result?.suggestion||'No suggestions.','ok');
        else sa_ai_show('sa-ai-result-fw','Error: '+(data.message||'?'),'err');
    }).catch(e=>{ if(btn) btn.disabled=false; sa_ai_show('sa-ai-result-fw','Request failed: '+e,'err'); });
}
</script>
