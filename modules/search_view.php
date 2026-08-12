<?php
// SPDX-License-Identifier: GPL-3.0-only if (!defined('IN_CMS')) exit; ?>
<div class="um-nexus-wrapper">
    <div class="um-internal-header">
        <h2 class="um-internal-title">
            <i class="fas fa-search"></i>
            <?= isset($_GET['q']) && trim($_GET['q']) !== ''
                ? t('search.title', ['query' => h($query)], 'Search Results: "{query}"')
                : t('search.title_generic', [], 'Search Database') ?>
        </h2>
    </div>

    <div class="admin-box srch-form-box">
        <form action="index.php" method="GET" class="srch-form">
            <input type="hidden" name="p" value="search">
            <input type="text" name="q" value="<?= h($query ?? '') ?>" placeholder="<?= t('header.search_placeholder', [], 'Search...') ?>" required
                   class="srch-input"
                   onfocus="this.style.borderColor='rgba(197,160,89,0.8)';">
            <button type="submit" class="srch-btn"
                    onmouseover="this.style.background='#c5a059'; this.style.color='#000';"
                    onmouseout="this.style.background='rgba(197,160,89,0.1)'; this.style.color='#c5a059';">
                <i class="fas fa-search"></i>
            </button>
        </form>
    </div>

    <?php if (!isset($_GET['q']) || trim($_GET['q']) === ''): ?>
        <div class="srch-prompt">
            <?= t('search.prompt', [], 'ENTER A KEYWORD TO SEARCH PLAYERS, THREADS, AND POSTS.') ?>
        </div>

    <?php elseif (strlen($query) < 3): ?>
        <p class="admin-box srch-min-chars"><?= t('search.min_chars', [], 'Please enter at least 3 characters.'); ?></p>

    <?php else: ?>

        <?php if (!empty($results['users'])): ?>
            <h3 class="srch-section-title"><?= t('search.found_players', [], 'Found Players'); ?></h3>
            <div class="srch-users-grid">
                <?php foreach ($results['users'] as $u): ?>
                    <a href="?p=user&id=<?php echo (int)$u['id']; ?>" class="quick-card srch-user-card">
                        <img src="<?php echo !empty($u['avatar_url']) ? h($u['avatar_url']) : 'assets/img/default_av.png'; ?>"
                             class="srch-user-avatar" alt="">
                        <span><?php echo h($u['username']); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($results['threads'])): ?>
            <h3 class="srch-section-title"><?= t('search.found_threads', [], 'Chronicles (Threads)'); ?></h3>
            <div class="admin-box srch-results-box">
                <?php foreach ($results['threads'] as $t): ?>
                    <a href="?p=viewthread&id=<?php echo (int)$t['id']; ?>"
                       class="result-item srch-thread-item">
                        <i class="fas fa-scroll"></i> <?php echo h($t['title']); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($results['posts'])): ?>
            <h3 class="srch-section-title"><?= t('search.found_posts', [], 'Fragments (Posts)'); ?></h3>
            <?php foreach ($results['posts'] as $p): ?>
                <div class="admin-box srch-post-item">
                    <a href="?p=viewthread&id=<?php echo (int)$p['thread_id']; ?>" class="srch-post-link">
                        <?= t('search.post_in', [], 'In:'); ?> <?php echo h($p['title']); ?>
                    </a>
                    <div class="srch-post-snippet">
                        "...<?php echo h(mb_strimwidth(strip_tags(parseBBCode($p['content'])), 0, 150, "...")); ?>"
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (empty($results['users']) && empty($results['threads']) && empty($results['posts'])): ?>
            <p class="admin-box"><?= t('search.no_results', [], 'No results found.'); ?></p>
        <?php endif; ?>

    <?php endif; ?>
</div>