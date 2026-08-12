<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) { exit; }

// Helper: highlight the search term in text
function spk_highlight(string $text, string $q): string {
    if (empty($q)) return h($text);
    $words = array_filter(explode(' ', preg_quote($q, '/')));
    if (empty($words)) return h($text);
    $pattern = '/(' . implode('|', $words) . ')/iu';
    return preg_replace($pattern, '<mark>$1</mark>', h($text));
}

// Helper: shorten and clean up the snippet
function spk_snippet(string $raw, string $q, int $length = 180): string {
    $text = strip_tags($raw);
    $text = html_entity_decode($text);
    $text = preg_replace('/\s+/', ' ', trim($text));
    if (strlen($text) > $length) {
        // try to center the search term in the snippet
        $pos = mb_stripos($text, $q);
        if ($pos !== false && $pos > 60) {
            $start = max(0, $pos - 60);
            $text  = '…' . mb_substr($text, $start, $length);
        } else {
            $text = mb_substr($text, 0, $length) . '…';
        }
    }
    return spk_highlight($text, $q);
}

$total_pages = $total > 0 ? (int)ceil($total / $per_page) : 1;

function spk_search_url(string $q, int $page, string $type, int $board, string $author, string $from, string $to): string {
    $p = ['p'=>'spike_search','q'=>$q,'type'=>$type];
    if ($page  > 1)     $p['page']   = $page;
    if ($board > 0)     $p['board']  = $board;
    if (!empty($author)) $p['author'] = $author;
    if (!empty($from))  $p['from']   = $from;
    if (!empty($to))    $p['to']     = $to;
    return 'index.php?' . http_build_query($p);
}
?>

<div class="um-nexus-wrapper">

    <nav class="spk-breadcrumb" aria-label="Breadcrumb">
        <a href="index.php?p=spike"><i class="fas fa-comments" style="font-size:0.9em;"></i> <?= t('viewboard.breadcrumb_forum',[],'Forum') ?></a>
        <span class="spk-breadcrumb-sep"><i class="fas fa-chevron-right"></i></span>
        <span class="spk-breadcrumb-current"><?= t('spike_search.title',[],'Search') ?></span>
    </nav>

    <div class="spk-search-wrap">

        <form method="GET" action="index.php" id="spk-search-form">
            <input type="hidden" name="p" value="spike_search">
            <div class="spk-search-bar">
                <input type="text"
                       name="q"
                       id="spk-search-q"
                       class="spk-search-input"
                       value="<?= h($q) ?>"
                       placeholder="<?= t('spike_search.placeholder',[],'Search threads and posts…') ?>"
                       autocomplete="off"
                       autofocus>
                <button type="submit" class="spk-search-btn">
                    <i class="fas fa-search"></i>
                    <?= t('spike_search.btn',[],'Search') ?>
                </button>
            </div>

            <div class="spk-search-filters">
                <div class="spk-search-filter-label">
                    <i class="fas fa-filter" style="font-size:0.9em;"></i>
                    <?= t('spike_search.filter',[],'Filter') ?>
                </div>

                <select name="type" class="spk-search-filter-select">
                    <option value="both"   <?= $type==='both'  ?'selected':'' ?>><?= t('spike_search.type_both',[],'Threads + Posts') ?></option>
                    <option value="thread" <?= $type==='thread'?'selected':'' ?>><?= t('spike_search.type_thread',[],'Threads only') ?></option>
                    <option value="post"   <?= $type==='post'  ?'selected':'' ?>><?= t('spike_search.type_post',[],'Posts only') ?></option>
                </select>

                <select name="board" class="spk-search-filter-select">
                    <option value="0"><?= t('spike_search.all_boards',[],'All Boards') ?></option>
                    <?php
                    $current_cat = '';
                    foreach ($boards_for_filter as $bf):
                        if ($bf['cat_title'] !== $current_cat):
                            if ($current_cat !== '') echo '</optgroup>';
                            $current_cat = $bf['cat_title'];
                            echo '<optgroup label="' . h($bf['cat_title']) . '">';
                        endif;
                    ?>
                    <option value="<?= (int)$bf['id'] ?>" <?= $board_id===$bf['id']?'selected':'' ?>><?= h($bf['title']) ?></option>
                    <?php endforeach; ?>
                    <?php if ($current_cat !== '') echo '</optgroup>'; ?>
                </select>

                <input type="text"
                       name="author"
                       class="spk-search-filter-select"
                       style="min-width:130px;"
                       value="<?= h($author) ?>"
                       placeholder="<?= t('spike_search.by_user',[],'By user…') ?>">

                <input type="date" name="from" class="spk-search-filter-select" value="<?= h($date_from) ?>" title="From date">
                <input type="date" name="to"   class="spk-search-filter-select" value="<?= h($date_to) ?>"   title="To date">
            </div>
        </form>

        <?php if (!empty($error)): ?>
        <div style="padding:14px;background:rgba(200,0,0,0.04);border:1px solid rgba(200,0,0,0.12);color:#888;font-size:0.82em;font-family:sans-serif;margin-bottom:16px;">
            <i class="fas fa-exclamation-circle" style="margin-right:8px;opacity:0.6;"></i><?= h($error) ?>
        </div>
        <?php endif; ?>

        <?php if ($did_search && empty($error)): ?>
        <div class="spk-search-meta">
            <?php if ($total > 0): ?>
            <strong><?= number_format($total) ?></strong> <?= t('spike_search.results_found',[],'results for') ?>
            "<strong><?= h($q) ?></strong>"
            <?php if ($total_pages > 1): ?>
            · <?= t('viewthread.page',[],'Page') ?> <?= $page ?>/<?= $total_pages ?>
            <?php endif; ?>
            <?php else: ?>
            <?= t('spike_search.no_results',[],'No results found for') ?> "<strong><?= h($q) ?></strong>"
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($did_search && empty($error) && !empty($results)): ?>
        <div id="spk-search-results">
            <?php foreach ($results as $r):
                $thread_url = !empty($r['thread_slug'])
                    ? 'index.php?p=viewthread&slug=' . urlencode($r['thread_slug'])
                    : 'index.php?p=viewthread&id=' . (int)$r['thread_id'];
                if ($r['result_type'] === 'post') {
                    $thread_url .= '#post-' . (int)$r['source_id'];
                }
            ?>
            <a href="<?= $thread_url ?>" class="spk-search-result">
                <div class="spk-search-result-title">
                    <span class="spk-search-result-type spk-search-result-type--<?= h($r['result_type']) ?>">
                        <?= $r['result_type'] === 'thread' ? 'Thread' : 'Post' ?>
                    </span>
                    <?= spk_highlight($r['title'], $q) ?>
                </div>
                <?php if (!empty($r['snippet'])): ?>
                <div class="spk-search-result-snippet">
                    <?= spk_snippet($r['snippet'], $q) ?>
                </div>
                <?php endif; ?>
                <div class="spk-search-result-meta">
                    <span><i class="fas fa-user"></i> <?= h($r['author']) ?></span>
                    <span><i class="fas fa-comments"></i> <?= h($r['board_title']) ?></span>
                    <span><i class="fas fa-reply"></i> <?= (int)($r['reply_count'] ?? 0) ?> <?= ((int)($r['reply_count'] ?? 0) === 1) ? t('viewboard.post',[],'post') : t('viewboard.posts',[],'posts') ?></span>
                    <span><i class="fas fa-clock"></i> <?= date('d.m.Y', strtotime($r['created_date'])) ?></span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="spk-pagination" style="margin-top:20px;">
            <?php if ($page > 1): ?>
                <a href="<?= spk_search_url($q,1,$type,$board_id,$author,$date_from,$date_to) ?>" class="spk-page-btn">«</a>
                <a href="<?= spk_search_url($q,$page-1,$type,$board_id,$author,$date_from,$date_to) ?>" class="spk-page-btn">‹</a>
            <?php endif; ?>
            <?php for ($i=max(1,$page-2); $i<=min($total_pages,$page+2); $i++): ?>
                <a href="<?= spk_search_url($q,$i,$type,$board_id,$author,$date_from,$date_to) ?>"
                   class="spk-page-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?>
                <a href="<?= spk_search_url($q,$page+1,$type,$board_id,$author,$date_from,$date_to) ?>" class="spk-page-btn">›</a>
                <a href="<?= spk_search_url($q,$total_pages,$type,$board_id,$author,$date_from,$date_to) ?>" class="spk-page-btn">»</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php elseif ($did_search && empty($error) && empty($results)): ?>
        <div class="spk-search-empty">
            <i class="fas fa-search" style="font-size:2em;opacity:0.1;display:block;margin-bottom:12px;"></i>
            <?= t('spike_search.no_results_hint',[],'Try different keywords, fewer filters, or check your spelling.') ?>
        </div>
        <?php elseif (!$did_search): ?>
        <div style="padding:40px 0;text-align:center;">
            <i class="fas fa-search" style="font-size:2.5em;color:#1a1a1a;display:block;margin-bottom:14px;"></i>
            <div style="font-family:'Cinzel',serif;font-size:0.65em;letter-spacing:2px;color:#333;text-transform:uppercase;">
                <?= t('spike_search.hint',[],'Enter a search term above') ?>
            </div>
        </div>
        <?php endif; ?>

    </div></div><script>
// ── Live Search (debounced, AJAX) ─────────────────────────────
(function() {
    const input  = document.getElementById('spk-search-q');
    const form   = document.getElementById('spk-search-form');
    const minLen = <?= (int)$min_length ?>;
    let timer = null;

    if (!input) return;

    input.addEventListener('input', function() {
        clearTimeout(timer);
        const q = this.value.trim();
        if (q.length < minLen) return;

        timer = setTimeout(function() {
            const fd  = new FormData(form);
            const url = 'index.php?' + new URLSearchParams(Object.fromEntries(fd)).toString() + '&ajax=1';
            const box = document.getElementById('spk-search-results');

            fetch(url).then(r=>r.json()).then(data => {
                if (!data.ok || !data.results?.length) return;
                if (box) box.innerHTML = data.results.map(r => buildResult(r, q)).join('');
            }).catch(()=>{});
        }, 450);
    });

    function buildResult(r, q) {
        const url = r.thread_slug
            ? 'index.php?p=viewthread&slug=' + encodeURIComponent(r.thread_slug)
            : 'index.php?p=viewthread&id=' + r.thread_id;
        const finalUrl = r.result_type === 'post' ? url + '#post-' + r.source_id : url;
        const typeClass = r.result_type === 'thread' ? 'spk-search-result-type--thread' : 'spk-search-result-type--post';
        const snippet   = r.snippet ? ('<div class="spk-search-result-snippet">'+escHtml(r.snippet.substring(0,180))+'</div>') : '';
        return `<a href="${finalUrl}" class="spk-search-result">
            <div class="spk-search-result-title">
                <span class="spk-search-result-type ${typeClass}">${r.result_type}</span>
                ${escHtml(r.title)}
            </div>
            ${snippet}
            <div class="spk-search-result-meta">
                <span><i class="fas fa-user"></i> ${escHtml(r.author)}</span>
                <span><i class="fas fa-comments"></i> ${escHtml(r.board_title)}</span>
                <span><i class="fas fa-clock"></i> ${r.created_date?.substring(0,10)}</span>
            </div>
        </a>`;
    }

    function escHtml(s) {
        return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
})();
</script>