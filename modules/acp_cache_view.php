<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_ACP')) exit;
if ((int)($_SESSION['priv_level'] ?? 0) < 3) return;
?>

<div class="cache-wrap">

    <!-- ── Page Header ── -->
    <div class="cache-page-header">
        <div>
            <div class="cache-page-title"><i class="fas fa-database"></i> Cache Manager</div>
            <div class="cache-page-sub">Control CSS versioning, PHP OPcache and browser cache headers</div>
        </div>
        <button class="cache-btn cache-btn--danger" onclick="cache_clear('clear_all')" id="btn-clear-all">
            <i class="fas fa-trash-alt"></i> Clear All Caches
        </button>
    </div>

    <!-- ── Status Cards ── -->
    <div class="cache-cards">

        <!-- CSS Cache -->
        <div class="cache-card" id="card-css">
            <div class="cache-card-head">
                <i class="fas fa-paint-brush"></i>
                <span>CSS Cache</span>
                <span class="cache-status-badge cache-status-ok">Active</span>
            </div>
            <div class="cache-card-body">
                <div class="cache-meta-row">
                    <span class="cache-meta-label">Current Version</span>
                    <span class="cache-meta-value" id="css-version">v<?= h($cache_css_version) ?></span>
                </div>
                <div class="cache-meta-row">
                    <span class="cache-meta-label">Last Cleared</span>
                    <span class="cache-meta-value" id="css-cleared">
                        <?= $cache_css_cleared_at ? h($cache_css_cleared_at) : '—' ?>
                    </span>
                </div>
                <div class="cache-card-desc">
                    Incrementing <code>css_version</code> changes the ETag in <code>style.php</code>,
                    forcing all browsers to fetch fresh CSS on the next request.
                </div>
            </div>
            <div class="cache-card-actions">
                <button class="cache-btn cache-btn--primary" onclick="cache_clear('clear_css')" id="btn-css">
                    <i class="fas fa-sync-alt"></i> Clear CSS Cache
                </button>
            </div>
        </div>

        <!-- OPcache -->
        <div class="cache-card" id="card-opcache">
            <div class="cache-card-head">
                <i class="fas fa-microchip"></i>
                <span>PHP OPcache</span>
                <?php if ($opcache_available): ?>
                    <span class="cache-status-badge <?= !empty($opcache_status['enabled']) ? 'cache-status-ok' : 'cache-status-warn' ?>">
                        <?= !empty($opcache_status['enabled']) ? 'Enabled' : 'Disabled' ?>
                    </span>
                <?php else: ?>
                    <span class="cache-status-badge cache-status-off">Unavailable</span>
                <?php endif; ?>
            </div>
            <div class="cache-card-body">
                <?php if (!empty($opcache_status)): ?>
                <div class="cache-meta-row">
                    <span class="cache-meta-label">Cached Scripts</span>
                    <span class="cache-meta-value"><?= number_format($opcache_status['cached_scripts']) ?></span>
                </div>
                <div class="cache-meta-row">
                    <span class="cache-meta-label">Hits / Misses</span>
                    <span class="cache-meta-value">
                        <?= number_format($opcache_status['hits']) ?> / <?= number_format($opcache_status['misses']) ?>
                    </span>
                </div>
                <div class="cache-meta-row">
                    <span class="cache-meta-label">Memory Used</span>
                    <span class="cache-meta-value">
                        <?= $opcache_status['memory_used'] ?> MB
                        <span class="acp-s-757462cb">/ <?= $opcache_status['memory_free'] ?> MB free</span>
                    </span>
                </div>
                <?php endif; ?>
                <div class="cache-meta-row">
                    <span class="cache-meta-label">Last Cleared</span>
                    <span class="cache-meta-value" id="opc-cleared">
                        <?= $cache_opc_cleared_at ? h($cache_opc_cleared_at) : '—' ?>
                    </span>
                </div>
                <div class="cache-card-desc">
                    Resets all compiled PHP scripts from memory. New requests will recompile files,
                    ensuring code changes — like a updated <code>header.php</code> — take effect immediately.
                </div>
            </div>
            <div class="cache-card-actions">
                <button class="cache-btn <?= $opcache_available ? 'cache-btn--primary' : 'cache-btn--disabled' ?>"
                        onclick="cache_clear('clear_opcache')" id="btn-opcache"
                        <?= $opcache_available ? '' : 'disabled' ?>>
                    <i class="fas fa-sync-alt"></i> Clear OPcache
                </button>
            </div>
        </div>

        <!-- Browser Cache Headers -->
        <div class="cache-card" id="card-headers">
            <div class="cache-card-head">
                <i class="fas fa-globe"></i>
                <span>Browser Cache Headers</span>
                <span class="cache-status-badge cache-status-ok">Active</span>
            </div>
            <div class="cache-card-body">
                <div class="cache-meta-row">
                    <span class="cache-meta-label">Strategy</span>
                    <span class="cache-meta-value">ETag + max-age=86400</span>
                </div>
                <div class="cache-meta-row">
                    <span class="cache-meta-label">Cache-Control</span>
                    <span class="cache-meta-value"><code>public, max-age=86400</code></span>
                </div>
                <div class="cache-meta-row">
                    <span class="cache-meta-label">Invalidation</span>
                    <span class="cache-meta-value">Via <code>css_version</code> in ETag</span>
                </div>
                <div class="cache-card-desc">
                    CSS files are cached for 24h in browsers. Clearing the CSS cache increments
                    <code>css_version</code> which changes the ETag — browsers download fresh CSS
                    on the next visit without any manual action.
                </div>
            </div>
            <div class="cache-card-actions">
                <button class="cache-btn cache-btn--primary" onclick="cache_clear('clear_css')" id="btn-headers">
                    <i class="fas fa-sync-alt"></i> Force Revalidation
                </button>
            </div>
        </div>

    </div>

    <!-- ── Result Log ── -->
    <div class="cache-log acp-s-cb458930" id="cache-log">
        <div class="cache-log-head">
            <i class="fas fa-terminal"></i> Result
            <button class="cache-log-close" onclick="document.getElementById('cache-log').style.display='none'">✕</button>
        </div>
        <div class="cache-log-body" id="cache-log-body"></div>
    </div>

</div>


<script>
const cache_csrf = "<?= generateToken() ?>";

function cache_clear(action) {
    const ids = ['btn-css', 'btn-opcache', 'btn-headers', 'btn-clear-all'];
    ids.forEach(id => {
        const b = document.getElementById(id);
        if (b && !b.disabled) { b.disabled = true; b.classList.add('is-loading'); }
    });

    const fd = new FormData();
    fd.append('action',     action);
    fd.append('csrf_token', cache_csrf);

    fetch('acp.php?s=cache&ajax=1', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            ids.forEach(id => {
                const b = document.getElementById(id);
                if (b) { b.disabled = false; b.classList.remove('is-loading'); }
            });

            if (!d.ok) { cache_log_show([{ ok: false, msg: 'Request failed.' }]); return; }

            const lines = [];
            const ts    = new Date().toLocaleTimeString();

            if (d.results.css) {
                lines.push(d.results.css);
                if (d.results.css.ok) {
                    const vEl = document.getElementById('css-version');
                    if (vEl) {
                        const cur = parseInt(vEl.textContent.replace('v', '')) || 1;
                        vEl.textContent = 'v' + (cur + 1);
                    }
                    const clEl = document.getElementById('css-cleared');
                    if (clEl) clEl.textContent = ts;
                }
            }
            if (d.results.opcache) {
                lines.push(d.results.opcache);
                if (d.results.opcache.ok) {
                    const opEl = document.getElementById('opc-cleared');
                    if (opEl) opEl.textContent = ts;
                }
            }

            cache_log_show(lines);
        })
        .catch(() => {
            ids.forEach(id => {
                const b = document.getElementById(id);
                if (b) { b.disabled = false; b.classList.remove('is-loading'); }
            });
            cache_log_show([{ ok: false, msg: 'Network error.' }]);
        });
}

function cache_log_show(lines) {
    const log  = document.getElementById('cache-log');
    const body = document.getElementById('cache-log-body');
    const ts   = new Date().toLocaleTimeString();
    body.innerHTML = lines.map(l =>
        `<div class="${l.ok ? 'cache-log-ok' : 'cache-log-fail'}">[${ts}] ${l.ok ? '✓' : '✗'} ${l.msg}</div>`
    ).join('');
    log.style.display = 'block';
    log.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
</script>