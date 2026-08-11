<?php
if (!defined('IN_CMS')) exit;
if (!isset($userPriv) || $userPriv < 4) return;

// AI available?
$ai_active = isset($botSettings) && $botSettings->isActive() 
             && !empty($botSettings->data['ai_provider']) 
             && $botSettings->data['ai_provider'] !== 'none'
             && (!empty($botSettings->data['ai_api_key']) || !empty($botSettings->data['ai_api_key_enc']));
if (!$ai_active) return; // No output if AI is not configured

// AJAX AI handler (handled before the map initializes)
if (isset($_GET['action']) && str_starts_with($_GET['action'], 'ai_')) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    if (!class_exists('AiManager')) { echo json_encode(['error'=>'AiManager not available']); exit; }

    $action  = $_GET['action'];
    $mob_id  = trim($_POST['mob_id']  ?? '');
    $zone_id = (int)($_POST['zone_id'] ?? 0);

    // Load mob data
    $mob = null;
    if ($mob_id) {
        $stmt = $db->prepare("SELECT Mob_ID, Name, Level, Model, Race, Size, Speed, Strength, Constitution, Dexterity, Quickness, AggroLevel, AggroRange, RespawnInterval, Region FROM mob WHERE Mob_ID = ? LIMIT 1");
        $stmt->execute([$mob_id]);
        $mob = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$mob) { echo json_encode(['error'=>'Mob not found']); exit; }

    // Load zone data
    $zone = null;
    $zStmt = $db->prepare("SELECT ZoneID, Name AS ZoneName, RegionID FROM zones WHERE ZoneID = ? LIMIT 1");
    $zStmt->execute([$zone_id]);
    $zone = $zStmt->fetch(PDO::FETCH_ASSOC);

    // Count mobs in the same region (spawn density)
    $mob_density = 0;
    if ($mob) {
        $dStmt = $db->prepare("SELECT COUNT(*) FROM mob WHERE Region = ? AND Level BETWEEN ? AND ?");
        $dStmt->execute([$mob['Region'], max(1, $mob['Level']-5), $mob['Level']+5]);
        $mob_density = (int)$dStmt->fetchColumn();
    }

    global $botSettings;
    $ai = new AiManager($db, $botSettings, $currentUserId, $userPriv);

    // ── AI: Balance Check ──────────────────────────────────────
    if ($action === 'ai_mob_balance') {
        $result = $ai->request('mob_editor', 'balance_check', [
            'mob_name'      => $mob['Name'],
            'level'         => (int)$mob['Level'],
            'strength'      => (int)$mob['Strength'],
            'constitution'  => (int)$mob['Constitution'],
            'dexterity'     => (int)$mob['Dexterity'],
            'quickness'     => (int)$mob['Quickness'],
            'aggro_level'   => (int)$mob['AggroLevel'],
            'aggro_range'   => (int)$mob['AggroRange'],
            'size'          => (int)$mob['Size'],
            'zone'          => $zone['ZoneName'] ?? 'Unknown Zone',
            'region'        => (int)$mob['Region'],
            'mobs_in_area'  => $mob_density,
            'instruction'   => 'Analyze this DAoC mob\'s stats for balance. Check if STR/CON/DEX/QUI are appropriate for its level (typical values: level*4 to level*6). Check aggro range (300-1500 typical). Flag any stat that seems too high or too low. Suggest specific numeric corrections.',
        ], ['save_suggestion' => true, 'target_id' => 0]);
        echo json_encode($result);
        exit;
    }

    // ── AI: Suggest Spawn ──────────────────────────────────────
    if ($action === 'ai_mob_spawn') {
        $result = $ai->request('mob_editor', 'suggest_spawn', [
            'mob_name'         => $mob['Name'],
            'level'            => (int)$mob['Level'],
            'zone'             => $zone['ZoneName'] ?? 'Unknown Zone',
            'current_respawn'  => (int)$mob['RespawnInterval'],
            'mobs_same_level'  => $mob_density,
            'aggro_level'      => (int)$mob['AggroLevel'],
            'instruction'      => 'Suggest optimal spawn settings for this DAoC mob. Recommend: respawn_interval (in seconds, typical 60-300 for normal mobs, 600-3600 for bosses), aggro_level (0=passive, 50=defensive, 99=aggressive), aggro_range (pixels, 300-1500). Consider the mob density in the area. Return JSON: {"respawn":int,"aggro_level":int,"aggro_range":int,"reasoning":"..."}',
        ], ['save_suggestion' => true, 'target_id' => 0]);
        echo json_encode($result);
        exit;
    }

    // ── AI: Generate Lore ──────────────────────────────────────
    if ($action === 'ai_mob_lore') {
        $raceNames = [1=>'Human',2=>'Elf',9=>'Dwarf',25=>'Dragon',26=>'Drake',27=>'Wolf',28=>'Bear',48=>'Skeleton',49=>'Zombie',50=>'Ghost'];
        $result = $ai->request('mob_editor', 'generate_lore', [
            'mob_name'  => $mob['Name'],
            'level'     => (int)$mob['Level'],
            'zone'      => $zone['ZoneName'] ?? 'Unknown Zone',
            'race'      => $raceNames[(int)$mob['Race']] ?? 'Creature',
            'aggressive'=> (int)$mob['AggroLevel'] > 50,
            'instruction'=> 'Write a short lore description (2-3 sentences) for this DAoC mob. Include its origin, behavior, and why it inhabits this zone. Keep it in the Dark Age of Camelot medieval fantasy tone. This text could be shown in a tooltip or bestiary entry.',
        ], ['save_suggestion' => true, 'target_id' => 0]);
        echo json_encode($result);
        exit;
    }

    echo json_encode(['error' => 'Unknown AI action']);
    exit;
}
?>

<?php if ($ai_active): ?>

<!-- Insert AI panel into the edit panel (after ep-footer) -->
<div id="ep-ai-section">
    <div class="ep-ai-title"><i class="fas fa-robot acp-s-e5876b3f"></i>AI Assistant</div>
    <div class="ep-ai-buttons">
        <button class="ep-ai-btn" id="ep-ai-balance-btn" onclick="mob_ai_balance()">
            <i class="fas fa-balance-scale"></i> Balance
        </button>
        <button class="ep-ai-btn" id="ep-ai-spawn-btn" onclick="mob_ai_spawn()">
            <i class="fas fa-map-marker-alt"></i> Spawn
        </button>
        <button class="ep-ai-btn" id="ep-ai-lore-btn" onclick="mob_ai_lore()">
            <i class="fas fa-book"></i> Lore
        </button>
    </div>
    <div id="ep-ai-result"></div>
    <button id="ep-ai-apply-spawn" onclick="mob_ai_apply_spawn()">
        <i class="fas fa-check"></i> Apply Spawn Settings
    </button>
</div>

<script>
// ── Mob Editor AI Functions ────────────────────────────────────
const MOB_AI_PROVIDER = '<?= h(ucfirst($botSettings->getProvider())) ?>';
let mob_ai_last_spawn = null;
let mob_ai_current_mob_id = null;
let mob_ai_current_zone_id = null;

// Extend openEditPanel to reveal the AI panel.
(function() {
    const interval = setInterval(() => {
        if (typeof openEditPanel !== 'undefined') {
            clearInterval(interval);
            const origOpen = openEditPanel;
            window.openEditPanel = function(mobId, mobName, modelId, raceId) {
                mob_ai_current_mob_id  = mobId;
                mob_ai_current_zone_id = typeof currentZoneId !== 'undefined' ? currentZoneId : 0;
                origOpen(mobId, mobName, modelId, raceId);
                document.getElementById('ep-ai-section').classList.add('visible');
                mob_ai_reset();
            };
        }
    }, 100);
})();

function mob_ai_reset() {
    const r = document.getElementById('ep-ai-result');
    if (r) { r.className = 'ep-ai-result'; r.textContent = ''; }
    mob_ai_last_spawn = null;
    const applyBtn = document.getElementById('ep-ai-apply-spawn');
    if (applyBtn) applyBtn.className = 'ep-ai-apply-spawn';
}

function mob_ai_show(text, state='ok') {
    const el = document.getElementById('ep-ai-result');
    if (!el) return;
    el.textContent = text;
    el.className = 'visible ' + state;
}

function mob_ai_post(action, extraData={}) {
    const fd = new FormData();
    fd.append('mob_id',  mob_ai_current_mob_id  || '');
    fd.append('zone_id', mob_ai_current_zone_id || 0);
    Object.entries(extraData).forEach(([k,v]) => fd.append(k,v));
    return fetch(CMS_URL + '&action=' + action, { method: 'POST', body: fd }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

function mob_ai_balance() {
    const btn = document.getElementById('ep-ai-balance-btn');
    if (!mob_ai_current_mob_id) return;
    if (btn) btn.disabled = true;
    mob_ai_show('Analyzing mob balance…', 'loading');
    mob_ai_post('ai_mob_balance').then(r=>r.json()).then(data => {
        if (btn) btn.disabled = false;
        if (data.status==='ok') mob_ai_show(data.result?.suggestion || 'No issues found.', 'ok');
        else mob_ai_show('Error: '+(data.message||data.error||'?'), 'err');
    }).catch(e=>{ if(btn) btn.disabled=false; mob_ai_show('Request failed: '+e,'err'); });
}

function mob_ai_spawn() {
    const btn = document.getElementById('ep-ai-spawn-btn');
    if (!mob_ai_current_mob_id) return;
    if (btn) btn.disabled = true;
    mob_ai_show('Analyzing spawn settings…', 'loading');
    mob_ai_post('ai_mob_spawn').then(r=>r.json()).then(data => {
        if (btn) btn.disabled = false;
        if (data.status==='ok') {
            const suggestion = data.result?.suggestion || '';
            mob_ai_show(suggestion, 'ok');
            // Extract JSON for Apply
            try {
                const match = suggestion.match(/\{[\s\S]*\}/);
                if (match) {
                    mob_ai_last_spawn = JSON.parse(match[0]);
                    const applyBtn = document.getElementById('ep-ai-apply-spawn');
                    if (applyBtn) applyBtn.className = 'ep-ai-apply-spawn visible';
                }
            } catch(e) {}
        } else mob_ai_show('Error: '+(data.message||data.error||'?'), 'err');
    }).catch(e=>{ if(btn) btn.disabled=false; mob_ai_show('Request failed: '+e,'err'); });
}

function mob_ai_lore() {
    const btn = document.getElementById('ep-ai-lore-btn');
    if (!mob_ai_current_mob_id) return;
    if (btn) btn.disabled = true;
    mob_ai_show('Generating lore…', 'loading');
    mob_ai_post('ai_mob_lore').then(r=>r.json()).then(data => {
        if (btn) btn.disabled = false;
        if (data.status==='ok') mob_ai_show(data.result?.suggestion || '—', 'ok');
        else mob_ai_show('Error: '+(data.message||data.error||'?'), 'err');
    }).catch(e=>{ if(btn) btn.disabled=false; mob_ai_show('Request failed: '+e,'err'); });
}

function mob_ai_apply_spawn() {
    if (!mob_ai_last_spawn) return;
    const s = mob_ai_last_spawn;
    if (s.respawn    !== undefined) { const el=document.getElementById('ep-respawn'); if(el) el.value=s.respawn; }
    if (s.aggro_level!== undefined) { const el=document.getElementById('ep-aggro');   if(el) el.value=s.aggro_level; }
    if (s.aggro_range!== undefined) { const el=document.getElementById('ep-range');   if(el) el.value=s.aggro_range; }
    mob_ai_show('Spawn settings applied to form. Click Save to persist.', 'ok');
    const applyBtn = document.getElementById('ep-ai-apply-spawn');
    if (applyBtn) applyBtn.className = 'ep-ai-apply-spawn';
    mob_ai_last_spawn = null;
}
</script>
<?php endif; ?>
