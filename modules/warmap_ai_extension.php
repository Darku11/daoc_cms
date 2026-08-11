<?php
if (!defined('IN_CMS')) exit;

$_wm_ai_active = isset($botSettings) && $botSettings->isActive() && $botSettings->hasAiConfigured();

// ── AJAX AI handler ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wm_ai_action'])) {
    header('Content-Type: application/json');
    checkToken($_POST['csrf_token'] ?? '');

    if (!$_wm_ai_active || !class_exists('AiManager')) {
        echo json_encode(['error' => 'AI not available']); exit;
    }

    $wm_action = $_POST['wm_ai_action'];
    global $botSettings;
    $ai = new AiManager($db, $botSettings, $_SESSION['user_id'] ?? null, (int)($_SESSION['priv_level'] ?? 1));

    // Read keep data from POST
    $keeps_raw  = json_decode($_POST['keeps_data']  ?? '[]', true) ?: [];
    $counts_raw = json_decode($_POST['counts_data'] ?? '{}', true) ?: [];

    // Realm statistics from DB
    $realm_players = [];
    try {
        $r = $db->query("
            SELECT c.Realm, COUNT(*) cnt
            FROM dolcharacters c
            WHERE c.LastPlayed > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            GROUP BY c.Realm
        ");
        while ($row = $r->fetch(PDO::FETCH_ASSOC)) {
            $realm_players[(int)$row['Realm']] = (int)$row['cnt'];
        }
    } catch (\Throwable $e) {}

    // ── Keep Balance ───────────────────────────────────────────
    if ($wm_action === 'ai_keep_balance') {
        $result = $ai->request('core_architect', 'analyze_economy', [
            'keep_distribution' => $counts_raw,
            'keeps_detail'      => $keeps_raw,
            'active_players'    => $realm_players,
            'instruction'       => 'Analyze the current RvR keep distribution in Dark Age of Camelot. Counts: Albion=' . ($counts_raw['alb'] ?? 0) . ', Midgard=' . ($counts_raw['mid'] ?? 0) . ', Hibernia=' . ($counts_raw['hib'] ?? 0) . '. Active players per realm: ' . json_encode($realm_players) . '. Is this balanced? Which realm is dominant? Suggest 2-3 specific actions an admin could take (e.g. adjust NPC keep defenders, spawn events, adjust realm bonuses) to rebalance. Be concise.',
        ], ['save_suggestion' => true]);

        echo json_encode($result);
        exit;
    }

    // ── Predict Darkness Falls ────────────────────────────────
    if ($wm_action === 'ai_predict_df') {
        // Determine the Darkness Falls owner from the realm with the most keeps.
        $alb = (int)($counts_raw['alb'] ?? 0);
        $mid = (int)($counts_raw['mid'] ?? 0);
        $hib = (int)($counts_raw['hib'] ?? 0);
        $total = max(1, $alb + $mid + $hib);

        // Check portal keeps (ID-based)
        $portal_keeps = array_filter($keeps_raw, fn($k) => ($k['type'] ?? '') === 'portal');
        $portal_owners = [];
        foreach ($portal_keeps as $pk) {
            $portal_owners[] = ['name' => $pk['name'] ?? '?', 'owner' => $pk['owner'] ?? 'neutral', 'zone' => $pk['zone'] ?? '?'];
        }

        $result = $ai->request('core_architect', 'suggest_balance', [
            'current_keeps'   => ['alb' => $alb, 'mid' => $mid, 'hib' => $hib],
            'portal_keeps'    => $portal_owners,
            'active_players'  => $realm_players,
            'instruction'     => 'Predict which realm will control Darkness Falls (DF) in DAoC. In DAoC, DF is controlled by the realm that holds the most keeps. Current: Albion=' . $alb . ', Midgard=' . $mid . ', Hibernia=' . $hib . '. Portal keeps: ' . json_encode($portal_owners) . '. Active players: ' . json_encode($realm_players) . '. Predict: 1) Who controls DF now, 2) How stable is this control (likely to change?), 3) What would it take for another realm to flip DF? Keep it short and tactical.',
        ]);

        echo json_encode($result);
        exit;
    }

    // ── Suggest RvR Event ──────────────────────────────────────
    if ($wm_action === 'ai_suggest_event') {
        // Server activity over the last hours
        $activity_1h  = (int)($realm_players[1] ?? 0) + (int)($realm_players[2] ?? 0) + (int)($realm_players[3] ?? 0);

        // Latest RvR activity from logs
        $last_rvr = null;
        try {
            $stmt = $db->query("SELECT created_at FROM aldhran_logs WHERE action_type LIKE 'RVR%' ORDER BY created_at DESC LIMIT 1");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $last_rvr = $row ? $row['created_at'] : null;
        } catch (\Throwable $e) {}

        $result = $ai->request('core_architect', 'suggest_balance', [
            'current_players_online' => $activity_1h,
            'realm_players'          => $realm_players,
            'keep_distribution'      => $counts_raw,
            'last_rvr_event'         => $last_rvr ?? 'unknown',
            'server_name'            => $GLOBALS['cms_settings']['site_name'] ?? 'DAoC CMS',
            'instruction'            => 'Suggest a RvR event for a Dark Age of Camelot private server. Currently ' . $activity_1h . ' players online. Keep distribution: ' . json_encode($counts_raw) . '. Suggest: 1) Event type (e.g. double RP, keep siege event, relic raid), 2) Duration, 3) Which realm to incentivize, 4) Announcement text (2 sentences). Return JSON: {"event_type":"...","duration":"...","target_realm":"...","announcement":"...","reason":"..."}',
        ], ['save_suggestion' => true]);

        echo json_encode($result);
        exit;
    }

    // ── Broadcast suggested event via Discord bot ──────────────
    if ($wm_action === 'ai_broadcast_event') {
        $message = trim($_POST['message'] ?? '');
        if ($message === '') {
            echo json_encode(['status' => 'error', 'message' => 'No announcement text provided.']); exit;
        }
        if (!class_exists('BotEventDispatcher')) {
            require_once __DIR__ . '/includes/BotEventDispatcher.php';
        }
        try {
            $dispatcher = new BotEventDispatcher($db, $botSettings);
            $br_result  = $dispatcher->onBroadcast($message, (int)($_SESSION['user_id'] ?? 0));
            echo json_encode($br_result);
        } catch (\Throwable $e) {
            error_log("[warmap_ai] broadcast failed: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => 'Broadcast failed.']);
        }
        exit;
    }

    echo json_encode(['error' => 'Unknown WM AI action']);
    exit;
}
?>

<?php if ($_wm_ai_active): ?>
<style>
/* ── Warmap AI Panel ──────────────────────────────── */
#wm-ai-panel {
    position: absolute;
    top: 54px; /* unter dem wm-header */
    right: 0;
    width: 320px;
    background: rgba(10,10,10,0.97);
    border-left: 1px solid rgba(197,160,89,0.15);
    border-bottom: 1px solid rgba(197,160,89,0.1);
    z-index: 1000;
    display: none;
    flex-direction: column;
    box-shadow: -4px 4px 20px rgba(0,0,0,0.6);
}
#wm-ai-panel.open { display: flex; }
#wm-ai-panel-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 14px;
    border-bottom: 1px solid rgba(197,160,89,0.1);
    background: rgba(197,160,89,0.04);
}
#wm-ai-panel-title {
    font-family: 'Cinzel', serif; font-size: 0.6em;
    letter-spacing: 2px; text-transform: uppercase; color: #c5a059;
}
#wm-ai-panel-close {
    background: none; border: none; color: #444;
    cursor: pointer; font-size: 0.85em; padding: 0;
}
#wm-ai-panel-close:hover { color: #888; }
#wm-ai-panel-body { padding: 12px 14px; overflow-y: auto; max-height: 400px; }
.wm-ai-btn {
    width: 100%; background: transparent;
    border: 1px solid rgba(197,160,89,0.2); color: #c5a059;
    padding: 8px 12px; font-family: 'Cinzel', serif; font-size: 0.58em;
    letter-spacing: 1.5px; text-transform: uppercase; cursor: pointer;
    transition: background .2s; display: flex; align-items: center; gap: 8px;
    margin-bottom: 6px;
}
.wm-ai-btn:hover  { background: rgba(197,160,89,0.08); }
.wm-ai-btn:disabled { opacity: 0.3; cursor: not-allowed; }
.wm-ai-result {
    margin-top: 8px; padding: 10px 12px;
    background: #050505; border: 1px solid #1a1a1a;
    font-size: 0.72em; color: #888; line-height: 1.55;
    white-space: pre-wrap; display: none; max-height: 180px; overflow-y: auto;
}
.wm-ai-result.visible { display: block; }
.wm-ai-result.loading { color: #333; font-style: italic; }
.wm-ai-result.err     { border-color: rgba(224,112,112,0.2); color: #e07070; }
.wm-ai-apply-event {
    display: none; margin-top:6px; width: 100%;
    background:transparent; border:1px solid rgba(80,200,120,0.3); color:#50c878;
    padding:6px 12px; font-family:'Cinzel',serif; font-size:0.57em;
    letter-spacing:1px; text-transform:uppercase; cursor:pointer;
    align-items:center; gap:5px;
}
.wm-ai-apply-event.visible { display: flex; }

/* ── AI toggle button in wm-header ── */
#wm-ai-toggle {
    display: flex; align-items: center; gap: 6px;
    font-size: 0.6em; letter-spacing: 2px; color: #444;
    font-family: 'Cinzel', serif; text-transform: uppercase;
    background: none; border: 1px solid #1a1a1a; padding: 4px 10px;
    cursor: pointer; transition: all .2s;
}
#wm-ai-toggle:hover  { color: #c5a059; border-color: rgba(197,160,89,0.3); }
#wm-ai-toggle.active { color: #c5a059; border-color: rgba(197,160,89,0.4); background: rgba(197,160,89,0.04); }
</style>

<script>
// ── Warmap AI ─────────────────────────────────────────────────
const WM_AI_CSRF = '<?= generateToken() ?>';
let wm_ai_last = {};

// Insert AI toggle button into the wm-footer
document.addEventListener('DOMContentLoaded', function() {
    const footer = document.getElementById('wm-footer');
    if (!footer) return;

    // Toggle-Button
    const toggleBtn = document.createElement('button');
    toggleBtn.id = 'wm-ai-toggle';
    toggleBtn.innerHTML = '<i class="fas fa-robot"></i> AI Advisor';
    toggleBtn.onclick = () => wm_ai_toggle_panel();
    footer.style.position = 'relative';
    footer.appendChild(toggleBtn);

    // Insert AI panel after #warmap-wrap
    const wrap = document.getElementById('warmap-wrap');
    if (wrap) {
        wrap.style.position = 'relative';
        const panel = document.createElement('div');
        panel.id = 'wm-ai-panel';
        panel.innerHTML = `
            <div id="wm-ai-panel-head">
                <span id="wm-ai-panel-title"><i class="fas fa-robot" style="margin-right:6px;"></i>RvR AI Advisor</span>
                <button id="wm-ai-panel-close" onclick="wm_ai_toggle_panel()"><i class="fas fa-times"></i></button>
            </div>
            <div id="wm-ai-panel-body">
                <button class="wm-ai-btn" id="wm-ai-balance-btn" onclick="wm_ai_balance()">
                    <i class="fas fa-balance-scale"></i> Analyze Keep Balance
                </button>
                <button class="wm-ai-btn" id="wm-ai-df-btn" onclick="wm_ai_predict_df()">
                    <i class="fas fa-dungeon"></i> Predict Darkness Falls
                </button>
                <button class="wm-ai-btn" id="wm-ai-event-btn" onclick="wm_ai_suggest_event()">
                    <i class="fas fa-calendar-star"></i> Suggest RvR Event
                </button>
                <div id="wm-ai-result" class="wm-ai-result"></div>
                <button class="wm-ai-apply-event" id="wm-ai-apply-event" onclick="wm_ai_apply_event()">
                    <i class="fas fa-bullhorn"></i> Send to Discord Bot
                </button>
            </div>
        `;
        wrap.appendChild(panel);
    }
});

function wm_ai_toggle_panel() {
    const panel = document.getElementById('wm-ai-panel');
    const btn   = document.getElementById('wm-ai-toggle');
    if (!panel) return;
    panel.classList.toggle('open');
    if (btn) btn.classList.toggle('active', panel.classList.contains('open'));
}

function wm_ai_show(text, state='ok') {
    const el = document.getElementById('wm-ai-result');
    if (!el) return;
    el.textContent = text;
    el.className = 'wm-ai-result visible ' + state;
}

function wm_ai_get_data() {
    // Read the current keep data from the warmap JavaScript state.
    const keeps = typeof WM_KEEPS !== 'undefined' ? WM_KEEPS : [];
    const counts = { alb: 0, mid: 0, hib: 0 };
    keeps.forEach(k => { if (counts[k.owner] !== undefined) counts[k.owner]++; });
    return { keeps, counts };
}

function wm_ai_post(action) {
    const { keeps, counts } = wm_ai_get_data();
    const fd = new FormData();
    fd.append('wm_ai_action', action);
    fd.append('csrf_token', WM_AI_CSRF);
    fd.append('keeps_data',  JSON.stringify(keeps));
    fd.append('counts_data', JSON.stringify(counts));
    return fetch('index.php?p=warmap', { method:'POST', body:fd }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

function wm_ai_balance() {
    const btn = document.getElementById('wm-ai-balance-btn');
    if (btn) btn.disabled = true;
    wm_ai_show('Analyzing keep balance…', 'loading');
    wm_ai_post('ai_keep_balance')
        .then(r=>r.json())
        .then(data => {
            if (btn) btn.disabled = false;
            if (data.status==='ok') wm_ai_show(data.result?.suggestion || '—', 'ok');
            else wm_ai_show('Error: '+(data.message||data.error||'?'), 'err');
        })
        .catch(e => { if(btn) btn.disabled=false; wm_ai_show('Request failed: '+e,'err'); });
}

function wm_ai_predict_df() {
    const btn = document.getElementById('wm-ai-df-btn');
    if (btn) btn.disabled = true;
    wm_ai_show('Predicting Darkness Falls control…', 'loading');
    wm_ai_post('ai_predict_df')
        .then(r=>r.json())
        .then(data => {
            if (btn) btn.disabled = false;
            if (data.status==='ok') wm_ai_show(data.result?.suggestion || '—', 'ok');
            else wm_ai_show('Error: '+(data.message||data.error||'?'), 'err');
        })
        .catch(e => { if(btn) btn.disabled=false; wm_ai_show('Request failed: '+e,'err'); });
}

function wm_ai_suggest_event() {
    const btn = document.getElementById('wm-ai-event-btn');
    if (btn) btn.disabled = true;
    wm_ai_show('Generating event suggestion…', 'loading');
    wm_ai_post('ai_suggest_event')
        .then(r=>r.json())
        .then(data => {
            if (btn) btn.disabled = false;
            if (data.status==='ok') {
                const suggestion = data.result?.suggestion || '';
                wm_ai_show(suggestion, 'ok');
                try {
                    const match = suggestion.match(/\{[\s\S]*\}/);
                    if (match) {
                        wm_ai_last.event = JSON.parse(match[0]);
                        const applyBtn = document.getElementById('wm-ai-apply-event');
                        if (applyBtn && wm_ai_last.event?.announcement) applyBtn.className = 'wm-ai-apply-event visible';
                    }
                } catch(e) {}
            } else wm_ai_show('Error: '+(data.message||data.error||'?'), 'err');
        })
        .catch(e => { if(btn) btn.disabled=false; wm_ai_show('Request failed: '+e,'err'); });
}

function wm_ai_apply_event() {
    if (!wm_ai_last.event?.announcement) return;
    const announcement = wm_ai_last.event.announcement;
    const applyBtn = document.getElementById('wm-ai-apply-event');

    // Send to Discord bot via broadcast
    const fd = new FormData();
    fd.append('wm_ai_action', 'ai_broadcast_event');
    fd.append('csrf_token', WM_AI_CSRF);
    fd.append('message', announcement);

    fetch('index.php?p=warmap', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if ((data.status || '') === 'ok') {
                wm_ai_show('✓ Announcement sent to Discord!\n\n' + announcement, 'ok');
                if (applyBtn) applyBtn.className = 'wm-ai-apply-event';
            } else {
                throw new Error(data.message || data.error || 'Broadcast failed');
            }
        }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));})
        .catch(() => {
            // Fallback: copy to clipboard if the broadcast couldn't be sent
            navigator.clipboard?.writeText(announcement).then(() => {
                wm_ai_show('⚠️ Broadcast failed, copied to clipboard instead:\n\n' + announcement, 'err');
                if (applyBtn) applyBtn.className = 'wm-ai-apply-event';
            });
        });
}
</script>
<?php endif; ?>
