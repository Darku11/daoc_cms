<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) { exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nested_board_action'])) {
    require __DIR__ . '/spike_admin_nested_logic.php';
}

$nested_admin_boards = [];
try {
    $nested_admin_boards = $db->query(
        "SELECT id, cat_id, parent_id, title, pos
         FROM spike_boards
         ORDER BY cat_id ASC, pos ASC, id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
?>
<script>
(function () {
    'use strict';

    const boards = <?= json_encode($nested_admin_boards, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const byId = new Map(boards.map(board => [Number(board.id), board]));
    const children = new Map();

    boards.forEach(board => {
        const parentId = Number(board.parent_id || 0);
        if (!parentId) return;
        if (!children.has(parentId)) children.set(parentId, []);
        children.get(parentId).push(Number(board.id));
    });

    function descendantIds(boardId) {
        const found = new Set();
        const queue = [Number(boardId)];
        while (queue.length) {
            const current = queue.shift();
            (children.get(current) || []).forEach(childId => {
                if (found.has(childId)) return;
                found.add(childId);
                queue.push(childId);
            });
        }
        return found;
    }

    function boardDepth(board) {
        let depth = 0;
        let parentId = Number(board.parent_id || 0);
        const seen = new Set([Number(board.id)]);
        while (parentId && byId.has(parentId) && !seen.has(parentId) && depth < 20) {
            seen.add(parentId);
            depth++;
            parentId = Number(byId.get(parentId).parent_id || 0);
        }
        return depth;
    }

    function renderParentOptions(select, catId, selectedId, excludedBoardId) {
        if (!select) return;
        const excluded = excludedBoardId ? descendantIds(excludedBoardId) : new Set();
        if (excludedBoardId) excluded.add(Number(excludedBoardId));

        select.innerHTML = '';
        const root = document.createElement('option');
        root.value = '0';
        root.textContent = '— Root board —';
        select.appendChild(root);

        boards
            .filter(board => Number(board.cat_id) === Number(catId) && !excluded.has(Number(board.id)))
            .forEach(board => {
                const option = document.createElement('option');
                option.value = String(board.id);
                option.textContent = `${'↳ '.repeat(boardDepth(board))}${board.title}`;
                if (Number(board.id) === Number(selectedId)) option.selected = true;
                select.appendChild(option);
            });
    }

    const createCat = document.getElementById('new-board-cat');
    if (createCat) {
        const catField = createCat.closest('.sa-field');
        const parentField = document.createElement('div');
        parentField.className = 'sa-field';
        parentField.innerHTML = '<label class="sa-label">Parent Board (optional)</label><select id="new-board-parent" class="sa-select"></select>';
        catField.insertAdjacentElement('afterend', parentField);
        const createParent = document.getElementById('new-board-parent');
        renderParentOptions(createParent, createCat.value, 0, 0);
        createCat.addEventListener('change', () => renderParentOptions(createParent, createCat.value, 0, 0));
    }

    window.createBoard = function () {
        const catId = document.getElementById('new-board-cat')?.value || '0';
        const parentId = document.getElementById('new-board-parent')?.value || '0';
        const title = document.getElementById('new-board-title')?.value.trim() || '';
        const desc = document.getElementById('new-board-desc')?.value.trim() || '';
        const graphic = document.getElementById('new-board-graphic')?.value.trim() || '';
        const status = document.getElementById('new-board-status');

        if (!title) {
            if (status) {
                status.textContent = 'Required';
                status.style.color = 'var(--red)';
            }
            return;
        }

        const fd = new FormData();
        fd.append('nested_board_action', 'create_board');
        fd.append('csrf_token', spikeToken);
        fd.append('target_cat_id', catId);
        fd.append('parent_id', parentId);
        fd.append('board_title', title);
        fd.append('board_desc', desc);
        fd.append('board_graphic', graphic);

        fetch('acp.php?s=spike_admin', {method: 'POST', body: fd})
            .then(response => response.text())
            .then(data => {
                const ok = data.trim().startsWith('SUCCESS');
                if (status) {
                    status.textContent = ok ? '✓ Created' : data.replace(/^ERROR:\s*/, '');
                    status.style.color = ok ? 'var(--gold)' : 'var(--red)';
                }
                if (ok) setTimeout(() => window.location.reload(), 500);
            })
            .catch(error => {
                if (status) {
                    status.textContent = error.message || String(error);
                    status.style.color = 'var(--red)';
                }
            });
    };

    const moveCat = document.getElementById('move-board-cat');
    const moveOverlay = document.getElementById('sa-move-overlay');
    let movingBoardId = 0;

    if (moveCat) {
        const catField = moveCat.closest('.sa-field');
        const parentField = document.createElement('div');
        parentField.className = 'sa-field';
        parentField.innerHTML = '<label class="sa-label">Parent Board (optional)</label><select id="move-board-parent" class="sa-select"></select>';
        catField.insertAdjacentElement('afterend', parentField);
        moveCat.addEventListener('change', () => {
            renderParentOptions(document.getElementById('move-board-parent'), moveCat.value, 0, movingBoardId);
        });
    }

    window.saMoveBoardPrompt = function (boardId, currentCatId) {
        movingBoardId = Number(boardId);
        const board = byId.get(movingBoardId);
        document.getElementById('move-board-id').value = String(movingBoardId);
        if (moveCat) moveCat.value = String(currentCatId);
        renderParentOptions(
            document.getElementById('move-board-parent'),
            currentCatId,
            board ? Number(board.parent_id || 0) : 0,
            movingBoardId
        );
        moveOverlay?.classList.add('active');
    };

    window.moveBoardConfirm = function () {
        const boardId = document.getElementById('move-board-id')?.value || '0';
        const newCatId = moveCat?.value || '0';
        const newParentId = document.getElementById('move-board-parent')?.value || '0';
        const fd = new FormData();
        fd.append('nested_board_action', 'move_board');
        fd.append('csrf_token', spikeToken);
        fd.append('board_id', boardId);
        fd.append('new_cat_id', newCatId);
        fd.append('new_parent_id', newParentId);

        fetch('acp.php?s=spike_admin', {method: 'POST', body: fd})
            .then(response => response.text())
            .then(data => {
                if (!data.trim().startsWith('SUCCESS')) {
                    alert(data.replace(/^ERROR:\s*/, ''));
                    return;
                }
                window.location.reload();
            })
            .catch(error => alert(error.message || String(error)));
    };

    boards.forEach(board => {
        const id = Number(board.id);
        const row = document.querySelector(`.board-item[data-id="${id}"]`);
        if (!row) return;

        const parentId = Number(board.parent_id || 0);
        const hasChildren = (children.get(id) || []).length > 0;
        if (parentId || hasChildren) {
            row.draggable = false;
            row.removeAttribute('draggable');
            const dragIcon = row.querySelector('.sa-drag');
            if (dragIcon) dragIcon.title = 'Nested boards are moved with the Move action.';
        }

        if (parentId && byId.has(parentId)) {
            const titleCell = row.querySelector('.sa-td-title');
            if (titleCell) {
                const hint = document.createElement('small');
                hint.className = 'sa-status';
                hint.textContent = `↳ ${byId.get(parentId).title}`;
                titleCell.appendChild(hint);
            }
        }
    });
})();
</script>
