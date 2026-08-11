<?php
if (!defined('IN_ACP')) exit;

$userPriv      = (int)($_SESSION['priv_level'] ?? 0);
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

if ($userPriv < 4) return;

// ── Load handler (canonical file, not this view) ──────────
$_handler_path = __DIR__ . '/../includes/AiSuggestionApplyHandler.php';
if (file_exists($_handler_path) && !class_exists('AiSuggestionApplyHandler')) {
    require_once $_handler_path;
}
$_has_handler = class_exists('AiSuggestionApplyHandler');

// ── AJAX actions ──────────────────────────────────────────
if (isset($_GET['ajax'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json');

    $token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        aldhran_log("SECURITY_ALERT", "CSRF Token Validation Failed (AI Suggestions)", $currentUserId);
        echo json_encode(['ok' => false, 'error' => 'CSRF token validation failed. Please reload the page.']);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if (!$id) { echo json_encode(['ok' => false, 'error' => 'Missing ID']); exit; }

    if ($action === 'accept') {
        $db->prepare("UPDATE cms_ai_suggestions SET status='accepted', reviewed_at=NOW(), reviewed_by=? WHERE id=?")
           ->execute([$currentUserId, $id]);

        if ($_has_handler) {
            $stmt = $db->prepare("SELECT * FROM cms_ai_suggestions WHERE id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $handler = new AiSuggestionApplyHandler($db, $currentUserId, $userPriv);
                $result  = $handler->apply($row);
                aldhran_log('AI_SUGGESTION_ACCEPTED', "Suggestion #$id accepted", $currentUserId);
                echo json_encode(['ok' => true, 'apply' => $result]);
                exit;
            }
        }

        aldhran_log('AI_SUGGESTION_ACCEPTED', "Suggestion #$id accepted (no handler)", $currentUserId);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'reject') {
        $db->prepare("UPDATE cms_ai_suggestions SET status='rejected', reviewed_at=NOW(), reviewed_by=? WHERE id=?")
           ->execute([$currentUserId, $id]);
        aldhran_log('AI_SUGGESTION_REJECTED', "Suggestion #$id rejected", $currentUserId);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete') {
        $db->prepare("DELETE FROM cms_ai_suggestions WHERE id=?")->execute([$id]);
        aldhran_log('AI_SUGGESTION_DELETED', "Suggestion #$id deleted", $currentUserId);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

// ── Load suggestions by tab ───────────────────────────────
$_tab = in_array($_GET['tab'] ?? '', ['accepted','rejected']) ? $_GET['tab'] : 'pending';

$_suggestions = [];
try {
    $stmt = $db->prepare(
        "SELECT s.*, u.username AS reviewed_by_name
         FROM cms_ai_suggestions s
         LEFT JOIN users u ON u.id = s.reviewed_by
         WHERE s.status = ?
         ORDER BY s.created_at DESC
         LIMIT 100"
    );
    $stmt->execute([$_tab]);
    $_suggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $_suggestions = [];
}

// ── Counts for tab badges ─────────────────────────────────
$_counts = ['pending'=>0,'accepted'=>0,'rejected'=>0];
try {
    $rows = $db->query("SELECT status, COUNT(*) c FROM cms_ai_suggestions GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if (isset($_counts[$r['status']])) $_counts[$r['status']] = (int)$r['c'];
    }
} catch (\Throwable $e) {}

$_csrf = generateToken();
?>

<div class="ais-wrap">

    <div class="ais-header">
        <h2><i class="fas fa-lightbulb"></i> AI Suggestions</h2>
        <?php if (!$_has_handler): ?>
        <div class="ais-warn">
            <i class="fas fa-triangle-exclamation"></i>
            AiSuggestionApplyHandler not found — Accept will mark as accepted but won't auto-apply.
        </div>
        <?php endif; ?>
    </div>

    <!-- Tabs -->
    <div class="ais-tabs">
        <?php foreach (['pending'=>'fa-clock','accepted'=>'fa-check-circle','rejected'=>'fa-times-circle'] as $t => $icon): ?>
        <a href="acp.php?s=ai_suggestions&tab=<?= $t ?>"
           class="ais-tab <?= $_tab === $t ? 'is-active' : '' ?>">
            <i class="fas <?= $icon ?>"></i>
            <?= ucfirst($t) ?>
            <?php if ($_counts[$t] > 0): ?>
                <span class="ais-tab-badge <?= $t ?>"><?= $_counts[$t] ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Suggestion list -->
    <?php if (empty($_suggestions)): ?>
    <div class="ais-empty">
        <i class="fas fa-inbox"></i>
        <p>No <?= $_tab ?> suggestions.</p>
    </div>
    <?php else: ?>
    <div class="ais-list" id="ais-list">
        <?php foreach ($_suggestions as $s):
            $data    = json_decode($s['suggestion_data'] ?? '{}', true) ?? [];
            $text    = $data['text'] ?? $data['summary'] ?? $s['suggestion_text'] ?? '';
            $module  = $s['module_context'] ?? '—';
            $atype   = $s['action_type']    ?? $s['target_type'] ?? '—';
            $created = !empty($s['created_at']) ? date('d.m.Y H:i', strtotime($s['created_at'])) : '—';
            $reviewed = !empty($s['reviewed_at']) ? date('d.m.Y H:i', strtotime($s['reviewed_at'])) : null;
        ?>
        <div class="ais-card" id="ais-card-<?= (int)$s['id'] ?>">
            <div class="ais-card-head">
                <span class="ais-badge-module"><?= h($module) ?></span>
                <span class="ais-badge-type"><?= h($atype) ?></span>
                <?php if (!empty($s['target_id'])): ?>
                    <span class="ais-badge-id">ID <?= (int)$s['target_id'] ?></span>
                <?php endif; ?>
                <span class="ais-card-date"><?= $created ?></span>
                <?php if ($reviewed): ?>
                    <span class="ais-card-reviewed">
                        reviewed <?= $reviewed ?>
                        <?= !empty($s['reviewed_by_name']) ? ' by ' . h($s['reviewed_by_name']) : '' ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($text): ?>
            <div class="ais-card-body"><?= nl2br(h($text)) ?></div>
            <?php endif; ?>

            <?php if (!empty($data) && count($data) > 1 || (empty($text) && !empty($data))): ?>
            <details class="ais-card-raw">
                <summary>Raw data</summary>
                <pre><?= h(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            </details>
            <?php endif; ?>

            <?php if ($_tab === 'pending'): ?>
            <div class="ais-card-actions">
                <button class="ais-btn ais-btn--accept" onclick="aisAction(<?= (int)$s['id'] ?>, 'accept')">
                    <i class="fas fa-check"></i> Accept
                </button>
                <button class="ais-btn ais-btn--reject" onclick="aisAction(<?= (int)$s['id'] ?>, 'reject')">
                    <i class="fas fa-times"></i> Reject
                </button>
            </div>
            <?php else: ?>
            <div class="ais-card-actions">
                <button class="ais-btn ais-btn--delete" onclick="aisAction(<?= (int)$s['id'] ?>, 'delete')">
                    <i class="fas fa-trash-alt"></i> Delete
                </button>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>


<script>
const _ais_csrf = <?= json_encode($_csrf) ?>;

function aisAction(id, action) {
    if (action === 'delete' && !confirm('Delete this suggestion permanently?')) return;

    const card = document.getElementById('ais-card-' + id);
    if (card) card.style.opacity = '0.4';

    const fd = new FormData();
    fd.append('action',     action);
    fd.append('id',         id);
    fd.append('csrf_token', _ais_csrf);

    fetch('acp.php?s=ai_suggestions&ajax=1', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                card?.remove();
                // If list is now empty, show empty state
                const list = document.getElementById('ais-list');
                if (list && !list.querySelector('.ais-card')) {
                    list.outerHTML = '<div class="ais-empty"><i class="fas fa-inbox"></i><p>No suggestions.</p></div>';
                }
                // Update pending badge in quickbar if accepting/rejecting
                if (action !== 'delete') {
                    const bdg = document.querySelector('.acp-slot-bdg');
                    if (bdg) {
                        const n = Math.max(0, (parseInt(bdg.textContent) || 0) - 1);
                        if (n > 0) bdg.textContent = n;
                        else bdg.remove();
                    }
                }
            } else {
                if (card) card.style.opacity = '1';
                alert(d.error || 'Action failed.');
            }
        })
        .catch(() => {
            if (card) card.style.opacity = '1';
            alert('Network error.');
        });
}
</script>