<?php
if (!defined('IN_CMS')) exit;
if ((int)($userPriv ?? 0) < 2) {
    header("Location: index.php?p=home"); exit;
}

function logAdminAction(string $type, string $details): void {
    aldhran_log($type, $details, (int)($_SESSION['user_id'] ?? 0));
}

$ajax_action = $_POST['ajax_action'] ?? '';

if (isset($_GET['del_cat']) || isset($_GET['del_board'])) {
    header("Location: index.php?p=spike_admin"); exit;
}

if (!empty($ajax_action)) {
    if (ob_get_length()) ob_clean();
    checkToken($_POST['csrf_token'] ?? '');

    $need4 = ['update_matrix','sort_cats','sort_boards','recalc','purge_preview','purge_execute',
              'inline_update','move_board','delete_cat','delete_board','create_cat','create_board',
              'toggle_board_approval','save_settings','update_smilies',
              'create_prefix','delete_prefix','toggle_prefix','update_prefix',
              'create_smiley','delete_smiley','toggle_smiley',
              'merge_threads','move_post','clear_search_log','cleanup_read_markers'];
    if (in_array($ajax_action, $need4) && (int)($userPriv??0) < 4) {
        echo json_encode(['error'=>'Insufficient privileges.']); exit;
    }

    if ($ajax_action === 'toggle_board_approval') {
        header('Content-Type: application/json');
        $id = (int)($_POST['board_id'] ?? 0);
        try {
            $db->prepare("UPDATE spike_boards SET require_approval=1-require_approval WHERE id=?")->execute([$id]);
            $stmt = $db->prepare("SELECT require_approval FROM spike_boards WHERE id=?");
            $stmt->execute([$id]);
            echo json_encode(['ok'=>true, 'active'=>(bool)$stmt->fetchColumn()]);
        } catch(\Throwable $e){ echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    if ($ajax_action === 'update_matrix') {
        if (isset($_POST['cat_perms'])) {
            $stmt_cat = $db->prepare("UPDATE spike_categories SET min_priv=?, min_priv_post=? WHERE id=?");
            foreach ($_POST['cat_perms'] as $cid => $p) $stmt_cat->execute([(int)$p['v'],(int)$p['p'],(int)$cid]);
        }
        if (isset($_POST['board_perms'])) {
            $stmt_board = $db->prepare("UPDATE spike_boards SET min_priv=?, min_priv_post=? WHERE id=?");
            foreach ($_POST['board_perms'] as $bid => $p) $stmt_board->execute([(int)$p['v'],(int)$p['p'],(int)$bid]);
        }
        logAdminAction('FORUM_MATRIX', 'Updated permission matrix');
        echo "success"; exit;
    }

    if ($ajax_action === 'sort_cats' && isset($_POST['order'])) {
        $stmt_sort = $db->prepare("UPDATE spike_categories SET pos=? WHERE id=?");
        foreach ($_POST['order'] as $pos => $id) $stmt_sort->execute([(int)$pos+1,(int)$id]);
        echo "success"; exit;
    }

    if ($ajax_action === 'sort_boards' && isset($_POST['order'])) {
        $target_cat = (int)$_POST['target_cat_id'];
        $stmt_sort  = $db->prepare("UPDATE spike_boards SET pos=?, cat_id=? WHERE id=?");
        foreach ($_POST['order'] as $pos => $id) $stmt_sort->execute([(int)$pos+1,$target_cat,(int)$id]);
        echo "success"; exit;
    }

    if ($ajax_action === 'recalc') {
        $db->query("UPDATE users u SET u.forum_posts=(SELECT COUNT(*) FROM spike_posts p WHERE p.author_id=u.id)");
        logAdminAction('FORUM_MAINTENANCE','Recalculated post counts');
        echo "success"; exit;
    }

    if ($ajax_action === 'purge_preview') {
        $users = array_filter(array_map('trim', explode(',', $_POST['purge_users']??'')));
        $board = (int)($_POST['purge_board']??0);
        $since = $_POST['purge_since']??'';
        if (empty($users)) { echo 'No users specified.'; exit; }
        $pl = implode(',', array_fill(0,count($users),'?'));
        $stmt_val = $db->prepare("SELECT id FROM users WHERE username IN ($pl)");
        $stmt_val->execute(array_values($users));
        $valid_ids = $stmt_val->fetchAll(PDO::FETCH_COLUMN);
        if (empty($valid_ids)) { echo 'No matching users found.'; exit; }
        $id_pl  = implode(',',array_fill(0,count($valid_ids),'?'));
        $params = array_values($valid_ids);
        $where  = "p.author_id IN ($id_pl)";
        if ($board>0)       { $where .= " AND t.board_id=?"; $params[]=$board; }
        if (!empty($since)) { $where .= " AND p.created_at>=?"; $params[]=$since; }
        $stmt = $db->prepare("SELECT p.id, u.username as author_name, t.title as subject, p.created_at, b.title AS board_title FROM spike_posts p JOIN spike_threads t ON p.thread_id=t.id JOIN spike_boards b ON b.id=t.board_id JOIN users u ON p.author_id=u.id WHERE $where ORDER BY p.created_at DESC LIMIT 50");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if (empty($rows)) { echo '<span class="acp-s-29ee2084">0 posts found.</span>'; exit; }
        echo "<span style='color:var(--glow-gold);font-family:Cinzel;'>".count($rows)." post(s):</span><br><br>";
        foreach ($rows as $r) echo "<div style='padding:4px 0;border-bottom:1px solid #111;color:#666;'><span style='color:#888;'>".h($r['author_name'])."</span> — <span style='color:#555;'>".h($r['subject']?:'(no subject)')."</span> <span style='color:#333;font-size:10px;'>in ".h($r['board_title']?:'?')." @ ".substr($r['created_at'],0,10)."</span></div>";
        exit;
    }

    if ($ajax_action === 'purge_execute') {
        $users = array_filter(array_map('trim', explode(',', $_POST['purge_users']??'')));
        $board = (int)($_POST['purge_board']??0);
        $since = $_POST['purge_since']??'';
        if (empty($users)) { echo 'No users specified.'; exit; }
        $pl  = implode(',',array_fill(0,count($users),'?'));
        $sv  = $db->prepare("SELECT id FROM users WHERE username IN ($pl)");
        $sv->execute(array_values($users));
        $vid = $sv->fetchAll(PDO::FETCH_COLUMN);
        if (empty($vid)) { echo 'No matching users found.'; exit; }
        $id_pl  = implode(',',array_fill(0,count($vid),'?'));
        $params = array_values($vid);
        $where  = "author_id IN ($id_pl)";
        if ($board>0)       { $where .= " AND thread_id IN (SELECT id FROM spike_threads WHERE board_id=?)"; $params[]=$board; }
        if (!empty($since)) { $where .= " AND created_at>=?"; $params[]=$since; }
        try {
            $db->beginTransaction();
            $cs = $db->prepare("SELECT COUNT(*) FROM spike_posts WHERE $where"); $cs->execute($params);
            $deleted=(int)$cs->fetchColumn();
            $db->prepare("DELETE FROM spike_posts WHERE $where")->execute($params);
            logAdminAction('PURGE',"Purged $deleted posts from: ".implode(', ',$users));
            $db->commit();
            echo "SUCCESS — $deleted post(s) deleted.";
        } catch (Exception $e) { $db->rollBack(); echo "ERROR: ".$e->getMessage(); }
        exit;
    }

    if ($ajax_action === 'inline_update') {
        $field = $_POST['field']??''; $id=(int)$_POST['record_id']; $value=trim($_POST['value']??'');
        if ($field==='cat_title'&&$id>0) { $db->prepare("UPDATE spike_categories SET title=? WHERE id=?")->execute([$value,$id]); echo 'SUCCESS'; }
        elseif (in_array($field,['board_title','board_desc'],true)&&$id>0) { $col=($field==='board_title')?'title':'description'; $db->prepare("UPDATE spike_boards SET $col=? WHERE id=?")->execute([$value,$id]); echo 'SUCCESS'; }
        else echo 'ERROR: Unknown field.';
        exit;
    }

    if ($ajax_action === 'move_board') {
        $bid=(int)($_POST['board_id']??0); $ncat=(int)($_POST['new_cat_id']??0);
        if ($bid&&$ncat) { $db->prepare("UPDATE spike_boards SET cat_id=? WHERE id=?")->execute([$ncat,$bid]); logAdminAction('MOVE_BOARD',"Moved board $bid to cat $ncat"); echo 'SUCCESS'; }
        else echo 'ERROR: Invalid IDs.';
        exit;
    }

    if ($ajax_action === 'delete_cat') {
        $cat_id=(int)($_POST['cat_id']??0);
        if ($cat_id<=0){echo 'ERROR';exit;}
        try {
            $db->beginTransaction();
            $bids=$db->prepare("SELECT id FROM spike_boards WHERE cat_id=?"); $bids->execute([$cat_id]); $bids=$bids->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($bids)) {
                $bpl=implode(',',array_fill(0,count($bids),'?'));
                $tids=$db->prepare("SELECT id FROM spike_threads WHERE board_id IN ($bpl)"); $tids->execute($bids); $tids=$tids->fetchAll(PDO::FETCH_COLUMN);
                if(!empty($tids)){$tpl=implode(',',array_fill(0,count($tids),'?')); $db->prepare("DELETE FROM spike_posts WHERE thread_id IN ($tpl)")->execute($tids);}
                $db->prepare("DELETE FROM spike_threads WHERE board_id IN ($bpl)")->execute($bids);
            }
            $db->prepare("DELETE FROM spike_boards WHERE cat_id=?")->execute([$cat_id]);
            $db->prepare("DELETE FROM spike_categories WHERE id=?")->execute([$cat_id]);
            logAdminAction('DELETE_CAT',"Deleted category $cat_id"); $db->commit(); echo 'SUCCESS';
        } catch(Exception $e){$db->rollBack();echo 'ERROR';}
        exit;
    }

    if ($ajax_action === 'delete_board') {
        $bid=(int)($_POST['board_id']??0);
        if ($bid<=0){echo 'ERROR';exit;}
        try {
            $db->beginTransaction();
            $tids=$db->prepare("SELECT id FROM spike_threads WHERE board_id=?"); $tids->execute([$bid]); $tids=$tids->fetchAll(PDO::FETCH_COLUMN);
            if(!empty($tids)){$tpl=implode(',',array_fill(0,count($tids),'?')); $db->prepare("DELETE FROM spike_posts WHERE thread_id IN ($tpl)")->execute($tids);}
            $db->prepare("DELETE FROM spike_threads WHERE board_id=?")->execute([$bid]);
            $db->prepare("DELETE FROM spike_boards WHERE id=?")->execute([$bid]);
            logAdminAction('DELETE_BOARD',"Deleted board $bid"); $db->commit(); echo 'SUCCESS';
        } catch(Exception $e){$db->rollBack();echo 'ERROR';}
        exit;
    }

    if ($ajax_action === 'create_cat') {
        $title=trim($_POST['cat_title']??'');
        if(!empty($title)){$max=(int)$db->query("SELECT COALESCE(MAX(pos),0) FROM spike_categories")->fetchColumn(); $db->prepare("INSERT INTO spike_categories (title,pos,min_priv,min_priv_post) VALUES (?,?,1,1)")->execute([$title,$max+1]); logAdminAction('CREATE_CAT',"Created '$title'"); echo 'SUCCESS';}
        else echo 'ERROR: Title required.';
        exit;
    }

    if ($ajax_action === 'create_board') {
        $cat=(int)($_POST['target_cat_id']??0); $title=trim($_POST['board_title']??''); $desc=trim($_POST['board_desc']??'');
        if($cat>0&&!empty($title)){$ms=$db->prepare("SELECT COALESCE(MAX(pos),0) FROM spike_boards WHERE cat_id=?"); $ms->execute([$cat]); $max=(int)$ms->fetchColumn(); $db->prepare("INSERT INTO spike_boards (cat_id,title,description,pos,min_priv,min_priv_post) VALUES (?,?,?,?,1,1)")->execute([$cat,$title,$desc,$max+1]); logAdminAction('CREATE_BOARD',"Created '$title'"); echo 'SUCCESS';}
        else echo 'ERROR: Cat ID and title required.';
        exit;
    }

    if ($ajax_action === 'handle_report') {
        header('Content-Type: application/json');
        $report_id  = (int)($_POST['report_id']??0);
        $new_status = in_array($_POST['new_status']??'',['reviewing','resolved','dismissed']) ? $_POST['new_status'] : 'reviewing';
        $note       = trim(substr($_POST['handler_note']??'',0,500));
        $handler_id = (int)($_SESSION['user_id']??0);
        try {
            $db->prepare("UPDATE spike_reports SET status=?,handled_by=?,handled_at=NOW(),handler_note=? WHERE id=?")
               ->execute([$new_status,$handler_id,$note,$report_id]);
            logAdminAction('HANDLE_REPORT',"Report #$report_id → $new_status");
            echo json_encode(['ok'=>true]);
        } catch(\Throwable $e){ echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    if ($ajax_action === 'add_forbidden_word') {
        header('Content-Type: application/json');
        $word        = strtolower(trim($_POST['word']??''));
        $scope       = in_array($_POST['scope']??'',['forum','discord','both']) ? $_POST['scope'] : 'both';
        $action_type = in_array($_POST['action_type']??'',['block','replace','flag']) ? $_POST['action_type'] : 'block';
        $replacement = trim($_POST['replacement']??'***');
        $admin_id    = (int)($_SESSION['user_id']??0);
        if (empty($word)||strlen($word)<2){ echo json_encode(['error'=>'word_too_short']); exit; }
        try {
            $db->prepare("INSERT IGNORE INTO spike_forbidden_words (word,scope,action,replacement,added_by) VALUES (?,?,?,?,?)")
               ->execute([$word,$scope,$action_type,$replacement,$admin_id]);
            logAdminAction('FORBIDDEN_WORD',"Added '$word' ($scope/$action_type)");
            echo json_encode(['ok'=>true]);
        } catch(\Throwable $e){ echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    if ($ajax_action === 'delete_forbidden_word') {
        header('Content-Type: application/json');
        $word_id = (int)($_POST['word_id']??0);
        $db->prepare("DELETE FROM spike_forbidden_words WHERE id=?")->execute([$word_id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($ajax_action === 'save_settings') {
        header('Content-Type: application/json');
        $allowed_keys = ['spam_cooldown','spam_min_bs_links','reactions_enabled','polls_enabled',
                         'attachments_enabled','smilies_enabled','max_attachment_size',
                         'allowed_mime_types','attachment_path',
                         'forbidden_words_action','edit_history_enabled','subscription_notify',
                         'stats_strip_enabled','latest_posts_enabled',
                         'search_enabled','unread_enabled','ignore_system_enabled',
                         'tagging_enabled','search_min_length','search_max_results'];
        $stmt = $db->prepare("INSERT INTO spike_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        foreach ($allowed_keys as $key) {
            if (isset($_POST['settings'][$key])) {
                $stmt->execute([$key, trim($_POST['settings'][$key])]);
            }
        }
        logAdminAction('SPIKE_SETTINGS','Updated Spike settings');
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($ajax_action === 'create_prefix') {
        header('Content-Type: application/json');
        $label    = trim(substr($_POST['label']    ?? '', 0, 40));
        $color    = preg_replace('/[^#a-zA-Z0-9]/', '', $_POST['color']    ?? '#c5a059');
        $bg_color = preg_replace('/[^#a-zA-Z0-9]/', '', $_POST['bg_color'] ?? 'transparent');
        if (empty($label)) { echo json_encode(['error'=>'label_required']); exit; }
        try {
            $max = (int)$db->query("SELECT COALESCE(MAX(pos),0) FROM spike_prefixes")->fetchColumn();
            $db->prepare("INSERT INTO spike_prefixes (label,color,bg_color,pos,is_active) VALUES (?,?,?,?,1)")
               ->execute([$label, $color, $bg_color, $max+1]);
            logAdminAction('CREATE_PREFIX', "Created prefix '$label'");
            echo json_encode(['ok'=>true, 'id'=>$db->lastInsertId()]);
        } catch(\Throwable $e){ echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    if ($ajax_action === 'delete_prefix') {
        header('Content-Type: application/json');
        $id = (int)($_POST['prefix_id'] ?? 0);
        try {
            $db->prepare("DELETE FROM spike_prefixes WHERE id=?")->execute([$id]);
            $db->prepare("UPDATE spike_threads SET prefix_id=NULL WHERE prefix_id=?")->execute([$id]);
            logAdminAction('DELETE_PREFIX', "Deleted prefix #$id");
            echo json_encode(['ok'=>true]);
        } catch(\Throwable $e){ echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    if ($ajax_action === 'toggle_prefix') {
        header('Content-Type: application/json');
        $id = (int)($_POST['prefix_id'] ?? 0);
        try {
            $db->prepare("UPDATE spike_prefixes SET is_active=1-is_active WHERE id=?")->execute([$id]);
            $stmt = $db->prepare("SELECT is_active FROM spike_prefixes WHERE id=?");
            $stmt->execute([$id]);
            echo json_encode(['ok'=>true, 'active'=>(bool)$stmt->fetchColumn()]);
        } catch(\Throwable $e){ echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    if ($ajax_action === 'update_prefix') {
        header('Content-Type: application/json');
        $id    = (int)($_POST['prefix_id'] ?? 0);
        $field = $_POST['field'] ?? '';
        $value = trim($_POST['value'] ?? '');
        if (!in_array($field, ['label','color','bg_color'], true)) { echo json_encode(['error'=>'invalid_field']); exit; }
        try {
            $db->prepare("UPDATE spike_prefixes SET `$field`=? WHERE id=?")->execute([$value, $id]);
            echo json_encode(['ok'=>true]);
        } catch(\Throwable $e){ echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    if ($ajax_action === 'create_smiley') {
        header('Content-Type: application/json');
        $code  = trim(substr($_POST['code']  ?? '', 0, 20));
        $emoji = trim(substr($_POST['emoji'] ?? '', 0, 10));
        $title = trim(substr($_POST['title'] ?? '', 0, 60));
        if (empty($code)) { echo json_encode(['error'=>'code_required']); exit; }
        try {
            $max = (int)$db->query("SELECT COALESCE(MAX(pos),0) FROM spike_smilies")->fetchColumn();
            $db->prepare("INSERT INTO spike_smilies (code,emoji,title,pos,is_active) VALUES (?,?,?,?,1)")
               ->execute([$code, $emoji, $title, $max+1]);
            logAdminAction('CREATE_SMILEY', "Created smiley '$code'");
            echo json_encode(['ok'=>true]);
        } catch(\Throwable $e){ echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    if ($ajax_action === 'delete_smiley') {
        header('Content-Type: application/json');
        $id = (int)($_POST['smiley_id'] ?? 0);
        try {
            $db->prepare("DELETE FROM spike_smilies WHERE id=?")->execute([$id]);
            echo json_encode(['ok'=>true]);
        } catch(\Throwable $e){ echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    if ($ajax_action === 'toggle_smiley') {
        header('Content-Type: application/json');
        $id = (int)($_POST['smiley_id'] ?? 0);
        try {
            $db->prepare("UPDATE spike_smilies SET is_active=1-is_active WHERE id=?")->execute([$id]);
            $stmt = $db->prepare("SELECT is_active FROM spike_smilies WHERE id=?");
            $stmt->execute([$id]);
            echo json_encode(['ok'=>true, 'active'=>(bool)$stmt->fetchColumn()]);
        } catch(\Throwable $e){ echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    if ($ajax_action === 'search_threads') {
        header('Content-Type: application/json');
        $q = trim(substr($_POST['q'] ?? '', 0, 100));
        if (strlen($q) < 2) { echo json_encode(['ok'=>true,'threads'=>[]]); exit; }
        try {
            $stmt = $db->prepare("
                SELECT t.id, t.title, b.title AS board_title,
                       (SELECT COUNT(*) FROM spike_posts WHERE thread_id=t.id) AS post_count
                FROM spike_threads t
                JOIN spike_boards b ON t.board_id=b.id
                WHERE t.title LIKE ?
                ORDER BY t.created_at DESC
                LIMIT 10
            ");
            $stmt->execute(['%'.$q.'%']);
            echo json_encode(['ok'=>true,'threads'=>$stmt->fetchAll()]);
        } catch(\Throwable $e){ echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    if ($ajax_action === 'preview_post') {
        header('Content-Type: application/json');
        $pid = (int)($_POST['post_id'] ?? 0);
        try {
            $stmt = $db->prepare("
                SELECT p.id, u.username as author, t.title as thread_title, p.created_at as date
                FROM spike_posts p
                JOIN spike_threads t ON p.thread_id=t.id
                LEFT JOIN users u ON p.author_id=u.id
                WHERE p.id=?
            ");
            $stmt->execute([$pid]);
            $row = $stmt->fetch();
            if (!$row) { echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }
            echo json_encode(['ok'=>true, 'author'=>$row['author']??'?', 'thread_title'=>$row['thread_title'], 'date'=>date('d.m.Y H:i',strtotime($row['date']))]);
        } catch(\Throwable $e){ echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    if ($ajax_action === 'merge_threads') {
        header('Content-Type: application/json');
        $source_id = (int)($_POST['source_id'] ?? 0);
        $target_id = (int)($_POST['target_id'] ?? 0);
        if (!$source_id || !$target_id || $source_id === $target_id) { echo json_encode(['error'=>'invalid_ids']); exit; }
        try {
            $db->beginTransaction();
            $db->prepare("UPDATE spike_posts SET thread_id=? WHERE thread_id=?")->execute([$target_id, $source_id]);
            try {
                $db->prepare("INSERT IGNORE INTO spike_subscriptions (user_id,thread_id) SELECT user_id,? FROM spike_subscriptions WHERE thread_id=?")->execute([$target_id, $source_id]);
                $db->prepare("DELETE FROM spike_subscriptions WHERE thread_id=?")->execute([$source_id]);
            } catch(\Throwable $e) {}
            $db->prepare("DELETE FROM spike_threads WHERE id=?")->execute([$source_id]);
            try { $db->prepare("DELETE FROM spike_read_markers WHERE thread_id=?")->execute([$source_id]); } catch(\Throwable $e) {}
            try { $db->prepare("INSERT INTO spike_merge_log (source_thread,target_thread,merged_by) VALUES (?,?,?)")->execute([$source_id,$target_id,(int)($_SESSION['user_id']??0)]); } catch(\Throwable $e) {}
            $db->commit();
            logAdminAction('MERGE_THREADS', "Merged #$source_id into #$target_id");
            echo json_encode(['ok'=>true,'target_id'=>$target_id]);
        } catch(\Exception $e){ if($db->inTransaction())$db->rollBack(); echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    if ($ajax_action === 'move_post') {
        header('Content-Type: application/json');
        $post_id   = (int)($_POST['post_id'] ?? 0);
        $target_id = (int)($_POST['target_thread_id'] ?? 0);
        if (!$post_id || !$target_id) { echo json_encode(['error'=>'invalid_ids']); exit; }
        try {
            $stmt = $db->prepare("SELECT thread_id FROM spike_posts WHERE id=? LIMIT 1");
            $stmt->execute([$post_id]);
            $row = $stmt->fetch();
            if (!$row) { echo json_encode(['error'=>'post_not_found']); exit; }
            $orig = (int)$row['thread_id'];
            $fid_stmt = $db->prepare("SELECT MIN(id) FROM spike_posts WHERE thread_id=?");
            $fid_stmt->execute([$orig]);
            if ((int)$fid_stmt->fetchColumn() === $post_id) { echo json_encode(['error'=>'cannot_move_first_post']); exit; }
            $db->prepare("UPDATE spike_posts SET thread_id=? WHERE id=?")->execute([$target_id, $post_id]);
            logAdminAction('MOVE_POST', "Moved post #$post_id from #$orig to #$target_id");
            echo json_encode(['ok'=>true]);
        } catch(\Throwable $e){ echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    if ($ajax_action === 'clear_search_log') {
        header('Content-Type: application/json');
        try { $db->query("DELETE FROM spike_search_log"); logAdminAction('CLEAR_SEARCH_LOG','Cleared search log'); echo json_encode(['ok'=>true]); }
        catch(\Throwable $e){ echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    if ($ajax_action === 'cleanup_read_markers') {
        header('Content-Type: application/json');
        try {
            $stmt = $db->query("DELETE FROM spike_read_markers WHERE marked_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
            logAdminAction('CLEANUP_READ_MARKERS', "Deleted ".$stmt->rowCount()." old read markers");
            echo json_encode(['ok'=>true, 'deleted'=>$stmt->rowCount()]);
        } catch(\Throwable $e){ echo json_encode(['error'=>$e->getMessage()]); }
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode(['error'=>'Unknown action: '.$ajax_action]);
    exit;
}

$all_cats = $db->query("SELECT * FROM spike_categories ORDER BY pos ASC")->fetchAll();

$open_reports = [];
try {
    $open_reports = $db->query("
        SELECT r.*, p.content as post_content, t.title as thread_title, t.slug as thread_slug,
               u.username as reporter_name, a.username as post_author
        FROM spike_reports r
        JOIN spike_posts p ON r.post_id=p.id
        JOIN spike_threads t ON r.thread_id=t.id
        JOIN users u ON r.reporter_id=u.id
        LEFT JOIN users a ON p.author_id=a.id
        ORDER BY FIELD(r.status,'open','reviewing','resolved','dismissed'), r.created_at DESC
        LIMIT 100
    ")->fetchAll();
} catch(\Throwable $e){}

$forbidden_words_list = [];
try {
    $forbidden_words_list = $db->query("
        SELECT fw.*, u.username as added_by_name
        FROM spike_forbidden_words fw
        LEFT JOIN users u ON fw.added_by=u.id
        ORDER BY fw.created_at DESC
    ")->fetchAll();
} catch(\Throwable $e){}

$spike_settings = [];
try {
    $spike_settings = $db->query("SELECT setting_key, setting_value FROM spike_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch(\Throwable $e){}

$all_prefixes = [];
try {
    $all_prefixes = $db->query("SELECT * FROM spike_prefixes ORDER BY pos ASC")->fetchAll();
} catch(\Throwable $e){}

$smilies_list = [];
try {
    $smilies_list = $db->query("SELECT * FROM spike_smilies ORDER BY pos ASC")->fetchAll();
} catch(\Throwable $e){}