<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_ACP')) exit;

if (!isset($userPriv))      $userPriv      = (int)($_SESSION['priv_level'] ?? 0);
if (!isset($currentUserId)) $currentUserId = (int)($_SESSION['user_id']    ?? 0);

if ($userPriv < 3) return;

// ── AJAX Handler ──────────────────────────────────────────────
if (isset($_GET['dqc_ajax'])) {
    header('Content-Type: application/json');
    $action = $_GET['dqc_ajax'];

    if ($action === 'search_mobs') {
        $q = '%' . trim($_GET['q'] ?? '') . '%';
        $s = $db->prepare("SELECT Mob_ID as id, Name as name, Level as level, Region as region, Realm as realm FROM mob WHERE Name LIKE ? ORDER BY Name LIMIT 30");
        $s->execute([$q]);
        echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($action === 'search_npcs') {
        $q = '%' . trim($_GET['q'] ?? '') . '%';
        $s = $db->prepare("SELECT NpcTemplate_ID as id, Name as name, Level as level, ClassType as class_type FROM npctemplate WHERE Name LIKE ? ORDER BY Name LIMIT 30");
        $s->execute([$q]);
        echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
    if ($action === 'search_items') {
        $q = '%' . trim($_GET['q'] ?? '') . '%';
        $s = $db->prepare("SELECT ItemTemplate_ID as id, Name as name, Level as level, Item_Type as item_type, Price as price FROM itemtemplate WHERE Name LIKE ? ORDER BY Name LIMIT 30");
        $s->execute([$q]);
        echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    if ($action === 'load_quest') {
        $id = (int)($_GET['id'] ?? 0);
        $s = $db->prepare("SELECT * FROM dataquest WHERE ID = ?");
        $s->execute([$id]);
        echo json_encode($s->fetch(PDO::FETCH_ASSOC) ?: null);
        exit;
    }

    if ($action === 'save_quest' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = json_decode(file_get_contents('php://input'), true);
        if (!$raw) { echo json_encode(['ok' => false, 'error' => 'Invalid JSON']); exit; }
        checkToken($raw['csrf_token'] ?? '');

        $fields = [
            'Name'          => trim($raw['Name'] ?? ''),
            'StartType'     => (int)($raw['StartType'] ?? 0),
            'StartName'     => trim($raw['StartName'] ?? ''),
            'StartRegionID' => (int)($raw['StartRegionID'] ?? 0),
            'Description'   => trim($raw['Description'] ?? ''),
            'AcceptText'    => trim($raw['AcceptText'] ?? ''),
            'FinishText'    => trim($raw['FinishText'] ?? ''),
            'MinLevel'      => (int)($raw['MinLevel'] ?? 1),
            'MaxLevel'      => (int)($raw['MaxLevel'] ?? 50),
            'AllowedClasses'=> trim($raw['AllowedClasses'] ?? ''),
            'RewardXP'      => trim($raw['RewardXP'] ?? ''),
            'RewardMoney'   => trim($raw['RewardMoney'] ?? ''),
            'RewardRP'      => trim($raw['RewardRP'] ?? ''),
            'RewardBP'      => trim($raw['RewardBP'] ?? ''),
            'StepType'      => trim($raw['StepType'] ?? ''),
            'StepText'      => trim($raw['StepText'] ?? ''),
            'TargetName'    => trim($raw['TargetName'] ?? ''),
            'TargetText'    => trim($raw['TargetText'] ?? ''),
            'SourceName'    => trim($raw['SourceName'] ?? ''),
            'SourceText'    => trim($raw['SourceText'] ?? ''),
            'StepItemTemplates'       => trim($raw['StepItemTemplates'] ?? ''),
            'FinalRewardItemTemplates'=> trim($raw['FinalRewardItemTemplates'] ?? ''),
            'QuestDependency'         => trim($raw['QuestDependency'] ?? ''),
            'ClassType'     => trim($raw['ClassType'] ?? 'DOL.GS.Quests.DataQuest'),
        ];

        if (empty($fields['Name'])) {
            echo json_encode(['ok' => false, 'error' => 'Quest name is required']);
            exit;
        }

        $id = (int)($raw['ID'] ?? 0);
        if ($id > 0) {
            $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($fields)));
            $vals = array_values($fields);
            $vals[] = $id;
            $db->prepare("UPDATE dataquest SET $sets WHERE ID = ?")->execute($vals);
            aldhran_log("DQC_UPDATE", "Quest #{$id} updated: " . $fields['Name'], $currentUserId);
            echo json_encode(['ok' => true, 'id' => $id]);
        } else {
            $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($fields)));
            $phs  = implode(', ', array_fill(0, count($fields), '?'));
            $db->prepare("INSERT INTO dataquest ($cols) VALUES ($phs)")->execute(array_values($fields));
            $newId = (int)$db->lastInsertId();
            aldhran_log("DQC_CREATE", "Quest #{$newId} created: " . $fields['Name'], $currentUserId);
            echo json_encode(['ok' => true, 'id' => $newId]);
        }
        exit;
    }

    if ($action === 'delete_quest' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        checkToken($_POST['csrf_token'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare("DELETE FROM dataquest WHERE ID = ?")->execute([$id]);
            aldhran_log("DQC_DELETE", "Quest #{$id} deleted", $currentUserId);
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false]);
        }
        exit;
    }

    if ($action === 'suggest_rewards') {
        $level    = max(1, min(50, (int)($_GET['level'] ?? 1)));
        $base_xp  = (int)(pow($level, 2.5) * 100);
        $base_gold= (int)($level * 1.5);
        $base_rp  = $level >= 10 ? (int)($level * 50) : 0;
        echo json_encode(['xp' => $base_xp, 'gold' => $base_gold . 'p', 'rp' => $base_rp]);
        exit;
    }

    // ════════════════════════════════════════════════════════
    // AI ACTIONS
    // ════════════════════════════════════════════════════════

    if (in_array($action, ['ai_validate', 'ai_balance_reward', 'ai_suggest_quest', 'ai_generate_dialogue'])) {
        if (!class_exists('AiManager')) { echo json_encode(['error' => 'AiManager not available']); exit; }

        $raw = json_decode(file_get_contents('php://input'), true);
        if (!$raw) { echo json_encode(['error' => 'Invalid JSON']); exit; }
        checkToken($raw['csrf_token'] ?? '');

        global $botSettings;
        $ai = new AiManager($db, $botSettings, $currentUserId, $userPriv);

        // ── AI: Validate Steps ────────────────────────────────
        if ($action === 'ai_validate') {
            $result = $ai->request('dqc', 'validate_steps', [
                'quest_name'   => $raw['Name']      ?? '',
                'step_types'   => $raw['StepType']  ?? '',
                'step_texts'   => $raw['StepText']  ?? '',
                'target_name'  => $raw['TargetName']?? '',
                'source_name'  => $raw['SourceName']?? '',
                'start_type'   => $raw['StartType'] ?? 0,
                'min_level'    => $raw['MinLevel']  ?? 1,
                'max_level'    => $raw['MaxLevel']  ?? 50,
                'step_items'   => $raw['StepItemTemplates']  ?? '',
                'dependencies' => $raw['QuestDependency']    ?? '',
                'instruction'  => 'Validate this DAoC DataQuest for logical consistency. Check: step count matches text count, all targets are named, no unreachable steps, kill steps have a target, deliver steps have an item. Report specific errors and warnings. Then suggest one improvement.',
            ], ['save_suggestion' => true, 'target_id' => (int)($raw['ID'] ?? 0)]);
            echo json_encode($result);
            exit;
        }

        // ── AI: Balance Reward ────────────────────────────────
        if ($action === 'ai_balance_reward') {
            $step_count = count(array_filter(explode('|', $raw['StepType'] ?? ''), fn($s) => trim($s) !== ''));
            $result = $ai->request('dqc', 'balance_reward', [
                'quest_name'   => $raw['Name']       ?? '',
                'min_level'    => (int)($raw['MinLevel']  ?? 1),
                'max_level'    => (int)($raw['MaxLevel']  ?? 50),
                'step_count'   => $step_count,
                'current_xp'   => $raw['RewardXP']   ?? '0',
                'current_gold' => $raw['RewardMoney'] ?? '0',
                'current_rp'   => $raw['RewardRP']    ?? '0',
                'instruction'  => 'Evaluate the reward balance for this DAoC quest. Given the level range and number of steps, are the XP, gold, and RP rewards appropriate? Suggest specific values if they need adjusting. Consider that players at cap (level 50) care more about RP than XP.',
            ], ['save_suggestion' => true, 'target_id' => (int)($raw['ID'] ?? 0)]);
            echo json_encode($result);
            exit;
        }

        // ── AI: Suggest Quest Texts ───────────────────────────
        if ($action === 'ai_suggest_quest') {
            $result = $ai->request('dqc', 'suggest_quest', [
                'quest_name'  => $raw['Name']       ?? '',
                'start_name'  => $raw['StartName']  ?? '',
                'target_name' => $raw['TargetName'] ?? '',
                'step_types'  => $raw['StepType']   ?? '',
                'min_level'   => (int)($raw['MinLevel'] ?? 1),
                'max_level'   => (int)($raw['MaxLevel'] ?? 50),
                'instruction' => 'Write a compelling quest description (2-3 sentences for the journal entry), an accept text (what the NPC says when the player accepts), and a finish text (what the NPC says on completion). Keep it lore-appropriate for Dark Age of Camelot. Return as JSON: {"description":"...","accept_text":"...","finish_text":"..."}',
            ], ['save_suggestion' => true, 'target_id' => (int)($raw['ID'] ?? 0)]);
            echo json_encode($result);
            exit;
        }

        // ── AI: Generate Dialogue ─────────────────────────────
        if ($action === 'ai_generate_dialogue') {
            $result = $ai->request('dqc', 'generate_dialogue', [
                'npc_name'    => $raw['SourceName'] ?? $raw['StartName'] ?? 'Quest Giver',
                'quest_name'  => $raw['Name']       ?? '',
                'step_texts'  => $raw['StepText']   ?? '',
                'target_name' => $raw['TargetName'] ?? '',
                'instruction' => 'Write natural NPC dialogue for each quest step. The NPC should react to the player\'s progress with flavor text. Write one short line per step (pipe-separated to match StepText format). Keep it in the DAoC medieval fantasy tone.',
            ], ['save_suggestion' => true, 'target_id' => (int)($raw['ID'] ?? 0)]);
            echo json_encode($result);
            exit;
        }
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

$quest_list = $db->query("SELECT ID, Name, MinLevel, MaxLevel, StartName, AllowedClasses FROM dataquest ORDER BY ID DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

$csrf = generateToken();

// AI available?
$ai_active = isset($botSettings) && $botSettings->isActive() && $botSettings->hasAiConfigured();
?>

<style>
/* ── DQC Root ───────────────────────────────────────── */
.dqc-wrap {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 80px);
    background: #050505;
    font-family: 'Crimson Pro', Georgia, serif;
}

/* ── Toolbar ────────────────────────────────────────── */
.dqc-toolbar {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 18px;
    background: #080808;
    border-bottom: 1px solid #111;
    flex-shrink: 0;
}
.dqc-toolbar-title {
    font-family: 'Cinzel', serif;
    font-size: 0.65em;
    letter-spacing: 3px;
    color: #c5a059;
    text-transform: uppercase;
}
.dqc-btn {
    background: transparent;
    border: 1px solid #222;
    color: #666;
    padding: 6px 14px;
    font-family: 'Cinzel', serif;
    font-size: 0.58em;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 7px;
}
.dqc-btn:hover { border-color: #444; color: #aaa; }
.dqc-btn--gold { border-color: rgba(197,160,89,0.4); color: #c5a059; }
.dqc-btn--gold:hover { background: rgba(197,160,89,0.08); border-color: #c5a059; }
.dqc-btn--red  { border-color: rgba(224,112,112,0.3); color: #e07070; }
.dqc-btn--red:hover  { background: rgba(224,112,112,0.07); }
.dqc-btn--green{ border-color: rgba(80,200,120,0.3); color: #50c878; }
.dqc-btn--green:hover{ background: rgba(80,200,120,0.07); }
.dqc-status {
    margin-left: auto;
    font-size: 0.65em;
    color: #2a2a2a;
    font-family: 'Cinzel', serif;
    letter-spacing: 1px;
}
.dqc-status--saved { color: #50c878; }
.dqc-status--dirty { color: #c5a059; }

/* ── Main Layout ────────────────────────────────────── */
.dqc-main {
    display: grid;
    grid-template-columns: 240px 1fr 300px;
    flex: 1;
    overflow: hidden;
    min-height: 0;
}

/* ── Panel Base ─────────────────────────────────────── */
.dqc-panel {
    border-right: 1px solid #0e0e0e;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    min-height: 0;
}
.dqc-panel:last-child { border-right: none; }
.dqc-panel-head {
    padding: 10px 14px;
    background: rgba(197,160,89,0.04);
    border-bottom: 1px solid rgba(197,160,89,0.08);
    font-family: 'Cinzel', serif;
    font-size: 0.57em;
    letter-spacing: 2.5px;
    color: #666;
    text-transform: uppercase;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.dqc-panel-head i { color: #c5a059; opacity: 0.5; }
.dqc-panel-body {
    flex: 1;
    overflow-y: auto;
    padding: 12px;
}
.dqc-panel-body::-webkit-scrollbar { width: 3px; }
.dqc-panel-body::-webkit-scrollbar-thumb { background: #1a1a1a; }

/* ── LEFT: Quest List ───────────────────────────────── */
.dqc-search {
    width: 100%;
    background: #0a0a0a;
    border: 1px solid #1a1a1a;
    color: #888;
    padding: 7px 10px;
    font-size: 0.78em;
    outline: none;
    margin-bottom: 10px;
    box-sizing: border-box;
}
.dqc-search:focus { border-color: rgba(197,160,89,0.3); }

.dqc-quest-item {
    padding: 9px 12px;
    border: 1px solid transparent;
    margin-bottom: 3px;
    cursor: pointer;
    transition: all 0.15s;
}
.dqc-quest-item:hover { background: rgba(197,160,89,0.03); border-color: rgba(197,160,89,0.1); }
.dqc-quest-item.active { background: rgba(197,160,89,0.06); border-color: rgba(197,160,89,0.2); }
.dqc-quest-item-name {
    font-size: 0.82em;
    color: #ccc;
    font-weight: bold;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.dqc-quest-item-meta {
    font-size: 0.68em;
    color: #333;
    margin-top: 2px;
    font-family: 'Cinzel', serif;
    letter-spacing: 0.5px;
}
.dqc-new-btn {
    width: 100%;
    background: transparent;
    border: 1px dashed rgba(197,160,89,0.2);
    color: #444;
    padding: 8px;
    font-family: 'Cinzel', serif;
    font-size: 0.58em;
    letter-spacing: 2px;
    text-transform: uppercase;
    cursor: pointer;
    transition: all 0.2s;
    margin-bottom: 10px;
}
.dqc-new-btn:hover { border-color: rgba(197,160,89,0.5); color: #c5a059; background: rgba(197,160,89,0.03); }

/* ── MIDDLE: Stage / Form ───────────────────────────── */
.dqc-stage-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: #1a1a1a;
    font-family: 'Cinzel', serif;
    font-size: 0.65em;
    letter-spacing: 3px;
    text-transform: uppercase;
    gap: 12px;
}
.dqc-stage-empty i { font-size: 3em; opacity: 0.15; }

.dqc-form { display: none; padding: 0; }
.dqc-form.active { display: block; }

.dqc-section { margin-bottom: 0; border-bottom: 1px solid #0e0e0e; }
.dqc-section-toggle {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    cursor: pointer;
    font-family: 'Cinzel', serif;
    font-size: 0.6em;
    letter-spacing: 2px;
    color: #555;
    text-transform: uppercase;
    background: rgba(0,0,0,0.2);
    user-select: none;
    transition: color 0.2s;
}
.dqc-section-toggle:hover { color: #888; }
.dqc-section-toggle i.toggle-icon { margin-left: auto; font-size: 0.8em; transition: transform 0.2s; }
.dqc-section-toggle.open i.toggle-icon { transform: rotate(180deg); }
.dqc-section-body { padding: 14px 16px; display: none; }
.dqc-section-body.open { display: block; }

.dqc-field { margin-bottom: 14px; }
.dqc-field:last-child { margin-bottom: 0; }
.dqc-label {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-family: 'Cinzel', serif;
    font-size: 0.57em;
    letter-spacing: 1.5px;
    color: #444;
    text-transform: uppercase;
    margin-bottom: 5px;
}
.dqc-label-hint { color: #2a2a2a; font-size: 0.85em; letter-spacing: 0; text-transform: none; }
.dqc-input, .dqc-select, .dqc-textarea {
    width: 100%;
    background: #0a0a0a;
    border: 1px solid #151515;
    color: #bbb;
    padding: 7px 10px;
    font-size: 0.8em;
    font-family: sans-serif;
    outline: none;
    box-sizing: border-box;
    transition: border-color 0.2s;
}
.dqc-input:focus, .dqc-select:focus, .dqc-textarea:focus { border-color: rgba(197,160,89,0.3); }
.dqc-select option { background: #111; }
.dqc-textarea { min-height: 70px; resize: vertical; line-height: 1.5; }
.dqc-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.dqc-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }

.dqc-step-row { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }
.dqc-step-pill {
    display: flex;
    align-items: center;
    gap: 6px;
    background: rgba(197,160,89,0.06);
    border: 1px solid rgba(197,160,89,0.15);
    padding: 4px 10px;
    font-family: 'Cinzel', serif;
    font-size: 0.58em;
    letter-spacing: 1px;
    color: #c5a059;
    cursor: pointer;
    transition: background 0.2s;
}
.dqc-step-pill:hover { background: rgba(197,160,89,0.12); }
.dqc-step-pill.active { background: rgba(197,160,89,0.15); border-color: #c5a059; }

.dqc-validator { padding: 10px 14px; border-top: 1px solid #0e0e0e; flex-shrink: 0; min-height: 36px; }
.dqc-validator-msg { font-size: 0.7em; font-family: sans-serif; display: flex; align-items: center; gap: 8px; }
.dqc-validator-msg--ok    { color: #50c878; }
.dqc-validator-msg--warn  { color: #c5a059; }
.dqc-validator-msg--error { color: #e07070; }

/* ── RIGHT: Tabs ────────────────────────────────────── */
.dqc-tabs { display: flex; border-bottom: 1px solid #111; flex-shrink: 0; }
.dqc-tab {
    flex: 1;
    padding: 8px;
    text-align: center;
    font-family: 'Cinzel', serif;
    font-size: 0.55em;
    letter-spacing: 2px;
    color: #333;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    transition: color 0.2s;
    text-transform: uppercase;
}
.dqc-tab:hover { color: #666; }
.dqc-tab.active { color: #c5a059; border-bottom-color: #c5a059; }

.dqc-tab-panel { display: none; }
.dqc-tab-panel.active { display: flex; flex-direction: column; height: 100%; }

.dqc-lib-item {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 10px; border-bottom: 1px solid #0a0a0a;
    cursor: pointer; transition: background 0.15s;
}
.dqc-lib-item:hover { background: rgba(197,160,89,0.04); }
.dqc-lib-item-icon { font-size: 0.75em; color: #333; width: 16px; text-align: center; flex-shrink: 0; }
.dqc-lib-item-name { font-size: 0.78em; color: #888; flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.dqc-lib-item-meta { font-size: 0.65em; color: #2a2a2a; font-family: 'Cinzel', serif; flex-shrink: 0; }
.dqc-lib-loading { color: #222; font-size: 0.7em; font-family: 'Cinzel', serif; letter-spacing: 2px; text-align: center; padding: 20px; text-transform: uppercase; }

.dqc-inspector-empty { color: #1a1a1a; font-family: 'Cinzel', serif; font-size: 0.6em; letter-spacing: 2px; text-transform: uppercase; text-align: center; padding: 30px 20px; }
.dqc-inspector-prop { display: flex; justify-content: space-between; align-items: baseline; padding: 7px 0; border-bottom: 1px solid rgba(255,255,255,0.03); font-size: 0.78em; }
.dqc-inspector-key { color: #333; font-family: 'Cinzel', serif; font-size: 0.85em; letter-spacing: 1px; }
.dqc-inspector-val { color: #888; text-align: right; max-width: 60%; word-break: break-all; }

.dqc-sim-chat { flex: 1; overflow-y: auto; padding: 10px; background: #030303; }
.dqc-sim-chat::-webkit-scrollbar { width: 3px; }
.dqc-sim-chat::-webkit-scrollbar-thumb { background: #111; }
.dqc-sim-msg { margin-bottom: 10px; padding: 8px 12px; font-size: 0.8em; line-height: 1.5; border-left: 2px solid #1a1a1a; font-family: sans-serif; }
.dqc-sim-msg--npc    { border-left-color: #c5a059; color: #888; background: rgba(197,160,89,0.03); }
.dqc-sim-msg--sys    { border-left-color: #4a9ade; color: #4a6080; font-style: italic; font-size: 0.75em; }
.dqc-sim-msg--reward { border-left-color: #50c878; color: #4a7a5a; }
.dqc-sim-msg--speaker { font-family: 'Cinzel', serif; font-size: 0.75em; color: #555; letter-spacing: 1px; margin-bottom: 3px; }
.dqc-sim-actions { display: flex; gap: 6px; padding: 8px; border-top: 1px solid #0e0e0e; flex-shrink: 0; flex-wrap: wrap; }
.dqc-sim-action-btn { background: rgba(197,160,89,0.07); border: 1px solid rgba(197,160,89,0.2); color: #c5a059; padding: 5px 12px; font-family: 'Cinzel', serif; font-size: 0.58em; letter-spacing: 1px; cursor: pointer; transition: background 0.2s; flex: 1; }
.dqc-sim-action-btn:hover { background: rgba(197,160,89,0.14); }

/* ── AI Panel ───────────────────────────────────────── */
.dqc-ai-toolbar {
    display: none;
    gap: 6px;
    flex-wrap: wrap;
    padding: 8px 18px;
    border-bottom: 1px solid #111;
    background: rgba(197,160,89,0.02);
    flex-shrink: 0;
}
.dqc-ai-btn {
    background: transparent;
    border: 1px solid rgba(197,160,89,0.2);
    color: #c5a059;
    padding: 5px 12px;
    font-family: 'Cinzel', serif;
    font-size: 0.55em;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    cursor: pointer;
    transition: background 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.dqc-ai-btn:hover { background: rgba(197,160,89,0.08); }
.dqc-ai-btn:disabled { opacity: 0.3; cursor: not-allowed; }
.dqc-ai-result-panel {
    margin: 0 16px 12px;
    padding: 12px 14px;
    background: #050505;
    border: 1px solid rgba(197,160,89,0.12);
    font-size: 0.78em;
    color: #888;
    line-height: 1.6;
    font-family: sans-serif;
    white-space: pre-wrap;
    display: none;
    min-height: 200px;
    max-height: 400px;
    overflow-y: auto;
}
.dqc-ai-result-panel.visible { display: block; }
.dqc-ai-result-panel.loading { color: #333; font-style: italic; }
.dqc-ai-result-panel.err     { border-color: rgba(224,112,112,0.2); color: #e07070; }
.dqc-ai-apply-row { display: flex; gap: 6px; margin: 0 16px 12px; flex-wrap: wrap; }
.dqc-ai-apply-btn {
    background: transparent;
    border: 1px solid rgba(80,200,120,0.3);
    color: #50c878;
    padding: 5px 12px;
    font-family: 'Cinzel', serif;
    font-size: 0.55em;
    letter-spacing: 1px;
    text-transform: uppercase;
    cursor: pointer;
    transition: background 0.2s;
    display: none;
    align-items: center;
    gap: 5px;
}
.dqc-ai-apply-btn.visible { display: inline-flex; }
.dqc-ai-apply-btn:hover { background: rgba(80,200,120,0.06); }
</style>

<div class="dqc-wrap">

    <!-- ── Toolbar ── -->
    <div class="dqc-toolbar">
        <span class="dqc-toolbar-title"><i class="fas fa-scroll" style="margin-right:8px;"></i>Dataquest Creator</span>
        <button class="dqc-btn dqc-btn--gold" onclick="dqc.newQuest()">
            <i class="fas fa-plus"></i> New Quest
        </button>
        <button class="dqc-btn dqc-btn--green" onclick="dqc.saveQuest()" id="dqc-save-btn" disabled>
            <i class="fas fa-save"></i> Save Draft
        </button>
        <button class="dqc-btn dqc-btn--red" onclick="dqc.deleteQuest()" id="dqc-del-btn" style="display:none;">
            <i class="fas fa-trash"></i> Delete
        </button>
        <span class="dqc-status" id="dqc-status">No quest loaded</span>
    </div>

    <!-- ── AI Toolbar ── -->
    <?php if ($ai_active): ?>
    <div class="dqc-ai-toolbar" id="dqc-ai-toolbar">
        <button class="dqc-ai-btn" id="dqc-ai-validate-btn" onclick="dqc_ai_validate()">
            <i class="fas fa-check-circle"></i> Validate Steps
        </button>
        <button class="dqc-ai-btn" id="dqc-ai-reward-btn" onclick="dqc_ai_balance_reward()">
            <i class="fas fa-coins"></i> Balance Rewards
        </button>
        <button class="dqc-ai-btn" id="dqc-ai-suggest-btn" onclick="dqc_ai_suggest_quest()">
            <i class="fas fa-scroll"></i> Generate Texts
        </button>
        <button class="dqc-ai-btn" id="dqc-ai-dialogue-btn" onclick="dqc_ai_generate_dialogue()">
            <i class="fas fa-comments"></i> Generate Dialogue
        </button>
        <span class="acp-s-7224ae70">
            AI: <?= h(ucfirst($botSettings->getProvider())) ?>
        </span>
    </div>
    <?php endif; ?>

    <!-- ── Main ── -->
    <div class="dqc-main">

        <!-- ════ LEFT: Quest List ════ -->
        <div class="dqc-panel">
            <div class="dqc-panel-head"><i class="fas fa-list"></i> Quests</div>
            <div class="dqc-panel-body">
                <button class="dqc-new-btn" onclick="dqc.newQuest()">
                    <i class="fas fa-plus"></i> &nbsp;New Quest
                </button>
                <input type="text" class="dqc-search" placeholder="Filter quests..." id="dqc-quest-filter"
                       oninput="dqc.filterQuests(this.value)">
                <div id="dqc-quest-list">
                    <?php foreach ($quest_list as $q): ?>
                    <div class="dqc-quest-item" data-id="<?= $q['ID'] ?>" onclick="dqc.loadQuest(<?= $q['ID'] ?>)">
                        <div class="dqc-quest-item-name"><?= h($q['Name']) ?></div>
                        <div class="dqc-quest-item-meta">
                            LVL <?= (int)$q['MinLevel'] ?>–<?= (int)$q['MaxLevel'] ?>
                            <?php if ($q['StartName']): ?>· <?= h($q['StartName']) ?><?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ════ MIDDLE: Stage / Form ════ -->
        <div class="dqc-panel acp-s-0d193846">
            <div class="dqc-panel-head"><i class="fas fa-drafting-compass"></i> Quest Stage</div>

            <div class="acp-s-1726213d">

            <div class="dqc-stage-empty" id="dqc-stage-empty">
                <i class="fas fa-scroll"></i>
                Select or create a quest
                <span class="acp-s-c0f137a7">Use "New Quest" or click a quest from the list</span>
            </div>

            <div class="dqc-form" id="dqc-form">
                <div class="dqc-panel-body acp-s-57ce1f1b">

                    <!-- Section: Identity -->
                    <div class="dqc-section">
                        <div class="dqc-section-toggle open" onclick="dqc.toggleSection(this)">
                            <i class="fas fa-id-card"></i> Identity
                            <i class="fas fa-chevron-down toggle-icon"></i>
                        </div>
                        <div class="dqc-section-body open">
                            <div class="dqc-field">
                                <label class="dqc-label">Quest Name <span class="dqc-label-hint">required</span></label>
                                <input type="text" class="dqc-input" id="f-Name" placeholder="e.g. The Lost Tome" oninput="dqc.markDirty()">
                            </div>
                            <div class="dqc-row-2">
                                <div class="dqc-field">
                                    <label class="dqc-label">Min Level</label>
                                    <input type="number" class="dqc-input" id="f-MinLevel" value="1" min="1" max="50" oninput="dqc.markDirty(); dqc.suggestRewards()">
                                </div>
                                <div class="dqc-field">
                                    <label class="dqc-label">Max Level</label>
                                    <input type="number" class="dqc-input" id="f-MaxLevel" value="50" min="1" max="50" oninput="dqc.markDirty()">
                                </div>
                            </div>
                            <div class="dqc-field">
                                <label class="dqc-label">Allowed Classes <span class="dqc-label-hint">comma-separated IDs, empty = all</span></label>
                                <input type="text" class="dqc-input" id="f-AllowedClasses" placeholder="e.g. 1,2,3" oninput="dqc.markDirty()">
                            </div>
                            <div class="dqc-field">
                                <label class="dqc-label">Quest Dependency <span class="dqc-label-hint">ID of required quest</span></label>
                                <input type="text" class="dqc-input" id="f-QuestDependency" placeholder="e.g. 42" oninput="dqc.markDirty()">
                            </div>
                        </div>
                    </div>

                    <!-- Section: Start & Source -->
                    <div class="dqc-section">
                        <div class="dqc-section-toggle" onclick="dqc.toggleSection(this)">
                            <i class="fas fa-map-marker-alt"></i> Start & Source NPC
                            <i class="fas fa-chevron-down toggle-icon"></i>
                        </div>
                        <div class="dqc-section-body">
                            <div class="dqc-row-2">
                                <div class="dqc-field">
                                    <label class="dqc-label">Start Type</label>
                                    <select class="dqc-select" id="f-StartType" onchange="dqc.markDirty()">
                                        <option value="0">0 – NPC</option>
                                        <option value="1">1 – Item</option>
                                        <option value="2">2 – Death</option>
                                        <option value="3">3 – Timer</option>
                                        <option value="4">4 – Random</option>
                                    </select>
                                </div>
                                <div class="dqc-field">
                                    <label class="dqc-label">Start Region ID</label>
                                    <input type="number" class="dqc-input" id="f-StartRegionID" value="0" oninput="dqc.markDirty()">
                                </div>
                            </div>
                            <div class="dqc-field">
                                <label class="dqc-label">Start Name</label>
                                <input type="text" class="dqc-input" id="f-StartName" placeholder="e.g. Guard Thomas" oninput="dqc.markDirty()">
                            </div>
                            <div class="dqc-field">
                                <label class="dqc-label">Source Name</label>
                                <input type="text" class="dqc-input" id="f-SourceName" placeholder="NPC that assigns quest" oninput="dqc.markDirty()">
                            </div>
                            <div class="dqc-field">
                                <label class="dqc-label">Source Text</label>
                                <textarea class="dqc-textarea" id="f-SourceText" placeholder="What the source NPC says..." oninput="dqc.markDirty()"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Section: Dialogue -->
                    <div class="dqc-section">
                        <div class="dqc-section-toggle" onclick="dqc.toggleSection(this)">
                            <i class="fas fa-comments"></i> Dialogue
                            <i class="fas fa-chevron-down toggle-icon"></i>
                        </div>
                        <div class="dqc-section-body">
                            <div class="dqc-field">
                                <label class="dqc-label">Description</label>
                                <textarea class="dqc-textarea" id="f-Description" placeholder="Quest description in journal..." oninput="dqc.markDirty()"></textarea>
                            </div>
                            <div class="dqc-field">
                                <label class="dqc-label">Accept Text</label>
                                <textarea class="dqc-textarea" id="f-AcceptText" placeholder="Text when quest is accepted..." oninput="dqc.markDirty()"></textarea>
                            </div>
                            <div class="dqc-field">
                                <label class="dqc-label">Finish Text</label>
                                <textarea class="dqc-textarea" id="f-FinishText" placeholder="Text when quest is completed..." oninput="dqc.markDirty()"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Section: Steps -->
                    <div class="dqc-section">
                        <div class="dqc-section-toggle" onclick="dqc.toggleSection(this)">
                            <i class="fas fa-shoe-prints"></i> Steps
                            <i class="fas fa-chevron-down toggle-icon"></i>
                        </div>
                        <div class="dqc-section-body">
                            <div class="dqc-field">
                                <label class="dqc-label">Step Types <span class="dqc-label-hint">pipe-separated: 0=Kill 1=KillWG 2=Deliver 3=Interact 4=Whisper 5=Search 6=Collect 7=Dialog</span></label>
                                <input type="text" class="dqc-input" id="f-StepType" placeholder="e.g. 4|0|2" oninput="dqc.markDirty(); dqc.updateStepPills()">
                            </div>
                            <div id="dqc-step-pills" class="dqc-step-row"></div>
                            <div class="dqc-field">
                                <label class="dqc-label">Step Texts <span class="dqc-label-hint">pipe-separated</span></label>
                                <textarea class="dqc-textarea" id="f-StepText" placeholder="Talk to the guard|Kill 5 wolves|Return to guard" oninput="dqc.markDirty()"></textarea>
                            </div>
                            <div class="dqc-field">
                                <label class="dqc-label">Target Name</label>
                                <input type="text" class="dqc-input" id="f-TargetName" placeholder="e.g. Grey Wolf" oninput="dqc.markDirty()">
                            </div>
                            <div class="dqc-field">
                                <label class="dqc-label">Target Text</label>
                                <input type="text" class="dqc-input" id="f-TargetText" placeholder="Interaction text at target" oninput="dqc.markDirty()">
                            </div>
                            <div class="dqc-field">
                                <label class="dqc-label">Step Item Templates</label>
                                <input type="text" class="dqc-input" id="f-StepItemTemplates" placeholder="e.g. wolf_pelt|" oninput="dqc.markDirty()">
                            </div>
                        </div>
                    </div>

                    <!-- Section: Rewards -->
                    <div class="dqc-section">
                        <div class="dqc-section-toggle" onclick="dqc.toggleSection(this)">
                            <i class="fas fa-gift"></i> Rewards
                            <i class="fas fa-chevron-down toggle-icon"></i>
                        </div>
                        <div class="dqc-section-body">
                            <div class="acp-s-a7409f75">
                                <button type="button" class="dqc-btn acp-s-9b11abc3" onclick="dqc.suggestRewards(true)">
                                    <i class="fas fa-magic"></i> Suggest Defaults
                                </button>
                                <span id="dqc-reward-suggest-info" class="acp-s-21362975"></span>
                            </div>
                            <div class="dqc-row-2">
                                <div class="dqc-field">
                                    <label class="dqc-label">Reward XP</label>
                                    <input type="text" class="dqc-input" id="f-RewardXP" placeholder="e.g. 5000" oninput="dqc.markDirty()">
                                </div>
                                <div class="dqc-field">
                                    <label class="dqc-label">Reward Money</label>
                                    <input type="text" class="dqc-input" id="f-RewardMoney" placeholder="e.g. 2p" oninput="dqc.markDirty()">
                                </div>
                            </div>
                            <div class="dqc-row-2">
                                <div class="dqc-field">
                                    <label class="dqc-label">Reward RP</label>
                                    <input type="text" class="dqc-input" id="f-RewardRP" placeholder="e.g. 500" oninput="dqc.markDirty()">
                                </div>
                                <div class="dqc-field">
                                    <label class="dqc-label">Reward BP</label>
                                    <input type="text" class="dqc-input" id="f-RewardBP" placeholder="e.g. 100" oninput="dqc.markDirty()">
                                </div>
                            </div>
                            <div class="dqc-field">
                                <label class="dqc-label">Final Reward Item Templates</label>
                                <input type="text" class="dqc-input" id="f-FinalRewardItemTemplates" placeholder="e.g. iron_sword|leather_cap" oninput="dqc.markDirty()">
                            </div>
                        </div>
                    </div>

                </div><!-- /panel-body -->
            </div><!-- /dqc-form -->

            <!-- AI Result Panel -->
            <?php if ($ai_active): ?>
            <div id="dqc-ai-result" class="dqc-ai-result-panel"></div>
            <div class="dqc-ai-apply-row">
                <button class="dqc-ai-apply-btn" id="dqc-ai-apply-quest-btn" onclick="dqc_ai_apply_quest_texts()">
                    <i class="fas fa-check"></i> Apply Quest Texts
                </button>
                <button class="dqc-ai-apply-btn" id="dqc-ai-apply-dialogue-btn" onclick="dqc_ai_apply_dialogue()">
                    <i class="fas fa-check"></i> Apply Dialogue
                </button>
            </div>
            <?php endif; ?>

            </div><!-- /scroll-wrapper -->

            <div class="dqc-validator">
                <div class="dqc-validator-msg" id="dqc-validator-msg">
                    <i class="fas fa-circle acp-s-9b11abc3"></i>
                    <span>No issues detected.</span>
                </div>
            </div>

        </div><!-- /middle panel -->

        <!-- ════ RIGHT: Tabs ════ -->
        <div class="dqc-panel acp-s-a30faf26">
            <div class="dqc-tabs">
                <div class="dqc-tab active" onclick="dqc.switchTab('library')">Library</div>
                <div class="dqc-tab" onclick="dqc.switchTab('inspector')">Inspector</div>
                <div class="dqc-tab" onclick="dqc.switchTab('simulator')">Simulator</div>
            </div>

            <!-- Library Tab -->
            <div class="dqc-tab-panel active acp-s-1436bde4" id="tab-library">
                <div class="acp-s-17af6920">
                    <div class="acp-s-247fb328">
                        <button class="dqc-btn dqc-btn--gold acp-s-f276b1e2" onclick="dqc.lib.setType('mobs')" id="lib-btn-mobs">Mobs</button>
                        <button class="dqc-btn acp-s-f276b1e2" onclick="dqc.lib.setType('npcs')" id="lib-btn-npcs">NPCs</button>
                        <button class="dqc-btn acp-s-f276b1e2" onclick="dqc.lib.setType('items')" id="lib-btn-items">Items</button>
                    </div>
                    <input type="text" class="dqc-search acp-s-e7ec5403" placeholder="Search..." id="dqc-lib-search"
                           oninput="dqc.lib.search(this.value)">
                </div>
                <div id="dqc-lib-results" class="acp-s-0f9feb49">
                    <div class="dqc-lib-loading">Type to search</div>
                </div>
                <div class="acp-s-3248ebd7">
                    Click an entry to insert into the active field
                </div>
            </div>

            <!-- Inspector Tab -->
            <div class="dqc-tab-panel acp-s-1436bde4" id="tab-inspector">
                <div class="dqc-panel-body" id="dqc-inspector-body">
                    <div class="dqc-inspector-empty">
                        <i class="fas fa-mouse-pointer acp-s-e84dbd14"></i>
                        Load a quest to inspect
                    </div>
                </div>
            </div>

            <!-- Simulator Tab -->
            <div class="dqc-tab-panel acp-s-1436bde4" id="tab-simulator">
                <div class="acp-s-17af6920">
                    <button class="dqc-btn dqc-btn--gold acp-s-1f13dd03" onclick="dqc.sim.run()">
                        <i class="fas fa-play"></i> Run Simulation
                    </button>
                </div>
                <div class="dqc-sim-chat" id="dqc-sim-chat">
                    <div class="dqc-lib-loading acp-s-3ae1f62a">Load a quest and press Run Simulation</div>
                </div>
                <div class="dqc-sim-actions acp-s-cb458930" id="dqc-sim-actions">
                    <button class="dqc-sim-action-btn" onclick="dqc.sim.accept()">Accept Quest</button>
                    <button class="dqc-sim-action-btn" onclick="dqc.sim.decline()">Decline</button>
                </div>
            </div>

        </div><!-- /right panel -->

    </div><!-- /dqc-main -->
</div><!-- /dqc-wrap -->

<script>
const DQC_CSRF    = <?= json_encode($csrf) ?>;
const DQC_AJAXURL = 'acp.php?s=dqc&dqc_ajax=';

const dqc = {
    current: null,
    dirty: false,
    _stepTypes: {0:'Kill',1:'KillWG',2:'Deliver',3:'Interact',4:'Whisper',5:'Search',6:'Collect',7:'Dialog'},
    _fields: ['Name','MinLevel','MaxLevel','AllowedClasses','QuestDependency',
              'StartType','StartRegionID','StartName','SourceName','SourceText',
              'Description','AcceptText','FinishText',
              'StepType','StepText','TargetName','TargetText','StepItemTemplates',
              'RewardXP','RewardMoney','RewardRP','RewardBP','FinalRewardItemTemplates'],

    setStatus(msg, cls = '') {
        const el = document.getElementById('dqc-status');
        el.textContent = msg;
        el.className = 'dqc-status' + (cls ? ' dqc-status--' + cls : '');
    },

    markDirty() {
        this.dirty = true;
        this.setStatus('Unsaved changes', 'dirty');
        document.getElementById('dqc-save-btn').disabled = false;
        this.validate();
    },

    filterQuests(q) {
        q = q.toLowerCase();
        document.querySelectorAll('.dqc-quest-item').forEach(el => {
            const name = el.querySelector('.dqc-quest-item-name').textContent.toLowerCase();
            el.style.display = name.includes(q) ? '' : 'none';
        });
    },

    newQuest() {
        if (this.dirty && !confirm('You have unsaved changes. Continue?')) return;
        this.current = { ID: 0 };
        this.dirty = false;
        this._fields.forEach(f => { const el = document.getElementById('f-' + f); if (el) el.value = ''; });
        document.getElementById('f-MinLevel').value = '1';
        document.getElementById('f-MaxLevel').value = '50';
        document.getElementById('f-StartType').value = '0';
        document.querySelectorAll('.dqc-quest-item').forEach(e => e.classList.remove('active'));
        document.getElementById('dqc-stage-empty').style.display = 'none';
        document.getElementById('dqc-form').classList.add('active');
        document.getElementById('dqc-del-btn').style.display = 'none';
        document.getElementById('dqc-save-btn').disabled = false;
        this.setStatus('New quest', 'dirty');
        this.updateStepPills();
        this.updateInspector();
        this._showAiToolbar();
    },

    async loadQuest(id) {
        if (this.dirty && !confirm('You have unsaved changes. Continue?')) return;
        this.setStatus('Loading...', '');
        try {
            const res  = await fetch(DQC_AJAXURL + 'load_quest&id=' + id).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
            const data = await res.json();
            if (!data) { this.setStatus('Not found', ''); return; }
            this.current = data;
            this.dirty = false;
            this._fields.forEach(f => { const el = document.getElementById('f-' + f); if (el) el.value = data[f] ?? ''; });
            document.querySelectorAll('.dqc-quest-item').forEach(e => e.classList.toggle('active', parseInt(e.dataset.id) === id));
            document.getElementById('dqc-stage-empty').style.display = 'none';
            document.getElementById('dqc-form').classList.add('active');
            document.getElementById('dqc-del-btn').style.display = '';
            document.getElementById('dqc-save-btn').disabled = true;
            this.setStatus('Quest #' + id + ' loaded', 'saved');
            this.updateStepPills();
            this.updateInspector();
            this.validate();
            this._showAiToolbar();
        } catch(e) { this.setStatus('Load error', ''); }
    },

    _showAiToolbar() {
        const tb = document.getElementById('dqc-ai-toolbar');
        if (tb) tb.style.display = 'flex';
        if (typeof dqc_ai_hide === 'function') dqc_ai_hide();
    },

    async saveQuest() {
        const name = document.getElementById('f-Name').value.trim();
        if (!name) { alert('Quest name is required.'); return; }
        this.setStatus('Saving...', '');
        const payload = { ID: this.current?.ID ?? 0, csrf_token: DQC_CSRF };
        this._fields.forEach(f => { const el = document.getElementById('f-' + f); if (el) payload[f] = el.value; });
        try {
            const res  = await fetch(DQC_AJAXURL + 'save_quest', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
            const data = await res.json();
            if (data.ok) {
                const newId  = data.id;
                const wasNew = !this.current?.ID;
                this.current = { ...this.current, ...payload, ID: newId };
                this.dirty = false;
                document.getElementById('dqc-save-btn').disabled = true;
                document.getElementById('dqc-del-btn').style.display = '';
                this.setStatus('Saved as #' + newId, 'saved');
                if (wasNew) this.addToList(newId, name, payload.MinLevel, payload.MaxLevel);
                else this.updateListItem(newId, name, payload.MinLevel, payload.MaxLevel);
                this.updateInspector();
            } else { this.setStatus('Save failed: ' + (data.error || '?'), ''); }
        } catch(e) { this.setStatus('Save error', ''); }
    },

    async deleteQuest() {
        const id = this.current?.ID;
        if (!id || !confirm('Delete quest #' + id + '? This cannot be undone.')) return;
        const fd = new FormData();
        fd.append('csrf_token', DQC_CSRF);
        fd.append('id', id);
        const res  = await fetch(DQC_AJAXURL + 'delete_quest', { method: 'POST', body: fd }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
        const data = await res.json();
        if (data.ok) {
            document.querySelector('.dqc-quest-item[data-id="' + id + '"]')?.remove();
            document.getElementById('dqc-stage-empty').style.display = '';
            document.getElementById('dqc-form').classList.remove('active');
            document.getElementById('dqc-del-btn').style.display = 'none';
            const tb = document.getElementById('dqc-ai-toolbar');
            if (tb) tb.style.display = 'none';
            this.current = null; this.dirty = false;
            this.setStatus('Quest deleted', '');
        }
    },

    addToList(id, name, min, max) {
        const list = document.getElementById('dqc-quest-list');
        const el   = document.createElement('div');
        el.className  = 'dqc-quest-item active';
        el.dataset.id = id;
        el.onclick    = () => dqc.loadQuest(id);
        el.innerHTML  = `<div class="dqc-quest-item-name">${name}</div><div class="dqc-quest-item-meta">LVL ${min}–${max}</div>`;
        list.prepend(el);
        document.querySelectorAll('.dqc-quest-item').forEach(e => e.classList.toggle('active', parseInt(e.dataset.id) === id));
    },

    updateListItem(id, name, min, max) {
        const el = document.querySelector('.dqc-quest-item[data-id="' + id + '"]');
        if (el) {
            el.querySelector('.dqc-quest-item-name').textContent = name;
            el.querySelector('.dqc-quest-item-meta').textContent = `LVL ${min}–${max}`;
        }
    },

    toggleSection(header) {
        header.classList.toggle('open');
        header.nextElementSibling.classList.toggle('open');
    },

    switchTab(name) {
        document.querySelectorAll('.dqc-tab').forEach((t,i) => t.classList.toggle('active', ['library','inspector','simulator'][i] === name));
        ['library','inspector','simulator'].forEach(n => { const el = document.getElementById('tab-' + n); if (el) el.classList.toggle('active', n === name); });
    },

    updateStepPills() {
        const val  = (document.getElementById('f-StepType')?.value || '').trim();
        const wrap = document.getElementById('dqc-step-pills');
        if (!wrap) return;
        if (!val) { wrap.innerHTML = ''; return; }
        wrap.innerHTML = val.split('|').map((t, i) => {
            const label = this._stepTypes[parseInt(t)] || ('Type ' + t);
            return `<div class="dqc-step-pill"><i class="fas fa-shoe-prints acp-s-39a2a027"></i>${i+1}. ${label}</div>`;
        }).join('');
    },

    async suggestRewards(force = false) {
        const level    = parseInt(document.getElementById('f-MinLevel')?.value) || 1;
        const hasValues = document.getElementById('f-RewardXP').value.trim();
        if (hasValues && !force) return;
        try {
            const res = await fetch(DQC_AJAXURL + 'suggest_rewards&level=' + level).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
            const d   = await res.json();
            document.getElementById('f-RewardXP').value    = d.xp;
            document.getElementById('f-RewardMoney').value = d.gold;
            document.getElementById('f-RewardRP').value    = d.rp;
            document.getElementById('dqc-reward-suggest-info').textContent = `Suggested for level ${level}`;
            this.markDirty();
        } catch(e) {}
    },

    updateInspector() {
        const body = document.getElementById('dqc-inspector-body');
        if (!this.current || !this.current.ID) {
            body.innerHTML = '<div class="dqc-inspector-empty"><i class="fas fa-mouse-pointer acp-s-2ca61209"></i>Load a quest to inspect</div>';
            return;
        }
        const show = ['ID','Name','MinLevel','MaxLevel','StartType','StartName','AllowedClasses','RewardXP','RewardMoney','RewardRP','RewardBP'];
        body.innerHTML = show.map(k => `<div class="dqc-inspector-prop"><span class="dqc-inspector-key">${k}</span><span class="dqc-inspector-val">${this.current[k] ?? '—'}</span></div>`).join('');
    },

    validate() {
        const el        = document.getElementById('dqc-validator-msg');
        const name      = document.getElementById('f-Name')?.value.trim();
        const minLvl    = parseInt(document.getElementById('f-MinLevel')?.value);
        const maxLvl    = parseInt(document.getElementById('f-MaxLevel')?.value);
        const stepTypes = document.getElementById('f-StepType')?.value.trim();
        const stepTexts = document.getElementById('f-StepText')?.value.trim();
        const errors = [], warnings = [];
        if (!name) errors.push('Quest name is required.');
        if (minLvl > maxLvl) errors.push('Min level cannot exceed max level.');
        if (stepTypes && stepTexts) {
            const t = stepTypes.split('|').length, s = stepTexts.split('|').length;
            if (t !== s) warnings.push(`Step type count (${t}) ≠ step text count (${s}).`);
        }
        if (!document.getElementById('f-StartName')?.value.trim()) warnings.push('No start NPC/item defined.');
        if (errors.length) {
            el.className = 'dqc-validator-msg dqc-validator-msg--error';
            el.innerHTML = '<i class="fas fa-exclamation-circle"></i><span>' + errors.join(' ') + '</span>';
        } else if (warnings.length) {
            el.className = 'dqc-validator-msg dqc-validator-msg--warn';
            el.innerHTML = '<i class="fas fa-exclamation-triangle"></i><span>' + warnings.join(' ') + '</span>';
        } else {
            el.className = 'dqc-validator-msg dqc-validator-msg--ok';
            el.innerHTML = '<i class="fas fa-check-circle"></i><span>No issues detected.</span>';
        }
    },

    lib: {
        type: 'mobs',
        _timer: null,
        icons: { mobs: 'fa-dragon', npcs: 'fa-user-tie', items: 'fa-box' },
        setType(t) {
            this.type = t;
            document.getElementById('dqc-lib-search').value = '';
            document.getElementById('dqc-lib-results').innerHTML = '<div class="dqc-lib-loading">Type to search</div>';
        },
        search(q) {
            clearTimeout(this._timer);
            if (q.length < 2) { document.getElementById('dqc-lib-results').innerHTML = '<div class="dqc-lib-loading">Type to search</div>'; return; }
            this._timer = setTimeout(() => this._fetch(q), 250);
        },
        async _fetch(q) {
            const res_el = document.getElementById('dqc-lib-results');
            res_el.innerHTML = '<div class="dqc-lib-loading">Searching...</div>';
            const action = this.type === 'mobs' ? 'search_mobs' : this.type === 'npcs' ? 'search_npcs' : 'search_items';
            try {
                const res  = await fetch(DQC_AJAXURL + action + '&q=' + encodeURIComponent(q)).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
                const data = await res.json();
                if (!data.length) { res_el.innerHTML = '<div class="dqc-lib-loading">No results</div>'; return; }
                const icon = this.icons[this.type];
                res_el.innerHTML = data.map(item => {
                    const meta = item.level ? 'LVL ' + item.level : (item.price ? item.price + 'c' : '');
                    return `<div class="dqc-lib-item" onclick="dqc.lib.insert('${item.name?.replace(/'/g,"\\'")}', '${item.id}')">
                        <i class="fas ${icon} dqc-lib-item-icon"></i>
                        <span class="dqc-lib-item-name">${item.name}</span>
                        <span class="dqc-lib-item-meta">${meta}</span>
                    </div>`;
                }).join('');
            } catch(e) { res_el.innerHTML = '<div class="dqc-lib-loading">Error</div>'; }
        },
        insert(name, id) {
            const focused = document.activeElement;
            const targets = { 'f-StartName': name, 'f-SourceName': name, 'f-TargetName': name, 'f-StepItemTemplates': id, 'f-FinalRewardItemTemplates': id };
            if (focused && targets[focused.id] !== undefined) { focused.value = targets[focused.id]; dqc.markDirty(); return; }
            navigator.clipboard?.writeText(name).then(() => { document.getElementById('dqc-status').textContent = '"' + name + '" copied'; });
        }
    },

    sim: {
        _step: 0,
        run() {
            const chat   = document.getElementById('dqc-sim-chat');
            const actions= document.getElementById('dqc-sim-actions');
            const name   = document.getElementById('f-Name')?.value || 'Unnamed Quest';
            const src    = document.getElementById('f-SourceName')?.value || 'Quest Giver';
            const srcTxt = document.getElementById('f-SourceText')?.value || '(no source text)';
            const accept = document.getElementById('f-AcceptText')?.value || '(no accept text)';
            const steps  = (document.getElementById('f-StepText')?.value || '').split('|').filter(Boolean);
            const xp     = document.getElementById('f-RewardXP')?.value || '0';
            const gold   = document.getElementById('f-RewardMoney')?.value || '0';
            const rp     = document.getElementById('f-RewardRP')?.value || '0';
            this._step = 0;
            dqc.switchTab('simulator');
            chat.innerHTML = `
                <div class="dqc-sim-msg dqc-sim-msg--sys"><i class="fas fa-play acp-s-487b71ac"></i>Simulating: <strong>${name}</strong></div>
                <div class="dqc-sim-msg dqc-sim-msg--npc"><div class="dqc-sim-msg--speaker">${src}</div>${srcTxt}</div>`;
            actions.style.display = 'flex';
            actions.innerHTML = `<button class="dqc-sim-action-btn" onclick="dqc.sim.accept()">Accept Quest</button><button class="dqc-sim-action-btn acp-s-cb1e3da1" onclick="dqc.sim.decline()">Decline</button>`;
            this._steps = steps; this._acceptText = accept; this._xp = xp; this._gold = gold; this._rp = rp; this._name = name; this._src = src;
        },
        accept() {
            const chat = document.getElementById('dqc-sim-chat'), actions = document.getElementById('dqc-sim-actions');
            chat.innerHTML += `<div class="dqc-sim-msg dqc-sim-msg--npc"><div class="dqc-sim-msg--speaker">${this._src}</div>${this._acceptText}</div>`;
            chat.innerHTML += `<div class="dqc-sim-msg dqc-sim-msg--sys"><i class="fas fa-journal-whills acp-s-487b71ac"></i>Quest accepted: <strong>${this._name}</strong></div>`;
            this._steps.length ? (this._step = 0, this._showStep(chat, actions)) : this._finish(chat, actions);
        },
        _showStep(chat, actions) {
            if (this._step >= this._steps.length) { this._finish(chat, actions); return; }
            chat.innerHTML += `<div class="dqc-sim-msg dqc-sim-msg--sys"><i class="fas fa-shoe-prints acp-s-487b71ac"></i>Step ${this._step + 1}: ${this._steps[this._step]}</div>`;
            actions.innerHTML = `<button class="dqc-sim-action-btn" onclick="dqc.sim.nextStep()">Complete Step</button>`;
            chat.scrollTop = chat.scrollHeight;
        },
        nextStep() { this._step++; this._showStep(document.getElementById('dqc-sim-chat'), document.getElementById('dqc-sim-actions')); },
        _finish(chat, actions) {
            const finishTxt = document.getElementById('f-FinishText')?.value || '(no finish text)';
            chat.innerHTML += `<div class="dqc-sim-msg dqc-sim-msg--npc"><div class="dqc-sim-msg--speaker">${this._src}</div>${finishTxt}</div>`;
            chat.innerHTML += `<div class="dqc-sim-msg dqc-sim-msg--reward"><i class="fas fa-gift acp-s-487b71ac"></i><strong>Rewards:</strong> ${this._xp} XP · ${this._gold} Gold · ${this._rp} RP</div>`;
            chat.innerHTML += `<div class="dqc-sim-msg dqc-sim-msg--sys"><i class="fas fa-check acp-s-487b71ac"></i>Quest complete.</div>`;
            actions.innerHTML = `<button class="dqc-sim-action-btn" onclick="dqc.sim.run()">Restart</button>`;
            chat.scrollTop = chat.scrollHeight;
        },
        decline() {
            const chat = document.getElementById('dqc-sim-chat'), actions = document.getElementById('dqc-sim-actions');
            chat.innerHTML += `<div class="dqc-sim-msg dqc-sim-msg--sys acp-s-6ba8f8cb">Quest declined.</div>`;
            actions.style.display = 'none';
            chat.scrollTop = chat.scrollHeight;
        }
    }
};

<?php if ($ai_active): ?>
// ── AI Functions ───────────────────────────────────────────────
let dqc_ai_last = {};

function dqc_ai_get_payload() {
    const fields = ['Name','MinLevel','MaxLevel','AllowedClasses','QuestDependency',
                    'StartType','StartRegionID','StartName','SourceName','SourceText',
                    'Description','AcceptText','FinishText',
                    'StepType','StepText','TargetName','TargetText','StepItemTemplates',
                    'RewardXP','RewardMoney','RewardRP','RewardBP','FinalRewardItemTemplates'];
    const payload = { csrf_token: DQC_CSRF, ID: dqc.current?.ID || 0 };
    fields.forEach(f => { const el = document.getElementById('f-'+f); if (el) payload[f] = el.value; });
    return payload;
}

function dqc_ai_show(text, state='ok') {
    const el = document.getElementById('dqc-ai-result');
    if (!el) return;
    el.textContent = text;
    el.className = 'dqc-ai-result-panel visible ' + state;
}

function dqc_ai_hide() {
    const el = document.getElementById('dqc-ai-result');
    if (el) { el.className = 'dqc-ai-result-panel'; el.textContent = ''; }
    document.querySelectorAll('.dqc-ai-apply-btn').forEach(b => b.classList.remove('visible'));
}

async function dqc_ai_validate() {
    const btn = document.getElementById('dqc-ai-validate-btn');
    if (btn) btn.disabled = true;
    dqc_ai_show('Validating quest logic…', 'loading');
    try {
        const res  = await fetch(DQC_AJAXURL + 'ai_validate', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(dqc_ai_get_payload()) }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
        const data = await res.json();
        if (btn) btn.disabled = false;
        data.status === 'ok' ? dqc_ai_show(data.result?.suggestion || 'No issues found.') : dqc_ai_show('Error: ' + (data.message || data.error || '?'), 'err');
    } catch(e) { if (btn) btn.disabled = false; dqc_ai_show('Request failed: '+e, 'err'); }
}

async function dqc_ai_balance_reward() {
    const btn = document.getElementById('dqc-ai-reward-btn');
    if (btn) btn.disabled = true;
    dqc_ai_show('Analyzing reward balance…', 'loading');
    try {
        const res  = await fetch(DQC_AJAXURL + 'ai_balance_reward', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(dqc_ai_get_payload()) }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
        const data = await res.json();
        if (btn) btn.disabled = false;
        data.status === 'ok' ? dqc_ai_show(data.result?.suggestion || '—') : dqc_ai_show('Error: ' + (data.message || '?'), 'err');
    } catch(e) { if (btn) btn.disabled = false; dqc_ai_show('Request failed: '+e, 'err'); }
}

async function dqc_ai_suggest_quest() {
    const btn = document.getElementById('dqc-ai-suggest-btn');
    if (btn) btn.disabled = true;
    dqc_ai_show('Generating quest texts…', 'loading');
    try {
        const res  = await fetch(DQC_AJAXURL + 'ai_suggest_quest', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(dqc_ai_get_payload()) }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
        const data = await res.json();
        if (btn) btn.disabled = false;
        if (data.status === 'ok') {
            const suggestion = data.result?.suggestion || '';
            dqc_ai_show(suggestion);
            try {
                const match = suggestion.match(/\{[\s\S]*\}/);
                if (match) {
                    dqc_ai_last.quest_texts = JSON.parse(match[0]);
                    document.getElementById('dqc-ai-apply-quest-btn')?.classList.add('visible');
                }
            } catch(e) {}
        } else dqc_ai_show('Error: ' + (data.message || '?'), 'err');
    } catch(e) { if (btn) btn.disabled = false; dqc_ai_show('Request failed: '+e, 'err'); }
}

async function dqc_ai_generate_dialogue() {
    const btn = document.getElementById('dqc-ai-dialogue-btn');
    if (btn) btn.disabled = true;
    dqc_ai_show('Generating NPC dialogue…', 'loading');
    try {
        const res  = await fetch(DQC_AJAXURL + 'ai_generate_dialogue', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(dqc_ai_get_payload()) }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
        const data = await res.json();
        if (btn) btn.disabled = false;
        if (data.status === 'ok') {
            dqc_ai_last.dialogue = data.result?.suggestion || '';
            dqc_ai_show(dqc_ai_last.dialogue);
            if (dqc_ai_last.dialogue) document.getElementById('dqc-ai-apply-dialogue-btn')?.classList.add('visible');
        } else dqc_ai_show('Error: ' + (data.message || '?'), 'err');
    } catch(e) { if (btn) btn.disabled = false; dqc_ai_show('Request failed: '+e, 'err'); }
}

function dqc_ai_apply_quest_texts() {
    if (!dqc_ai_last.quest_texts) return;
    const t = dqc_ai_last.quest_texts;
    if (t.description) { const el = document.getElementById('f-Description'); if (el) el.value = t.description; }
    if (t.accept_text) { const el = document.getElementById('f-AcceptText');  if (el) el.value = t.accept_text; }
    if (t.finish_text) { const el = document.getElementById('f-FinishText');  if (el) el.value = t.finish_text; }
    dqc.markDirty();
    document.getElementById('dqc-ai-apply-quest-btn')?.classList.remove('visible');
}

function dqc_ai_apply_dialogue() {
    if (!dqc_ai_last.dialogue) return;
    const el = document.getElementById('f-TargetText');
    if (el) el.value = dqc_ai_last.dialogue;
    dqc.markDirty();
    document.getElementById('dqc-ai-apply-dialogue-btn')?.classList.remove('visible');
}
<?php endif; ?>
</script>