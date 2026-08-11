<?php
if (!defined('IN_ACP')) exit;

// Fallback: pull $userPriv/$currentUserId from session if not already in scope
if (!isset($userPriv))      $userPriv      = (int)($_SESSION['priv_level'] ?? 0);
if (!isset($currentUserId)) $currentUserId = (int)($_SESSION['user_id']    ?? 0);

$_ai_ext_active  = isset($botSettings) && $botSettings->isActive() && $botSettings->hasAiConfigured();
$_ai_csrf        = generateToken();
$_ai_provider    = $_ai_ext_active ? ucfirst($botSettings->getProvider()) : '';
$_ai_section     = preg_replace('/[^a-z0-9_]/', '', $_GET['s'] ?? '');

// ── Content Manager AI AJAX ───────────────────────────────────────────────
if (isset($_POST['cm_ai_action']) && $_ai_section === 'content_manager') {
    header('Content-Type: application/json');
    if ($userPriv < 4) { echo json_encode(['status' => 'error', 'message' => 'permission_denied']); exit; }
    checkToken($_POST['csrf_token'] ?? '');
    if (!$_ai_ext_active || !class_exists('AiManager')) {
        echo json_encode(['status' => 'error', 'message' => 'AI not configured or AiManager not available']);
        exit;
    }
    $cm_action = $_POST['cm_ai_action'];
    $ai = new AiManager($db, $botSettings, $currentUserId, $userPriv);
    if ($cm_action === 'improve_text') {
        $content = strip_tags(trim($_POST['content'] ?? ''));
        $title   = trim($_POST['title'] ?? '');
        if (empty($content)) { echo json_encode(['status' => 'error', 'message' => 'No content to improve.']); exit; }
        $result = $ai->request('translation_editor', 'improve_text', [
            'page_title'   => $title,
            'page_content' => substr($content, 0, 2000),
            'instruction'  => 'Improve the writing quality of this CMS page content. Fix grammar, improve clarity, make it more engaging. Keep the same information and tone. Return only the improved text without HTML tags.',
        ], ['save_suggestion' => true]);
        echo json_encode($result); exit;
    }
    if ($cm_action === 'suggest_seo') {
        $title   = trim($_POST['title']   ?? '');
        $content = strip_tags(trim($_POST['content'] ?? ''));
        $result  = $ai->request('translation_editor', 'suggest_text', [
            'page_title'   => $title,
            'page_content' => substr($content, 0, 1500),
            'server_name'  => $GLOBALS['cms_settings']['site_name'] ?? 'DAoC CMS',
            'instruction'  => 'Suggest SEO improvements for this page. Provide: 1) An improved meta title (max 60 chars), 2) A meta description (max 155 chars), 3) 3-5 relevant keywords. Return as JSON: {"meta_title":"...","meta_description":"...","keywords":["..."]}',
        ], ['save_suggestion' => true]);
        echo json_encode($result); exit;
    }
    echo json_encode(['status' => 'error', 'message' => 'Unknown CM AI action']); exit;
}

// ── Translation Editor AI AJAX ────────────────────────────────────────────
if (isset($_POST['tle_ai_action']) && $_ai_section === 'translation_editor') {
    header('Content-Type: application/json');
    if ($userPriv < 4) { echo json_encode(['status' => 'error', 'message' => 'permission_denied']); exit; }
    checkToken($_POST['csrf_token'] ?? '');
    if (!$_ai_ext_active || !class_exists('AiManager')) {
        echo json_encode(['status' => 'error', 'message' => 'AI not configured']); exit;
    }
    $tle_action = $_POST['tle_ai_action'];
    $ai = new AiManager($db, $botSettings, $currentUserId, $userPriv);
    if ($tle_action === 'detect_missing') {
        $lang      = preg_replace('/[^a-zA-Z]/', '', $_POST['lang'] ?? 'en');
        $base_lang = 'en';
        $base_keys = $db->prepare("SELECT var_key FROM cms_translations WHERE lang_code = ? LIMIT 500");
        $base_keys->execute([$base_lang]);
        $all_base = $base_keys->fetchAll(PDO::FETCH_COLUMN);
        $target_keys = $db->prepare("SELECT var_key FROM cms_translations WHERE lang_code = ?");
        $target_keys->execute([$lang]);
        $all_target = array_flip($target_keys->fetchAll(PDO::FETCH_COLUMN));
        $missing = array_filter($all_base, fn($k) => !isset($all_target[$k]));
        echo json_encode(['ok' => true, 'missing' => array_values($missing), 'count' => count($missing)]); exit;
    }
    if ($tle_action === 'improve_single') {
        $result = $ai->request('translation_editor', 'improve_text', [
            'translation_key'  => trim($_POST['var_key']   ?? ''),
            'current_value'    => trim($_POST['var_value'] ?? ''),
            'language'         => preg_replace('/[^a-zA-Z]/', '', $_POST['lang'] ?? 'en'),
            'instruction'      => 'Improve this UI translation string for a Dark Age of Camelot CMS. Keep it concise and suitable for a UI label/button. Return only the improved translation, nothing else.',
        ], ['save_suggestion' => true]);
        echo json_encode($result); exit;
    }
    if ($tle_action === 'suggest_tone') {
        $lang = preg_replace('/[^a-zA-Z]/', '', $_POST['lang'] ?? 'en');
        $sample_keys = $db->prepare("SELECT var_key, var_value FROM cms_translations WHERE lang_code = ? LIMIT 20");
        $sample_keys->execute([$lang]);
        $samples = $sample_keys->fetchAll(PDO::FETCH_ASSOC);
        $result = $ai->request('translation_editor', 'suggest_text', [
            'sample_strings' => $samples,
            'instruction'    => 'Analyze the tone and style of these UI translation strings. Are they consistent? Too formal? Too casual? Suggest a tone guideline (2-3 sentences). Flag any strings that seem off-tone.',
        ]);
        echo json_encode($result); exit;
    }
    echo json_encode(['status' => 'error', 'message' => 'Unknown TLE AI action']); exit;
}

// ── Theme Editor AI AJAX ──────────────────────────────────────────────────
if (isset($_POST['te_ai_action']) && $_ai_section === 'theme_editor') {
    header('Content-Type: application/json');
    if ($userPriv < 4) { echo json_encode(['status' => 'error', 'message' => 'permission_denied']); exit; }
    checkToken($_POST['csrf_token'] ?? '');
    if (!$_ai_ext_active || !class_exists('AiManager')) {
        echo json_encode(['status' => 'error', 'message' => 'AI not configured']); exit;
    }
    $te_action = $_POST['te_ai_action'];
    $ai = new AiManager($db, $botSettings, $currentUserId, $userPriv);
    if ($te_action === 'suggest_css') {
        $result = $ai->request('theme_editor', 'suggest_css', [
            'module'      => preg_replace('/[^a-z0-9_]/', '', $_POST['module'] ?? ''),
            'current_css' => substr(trim($_POST['current_css'] ?? ''), 0, 3000),
            'request'     => trim($_POST['request'] ?? ''),
            'instruction' => 'Suggest CSS improvements. The theme uses CSS custom properties (variables like --gold, --bg-1). Return only valid CSS. No JavaScript. No inline styles.',
        ], ['save_suggestion' => true]);
        echo json_encode($result); exit;
    }
    if ($te_action === 'explain_variable') {
        $css_var = trim($_POST['css_variable']  ?? '');
        $value   = trim($_POST['current_value'] ?? '');
        $result  = $ai->request('theme_editor', 'explain_variable', [
            'css_variable'  => $css_var,
            'current_value' => $value,
            'instruction'   => "Explain what this CSS variable does in a dark-themed game server CMS. Current value: {$value}. Where is it typically used? Suggest 2-3 alternative values with descriptions.",
        ]);
        echo json_encode($result); exit;
    }
    echo json_encode(['status' => 'error', 'message' => 'Unknown TE AI action']); exit;
}

// ── Core Architect AI AJAX ────────────────────────────────────────────────
if (isset($_POST['ca_ai_action']) && $_ai_section === 'core_architect') {
    header('Content-Type: application/json');
    if ($userPriv < 4) { echo json_encode(['status' => 'error', 'message' => 'permission_denied']); exit; }
    checkToken($_POST['csrf_token'] ?? '');
    if (!$_ai_ext_active || !class_exists('AiManager')) {
        echo json_encode(['status' => 'error', 'message' => 'AI not configured']); exit;
    }
    $ca_action = $_POST['ca_ai_action'];
    $ai = new AiManager($db, $botSettings, $currentUserId, $userPriv);
    if ($ca_action === 'analyze_economy') {
        $eco_data = [];
        try {
            $eco_data = $db->query("SELECT COUNT(*) total_chars, AVG(Platinum) avg_plat, MAX(Platinum) max_plat, AVG(Level) avg_level, SUM(RealmPoints) total_rp, COUNT(DISTINCT AccountName) active_accounts FROM dolcharacters WHERE Level > 0")->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {}
        $realm_dist = [];
        try {
            $r = $db->query("SELECT Realm, COUNT(*) cnt FROM dolcharacters WHERE Level > 0 GROUP BY Realm");
            while ($row = $r->fetch(PDO::FETCH_ASSOC)) $realm_dist[$row['Realm']] = (int)$row['cnt'];
        } catch (\Throwable $e) {}
        $result = $ai->request('core_architect', 'analyze_economy', [
            'economy_snapshot'   => $eco_data,
            'realm_distribution' => $realm_dist,
            'instruction'        => 'Analyze this DAoC private server economy snapshot. Identify: 1) Wealth concentration issues, 2) Realm balance problems, 3) Player activity trends. Give 3 specific, actionable recommendations.',
        ], ['save_suggestion' => true]);
        echo json_encode($result); exit;
    }
    if ($ca_action === 'suggest_balance') {
        $issue  = trim($_POST['issue']  ?? '');
        $realm  = trim($_POST['realm']  ?? 'all');
        $metric = trim($_POST['metric'] ?? 'general');
        $result = $ai->request('core_architect', 'suggest_balance', [
            'reported_issue' => $issue,
            'affected_realm' => $realm,
            'metric'         => $metric,
            'instruction'    => "A DAoC server admin reports: '{$issue}'. Realm: {$realm}. Suggest 3 concrete changes. Include expected impact and risks.",
        ], ['save_suggestion' => true]);
        echo json_encode($result); exit;
    }
    if ($ca_action === 'detect_inflation') {
        $outliers = [];
        try {
            $outliers = $db->query("SELECT lt.ItemTemplateID, lt.Chance, it.Name, it.Price, it.Level FROM loottemplate lt LEFT JOIN itemtemplate it ON it.Id_nb = lt.ItemTemplateID WHERE lt.Chance > 50 AND it.Price > 100000 ORDER BY lt.Chance DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {}
        if (empty($outliers)) {
            echo json_encode(['status' => 'ok', 'result' => ['suggestion' => 'No inflation outliers detected.']]); exit;
        }
        $result = $ai->request('core_architect', 'analyze_economy', [
            'high_value_drops' => $outliers,
            'instruction'      => 'These items have high drop rates for high-value items. Identify inflation risks and suggest adjusted drop rates. Format: item name, current drop %, recommended %, reason.',
        ], ['save_suggestion' => true]);
        echo json_encode($result); exit;
    }
    echo json_encode(['status' => 'error', 'message' => 'Unknown CA AI action']); exit;
}

if (!$_ai_ext_active) return;
?>

<?php if ($_ai_section === 'content_manager'): ?>
<div class="cm-ai-panel acp-s-d46ce2d4" id="cm-ai-panel">
    <div class="acp-s-6d02631f">
        <i class="fas fa-robot"></i> AI Assistant — <?= h($_ai_provider) ?>
    </div>
    <button onclick="cm_ai_improve()" id="cm-ai-improve-btn" class="acp-s-9b36a49d"><i class="fas fa-pen"></i> Improve Text</button>
    <button onclick="cm_ai_seo()" id="cm-ai-seo-btn" class="acp-s-e3064ecd"><i class="fas fa-search"></i> SEO Suggestions</button>
    <div id="cm-ai-result" class="acp-s-9e3bb352"></div>
</div>
<script>
const CM_AI_CSRF = '<?= $_ai_csrf ?>';
(function() {
    const formCard = document.getElementById('cm-form-card');
    const aiPanel  = document.getElementById('cm-ai-panel');
    if (!formCard || !aiPanel) return;
    aiPanel.style.display = formCard.style.display !== 'none' ? 'block' : 'none';
    const observer = new MutationObserver(() => { aiPanel.style.display = formCard.style.display !== 'none' ? 'block' : 'none'; });
    observer.observe(formCard, { attributes: true, attributeFilter: ['style'] });
})();
function cm_ai_show(text, state) {
    const el = document.getElementById('cm-ai-result');
    if (!el) return;
    el.textContent = text; el.style.display = 'block';
    el.style.color = state === 'err' ? '#e07070' : state === 'loading' ? '#444' : '#888';
    el.style.fontStyle = state === 'loading' ? 'italic' : 'normal';
}
function cm_ai_get_content() {
    const editor = document.getElementById('editor');
    if (!editor) return '';
    if (window.$ && typeof $('#editor').data === 'function' && $('#editor').data('trumbowyg')) return $('#editor').trumbowyg('html') || '';
    return editor.value || '';
}
function cm_ai_improve() {
    const title = document.getElementById('p_title')?.value || '';
    const content = cm_ai_get_content();
    if (!content.trim()) { cm_ai_show('No content to improve.', 'err'); return; }
    const btn = document.getElementById('cm-ai-improve-btn');
    if (btn) btn.disabled = true;
    cm_ai_show('Improving content…', 'loading');
    const fd = new FormData();
    fd.append('cm_ai_action', 'improve_text'); fd.append('csrf_token', CM_AI_CSRF);
    fd.append('title', title); fd.append('content', content);
    fetch('acp.php?s=content_manager', { method: 'POST', body: fd })
        .then(r => { const ct = r.headers.get('content-type')||''; if (!ct.includes('application/json')) return r.text().then(txt => { throw new Error('Server returned HTML. Preview: ' + txt.substring(0,100)); }); return r.json(); })
        .then(data => { if (btn) btn.disabled = false; if (data.status==='ok') cm_ai_show(data.result?.suggestion||'—','ok'); else cm_ai_show('Error: '+(data.message||'?'),'err'); })
        .catch(e => { if (btn) btn.disabled = false; cm_ai_show('Request failed: '+e,'err'); });
}
function cm_ai_seo() {
    const title = document.getElementById('p_title')?.value || '';
    const content = cm_ai_get_content();
    const btn = document.getElementById('cm-ai-seo-btn');
    if (btn) btn.disabled = true;
    cm_ai_show('Generating SEO suggestions…', 'loading');
    const fd = new FormData();
    fd.append('cm_ai_action', 'suggest_seo'); fd.append('csrf_token', CM_AI_CSRF);
    fd.append('title', title); fd.append('content', content);
    fetch('acp.php?s=content_manager', { method: 'POST', body: fd })
        .then(r => { const ct = r.headers.get('content-type')||''; if (!ct.includes('application/json')) return r.text().then(txt => { throw new Error('Server returned HTML. Preview: ' + txt.substring(0,100)); }); return r.json(); })
        .then(data => { if (btn) btn.disabled = false; if (data.status==='ok') cm_ai_show(data.result?.suggestion||'—','ok'); else cm_ai_show('Error: '+(data.message||'?'),'err'); })
        .catch(e => { if (btn) btn.disabled = false; cm_ai_show('Request failed: '+e,'err'); });
}
</script>
<?php endif; ?>

<?php if ($_ai_section === 'translation_editor'): ?>
<div class="acp-s-d46ce2d4">
    <div class="acp-s-6d02631f"><i class="fas fa-robot"></i> AI Assistant — <?= h($_ai_provider) ?></div>
    <button onclick="tle_ai_detect_missing()" id="tle-ai-missing-btn" class="acp-s-9b36a49d"><i class="fas fa-search"></i> Detect Missing Keys</button>
    <button onclick="tle_ai_tone()" id="tle-ai-tone-btn" class="acp-s-e3064ecd"><i class="fas fa-comment-alt"></i> Check Tone</button>
    <div id="tle-ai-result" class="acp-s-9e3bb352"></div>
</div>
<script>
const TLE_AI_CSRF = '<?= $_ai_csrf ?>';
function tle_ai_show(text, state) {
    const el = document.getElementById('tle-ai-result');
    if (!el) return;
    el.textContent = text; el.style.display = 'block';
    el.style.color = state === 'err' ? '#e07070' : state === 'loading' ? '#444' : '#888';
    el.style.fontStyle = state === 'loading' ? 'italic' : 'normal';
}
function tle_ai_post(action, extra) {
    const fd = new FormData();
    fd.append('tle_ai_action', action); fd.append('csrf_token', TLE_AI_CSRF);
    Object.entries(extra || {}).forEach(([k, v]) => fd.append(k, v));
    return fetch('acp.php?s=translation_editor', { method: 'POST', body: fd })
        .then(r => { const ct = r.headers.get('content-type')||''; if (!ct.includes('application/json')) return r.text().then(txt => { throw new Error('Server returned HTML. Preview: '+txt.substring(0,100)); }); return r.json(); }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}
function tle_ai_detect_missing() {
    const lang = document.getElementById('tle-lang-select')?.value || 'de';
    const btn = document.getElementById('tle-ai-missing-btn');
    if (btn) btn.disabled = true;
    tle_ai_show('Scanning for missing keys…', 'loading');
    tle_ai_post('detect_missing', { lang })
        .then(data => { if (btn) btn.disabled = false; if (data.ok) { if (data.count===0) tle_ai_show('✓ No missing keys found for '+lang.toUpperCase()+'.','ok'); else tle_ai_show(data.count+' missing keys in '+lang.toUpperCase()+':\n'+data.missing.join('\n'),'ok'); } else tle_ai_show('Error: '+(data.error||'?'),'err'); })
        .catch(e => { if (btn) btn.disabled = false; tle_ai_show('Request failed: '+e,'err'); });
}
function tle_ai_tone() {
    const lang = document.getElementById('tle-lang-select')?.value || 'de';
    const btn = document.getElementById('tle-ai-tone-btn');
    if (btn) btn.disabled = true;
    tle_ai_show('Analyzing tone consistency…', 'loading');
    tle_ai_post('suggest_tone', { lang })
        .then(data => { if (btn) btn.disabled = false; if (data.status==='ok') tle_ai_show(data.result?.suggestion||'—','ok'); else tle_ai_show('Error: '+(data.message||'?'),'err'); })
        .catch(e => { if (btn) btn.disabled = false; tle_ai_show('Request failed: '+e,'err'); });
}
</script>
<?php endif; ?>

<?php if ($_ai_section === 'theme_editor'): ?>
<div class="acp-s-5d325454">
    <div class="acp-s-6d02631f"><i class="fas fa-robot"></i> AI CSS Assistant — <?= h($_ai_provider) ?></div>
    <label class="acp-s-525b86be">What do you want to change?</label>
    <input type="text" id="te-ai-request" placeholder="e.g. Make the gold color more vibrant" class="acp-s-c7be0723">
    <button onclick="te_ai_suggest()" id="te-ai-suggest-btn" class="acp-s-9b36a49d"><i class="fas fa-magic"></i> Suggest CSS</button>
    <button onclick="te_ai_explain()" id="te-ai-explain-btn" class="acp-s-e3064ecd"><i class="fas fa-question-circle"></i> Explain Selected Variable</button>
    <div id="te-ai-result" class="acp-s-ed5d2cf9"></div>
    <button id="te-ai-apply-btn" onclick="te_ai_apply()" class="acp-s-4f739b0c"><i class="fas fa-check"></i> Append to CSS Editor</button>
</div>
<script>
const TE_AI_CSRF = '<?= $_ai_csrf ?>';
let te_ai_last_css = '';
function te_ai_show(text, state) {
    const el = document.getElementById('te-ai-result');
    if (!el) return;
    el.textContent = text; el.style.display = 'block';
    el.style.color = state === 'err' ? '#e07070' : state === 'loading' ? '#444' : '#aaa';
    el.style.fontStyle = state === 'loading' ? 'italic' : 'normal';
}
function te_ai_post(action, extra) {
    const fd = new FormData();
    fd.append('te_ai_action', action); fd.append('csrf_token', TE_AI_CSRF);
    Object.entries(extra || {}).forEach(([k, v]) => fd.append(k, v));
    return fetch('acp.php?s=theme_editor', { method: 'POST', body: fd })
        .then(r => { const ct = r.headers.get('content-type')||''; if (!ct.includes('application/json')) return r.text().then(txt => { throw new Error('Server returned HTML. Preview: '+txt.substring(0,100)); }); return r.json(); }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}
function te_ai_suggest() {
    const request = document.getElementById('te-ai-request')?.value.trim();
    const currentCss = document.getElementById('te-css')?.value || '';
    const module = (typeof currentModule !== 'undefined') ? currentModule : '';
    if (!request) { te_ai_show('Please describe what you want to change.', 'err'); return; }
    const btn = document.getElementById('te-ai-suggest-btn');
    if (btn) btn.disabled = true;
    te_ai_show('Generating CSS suggestion…', 'loading');
    te_ai_post('suggest_css', { current_css: currentCss, module, request })
        .then(data => { if (btn) btn.disabled = false; if (data.status==='ok') { te_ai_last_css = data.result?.suggestion||''; te_ai_show(te_ai_last_css,'ok'); const applyBtn=document.getElementById('te-ai-apply-btn'); if(applyBtn&&te_ai_last_css) applyBtn.style.display='inline-flex'; } else te_ai_show('Error: '+(data.message||'?'),'err'); })
        .catch(e => { if (btn) btn.disabled = false; te_ai_show('Request failed: '+e,'err'); });
}
function te_ai_explain() {
    const cssEditor = document.getElementById('te-css');
    const selectedText = cssEditor ? cssEditor.value.substring(cssEditor.selectionStart, cssEditor.selectionEnd).trim() : '';
    const cssVar = selectedText || document.getElementById('te-ai-request')?.value.trim();
    if (!cssVar) { te_ai_show('Select a CSS variable in the editor or type it above.', 'err'); return; }
    const btn = document.getElementById('te-ai-explain-btn');
    if (btn) btn.disabled = true;
    te_ai_show('Explaining variable…', 'loading');
    te_ai_post('explain_variable', { css_variable: cssVar })
        .then(data => { if (btn) btn.disabled = false; if (data.status==='ok') te_ai_show(data.result?.suggestion||'—','ok'); else te_ai_show('Error: '+(data.message||'?'),'err'); })
        .catch(e => { if (btn) btn.disabled = false; te_ai_show('Request failed: '+e,'err'); });
}
function te_ai_apply() {
    if (!te_ai_last_css) return;
    const cssEditor = document.getElementById('te-css');
    if (!cssEditor) return;
    cssEditor.value += '\n\n/* AI Suggestion */\n' + te_ai_last_css;
    te_ai_show('✓ CSS appended to editor. Click Save to apply.', 'ok');
    document.getElementById('te-ai-apply-btn').style.display = 'none';
}
</script>
<?php endif; ?>

<?php if ($_ai_section === 'core_architect'): ?>
<div class="ca2-section-head acp-s-3ae1f62a">
    <i class="fas fa-robot"></i> AI Economy Advisor
    <span class="acp-s-2c99d6f0"><?= h($_ai_provider) ?></span>
</div>
<div class="acp-s-97f7de64">
    <div class="acp-s-11848735">
        <div class="acp-s-260dc000">Quick Analysis</div>
        <button onclick="ca_ai_analyze()" id="ca-ai-analyze-btn" class="acp-s-338b3a2b"><i class="fas fa-chart-line"></i> Analyze Economy</button>
        <button onclick="ca_ai_inflation()" id="ca-ai-inflation-btn" class="acp-s-e3064ecd"><i class="fas fa-coins"></i> Detect Inflation</button>
    </div>
    <div class="acp-s-11848735">
        <div class="acp-s-260dc000">Balance Advisor</div>
        <input type="text" id="ca-ai-issue" placeholder="Describe the balance issue…" class="acp-s-99127bf6">
        <select id="ca-ai-realm" class="acp-s-90934219">
            <option value="all">All Realms</option><option value="albion">Albion</option><option value="midgard">Midgard</option><option value="hibernia">Hibernia</option>
        </select>
        <button onclick="ca_ai_balance()" id="ca-ai-balance-btn" class="acp-s-bc97a1f3"><i class="fas fa-balance-scale"></i> Get Suggestions</button>
    </div>
</div>
<div id="ca-ai-result" class="acp-s-cb557355"></div>
<script>
const CA_AI_CSRF = '<?= $_ai_csrf ?>';
function ca_ai_show(text, state) {
    const el = document.getElementById('ca-ai-result');
    if (!el) return;
    el.textContent = text; el.style.display = 'block';
    el.style.color = state === 'err' ? '#e07070' : state === 'loading' ? '#444' : '#888';
    el.style.fontStyle = state === 'loading' ? 'italic' : 'normal';
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
function ca_ai_post(action, extra) {
    const fd = new FormData();
    fd.append('ca_ai_action', action); fd.append('csrf_token', CA_AI_CSRF);
    Object.entries(extra || {}).forEach(([k, v]) => fd.append(k, v));
    return fetch('acp.php?s=core_architect', { method: 'POST', body: fd })
        .then(r => { const ct = r.headers.get('content-type')||''; if (!ct.includes('application/json')) return r.text().then(txt => { throw new Error('Server returned HTML. Preview: '+txt.substring(0,100)); }); return r.json(); }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}
function ca_ai_analyze() { const btn=document.getElementById('ca-ai-analyze-btn'); if(btn) btn.disabled=true; ca_ai_show('Analyzing economy data…','loading'); ca_ai_post('analyze_economy').then(data=>{if(btn)btn.disabled=false;if(data.status==='ok')ca_ai_show(data.result?.suggestion||'—','ok');else ca_ai_show('Error: '+(data.message||'?'),'err');}).catch(e=>{if(btn)btn.disabled=false;ca_ai_show('Request failed: '+e,'err');}); }
function ca_ai_inflation() { const btn=document.getElementById('ca-ai-inflation-btn'); if(btn) btn.disabled=true; ca_ai_show('Scanning for inflation outliers…','loading'); ca_ai_post('detect_inflation').then(data=>{if(btn)btn.disabled=false;if(data.status==='ok')ca_ai_show(data.result?.suggestion||'—','ok');else ca_ai_show('Error: '+(data.message||'?'),'err');}).catch(e=>{if(btn)btn.disabled=false;ca_ai_show('Request failed: '+e,'err');}); }
function ca_ai_balance() { const issue=document.getElementById('ca-ai-issue')?.value.trim(); const realm=document.getElementById('ca-ai-realm')?.value||'all'; if(!issue){ca_ai_show('Please describe the balance issue.','err');return;} const btn=document.getElementById('ca-ai-balance-btn'); if(btn)btn.disabled=true; ca_ai_show('Generating balance suggestions…','loading'); ca_ai_post('suggest_balance',{issue,realm}).then(data=>{if(btn)btn.disabled=false;if(data.status==='ok')ca_ai_show(data.result?.suggestion||'—','ok');else ca_ai_show('Error: '+(data.message||'?'),'err');}).catch(e=>{if(btn)btn.disabled=false;ca_ai_show('Request failed: '+e,'err');}); }
</script>
<?php endif; ?>
