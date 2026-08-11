<?php
if (!defined('IN_CMS')) exit;

$_um_ai_active = isset($botSettings) && $botSettings->isActive() && $botSettings->hasAiConfigured();

// ── AJAX AI-Handler ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['um_ai_action'])) {
    header('Content-Type: application/json');
    checkToken($_POST['csrf_token'] ?? '');

    if (!$_um_ai_active || !class_exists('AiManager')) {
        echo json_encode(['error' => 'AI not available']); exit;
    }

    $um_ai_action = $_POST['um_ai_action'];
    $target_id    = (int)($_POST['target_id'] ?? 0);
    global $botSettings;
    $ai = new AiManager($db, $botSettings, $myUserId ?? 0, $userPriv ?? 1);

    // Load target user
    $user = null;
    if ($target_id) {
        $stmt = $db->prepare("SELECT id, username, standing, standing_reason, priv_level, last_activity, created_at, email FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$target_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$user) { echo json_encode(['error' => 'User not found']); exit; }

    // ── Analyze Suspicious ────────────────────────────────────
    if ($um_ai_action === 'ai_analyze_suspicious') {
        // Load login log (IP addresses)
        $logins = [];
        try {
            $stmt = $db->prepare("
                SELECT ip_address, action_type, details, created_at
                FROM aldhran_logs
                WHERE user_id = ? AND action_type IN ('LOGIN','LOGIN_FAIL','ACP_ACCESS')
                ORDER BY created_at DESC LIMIT 50
            ");
            $stmt->execute([$target_id]);
            $logins = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {}

        // Count unique IPs
        $unique_ips = array_unique(array_column($logins, 'ip_address'));

        // Characters on other accounts with the same IPs
        $shared_ips = [];
        foreach ($unique_ips as $ip) {
            try {
                $stmt2 = $db->prepare("
                    SELECT DISTINCT u.username, u.id
                    FROM aldhran_logs l
                    JOIN users u ON u.id = l.user_id
                    WHERE l.ip_address = ? AND l.user_id != ?
                    LIMIT 5
                ");
                $stmt2->execute([$ip, $target_id]);
                $others = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                if ($others) $shared_ips[$ip] = $others;
            } catch (\Throwable $e) {}
        }

        // Load forum posts
        $post_count = 0;
        try {
            $stmt_pc = $db->prepare("SELECT COUNT(*) FROM spike_posts WHERE author_id = ?");
            $stmt_pc->execute([$target_id]);
            $post_count = (int)$stmt_pc->fetchColumn();
        } catch (\Throwable $e) {}

        $result = $ai->request('discord', 'answer_question', [
            'username'       => $user['username'],
            'account_age'    => $user['created_at'],
            'standing'       => (int)$user['standing'],
            'last_activity'  => $user['last_activity'],
            'unique_ip_count'=> count($unique_ips),
            'shared_ips'     => $shared_ips,
            'login_history'  => array_slice($logins, 0, 20),
            'forum_posts'    => $post_count,
            'instruction'    => 'Analyze this game server account for suspicious activity. Check for: 1) Account sharing (multiple IPs from different regions), 2) Unusual login patterns (many failed logins, impossible travel), 3) Ban evasion indicators. Rate risk: LOW/MEDIUM/HIGH. Give specific evidence and recommended action. Be factual, not speculative.',
        ], ['save_suggestion' => true, 'target_id' => $target_id]);

        echo json_encode($result);
        exit;
    }

    // ── Ban Reason Helper ─────────────────────────────────────
    if ($um_ai_action === 'ai_ban_reason') {
        $bullet_points = trim($_POST['bullet_points'] ?? '');
        $ban_type      = trim($_POST['ban_type']      ?? 'temporary');

        $result = $ai->request('discord', 'answer_question', [
            'username'      => $user['username'],
            'ban_type'      => $ban_type,
            'bullet_points' => $bullet_points,
            'instruction'   => "Write a professional, clear ban reason for a game server. Ban type: {$ban_type}. Key facts provided by admin: {$bullet_points}. Format: 1-2 sentences stating what was violated and the consequence. Keep it factual and neutral. Return JSON: {\"reason\":\"...\",\"standing_reason\":\"...\"}",
        ]);

        echo json_encode($result);
        exit;
    }

    // ── Suggest Standing ──────────────────────────────────────
    if ($um_ai_action === 'ai_suggest_standing') {
        // Load forum activity
        $forum_activity = [];
        try {
            $stmt = $db->prepare("
                SELECT p.content, p.created_at, t.title as thread_title
                FROM spike_posts p
                LEFT JOIN spike_threads t ON t.id = p.thread_id
                WHERE p.author_id = ?
                ORDER BY p.created_at DESC LIMIT 10
            ");
            $stmt->execute([$target_id]);
            $forum_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {}

        // Admin log entries
        $admin_history = [];
        try {
            $stmt = $db->prepare("SELECT action_type, details, created_at FROM aldhran_logs WHERE target_id = ? ORDER BY created_at DESC LIMIT 20");
            $stmt->execute([$target_id]);
            $admin_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {}

        $result = $ai->request('discord', 'answer_question', [
            'username'       => $user['username'],
            'current_standing'=> (int)$user['standing'],
            'standing_reason'=> $user['standing_reason'] ?? '',
            'forum_posts'    => array_map(fn($p) => ['content' => strip_tags(substr($p['content'],0,200)), 'thread' => $p['thread_title']], $forum_activity),
            'admin_history'  => $admin_history,
            'instruction'    => 'Based on this user\'s forum activity and admin history, suggest an appropriate standing level (0=Good, 1=Warning I, 2=Warning II, 3=Restricted, 4=Suspended, 5=Banned). Explain your reasoning briefly. Return JSON: {"suggested_standing":0-5,"reasoning":"...","action":"none|warn|restrict|ban"}',
        ]);

        echo json_encode($result);
        exit;
    }

    echo json_encode(['error' => 'Unknown UM AI action']);
    exit;
}
?>

<?php if ($_um_ai_active): ?>
<script>
// ── UM AI Functions ────────────────────────────────────────────
// These functions are made available in acp_um_editor_view.php.
// The panel is inserted dynamically after the editor loads.

const UM_AI_CSRF = '<?= generateToken() ?>';
let um_ai_last = {};

function um_ai_inject_panel(targetId) {
    // Remove existing panel
    document.getElementById('um-ai-panel')?.remove();

    const panel = document.createElement('div');
    panel.id = 'um-ai-panel';
    panel.innerHTML = `
        <div class="acp-s-d46ce2d4">
            <div class="acp-s-6d02631f">
                <i class="fas fa-robot"></i> AI Assistant
                <span class="acp-s-8fe8560d">
                    <?= h(ucfirst($botSettings->getProvider())) ?>
                </span>
            </div>

            <button onclick="um_ai_analyze(${targetId})" id="um-ai-analyze-btn"
                class="acp-s-56f0bba6">
                <i class="fas fa-search"></i> Analyze Account
            </button>
            <button onclick="um_ai_suggest_standing(${targetId})" id="um-ai-standing-btn"
                class="acp-s-56f0bba6">
                <i class="fas fa-gavel"></i> Suggest Standing
            </button>

            <div class="acp-s-1c6a73dc">
                <div class="acp-s-c62d7c9e">
                    <i class="fas fa-ban acp-s-831b94f4"></i> Ban Reason Helper
                </div>
                <select id="um-ai-ban-type" class="acp-s-9627fe24">
                    <option value="temporary">Temporary Ban</option>
                    <option value="permanent">Permanent Ban</option>
                    <option value="warning">Warning</option>
                    <option value="restriction">Restriction</option>
                </select>
                <textarea id="um-ai-ban-points" placeholder="Key facts (one per line):&#10;- Harassed other players in chat&#10;- Repeated offense after warning"
                    class="acp-s-56fd39ad"></textarea>
                <button onclick="um_ai_ban_reason(${targetId})"
                    class="acp-s-34fcb8bd">
                    <i class="fas fa-pen"></i> Generate Ban Reason
                </button>
            </div>

            <div id="um-ai-result" class="acp-s-28e815f7"></div>
            <button id="um-ai-apply-standing" onclick="um_ai_apply_standing()" class="acp-s-1a25915d">
                <i class="fas fa-check"></i> Apply Suggested Standing
            </button>
            <button id="um-ai-apply-ban-reason" onclick="um_ai_apply_ban_reason()" class="acp-s-28aa5e08">
                <i class="fas fa-check"></i> Apply to Reason Field
            </button>
        </div>
    `;

    // Insert after the editor form
    const editorForm = document.querySelector('.um-editor-form, .admin-container, [data-um-editor]');
    if (editorForm) editorForm.appendChild(panel);
    else document.body.appendChild(panel);
}

function um_ai_show(text, state='ok') {
    const el = document.getElementById('um-ai-result');
    if (!el) return;
    el.textContent = text; el.style.display = 'block';
    el.style.color = state==='err'?'#e07070':state==='loading'?'#333':'#888';
    el.style.fontStyle = state==='loading'?'italic':'normal';
}

function um_ai_post(action, targetId, extra={}) {
    const fd = new FormData();
    fd.append('um_ai_action', action);
    fd.append('csrf_token', UM_AI_CSRF);
    fd.append('target_id', targetId);
    Object.entries(extra).forEach(([k,v]) => fd.append(k,v));
    return fetch('modules/acp_um_sync_worker.php', { method:'POST', body:fd }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

function um_ai_analyze(targetId) {
    const btn = document.getElementById('um-ai-analyze-btn');
    if (btn) btn.disabled = true;
    um_ai_show('Analyzing account activity…', 'loading');
    um_ai_post('ai_analyze_suspicious', targetId)
        .then(r=>r.json())
        .then(data => {
            if (btn) btn.disabled = false;
            if (data.status==='ok') um_ai_show(data.result?.suggestion || '—', 'ok');
            else um_ai_show('Error: '+(data.message||data.error||'?'), 'err');
        })
        .catch(e => { if(btn) btn.disabled=false; um_ai_show('Request failed: '+e,'err'); });
}

function um_ai_suggest_standing(targetId) {
    const btn = document.getElementById('um-ai-standing-btn');
    if (btn) btn.disabled = true;
    um_ai_show('Evaluating standing…', 'loading');
    um_ai_post('ai_suggest_standing', targetId)
        .then(r=>r.json())
        .then(data => {
            if (btn) btn.disabled = false;
            if (data.status==='ok') {
                const suggestion = data.result?.suggestion || '';
                um_ai_show(suggestion, 'ok');
                try {
                    const match = suggestion.match(/\{[\s\S]*\}/);
                    if (match) {
                        um_ai_last.standing = JSON.parse(match[0]);
                        const applyBtn = document.getElementById('um-ai-apply-standing');
                        if (applyBtn && um_ai_last.standing?.suggested_standing !== undefined) applyBtn.style.display = 'inline-flex';
                    }
                } catch(e) {}
            } else um_ai_show('Error: '+(data.message||data.error||'?'), 'err');
        })
        .catch(e => { if(btn) btn.disabled=false; um_ai_show('Request failed: '+e,'err'); });
}

function um_ai_ban_reason(targetId) {
    const points  = document.getElementById('um-ai-ban-points')?.value.trim();
    const banType = document.getElementById('um-ai-ban-type')?.value || 'temporary';
    if (!points) { um_ai_show('Please enter at least one key fact.', 'err'); return; }
    um_ai_show('Generating ban reason…', 'loading');
    um_ai_post('ai_ban_reason', targetId, { bullet_points: points, ban_type: banType })
        .then(r=>r.json())
        .then(data => {
            if (data.status==='ok') {
                const suggestion = data.result?.suggestion || '';
                um_ai_show(suggestion, 'ok');
                try {
                    const match = suggestion.match(/\{[\s\S]*\}/);
                    if (match) {
                        um_ai_last.ban_reason = JSON.parse(match[0]);
                        const applyBtn = document.getElementById('um-ai-apply-ban-reason');
                        if (applyBtn && um_ai_last.ban_reason?.standing_reason) applyBtn.style.display = 'inline-flex';
                    }
                } catch(e) {}
            } else um_ai_show('Error: '+(data.message||data.error||'?'), 'err');
        })
        .catch(e => { um_ai_show('Request failed: '+e,'err'); });
}

function um_ai_apply_standing() {
    if (!um_ai_last.standing) return;
    const standingField = document.querySelector('input[name="u_stand"], select[name="u_stand"]');
    if (standingField) standingField.value = um_ai_last.standing.suggested_standing;
    um_ai_show('✓ Standing ' + um_ai_last.standing.suggested_standing + ' applied. Review and save.', 'ok');
    document.getElementById('um-ai-apply-standing').style.display = 'none';
}

function um_ai_apply_ban_reason() {
    if (!um_ai_last.ban_reason) return;
    const reasonField = document.querySelector('input[name="u_reason"], textarea[name="u_reason"]');
    if (reasonField) reasonField.value = um_ai_last.ban_reason.standing_reason || um_ai_last.ban_reason.reason || '';
    um_ai_show('✓ Ban reason applied. Review and save.', 'ok');
    document.getElementById('um-ai-apply-ban-reason').style.display = 'none';
}

// Extend loadUserEditor to display the AI panel.
(function() {
    const interval = setInterval(() => {
        if (typeof loadUserEditor !== 'undefined') {
            clearInterval(interval);
            const orig = loadUserEditor;
            window.loadUserEditor = function(id) {
                orig(id);
                // Insert panel after a short delay (wait for the AJAX response)
                setTimeout(() => um_ai_inject_panel(id), 800);
            };
        }
    }, 100);
})();
</script>
<?php endif; ?>