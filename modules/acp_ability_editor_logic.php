<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_ACP')) exit;

if (!isset($userPriv))      $userPriv      = (int)($_SESSION['priv_level'] ?? 0);
if (!isset($currentUserId)) $currentUserId = (int)($_SESSION['user_id']    ?? 0);

if (!isset($_GET['ajax'])) return;

header('Content-Type: application/json');
$action = preg_replace('/[^a-z0-9_]/', '', $_POST['action'] ?? $_GET['action'] ?? '');

if ($userPriv < 4) { echo json_encode(['error' => 'forbidden']); exit; }

switch ($action) {

    // ── NPC TEMPLATES ────────────────────────────────────────
    case 'npc_list':
        $q = trim($_GET['q'] ?? '');
        $limit = min((int)($_GET['limit'] ?? 10), 200);
        $off   = max((int)($_GET['offset'] ?? 0), 0);
        if ($q) {
            $s = $db->prepare("SELECT TemplateId, Name, ClassType, GuildName, Model, Size, Level, AggroLevel, AggroRange, Spells FROM npctemplate WHERE Name LIKE ? OR ClassType LIKE ? ORDER BY Name ASC LIMIT ? OFFSET ?");
            $s->execute(['%'.$q.'%','%'.$q.'%',$limit,$off]);
            $c = $db->prepare("SELECT COUNT(*) FROM npctemplate WHERE Name LIKE ? OR ClassType LIKE ?");
            $c->execute(['%'.$q.'%','%'.$q.'%']);
        } else {
            $s = $db->prepare("SELECT TemplateId, Name, ClassType, GuildName, Model, Size, Level, AggroLevel, AggroRange, Spells FROM npctemplate ORDER BY Name ASC LIMIT ? OFFSET ?");
            $s->execute([$limit,$off]);
            $c = $db->query("SELECT COUNT(*) FROM npctemplate");
        }
        echo json_encode(['ok'=>true,'rows'=>$s->fetchAll(),'total'=>(int)$c->fetchColumn()]);
        break;

    case 'npc_get':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) { echo json_encode(['error'=>'No ID']); break; }
        $s = $db->prepare("SELECT * FROM npctemplate WHERE TemplateId = ? LIMIT 1");
        $s->execute([$id]);
        $row = $s->fetch();
        if (!$row) { echo json_encode(['error'=>'Not found']); break; }
        // Spells is a semicolon-delimited list of IDs.
        $ids = array_filter(array_map('intval', explode(';', $row['Spells'] ?? '')));
        $row['linked_spells'] = [];
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $sq = $db->prepare("SELECT Spell_ID, Name, Type, Damage, `Value`, Duration, CastTime FROM spell WHERE Spell_ID IN ($ph)");
            $sq->execute(array_values($ids));
            $row['linked_spells'] = $sq->fetchAll();
        }
        $row['spell_ids'] = array_values($ids);
        echo json_encode(['ok'=>true,'row'=>$row]);
        break;

    case 'npc_save':
        checkToken($_POST['csrf_token'] ?? '');
        $id = (int)($_POST['TemplateId'] ?? 0);
        $allowed = ['Name','ClassType','GuildName','Model','Size','Level','MaxSpeed',
                    'MeleeDamageType','ParryChance','EvadeChance','BlockChance','LeftHandSwingChance',
                    'AggroLevel','AggroRange','Race','BodyType','MaxDistance','TetherRange',
                    'PackageID','Strength','Constitution','Dexterity','Quickness','Intelligence',
                    'Piety','Empathy','Charisma','Flags','Spells','Styles','Abilities'];
        if ($id > 0) {
            $sets=[]; $binds=[];
            foreach ($allowed as $col) { if (array_key_exists($col,$_POST)) { $sets[]="`$col`=?"; $binds[]=$_POST[$col]===''?null:$_POST[$col]; } }
            if ($sets) { $binds[]=$id; $db->prepare("UPDATE npctemplate SET ".implode(',',$sets)." WHERE TemplateId=?")->execute($binds); }
            aldhran_log('ABILITY_NPC_UPDATE',"Updated NPC $id",$currentUserId);
            echo json_encode(['ok'=>true,'action'=>'updated','id'=>$id]);
        } else {
            $cols=[]; $vals=[]; $ph=[];
            foreach ($allowed as $col) { if (array_key_exists($col,$_POST)) { $cols[]="`$col`"; $vals[]=$_POST[$col]===''?null:$_POST[$col]; $ph[]='?'; } }
            $db->prepare("INSERT INTO npctemplate (".implode(',',$cols).") VALUES (".implode(',',$ph).")")->execute($vals);
            $newId=(int)$db->lastInsertId();
            aldhran_log('ABILITY_NPC_CREATE',"Created NPC $newId",$currentUserId);
            echo json_encode(['ok'=>true,'action'=>'created','id'=>$newId]);
        }
        break;

    case 'npc_delete':
        checkToken($_POST['csrf_token'] ?? '');
        if ($userPriv < 5) { echo json_encode(['error'=>'Requires priv 5']); break; }
        $id = (int)($_POST['TemplateId'] ?? 0);
        $db->prepare("DELETE FROM npctemplate WHERE TemplateId=?")->execute([$id]);
        aldhran_log('ABILITY_NPC_DELETE',"Deleted NPC $id",$currentUserId);
        echo json_encode(['ok'=>true]);
        break;

    case 'npc_spell_add':
        checkToken($_POST['csrf_token'] ?? '');
        $npcId   = (int)($_POST['TemplateId'] ?? 0);
        $spellId = preg_replace('/[^a-zA-Z0-9\-]/', '', $_POST['SpellID'] ?? '');
        if (!$npcId||!$spellId) { echo json_encode(['error'=>'Missing params']); break; }
        $r = $db->prepare("SELECT Spells, AggroLevel FROM npctemplate WHERE TemplateId=? LIMIT 1");
        $r->execute([$npcId]);
        $npc = $r->fetch();
        if (!$npc) { echo json_encode(['error'=>'NPC not found']); break; }
        $existing = array_filter(array_map('intval', explode(';', $npc['Spells']??'')));
        if (in_array($spellId,$existing)) { echo json_encode(['error'=>'Spell already assigned']); break; }
        $sq = $db->prepare("SELECT Name,Type,Damage,`Value` FROM spell WHERE Spell_ID=? LIMIT 1");
        $sq->execute([$spellId]);
        $spell = $sq->fetch();
        $warning = null;
        if ($spell && ($spell['Damage']??0) > 5000) $warning = "Very high damage ({$spell['Damage']}) — check intentionality.";
        $existing[] = $spellId;
        $db->prepare("UPDATE npctemplate SET Spells=? WHERE TemplateId=?")->execute([implode(';',array_filter($existing)),$npcId]);
        aldhran_log('ABILITY_NPC_SPELL_ADD',"Added SpellID $spellId to NPC $npcId",$currentUserId);
        echo json_encode(['ok'=>true,'warning'=>$warning,'spell'=>$spell]);
        break;

    case 'npc_spell_remove':
        checkToken($_POST['csrf_token'] ?? '');
        $npcId   = (int)($_POST['TemplateId'] ?? 0);
        $spellId = preg_replace('/[^a-zA-Z0-9\-]/', '', $_POST['SpellID'] ?? '');
        $r = $db->prepare("SELECT Spells FROM npctemplate WHERE TemplateId=? LIMIT 1");
        $r->execute([$npcId]);
        $npc = $r->fetch();
        $existing = array_values(array_diff(array_filter(array_map('intval',explode(';',$npc['Spells']??''))) , [$spellId]));
        $db->prepare("UPDATE npctemplate SET Spells=? WHERE TemplateId=?")->execute([implode(';',$existing),$npcId]);
        aldhran_log('ABILITY_NPC_SPELL_REMOVE',"Removed SpellID $spellId from NPC $npcId",$currentUserId);
        echo json_encode(['ok'=>true]);
        break;

    // ── SPELLS ───────────────────────────────────────────────
    case 'spell_list':
        $q     = trim($_GET['q'] ?? '');
        $limit = min((int)($_GET['limit']??10),200);
        $off   = max((int)($_GET['offset']??0),0);
        $type  = trim($_GET['type']??'');
        $params=[]; $where=[];
        if ($q)    { $where[]="(Name LIKE ? OR Spell_ID LIKE ?)"; $params[]='%'.$q.'%'; $params[]='%'.$q.'%'; }
        if ($type) { $where[]="Type=?"; $params[]=$type; }
        $w = $where ? 'WHERE '.implode(' AND ',$where) : '';
        $s = $db->prepare("SELECT Spell_ID, Name, Type, Target, `Range`, Duration, CastTime, Damage, `Value`, Radius, DamageType, RecastDelay, Concentration FROM spell $w ORDER BY Spell_ID ASC LIMIT ? OFFSET ?");
        $s->execute(array_merge($params,[$limit,$off]));
        $c = $db->prepare("SELECT COUNT(*) FROM spell $w");
        $c->execute($params);
        echo json_encode(['ok'=>true,'rows'=>$s->fetchAll(),'total'=>(int)$c->fetchColumn()]);
        break;

    case 'spell_get':
        $id = preg_replace('/[^a-zA-Z0-9\-]/', '', $_GET['id'] ?? '');
        if (!$id) { echo json_encode(['error'=>'No ID']); break; }
        $s = $db->prepare("SELECT * FROM spell WHERE Spell_ID=? LIMIT 1");
        $s->execute([$id]);
        $row = $s->fetch();
        if (!$row) { echo json_encode(['error'=>'Not found']); break; }
        // Lines
        $l = $db->prepare("SELECT lxs.LineName, lxs.Level, sl.Name as LineDisplayName FROM linexspell lxs LEFT JOIN spellline sl ON sl.KeyName=lxs.LineName WHERE lxs.SpellID=?");
        $l->execute([$id]);
        $row['used_in_lines'] = $l->fetchAll();
        // Styles
        try {
            $st = $db->prepare("SELECT sxs.StyleID, sxs.ClassID, s.Name as StyleName FROM stylexspell sxs LEFT JOIN style s ON s.StyleID=sxs.StyleID WHERE sxs.SpellID=?");
            $st->execute([$id]);
            $row['used_in_styles'] = $st->fetchAll();
        } catch (\Throwable $e) { $row['used_in_styles']=[]; }
        echo json_encode(['ok'=>true,'row'=>$row]);
        break;

    case 'spell_save':
        checkToken($_POST['csrf_token'] ?? '');
        $id = preg_replace('/[^a-zA-Z0-9\-]/', '', $_POST['Spell_ID'] ?? '');
        $allowed = ['Name','Description','Type','Target','Range','Duration','PulsePower',
                    'Pulse','Frequency','CastTime','Damage','DamageType','Value','Radius',
                    'RecastDelay','ResurrectMana','ResurrectHealth','Movingcast','Concentration',
                    'Interrupt','Mysterious','InstrumentRequirement','Condition','IgnoreBonus',
                    'Icon','ClientEffect','Message1','Message2','Message3','Message4',
                    'Effectgroup','SubSpellID','IsFocus','TooltipId','PackageID','Power',
                    'SharedTimerGroup','IsSecondary','IsPrimary','AllowBolt','LifeDrainReturn',
                    'AmnesiaChance'];
        if ($id) {
            $sets=[]; $binds=[];
            foreach ($allowed as $col) { if (array_key_exists($col,$_POST)) { $sets[]="`$col`=?"; $binds[]=$_POST[$col]===''?null:$_POST[$col]; } }
            if ($sets) { $binds[]=$id; $db->prepare("UPDATE spell SET ".implode(',',$sets)." WHERE Spell_ID=?")->execute($binds); }
            aldhran_log('ABILITY_SPELL_UPDATE',"Updated Spell $id",$currentUserId);
            echo json_encode(['ok'=>true,'action'=>'updated','id'=>$id]);
        } else {
            $cols=[]; $vals=[]; $ph=[];
            foreach ($allowed as $col) { if (array_key_exists($col,$_POST)) { $cols[]="`$col`"; $vals[]=$_POST[$col]===''?null:$_POST[$col]; $ph[]='?'; } }
            $db->prepare("INSERT INTO spell (".implode(',',$cols).") VALUES (".implode(',',$ph).")")->execute($vals);
            $newId=(int)$db->lastInsertId();
            aldhran_log('ABILITY_SPELL_CREATE',"Created Spell $newId",$currentUserId);
            echo json_encode(['ok'=>true,'action'=>'created','id'=>$newId]);
        }
        break;

    case 'spell_delete':
        checkToken($_POST['csrf_token'] ?? '');
        if ($userPriv < 5) { echo json_encode(['error'=>'Requires priv 5']); break; }
        $id = preg_replace('/[^a-zA-Z0-9\-]/', '', $_POST['Spell_ID'] ?? '');
        $u = $db->prepare("SELECT COUNT(*) FROM linexspell WHERE SpellID=?"); $u->execute([$id]);
        if ((int)$u->fetchColumn() > 0 && empty($_POST['force'])) {
            echo json_encode(['error'=>'Spell is used in spell lines. Pass force=1 to delete anyway.']); break;
        }
        $db->prepare("DELETE FROM spell WHERE Spell_ID=?")->execute([$id]);
        aldhran_log('ABILITY_SPELL_DELETE',"Deleted Spell $id",$currentUserId);
        echo json_encode(['ok'=>true]);
        break;

    case 'spell_types':
        $s = $db->query("SELECT DISTINCT Type FROM spell WHERE Type IS NOT NULL AND Type!='' ORDER BY Type ASC");
        echo json_encode(['ok'=>true,'types'=>$s->fetchAll(PDO::FETCH_COLUMN)]);
        break;

    case 'spell_search':
        $q = trim($_GET['q']??'');
        if (strlen($q)<2) { echo json_encode(['ok'=>true,'rows'=>[]]); break; }
        $s = $db->prepare("SELECT Spell_ID, Name, Type FROM spell WHERE Name LIKE ? OR Spell_ID LIKE ? ORDER BY Name ASC LIMIT 20");
        $s->execute(['%'.$q.'%','%'.$q.'%']);
        echo json_encode(['ok'=>true,'rows'=>$s->fetchAll()]);
        break;

    // ── SPELL LINES ──────────────────────────────────────────
    case 'spellline_list':
        $q = trim($_GET['q']??'');
        if ($q) {
            $s = $db->prepare("SELECT SpellLineID, KeyName, Name, Spec, IsBaseLine, ClassIDHint FROM spellline WHERE KeyName LIKE ? OR Name LIKE ? ORDER BY KeyName ASC LIMIT 200");
            $s->execute(['%'.$q.'%','%'.$q.'%']);
        } else {
            $s = $db->query("SELECT SpellLineID, KeyName, Name, Spec, IsBaseLine, ClassIDHint FROM spellline ORDER BY KeyName ASC LIMIT 200");
        }
        echo json_encode(['ok'=>true,'rows'=>$s->fetchAll()]);
        break;

    case 'spellline_get':
        $key = trim($_GET['key']??'');
        if (!$key) { echo json_encode(['error'=>'No key']); break; }
        $s = $db->prepare("SELECT * FROM spellline WHERE KeyName=? LIMIT 1");
        $s->execute([$key]);
        $row = $s->fetch();
        if (!$row) {
            $s2 = $db->prepare("SELECT * FROM spellline WHERE Name=? LIMIT 1");
            $s2->execute([$key]);
            $row = $s2->fetch();
        }
        if (!$row) { echo json_encode(['error'=>'Not found: '.$key]); break; }
        $lineKey = !empty($row['KeyName']) ? $row['KeyName'] : $row['Name'];
        $l = $db->prepare("SELECT lxs.LineXSpell_ID, lxs.SpellID, lxs.Level, s.Name as SpellName, s.Type as SpellType, s.Damage, s.`Value`, s.Duration, s.CastTime FROM linexspell lxs LEFT JOIN spell s ON s.Spell_ID=lxs.SpellID WHERE lxs.LineName=? ORDER BY lxs.Level ASC");
        $l->execute([$lineKey]);
        $row['spells'] = $l->fetchAll();
        $row['_lineKey'] = $lineKey;
        echo json_encode(['ok'=>true,'row'=>$row]);
        break;

    case 'spellline_save':
        checkToken($_POST['csrf_token'] ?? '');
        $key = trim($_POST['KeyName']??'');
        if (!$key) { echo json_encode(['error'=>'KeyName required']); break; }
        $ex = $db->prepare("SELECT SpellLineID FROM spellline WHERE KeyName=?");
        $ex->execute([$key]);
        $allowed = ['KeyName','Name','Spec','IsBaseLine','ClassIDHint','PackageID'];
        if ($ex->fetch()) {
            $sets=[]; $binds=[];
            foreach ($allowed as $col) { if ($col!=='KeyName' && array_key_exists($col,$_POST)) { $sets[]="`$col`=?"; $binds[]=$_POST[$col]===''?null:$_POST[$col]; } }
            if ($sets) { $binds[]=$key; $db->prepare("UPDATE spellline SET ".implode(',',$sets)." WHERE KeyName=?")->execute($binds); }
            aldhran_log('ABILITY_SPELLLINE_UPDATE',"Updated SpellLine $key",$currentUserId);
            echo json_encode(['ok'=>true,'action'=>'updated']);
        } else {
            $cols=[]; $vals=[]; $ph=[];
            foreach ($allowed as $col) { if (array_key_exists($col,$_POST)) { $cols[]="`$col`"; $vals[]=$_POST[$col]===''?null:$_POST[$col]; $ph[]='?'; } }
            $db->prepare("INSERT INTO spellline (".implode(',',$cols).") VALUES (".implode(',',$ph).")")->execute($vals);
            aldhran_log('ABILITY_SPELLLINE_CREATE',"Created SpellLine $key",$currentUserId);
            echo json_encode(['ok'=>true,'action'=>'created']);
        }
        break;

    case 'linexspell_save':
        checkToken($_POST['csrf_token'] ?? '');
        $lineName = trim($_POST['LineName']??'');
        $spellId  = preg_replace('/[^a-zA-Z0-9\-]/', '', $_POST['SpellID'] ?? '');
        $level    = (int)($_POST['Level']??1);
        if (!$lineName||!$spellId) { echo json_encode(['error'=>'Missing params']); break; }
        $ex = $db->prepare("SELECT LineXSpell_ID FROM linexspell WHERE LineName=? AND SpellID=?");
        $ex->execute([$lineName,$spellId]);
        if ($ex->fetch()) {
            $db->prepare("UPDATE linexspell SET Level=? WHERE LineName=? AND SpellID=?")->execute([$level,$lineName,$spellId]);
        } else {
            $db->prepare("INSERT INTO linexspell (LineName,SpellID,Level) VALUES (?,?,?)")->execute([$lineName,$spellId,$level]);
        }
        aldhran_log('ABILITY_LINEXSPELL_SAVE',"$lineName ↔ SpellID $spellId @ Lv $level",$currentUserId);
        echo json_encode(['ok'=>true]);
        break;

    case 'linexspell_delete':
        checkToken($_POST['csrf_token'] ?? '');
        $lineName = trim($_POST['LineName']??'');
        $spellId  = preg_replace('/[^a-zA-Z0-9\-]/', '', $_POST['SpellID'] ?? '');
        $db->prepare("DELETE FROM linexspell WHERE LineName=? AND SpellID=?")->execute([$lineName,$spellId]);
        aldhran_log('ABILITY_LINEXSPELL_DELETE',"Removed SpellID $spellId from Line $lineName",$currentUserId);
        echo json_encode(['ok'=>true]);
        break;

    // ── STYLES ───────────────────────────────────────────────
    case 'style_list':
        $q     = trim($_GET['q']??'');
        $limit = min((int)($_GET['limit']??10),200);
        $off   = max((int)($_GET['offset']??0),0);
        $params=[]; $where=[];
        if ($q) { $where[]="(Name LIKE ? OR StyleID LIKE ?)"; $params[]='%'.$q.'%'; $params[]='%'.$q.'%'; }
        $w = $where ? 'WHERE '.implode(' AND ',$where) : '';
        $s = $db->prepare("SELECT StyleID, ID, ClassId, Name, SpecKeyName, SpecLevelRequirement, AttackResultRequirement, OpeningRequirementType, GrowthRate, BonusToHit, BonusToDefense, EnduranceCost, Icon FROM style $w ORDER BY SpecKeyName ASC, SpecLevelRequirement ASC LIMIT ? OFFSET ?");
        $s->execute(array_merge($params,[$limit,$off]));
        $c = $db->prepare("SELECT COUNT(*) FROM style $w");
        $c->execute($params);
        echo json_encode(['ok'=>true,'rows'=>$s->fetchAll(),'total'=>(int)$c->fetchColumn()]);
        break;

    case 'style_get':
        $id = (int)($_GET['id']??0);
        if (!$id) { echo json_encode(['error'=>'No ID']); break; }
        $s = $db->prepare("SELECT * FROM style WHERE StyleID=? LIMIT 1");
        $s->execute([$id]);
        $row = $s->fetch();
        if (!$row) { echo json_encode(['error'=>'Not found']); break; }
        try {
            $sp = $db->prepare("SELECT sxs.StyleID,sxs.SpellID,sxs.ClassID,s.Name as SpellName,s.Type as SpellType FROM stylexspell sxs LEFT JOIN spell s ON s.Spell_ID=sxs.SpellID WHERE sxs.StyleID=?");
            $sp->execute([$id]);
            $row['linked_spells'] = $sp->fetchAll();
        } catch (\Throwable $e) { $row['linked_spells']=[]; }
        echo json_encode(['ok'=>true,'row'=>$row]);
        break;

    case 'style_save':
        checkToken($_POST['csrf_token'] ?? '');
        $id = (int)($_POST['StyleID']??0);
        $allowed = ['Name','SpecKeyName','SpecLevelRequirement','AttackResultRequirement',
                    'OpeningRequirementType','OpeningRequirementValue','WeaponTypeRequirement',
                    'GrowthRate','GrowthOffset','BonusToHit','BonusToDefense','Icon',
                    'TwoHandAnimation','StealthRequirement','EnduranceCost','ArmorHitLocation',
                    'RandomProc','PackageID','ClassId','ID'];
        if ($id > 0) {
            $sets=[]; $binds=[];
            foreach ($allowed as $col) { if (array_key_exists($col,$_POST)) { $sets[]="`$col`=?"; $binds[]=$_POST[$col]===''?null:$_POST[$col]; } }
            if ($sets) { $binds[]=$id; $db->prepare("UPDATE style SET ".implode(',',$sets)." WHERE StyleID=?")->execute($binds); }
            aldhran_log('ABILITY_STYLE_UPDATE',"Updated Style $id",$currentUserId);
            echo json_encode(['ok'=>true,'action'=>'updated','id'=>$id]);
        } else {
            $cols=[]; $vals=[]; $ph=[];
            foreach ($allowed as $col) { if (array_key_exists($col,$_POST)) { $cols[]="`$col`"; $vals[]=$_POST[$col]===''?null:$_POST[$col]; $ph[]='?'; } }
            $db->prepare("INSERT INTO style (".implode(',',$cols).") VALUES (".implode(',',$ph).")")->execute($vals);
            $newId=(int)$db->lastInsertId();
            aldhran_log('ABILITY_STYLE_CREATE',"Created Style $newId",$currentUserId);
            echo json_encode(['ok'=>true,'action'=>'created','id'=>$newId]);
        }
        break;

    case 'stylexspell_save':
        checkToken($_POST['csrf_token'] ?? '');
        $styleId=(int)($_POST['StyleID']??0); $spellId=preg_replace('/[^a-zA-Z0-9\-]/', '', $_POST['SpellID'] ?? ''); $classId=(int)($_POST['ClassID']??0);
        if (!$styleId||!$spellId) { echo json_encode(['error'=>'Missing params']); break; }
        try {
            $ex=$db->prepare("SELECT COUNT(*) FROM stylexspell WHERE StyleID=? AND SpellID=? AND ClassID=?");
            $ex->execute([$styleId,$spellId,$classId]);
            if ((int)$ex->fetchColumn()>0) { echo json_encode(['error'=>'Already linked']); break; }
            $db->prepare("INSERT INTO stylexspell (StyleID,SpellID,ClassID) VALUES (?,?,?)")->execute([$styleId,$spellId,$classId]);
            aldhran_log('ABILITY_STYLEXSPELL_ADD',"SpellID $spellId → StyleID $styleId (Class $classId)",$currentUserId);
            echo json_encode(['ok'=>true]);
        } catch (\Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
        break;

    case 'stylexspell_delete':
        checkToken($_POST['csrf_token'] ?? '');
        $styleId=(int)($_POST['StyleID']??0); $spellId=preg_replace('/[^a-zA-Z0-9\-]/', '', $_POST['SpellID'] ?? ''); $classId=(int)($_POST['ClassID']??0);
        try {
            $db->prepare("DELETE FROM stylexspell WHERE StyleID=? AND SpellID=? AND ClassID=?")->execute([$styleId,$spellId,$classId]);
            aldhran_log('ABILITY_STYLEXSPELL_DELETE',"Unlinked SpellID $spellId from StyleID $styleId",$currentUserId);
            echo json_encode(['ok'=>true]);
        } catch (\Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
        break;

    // ── ABILITY ──────────────────────────────────────────────
    case 'ability_list':
        $q=trim($_GET['q']??''); $limit=min((int)($_GET['limit']??10),200); $off=max((int)($_GET['offset']??0),0);
        try {
            if ($q) { $s=$db->prepare("SELECT * FROM ability WHERE KeyName LIKE ? OR Name LIKE ? ORDER BY KeyName ASC LIMIT ? OFFSET ?"); $s->execute(['%'.$q.'%','%'.$q.'%',$limit,$off]); $c=$db->prepare("SELECT COUNT(*) FROM ability WHERE KeyName LIKE ? OR Name LIKE ?"); $c->execute(['%'.$q.'%','%'.$q.'%']); }
            else    { $s=$db->prepare("SELECT * FROM ability ORDER BY KeyName ASC LIMIT ? OFFSET ?"); $s->execute([$limit,$off]); $c=$db->query("SELECT COUNT(*) FROM ability"); }
            echo json_encode(['ok'=>true,'rows'=>$s->fetchAll(),'total'=>(int)$c->fetchColumn()]);
        } catch (\Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
        break;

    case 'ability_get':
        $key=trim($_GET['key']??'');
        try { $s=$db->prepare("SELECT * FROM ability WHERE KeyName=? LIMIT 1"); $s->execute([$key]); $row=$s->fetch(); echo json_encode($row?['ok'=>true,'row'=>$row]:['error'=>'Not found']); }
        catch (\Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
        break;

    case 'ability_save':
        checkToken($_POST['csrf_token'] ?? '');
        $key=trim($_POST['KeyName']??'');
        if (!$key) { echo json_encode(['error'=>'KeyName required']); break; }
        try {
            $ab_cols = [];
            foreach ($db->query("DESCRIBE ability")->fetchAll() as $col) $ab_cols[] = $col['Field'];
            $allowed = array_values(array_intersect(['KeyName','Name','Description','IconID','Value'], $ab_cols));
            $ex=$db->prepare("SELECT COUNT(*) FROM ability WHERE KeyName=?"); $ex->execute([$key]);
            if ((int)$ex->fetchColumn()>0) {
                $sets=[]; $binds=[];
                foreach ($allowed as $col) { if ($col!=='KeyName'&&array_key_exists($col,$_POST)) { $sets[]="`$col`=?"; $binds[]=$_POST[$col]; } }
                if ($sets) { $binds[]=$key; $db->prepare("UPDATE ability SET ".implode(',',$sets)." WHERE KeyName=?")->execute($binds); }
                echo json_encode(['ok'=>true,'action'=>'updated']);
            } else {
                $cols=[]; $vals=[]; $ph=[];
                foreach ($allowed as $col) { if (array_key_exists($col,$_POST)) { $cols[]="`$col`"; $vals[]=$_POST[$col]; $ph[]='?'; } }
                $db->prepare("INSERT INTO ability (".implode(',',$cols).") VALUES (".implode(',',$ph).")")->execute($vals);
                echo json_encode(['ok'=>true,'action'=>'created']);
            }
            aldhran_log('ABILITY_SAVE',"Saved Ability $key",$currentUserId);
        } catch (\Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
        break;

    case 'ability_delete':
        checkToken($_POST['csrf_token'] ?? '');
        if ($userPriv < 5) { echo json_encode(['error'=>'Requires priv 5']); break; }
        $key=trim($_POST['KeyName']??'');
        try { $db->prepare("DELETE FROM ability WHERE KeyName=?")->execute([$key]); aldhran_log('ABILITY_DELETE',"Deleted $key",$currentUserId); echo json_encode(['ok'=>true]); }
        catch (\Throwable $e) { echo json_encode(['error'=>$e->getMessage()]); }
        break;

    // ── CHANGE HISTORY ──────────────────────────────────────
    case 'changelog':
        $target=trim($_GET['target']??'');
        try {
            $s=$db->prepare("SELECT al.action_type,al.details,al.created_at,u.username FROM aldhran_logs al LEFT JOIN users u ON u.id=al.user_id WHERE al.action_type LIKE 'ABILITY_%' AND (?='' OR al.details LIKE ?) ORDER BY al.created_at DESC LIMIT 20");
            $s->execute([$target,'%'.$target.'%']);
            echo json_encode(['ok'=>true,'rows'=>$s->fetchAll()]);
        } catch (\Throwable $e) { echo json_encode(['ok'=>true,'rows'=>[]]); }
        break;

    default:
        echo json_encode(['error'=>'Unknown action: '.$action]);
}
exit;
