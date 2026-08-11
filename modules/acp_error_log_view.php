<?php if (!defined('IN_CMS')) exit;

function err_type_label(int $errno): string {
    return match($errno) {
        E_ERROR             => 'E_ERROR',
        E_WARNING           => 'E_WARNING',
        E_PARSE             => 'E_PARSE',
        E_NOTICE            => 'E_NOTICE',
        E_CORE_ERROR        => 'E_CORE_ERROR',
        E_CORE_WARNING      => 'E_CORE_WARNING',
        E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING   => 'E_COMPILE_WARNING',
        E_USER_ERROR        => 'E_USER_ERROR',
        E_USER_WARNING      => 'E_USER_WARNING',
        E_USER_NOTICE       => 'E_USER_NOTICE',
        E_STRICT            => 'E_STRICT',
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_DEPRECATED        => 'E_DEPRECATED',
        E_USER_DEPRECATED   => 'E_USER_DEPRECATED',
        default             => 'E_UNKNOWN',
    };
}
?>


<div class="err-container">
    <div class="err-header">
        <div class="err-title">
            <i class="fas fa-exclamation-triangle"></i>
            <h2>System Error Log</h2>
        </div>
        <div class="err-actions">
            <button onclick="err_clear_log()" class="err-btn-clear">
                <i class="fas fa-trash-alt"></i> Clear Log
            </button>
        </div>
    </div>

    <?php if ($ai_active && !empty($error_entries)): ?>
    <div class="err-ai-bar">
        <button class="err-ai-btn" id="err-ai-pattern-btn" onclick="err_ai_detect_pattern()">
            <i class="fas fa-chart-line"></i> Detect Patterns
        </button>
        <span class="acp-s-89c48b43">
            — or click <i class="fas fa-robot acp-s-59e73eda"></i> on any error row for individual analysis
        </span>
    </div>
    <div id="err-ai-result" class="err-ai-result"></div>
    <?php endif; ?>

    <div class="err-table-wrapper">
        <table class="err-table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Message</th>
                    <th>File & Line</th>
                    <th>Time</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($error_entries)): ?>
                    <tr><td colspan="5" class="err-empty">No errors logged. The realm is at peace.</td></tr>
                <?php else: ?>
                    <?php foreach ($error_entries as $e):
                        $errLabel = err_type_label((int)$e['errno']);
                    ?>
                        <tr class="err-row">
                            <td>
                                <span class="err-type-badge type-<?= $errLabel ?>"><?= $errLabel ?></span>
                            </td>
                            <td class="err-msg-cell"><?= h($e['errstr']) ?></td>
                            <td class="err-loc-cell">
                                <small><?= h(basename($e['errfile'])) ?>:<strong><?= (int)$e['errline'] ?></strong></small>
                            </td>
                            <td class="err-time"><?= date('H:i:s d.m.y', strtotime($e['created_at'])) ?></td>
                            <td class="acp-s-791ac2b4">
                                <button onclick="err_toggle_details(this)" class="err-btn-view">
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                                <?php if ($ai_active): ?>
                                <button onclick="err_ai_analyze(<?= (int)$e['id'] ?>, this)" class="err-btn-view" title="AI Analysis">
                                    <i class="fas fa-robot"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr class="err-details-row acp-s-cb458930">
                            <td colspan="5">
                                <div class="err-details-box">
                                    <p><strong>Full Path:</strong> <?= h($e['errfile']) ?></p>
                                    <p><strong>Request URL:</strong> <?= h($e['request_url'] ?? 'N/A') ?></p>
                                    <p><strong>Stacktrace:</strong></p>
                                    <pre class="err-stacktrace"><?= h($e['stacktrace'] ?? 'No trace available') ?></pre>
                                    <?php if ($ai_active): ?>
                                    <div class="err-ai-inline-result" id="err-ai-inline-<?= (int)$e['id'] ?>"></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const err_csrf = "<?= generateToken() ?>";

function err_toggle_details(btn) {
    const row = btn.closest('.err-row').nextElementSibling;
    const isVisible = row.style.display !== 'none';
    row.style.display = isVisible ? 'none' : 'table-row';
    btn.classList.toggle('active', !isVisible);
}

function err_clear_log() {
    if (!confirm('Are you sure you want to delete all log entries?')) return;
    const fd = new FormData();
    fd.append('ajax_action', 'clear_log');
    fd.append('csrf_token', err_csrf);
    fd.append('confirmed', '1');
    fetch('acp.php?s=error_log&ajax=1', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => { if (d.ok) window.location.reload(); }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

<?php if ($ai_active): ?>
function err_ai_show(text, state='ok') {
    const el = document.getElementById('err-ai-result');
    if (!el) return;
    el.textContent = text;
    el.className = 'err-ai-result visible ' + state;
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function err_ai_detect_pattern() {
    const btn = document.getElementById('err-ai-pattern-btn');
    if (btn) btn.disabled = true;
    err_ai_show('Analyzing error patterns…', 'loading');
    const fd = new FormData();
    fd.append('ajax_action', 'ai_detect_pattern');
    fd.append('csrf_token', err_csrf);
    fetch('acp.php?s=error_log&ajax=1', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (btn) btn.disabled = false;
            if (data.status === 'ok') err_ai_show(data.result?.suggestion || 'No patterns found.', 'ok');
            else err_ai_show('Error: ' + (data.message || '?'), 'err');
        })
        .catch(e => { if (btn) btn.disabled = false; err_ai_show('Request failed: ' + e, 'err'); });
}

function err_ai_analyze(errorId, btn) {
    const errRow = btn.closest('.err-row');
    const detailsRow = errRow.nextElementSibling;
    if (detailsRow.style.display === 'none') {
        detailsRow.style.display = 'table-row';
    }

    const inlineResult = document.getElementById('err-ai-inline-' + errorId);
    if (inlineResult) {
        inlineResult.style.display = 'block';
        inlineResult.textContent = 'Analyzing error…';
        inlineResult.style.color = '#ffb000';
        inlineResult.style.fontStyle = 'italic';
    }

    if (btn) btn.disabled = true;
    const fd = new FormData();
    fd.append('ajax_action', 'ai_analyze_error');
    fd.append('csrf_token', err_csrf);
    fd.append('error_id', errorId);
    fetch('acp.php?s=error_log&ajax=1', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (btn) btn.disabled = false;
            if (inlineResult) {
                inlineResult.style.display = 'block';
                inlineResult.style.fontStyle = 'normal';
                if (data.status === 'ok') {
                    inlineResult.textContent = data.result?.suggestion || 'No analysis available.';
                    inlineResult.style.color = '#d1c0a8';
                } else {
                    inlineResult.textContent = 'Error: ' + (data.message || '?');
                    inlineResult.style.color = '#ff0000';
                }
            }
        })
        .catch(e => {
            if (btn) btn.disabled = false;
            if (inlineResult) { inlineResult.textContent = 'Request failed: ' + e; inlineResult.style.color = '#ff0000'; }
        });
}
<?php endif; ?>
</script>
