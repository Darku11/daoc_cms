<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) { exit; }

if (ob_get_length()) ob_clean();
header('Content-Type: text/plain; charset=UTF-8');

if ((int)($userPriv ?? 0) < 4) {
    http_response_code(403);
    echo 'ERROR: Insufficient privileges.';
    exit;
}

checkToken($_POST['csrf_token'] ?? '');
$nested_action = (string)($_POST['nested_board_action'] ?? '');

function spike_nested_graphic_value(string $value): ?string
{
    if (function_exists('spikeBoardGraphicValue')) {
        return spikeBoardGraphicValue($value);
    }

    $value = trim($value);
    if ($value === '') return '';
    if (mb_strlen($value) > 255 || preg_match('/[\x00-\x1F<>"\']/', $value)) return null;
    if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $value) && !preg_match('#^https?://#i', $value)) return null;
    return $value;
}

function spike_nested_log(string $type, string $details): void
{
    if (function_exists('logAdminAction')) {
        logAdminAction($type, $details);
        return;
    }
    aldhran_log($type, $details, (int)($_SESSION['user_id'] ?? 0));
}

function spike_nested_descendants(PDO $db, int $boardId): array
{
    $rows = $db->query("SELECT id, parent_id FROM spike_boards")->fetchAll(PDO::FETCH_ASSOC);
    $children = [];
    foreach ($rows as $row) {
        $parent = (int)($row['parent_id'] ?? 0);
        if ($parent > 0) $children[$parent][] = (int)$row['id'];
    }

    $found = [];
    $queue = [$boardId];
    while ($queue) {
        $current = array_shift($queue);
        foreach ($children[$current] ?? [] as $childId) {
            if (isset($found[$childId])) continue;
            $found[$childId] = true;
            $queue[] = $childId;
        }
    }
    return array_map('intval', array_keys($found));
}

if ($nested_action === 'create_board') {
    $catId    = (int)($_POST['target_cat_id'] ?? 0);
    $parentId = (int)($_POST['parent_id'] ?? 0);
    $title    = trim((string)($_POST['board_title'] ?? ''));
    $desc     = trim((string)($_POST['board_desc'] ?? ''));
    $graphic  = spike_nested_graphic_value((string)($_POST['board_graphic'] ?? ''));

    if ($catId <= 0 || $title === '') {
        echo 'ERROR: Category and title are required.';
        exit;
    }
    if ($graphic === null) {
        echo 'ERROR: Invalid graphic path/URL.';
        exit;
    }

    $catStmt = $db->prepare("SELECT id FROM spike_categories WHERE id=? LIMIT 1");
    $catStmt->execute([$catId]);
    if (!$catStmt->fetchColumn()) {
        echo 'ERROR: Category not found.';
        exit;
    }

    $parent = null;
    if ($parentId > 0) {
        $parentStmt = $db->prepare("SELECT id, cat_id FROM spike_boards WHERE id=? LIMIT 1");
        $parentStmt->execute([$parentId]);
        $parent = $parentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$parent || (int)$parent['cat_id'] !== $catId) {
            echo 'ERROR: Parent board must belong to the selected category.';
            exit;
        }
    }

    $parentValue = $parentId > 0 ? $parentId : null;
    $posStmt = $db->prepare("SELECT COALESCE(MAX(pos),0) FROM spike_boards WHERE cat_id=? AND parent_id <=> ?");
    $posStmt->execute([$catId, $parentValue]);
    $nextPos = (int)$posStmt->fetchColumn() + 1;

    $stmt = $db->prepare(
        "INSERT INTO spike_boards
            (cat_id, parent_id, title, description, graphic_url, pos, min_priv, min_priv_post)
         VALUES (?, ?, ?, ?, ?, ?, 1, 1)"
    );
    $stmt->execute([
        $catId,
        $parentValue,
        $title,
        $desc,
        $graphic === '' ? null : $graphic,
        $nextPos,
    ]);

    $newId = (int)$db->lastInsertId();
    spike_nested_log('CREATE_BOARD', "Created '$title' as board $newId" . ($parentId > 0 ? " below board $parentId" : ''));
    echo 'SUCCESS';
    exit;
}

if ($nested_action === 'move_board') {
    $boardId  = (int)($_POST['board_id'] ?? 0);
    $catId    = (int)($_POST['new_cat_id'] ?? 0);
    $parentId = (int)($_POST['new_parent_id'] ?? 0);

    if ($boardId <= 0 || $catId <= 0) {
        echo 'ERROR: Invalid board or category.';
        exit;
    }

    $boardStmt = $db->prepare("SELECT id, cat_id, parent_id, title FROM spike_boards WHERE id=? LIMIT 1");
    $boardStmt->execute([$boardId]);
    $board = $boardStmt->fetch(PDO::FETCH_ASSOC);
    if (!$board) {
        echo 'ERROR: Board not found.';
        exit;
    }

    $catStmt = $db->prepare("SELECT id FROM spike_categories WHERE id=? LIMIT 1");
    $catStmt->execute([$catId]);
    if (!$catStmt->fetchColumn()) {
        echo 'ERROR: Category not found.';
        exit;
    }

    $descendants = spike_nested_descendants($db, $boardId);
    if ($parentId === $boardId || in_array($parentId, $descendants, true)) {
        echo 'ERROR: A board cannot be moved below itself or one of its descendants.';
        exit;
    }

    if ($parentId > 0) {
        $parentStmt = $db->prepare("SELECT id, cat_id FROM spike_boards WHERE id=? LIMIT 1");
        $parentStmt->execute([$parentId]);
        $parent = $parentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$parent || (int)$parent['cat_id'] !== $catId) {
            echo 'ERROR: Parent board must belong to the target category.';
            exit;
        }
    }

    $parentValue = $parentId > 0 ? $parentId : null;
    $posStmt = $db->prepare("SELECT COALESCE(MAX(pos),0) FROM spike_boards WHERE cat_id=? AND parent_id <=> ? AND id<>?");
    $posStmt->execute([$catId, $parentValue, $boardId]);
    $nextPos = (int)$posStmt->fetchColumn() + 1;

    try {
        $db->beginTransaction();

        $subtree = array_merge([$boardId], $descendants);
        if ((int)$board['cat_id'] !== $catId && $subtree) {
            $placeholders = implode(',', array_fill(0, count($subtree), '?'));
            $params = array_merge([$catId], $subtree);
            $db->prepare("UPDATE spike_boards SET cat_id=? WHERE id IN ($placeholders)")->execute($params);
        }

        $db->prepare("UPDATE spike_boards SET parent_id=?, cat_id=?, pos=? WHERE id=?")
           ->execute([$parentValue, $catId, $nextPos, $boardId]);

        $db->commit();
        spike_nested_log(
            'MOVE_BOARD',
            "Moved board $boardId to category $catId" . ($parentId > 0 ? " below board $parentId" : ' as root board')
        );
        echo 'SUCCESS';
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo 'ERROR: ' . $e->getMessage();
    }
    exit;
}

echo 'ERROR: Unknown nested board action.';
exit;
