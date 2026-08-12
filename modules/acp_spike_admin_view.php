<?php
// SPDX-License-Identifier: GPL-3.0-only

if (!defined('IN_CMS')) { exit; }

$spike_token        = generateToken();
$report_open_count  = count(array_filter($open_reports, fn($r) => $r['status'] === 'open'));
?>

<div class="sa-wrap">

  <div class="sa-page-title">
    <h2><i class="fas fa-comments"></i> <?= t('spike_admin.title', [], 'Spike Admin Panel') ?></h2>
  </div>

 <div class="sa-tabs">
  <button class="sa-tab active" onclick="saTab(event,'sa-overview')" title="<?= t('spike_admin.overview', [], 'Overview') ?>">
    <i class="fas fa-th-list"></i><span class="sa-tab-label"><?= t('spike_admin.overview', [], 'Overview') ?></span>
  </button>
  <button class="sa-tab" onclick="saTab(event,'sa-perms')" title="<?= t('spike_admin.tile_permissions', [], 'Permissions') ?>">
    <i class="fas fa-lock"></i><span class="sa-tab-label"><?= t('spike_admin.tile_permissions', [], 'Permissions') ?></span>
  </button>
  <button class="sa-tab" onclick="saTab(event,'sa-prefixes')" title="<?= t('spike_admin.prefixes', [], 'Prefixes') ?>">
    <i class="fas fa-tag"></i><span class="sa-tab-label"><?= t('spike_admin.prefixes', [], 'Prefixes') ?></span>
  </button>
  <button class="sa-tab" onclick="saTab(event,'sa-smilies')" title="<?= t('spike_admin.smilies', [], 'Smilies') ?>">
    <i class="fas fa-smile"></i><span class="sa-tab-label"><?= t('spike_admin.smilies', [], 'Smilies') ?></span>
  </button>
  <button class="sa-tab" onclick="saTab(event,'sa-merge')" title="<?= t('spike_admin.merge_move', [], 'Merge / Move') ?>">
    <i class="fas fa-code-merge"></i><span class="sa-tab-label"><?= t('spike_admin.merge_move', [], 'Merge / Move') ?></span>
  </button>
  <button class="sa-tab" onclick="saTab(event,'sa-search-stats')" title="<?= t('spike_admin.search_stats', [], 'Search Stats') ?>">
    <i class="fas fa-chart-bar"></i><span class="sa-tab-label"><?= t('spike_admin.search_stats', [], 'Search Stats') ?></span>
  </button>
  <button class="sa-tab" style="position:relative;" onclick="saTab(event,'sa-reports')" title="<?= t('spike_admin.reports', [], 'Reports') ?>">
    <i class="fas fa-flag"></i><span class="sa-tab-label"><?= t('spike_admin.reports', [], 'Reports') ?></span>
    <?php if ($report_open_count > 0): ?>
      <span class="sa-tab-badge sa-tab-badge--red"><?= $report_open_count ?></span>
    <?php endif; ?>
  </button>
  <button class="sa-tab" onclick="saTab(event,'sa-words')" title="<?= t('spike_admin.forbidden_words', [], 'Forbidden Words') ?>">
    <i class="fas fa-ban"></i><span class="sa-tab-label"><?= t('spike_admin.forbidden_words', [], 'Forbidden Words') ?></span>
  </button>
  <button class="sa-tab" onclick="saTab(event,'sa-tools')" title="<?= t('spike_admin.tools', [], 'Tools') ?>">
    <i class="fas fa-wrench"></i><span class="sa-tab-label"><?= t('spike_admin.tools', [], 'Tools') ?></span>
  </button>
  <button class="sa-tab" onclick="saTab(event,'sa-settings')" title="<?= t('spike_admin.settings', [], 'Settings') ?>">
    <i class="fas fa-cog"></i><span class="sa-tab-label"><?= t('spike_admin.settings', [], 'Settings') ?></span>
  </button>
</div>

  <div id="sa-overview" class="sa-panel active">
    
    <div id="category-sort-container">
      <?php foreach ($all_cats as $cat):
        $stmt_boards = $db->prepare("SELECT * FROM spike_boards WHERE cat_id=? ORDER BY pos ASC");
        $stmt_boards->execute([$cat['id']]); $boards=$stmt_boards->fetchAll();
      ?>
      <div class="cat-wrapper" draggable="true" data-id="<?= $cat['id'] ?>" style="margin-bottom:24px; background:rgba(0,0,0,0.1); border:1px solid var(--border-0);">
        
        <div class="sa-section-head" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0;">
          <div class="sa-section-title" style="display:flex; align-items:center; gap:10px;">
            <i class="fas fa-grip-vertical sa-drag" style="cursor:grab; opacity:0.6;"></i>
            <i class="fas fa-folder" style="color:rgba(200,160,64,0.3);"></i>
            <input class="sa-inline-input sa-inline-input--cat" value="<?= h($cat['title']) ?>" onblur="inlineUpdate('cat_title',<?= $cat['id'] ?>,this.value)" style="font-family:'Cinzel',serif; font-size:1em; font-weight:bold; width:auto; min-width:200px;">
            <span class="sa-section-count" style="font-size:0.7em; font-family:sans-serif; font-weight:normal; text-transform:none;">(<?= count($boards) ?> <?= t('spike_admin.label_boards', [], 'boards') ?>)</span>
          </div>
          <button class="sa-btn sa-btn-red sa-btn-xs" onclick="if(confirm('<?= t('spike_admin.arch_confirm_delete_cat', [], 'Delete category and ALL its boards?') ?>')) deleteCat(<?= $cat['id'] ?>)">
            <i class="fas fa-trash"></i> <span class="hide-mobile"><?= t('spike_admin.arch_btn_delete', [], 'Delete') ?></span>
          </button>
        </div>

        <table class="sa-table sa-table--boards" style="margin-bottom:0; border-top:none;">
          <thead>
            <tr>
              <th style="width:24px;"></th>
              <th><?= t('spike_admin.arch_tab_boards', [], 'Boards') ?></th>
              <th><?= t('spike_admin.arch_label_description', [], 'Description') ?></th>
              <th style="text-align:center;">Threads</th>
              <th style="text-align:center;">Posts</th>
              <th style="text-align:center;"><?= t('spike_admin.matrix_col_view', [], 'View') ?></th>
              <th style="text-align:center;"><?= t('spike_admin.matrix_col_post', [], 'Post') ?></th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody class="board-sort-container" data-catid="<?= $cat['id'] ?>">
            <?php if (empty($boards)): ?>
            <tr>
              <td colspan="8" style="text-align:center; padding:15px; color:var(--parch-muted); font-style:italic;">
                <?= t('spike_admin.no_boards', [], 'no boards') ?>
              </td>
            </tr>
            <?php else: ?>
              <?php foreach ($boards as $b):
                $tc=$db->prepare("SELECT COUNT(*) FROM spike_threads WHERE board_id=?"); $tc->execute([$b['id']]);
                $pc=$db->prepare("SELECT COUNT(*) FROM spike_posts p JOIN spike_threads t ON p.thread_id=t.id WHERE t.board_id=?"); $pc->execute([$b['id']]);
              ?>
              <tr class="board-item" draggable="true" data-id="<?= $b['id'] ?>">
                <td><i class="fas fa-grip-lines sa-drag"></i></td>
                <td class="sa-td-title">
                  <input class="sa-inline-input" value="<?= h($b['title']) ?>" onblur="inlineUpdate('board_title',<?= $b['id'] ?>,this.value)">
                  <input class="sa-inline-input" style="margin-top:5px;font-size:11px;color:var(--parch-muted);" value="<?= h($b['graphic_url']??'') ?>" placeholder="Graphic path / URL" onblur="inlineUpdate('board_graphic',<?= $b['id'] ?>,this.value)">
                </td>
                <td><input class="sa-inline-input" style="color:var(--parch-muted);width:100%;" value="<?= h($b['description']??'') ?>" onblur="inlineUpdate('board_desc',<?= $b['id'] ?>,this.value)"></td>
                <td class="sa-td-num"><?= (int)$tc->fetchColumn() ?></td>
                <td class="sa-td-num"><?= (int)$pc->fetchColumn() ?></td>
                <td class="sa-td-num"><span class="sa-priv-badge">Lv<?= (int)$b['min_priv'] ?></span></td>
                <td class="sa-td-num"><span class="sa-priv-badge">Lv<?= (int)$b['min_priv_post'] ?></span></td>
                <td class="sa-td-actions" style="white-space:nowrap;">
                  <button class="sa-smiley-toggle <?= !empty($b['require_approval'])?'active':'' ?>" onclick="toggleBoardApproval(<?= $b['id'] ?>,this)" title="Require Approval for New Threads" style="margin-right:8px;"><i class="fas fa-user-shield"></i></button>
                  <button class="sa-btn" onclick='saBoardGraphicPrompt(<?= (int)$b['id'] ?>, <?= json_encode((string)($b['graphic_url'] ?? '')) ?>)' title="Set board graphic" style="margin-right:8px;"><i class="fas fa-image"></i> Graphic</button>
                  <button class="sa-btn sa-btn-gold" onclick="saMoveBoardPrompt(<?= $b['id'] ?>,<?= $cat['id'] ?>)"><i class="fas fa-arrow-right"></i> Move</button>
                  <button class="sa-btn sa-btn-red" onclick="if(confirm('<?= t('spike_admin.arch_confirm_delete_board', [], 'Delete board? All posts will be lost!') ?>')) deleteBoard(<?= $b['id'] ?>)"><i class="fas fa-trash"></i></button>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>

      </div>
      <?php endforeach; ?>
    </div>

    <div class="sa-form-panel">
      <h4><i class="fas fa-plus"></i><?= t('spike_admin.arch_new_board_title', [], 'New Board') ?></h4>
      <div class="sa-form-grid sa-form-grid-4" style="align-items:end;">
        <div class="sa-field"><label class="sa-label"><?= t('spike_admin.arch_label_category', [], 'Category') ?></label><select id="new-board-cat" class="sa-select"><?php foreach($all_cats as $mc): ?><option value="<?= $mc['id'] ?>"><?= h($mc['title']) ?></option><?php endforeach; ?></select></div>
        <div class="sa-field"><label class="sa-label"><?= t('spike_admin.arch_label_title', [], 'Title') ?></label><input type="text" id="new-board-title" class="sa-input" placeholder="<?= t('spike_admin.arch_placeholder_board', [], 'Board name...') ?>"></div>
        <div class="sa-field"><label class="sa-label"><?= t('spike_admin.arch_label_description', [], 'Description') ?></label><input type="text" id="new-board-desc" class="sa-input" placeholder="<?= t('spike_admin.arch_placeholder_desc', [], 'Short description...') ?>"></div>
        <div class="sa-field"><label class="sa-label">Graphic</label><input type="text" id="new-board-graphic" class="sa-input" placeholder="assets/img/... or https://..."></div>
        <div><button onclick="createBoard()" class="sa-btn sa-btn-gold" style="height:36px;padding:0 18px;"><i class="fas fa-plus"></i> <?= t('spike_admin.arch_btn_create_board', [], 'CREATE BOARD') ?></button><span class="sa-status" id="new-board-status"></span></div>
      </div>
    </div>

    <div class="sa-form-panel">
      <h4><i class="fas fa-folder-plus"></i><?= t('spike_admin.arch_new_cat_title', [], 'New Category') ?></h4>
      <div class="sa-form-grid" style="grid-template-columns:1fr auto;align-items:end;">
        <div class="sa-field"><label class="sa-label"><?= t('spike_admin.arch_label_title', [], 'Title') ?></label><input type="text" id="new-cat-title" class="sa-input" placeholder="<?= t('spike_admin.arch_placeholder_cat', [], 'Category name...') ?>"></div>
        <div><button onclick="createCat()" class="sa-btn sa-btn-gold" style="height:36px;padding:0 18px;"><i class="fas fa-plus"></i> <?= t('spike_admin.arch_btn_create_cat', [], 'CREATE CATEGORY') ?></button><span class="sa-status" id="new-cat-status"></span></div>
      </div>
    </div>
  </div>

  <div id="sa-perms" class="sa-panel">
    <form id="matrix-ajax-form">
      <table class="sa-perm-table">
        <thead><tr><th style="width:55%;"><?= t('spike_admin.matrix_col_section', [], 'Section') ?></th><th style="width:200px;"><?= t('spike_admin.matrix_col_view', [], 'View') ?></th><th style="width:200px;"><?= t('spike_admin.matrix_col_post', [], 'Post') ?></th></tr></thead>
        <tbody>
          <?php foreach($all_cats as $mc): ?>
          <tr class="perm-cat-row"><td><i class="fas fa-folder" style="margin-right:8px;opacity:0.3;"></i><?= h($mc['title']) ?></td>
          <td><select name="cat_perms[<?= $mc['id'] ?>][v]" class="sa-perm-select"><?php for($i=0;$i<=5;$i++) echo "<option value='$i'".((int)$mc['min_priv']===$i?' selected':'').">Level $i</option>"; ?></select></td>
          <td><select name="cat_perms[<?= $mc['id'] ?>][p]" class="sa-perm-select"><?php for($i=0;$i<=5;$i++) echo "<option value='$i'".((int)$mc['min_priv_post']===$i?' selected':'').">Level $i</option>"; ?></select></td></tr>
          <?php $sb=$db->prepare("SELECT * FROM spike_boards WHERE cat_id=? ORDER BY pos ASC"); $sb->execute([$mc['id']]); while($mb=$sb->fetch()): ?>
          <tr class="perm-board-row"><td><i class="fas fa-comments" style="margin-right:8px;opacity:0.15;"></i><?= h($mb['title']) ?></td>
          <td><select name="board_perms[<?= $mb['id'] ?>][v]" class="sa-perm-select"><?php for($i=0;$i<=5;$i++) echo "<option value='$i'".((int)$mb['min_priv']===$i?' selected':'').">Level $i</option>"; ?></select></td>
          <td><select name="board_perms[<?= $mb['id'] ?>][p]" class="sa-perm-select"><?php for($i=0;$i<=5;$i++) echo "<option value='$i'".((int)$mb['min_priv_post']===$i?' selected':'').">Level $i</option>"; ?></select></td></tr>
          <?php endwhile; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div style="margin-top:18px;display:flex;align-items:center;gap:14px;">
        <button type="button" onclick="ajaxCall('matrix-ajax-form','update_matrix','matrix-status')" class="sa-btn sa-btn-gold" style="padding:8px 22px;"><i class="fas fa-save"></i> <?= t('spike_admin.matrix_btn_push', [], 'PUSH CHANGES') ?></button>
        <span class="sa-status" id="matrix-status"></span>
      </div>
    </form>
  </div>

<div id="sa-prefixes" class="sa-panel">
  <div class="sa-form-panel">
    <h4><i class="fas fa-plus"></i> <?= t('spike_admin.prefix_add_title', [], 'Add Prefix') ?></h4>
    <div class="sa-form-grid sa-form-grid-4" style="align-items:end;">
      <div class="sa-field">
        <label class="sa-label"><?= t('spike_admin.prefix_label', [], 'Label') ?></label>
        <input type="text" id="pf-label" class="sa-input" placeholder="<?= t('spike_admin.prefix_label_placeholder', [], 'e.g. Announcement') ?>" maxlength="40">
      </div>
      <div class="sa-field">
        <label class="sa-label"><?= t('spike_admin.prefix_text_color', [], 'Text Color') ?></label>
        <div class="sa-color-picker-wrap">
          <div class="sa-color-swatch" id="pf-color-swatch" style="background:#c5a059;" onclick="document.getElementById('pf-color-input').click()"></div>
          <input type="color" id="pf-color-input" class="sa-color-input" value="#c5a059"
                 oninput="document.getElementById('pf-color-swatch').style.background=this.value;updatePfPreview()">
          <span id="pf-color-hex" style="font-family:monospace;font-size:0.72em;color:var(--parch-muted);">#c5a059</span>
        </div>
      </div>
      <div class="sa-field">
        <label class="sa-label"><?= t('spike_admin.prefix_bg_color', [], 'Bg Color') ?></label>
        <div class="sa-color-picker-wrap">
          <div class="sa-color-swatch" id="pf-bg-swatch" style="background:#1a1200;" onclick="document.getElementById('pf-bg-input').click()"></div>
          <input type="color" id="pf-bg-input" class="sa-color-input" value="#1a1200"
                 oninput="document.getElementById('pf-bg-swatch').style.background=this.value;updatePfPreview()">
        </div>
      </div>
      <div style="display:flex;flex-direction:column;gap:6px;">
        <div style="display:flex;align-items:center;gap:8px;">
          <span class="sa-prefix-preview" id="pf-preview" style="color:#c5a059;background:#1a1200;"><?= t('spike_admin.prefix_preview', [], 'Preview') ?></span>
        </div>
        <button onclick="createPrefix()" class="sa-btn sa-btn-gold" style="height:36px;padding:0 16px;">
          <i class="fas fa-plus"></i> <?= t('spike_admin.add', [], 'Add') ?>
        </button>
        <span class="sa-status" id="pf-status"></span>
      </div>
    </div>
  </div>

  <?php if (!empty($all_prefixes)): ?>
  <table class="sa-table">
    <thead>
      <tr>
        <th></th>
        <th><?= t('spike_admin.prefix_col_label', [], 'Label') ?></th>
        <th><?= t('spike_admin.prefix_col_preview', [], 'Preview') ?></th>
        <th><?= t('spike_admin.prefix_col_color', [], 'Color') ?></th>
        <th><?= t('spike_admin.prefix_col_bg', [], 'Bg') ?></th>
        <th style="text-align:center;"><?= t('spike_admin.prefix_col_active', [], 'Active') ?></th>
        <th style="text-align:right;"><?= t('spike_admin.col_delete', [], 'Del') ?></th>
      </tr>
    </thead>
    <tbody id="prefix-sort-container">
    <?php foreach($all_prefixes as $pf): ?>
    <tr class="prefix-item" draggable="true" data-id="<?= $pf['id'] ?>">
      <td style="width:24px;"><i class="fas fa-grip-lines sa-drag"></i></td>
      <td><input class="sa-inline-input" value="<?= h($pf['label']) ?>" onblur="inlineUpdatePrefix(<?= $pf['id'] ?>,'label',this.value)"></td>
      <td><span class="sa-prefix-preview" style="color:<?= h($pf['color']) ?>;background:<?= h($pf['bg_color']) ?>;"><?= h($pf['label']) ?></span></td>
      <td><span style="font-family:monospace;font-size:0.75em;color:var(--parch-muted);"><?= h($pf['color']) ?></span></td>
      <td><span style="font-family:monospace;font-size:0.75em;color:var(--parch-muted);"><?= h($pf['bg_color']) ?></span></td>
      <td style="text-align:center;">
        <button class="sa-smiley-toggle <?= $pf['is_active']?'active':'' ?>"
                onclick="togglePrefix(<?= $pf['id'] ?>,this)">
          <?= $pf['is_active'] ? 'ON' : 'OFF' ?>
        </button>
      </td>
      <td style="text-align:right;">
        <button class="sa-btn sa-btn-red sa-btn-xs" onclick="if(confirm('<?= t('spike_admin.prefix_delete_confirm', [], 'Delete prefix?') ?>')) deletePrefix(<?= $pf['id'] ?>,this)">
          <i class="fas fa-trash"></i>
        </button>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <div class="sa-empty-state"><?= t('spike_admin.prefix_empty', [], 'No prefixes yet.') ?></div>
  <?php endif; ?>
</div>

<div id="sa-smilies" class="sa-panel">
  <div class="sa-form-panel">
    <h4><i class="fas fa-plus"></i> <?= t('spike_admin.smiley_add_title', [], 'Add Smiley') ?></h4>
    <div class="sa-form-grid sa-form-grid-4" style="align-items:end;">
      <div class="sa-field">
        <label class="sa-label"><?= t('spike_admin.smiley_code', [], 'Code (e.g. :D)') ?></label>
        <input type="text" id="sm-code" class="sa-input" placeholder=":D" maxlength="20">
      </div>
      <div class="sa-field">
        <label class="sa-label"><?= t('spike_admin.smiley_emoji', [], 'Emoji') ?></label>
        <input type="text" id="sm-emoji" class="sa-input" placeholder="😄" maxlength="10">
      </div>
      <div class="sa-field">
        <label class="sa-label"><?= t('spike_admin.smiley_title', [], 'Title') ?></label>
        <input type="text" id="sm-title" class="sa-input" placeholder="Grin">
      </div>
      <div>
        <button onclick="addSmiley()" class="sa-btn sa-btn-gold" style="height:36px;padding:0 16px;">
          <i class="fas fa-plus"></i> <?= t('spike_admin.add', [], 'Add') ?>
        </button>
        <span class="sa-status" id="sm-status"></span>
      </div>
      </div>
    </div>

    <?php if (!empty($smilies_list)): ?>
    <div class="sa-smiley-grid">
      <?php foreach($smilies_list as $sm): ?>
      <div class="sa-smiley-card" data-id="<?= $sm['id'] ?>">
        <span class="sa-smiley-emoji"><?= $sm['emoji'] ?: '?' ?></span>
        <span class="sa-smiley-code"><?= h($sm['code']) ?></span>
        <div class="sa-smiley-actions">
          <button class="sa-smiley-toggle <?= $sm['is_active']?'active':'' ?>"
                  onclick="toggleSmiley(<?= $sm['id'] ?>,this)">
            <?= $sm['is_active'] ? 'ON' : 'OFF' ?>
          </button>
          <button class="sa-btn sa-btn-red sa-btn-xs" onclick="if(confirm('Delete?')) deleteSmiley(<?= $sm['id'] ?>,this)">
            <i class="fas fa-trash"></i>
          </button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
  <div class="sa-empty-state"><?= t('spike_admin.smilies_empty', [], 'No smilies configured.') ?></div>
<?php endif; ?>
</div>

<div id="sa-merge" class="sa-panel">

  <div class="sa-form-panel">
    <h4><i class="fas fa-code-merge"></i> <?= t('spike_admin.merge_threads_title', [], 'Merge Threads') ?></h4>
    <p style="font-size:0.78em;color:var(--parch-muted);margin:0 0 14px;font-family:sans-serif;line-height:1.6;">
      <?= t('spike_admin.merge_threads_desc', [], 'Posts from the source thread will be moved into the target thread in chronological order. The source thread is then deleted.') ?>
    </p>
    <div class="sa-form-grid sa-form-grid-2" style="margin-bottom:14px;">
      <div class="sa-field">
        <label class="sa-label"><?= t('spike_admin.merge_source_label', [], 'Source Thread (will be deleted)') ?></label>
        <input type="text" id="merge-source-q" class="sa-input" placeholder="<?= t('spike_admin.merge_search_placeholder', [], 'Search thread title…') ?>" oninput="mergeSearch('source',this.value)">
        <div class="sa-merge-search-result" id="merge-source-results" style="display:none;"></div>
        <div class="sa-merge-selected" id="merge-source-selected" style="display:none;">
          <span><?= t('spike_admin.merge_source_short', [], 'Source') ?>: <strong id="merge-source-label">—</strong></span>
          <button onclick="clearMergeSelect('source')" class="sa-btn sa-btn-xs" style="border:none;color:var(--border-2);"><i class="fas fa-times"></i></button>
        </div>
        <input type="hidden" id="merge-source-id" value="0">
      </div>
      <div class="sa-field">
        <label class="sa-label"><?= t('spike_admin.merge_target_label', [], 'Target Thread (keeps title + posts)') ?></label>
        <input type="text" id="merge-target-q" class="sa-input" placeholder="<?= t('spike_admin.merge_search_placeholder', [], 'Search thread title…') ?>" oninput="mergeSearch('target',this.value)">
        <div class="sa-merge-search-result" id="merge-target-results" style="display:none;"></div>
        <div class="sa-merge-selected" id="merge-target-selected" style="display:none;">
          <span><?= t('spike_admin.merge_target_short', [], 'Target') ?>: <strong id="merge-target-label">—</strong></span>
          <button onclick="clearMergeSelect('target')" class="sa-btn sa-btn-xs" style="border:none;color:var(--border-2);"><i class="fas fa-times"></i></button>
        </div>
        <input type="hidden" id="merge-target-id" value="0">
      </div>
    </div>

    <div class="sa-merge-arrow" id="merge-direction-indicator" style="display:none;">
      <div class="sa-thread-pill" id="merge-arrow-source"><?= t('spike_admin.merge_source_short', [], 'Source') ?></div>
      <i class="fas fa-long-arrow-alt-right"></i>
      <div class="sa-thread-pill sa-thread-pill--target" id="merge-arrow-target"><?= t('spike_admin.merge_target_short', [], 'Target') ?></div>
    </div>

    <div style="margin-top:14px;display:flex;align-items:center;gap:14px;">
      <button onclick="executeMerge()" class="sa-btn sa-btn-red" style="padding:8px 20px;" id="merge-exec-btn" disabled>
        <i class="fas fa-code-merge"></i> <?= t('spike_admin.merge_execute', [], 'Execute Merge') ?>
      </button>
      <span class="sa-status" id="merge-status"></span>
    </div>
  </div>

  <div class="sa-form-panel">
    <h4><i class="fas fa-arrows-alt"></i> <?= t('spike_admin.move_post_title', [], 'Move Post to Another Thread') ?></h4>
    <div class="sa-form-grid sa-form-grid-2" style="margin-bottom:14px;">
      <div class="sa-field">
        <label class="sa-label"><?= t('spike_admin.move_post_id', [], 'Post ID') ?></label>
        <input type="number" id="move-post-id" class="sa-input" placeholder="<?= t('spike_admin.move_post_placeholder', [], 'e.g. 1234') ?>" min="1">
        <div id="move-post-preview" style="margin-top:6px;font-size:0.75em;color:var(--border-2);font-family:sans-serif;"></div>
      </div>
      <div class="sa-field">
        <label class="sa-label"><?= t('spike_admin.move_target_label', [], 'Target Thread') ?></label>
        <input type="text" id="move-target-q" class="sa-input" placeholder="<?= t('spike_admin.move_target_placeholder', [], 'Search target thread…') ?>" oninput="mergeSearch('move-target',this.value)">
        <div class="sa-merge-search-result" id="move-target-results" style="display:none;"></div>
        <div class="sa-merge-selected" id="move-target-selected" style="display:none;">
          <span><?= t('spike_admin.merge_target_short', [], 'Target') ?>: <strong id="move-target-label">—</strong></span>
          <button onclick="clearMergeSelect('move-target')" class="sa-btn sa-btn-xs" style="border:none;color:var(--border-2);"><i class="fas fa-times"></i></button>
        </div>
        <input type="hidden" id="move-target-id" value="0">
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:14px;">
      <button onclick="previewPost()" class="sa-btn sa-btn-blue" style="padding:7px 16px;">
        <i class="fas fa-eye"></i> <?= t('spike_admin.preview_post', [], 'Preview Post') ?>
      </button>
      <button onclick="executeMove()" class="sa-btn sa-btn-gold" style="padding:7px 16px;" id="move-exec-btn" disabled>
        <i class="fas fa-arrows-alt"></i> <?= t('spike_admin.move_post', [], 'Move Post') ?>
      </button>
        <span class="sa-status" id="move-status"></span>
      </div>
    </div>

  </div>

  <div id="sa-search-stats" class="sa-panel">
    <?php
    $search_total = 0;
    $search_today = 0;
    $top_queries  = [];
    $read_stats   = [];
    try {
        $search_total = (int)$db->query("SELECT COUNT(*) FROM spike_search_log")->fetchColumn();
        $search_today = (int)$db->query("SELECT COUNT(*) FROM spike_search_log WHERE DATE(created_at)=CURDATE()")->fetchColumn();
        $top_queries  = $db->query("SELECT query, COUNT(*) AS cnt FROM spike_search_log GROUP BY query ORDER BY cnt DESC LIMIT 15")->fetchAll();
    } catch(\Throwable $e) {}
    try {
        require_once(__DIR__ . '/spike_unread_helper.php');
        $read_stats = spike_get_read_stats_real($db, 15);
    } catch(\Throwable $e) {}
    ?>
    <div class="sa-search-stats-grid">
  <div class="sa-search-stat-card">
    <div class="sa-search-stat-num"><?= number_format($search_total) ?></div>
    <div class="sa-search-stat-lbl"><?= t('spike_admin.search_total', [], 'Total Searches') ?></div>
  </div>
  <div class="sa-search-stat-card">
    <div class="sa-search-stat-num"><?= number_format($search_today) ?></div>
    <div class="sa-search-stat-lbl"><?= t('spike_admin.search_today', [], 'Today') ?></div>
  </div>
  <div class="sa-search-stat-card">
    <div class="sa-search-stat-num"><?= count($top_queries) ?></div>
    <div class="sa-search-stat-lbl"><?= t('spike_admin.search_unique', [], 'Unique Queries') ?></div>
  </div>
</div>

<div class="sa-stats-cols-2">
  <div class="sa-form-panel">
    <h4><i class="fas fa-search"></i> <?= t('spike_admin.search_top_queries', [], 'Top Search Queries') ?></h4>
    <?php if (!empty($top_queries)): ?>
    <ul class="sa-top-queries">
      <?php foreach($top_queries as $tq): ?>
      <li>
        <span class="sa-query-text"><?= h($tq['query']) ?></span>
        <span class="sa-query-count"><?= (int)$tq['cnt'] ?>×</span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <div style="font-size:0.72em;color:var(--border-2);padding:14px 0;font-style:italic;">
      <?= t('spike_admin.search_none', [], 'No searches yet.') ?>
    </div>
    <?php endif; ?>
    <div style="margin-top:12px;">
      <button onclick="clearSearchLog()" class="sa-btn sa-btn-red sa-btn-xs">
        <i class="fas fa-trash"></i> <?= t('spike_admin.search_clear_log', [], 'Clear Log') ?>
      </button>
      <span class="sa-status" id="search-log-status"></span>
    </div>
  </div>

  <div class="sa-form-panel">
    <h4><i class="fas fa-eye"></i> <?= t('spike_admin.read_most_threads', [], 'Most-Read Threads') ?></h4>
    <?php if (!empty($read_stats)): ?>
    <?php $max_reads = (int)($read_stats[0]['reader_count'] ?? 1); ?>
    <div>
      <?php foreach($read_stats as $rs): ?>
      <div class="sa-read-heatrow">
        <a href="index.php?p=viewthread&id=<?= (int)$rs['id'] ?>" target="_blank"
           class="sa-read-thread-title sa-thread-link"
           title="<?= h($rs['title']) ?>"><?= h(mb_substr($rs['title'],0,45)) ?></a>
        <div class="sa-read-bar-wrap">
          <div class="sa-read-bar-fill" style="width:<?= round(($rs['reader_count']/$max_reads)*100) ?>%;"></div>
        </div>
        <span class="sa-read-count"><?= (int)$rs['reader_count'] ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div style="font-size:0.72em;color:var(--border-2);padding:14px 0;font-style:italic;">
      <?= t('spike_admin.read_none', [], 'No read data yet.') ?>
    </div>
    <?php endif; ?>
  </div>
</div>
</div>

<div id="sa-reports" class="sa-panel">
  <?php if (empty($open_reports)): ?>
  <div class="sa-empty-state"><?= t('spike_admin.reports_none', [], 'No reports found.') ?></div>
  <?php else: ?>
  <table class="sa-table sa-table--reports">
    <thead>
      <tr>
        <th><?= t('spike_admin.col_post_thread', [], 'Post / Thread') ?></th>
        <th><?= t('spike_admin.col_reporter', [], 'Reporter') ?></th>
        <th><?= t('spike_admin.col_author', [], 'Author') ?></th>
        <th><?= t('spike_admin.col_reason', [], 'Reason') ?></th>
        <th><?= t('spike_admin.col_date', [], 'Date') ?></th>
        <th><?= t('spike_admin.col_status', [], 'Status') ?></th>
        <th style="text-align:right;"><?= t('spike_admin.col_actions', [], 'Actions') ?></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($open_reports as $r): 
        $vt_url = !empty($r['thread_slug']) 
            ? "index.php?p=viewthread&slug=" . urlencode($r['thread_slug']) . "#post-" . (int)$r['post_id']
            : "index.php?p=viewthread&id=" . (int)$r['thread_id'] . "#post-" . (int)$r['post_id'];
    ?>
    <tr style="opacity:<?= in_array($r['status'],['resolved','dismissed'])?'0.4':'1' ?>;">
      <td>
        <a href="<?= $vt_url ?>" target="_blank" class="sa-thread-link"><?= h(mb_substr($r['thread_title'],0,40)) ?></a>
        <div class="sa-post-preview"><?= h(mb_substr(strip_tags($r['post_content']),0,60)) ?>…</div>
      </td>
      <td class="sa-td-user"><?= h($r['reporter_name']) ?></td>
      <td class="sa-td-user"><?= h($r['post_author']??'?') ?></td>
      <td>
        <span class="sa-reason-badge"><?= h($r['reason']) ?></span>
        <?php if($r['details']): ?><div class="sa-reason-detail"><?= h(mb_substr($r['details'],0,60)) ?></div><?php endif; ?>
      </td>
      <td class="sa-td-date"><?= date('d.m.y H:i',strtotime($r['created_at'])) ?></td>
      <td><span class="sa-status-badge sa-status-<?= $r['status'] ?>"><?= h($r['status']) ?></span></td>
      <td style="text-align:right;white-space:nowrap;">
        <?php if(!in_array($r['status'],['resolved','dismissed'])): ?>
        <a href="<?= $vt_url ?>" 
           target="_blank" 
           class="sa-btn sa-btn-blue sa-btn-xs" 
           style="text-decoration:none;" 
           onclick="markReviewing(<?= $r['id'] ?>, this)">
          <i class="fas fa-eye"></i> <?= t('spike_admin.action_review', [], 'Review') ?>
        </a>
        <button class="sa-btn sa-btn-gold sa-btn-xs" onclick="handleReport(<?= $r['id'] ?>,'resolved')">
          <i class="fas fa-check"></i> <?= t('spike_admin.action_resolve', [], 'Resolve') ?>
        </button>
        <button class="sa-btn sa-btn-red sa-btn-xs" onclick="handleReport(<?= $r['id'] ?>,'dismissed')">
          <i class="fas fa-times"></i> <?= t('spike_admin.action_dismiss', [], 'Dismiss') ?>
        </button>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div id="sa-words" class="sa-panel">
  <div class="sa-form-panel" style="margin-bottom:20px;">
    <h4><i class="fas fa-plus"></i> <?= t('spike_admin.fw_add_title', [], 'Add Forbidden Word') ?></h4>
    <div class="sa-form-grid sa-form-grid-4" style="align-items:end;">
      <div class="sa-field">
        <label class="sa-label"><?= t('spike_admin.fw_word_label', [], 'Word / Phrase') ?></label>
        <input type="text" id="fw-word" class="sa-input" placeholder="<?= t('spike_admin.fw_word_placeholder', [], 'e.g. badword') ?>">
      </div>
      <div class="sa-field">
        <label class="sa-label"><?= t('spike_admin.fw_scope', [], 'Scope') ?></label>
        <select id="fw-scope" class="sa-select">
          <option value="both"><?= t('spike_admin.fw_scope_both', [], 'Forum + Discord') ?></option>
          <option value="forum"><?= t('spike_admin.fw_scope_forum', [], 'Forum only') ?></option>
          <option value="discord"><?= t('spike_admin.fw_scope_discord', [], 'Discord only') ?></option>
        </select>
      </div>
      <div class="sa-field">
        <label class="sa-label"><?= t('spike_admin.fw_action', [], 'Action') ?></label>
        <select id="fw-action" class="sa-select" onchange="document.getElementById('fw-replace-wrap').style.display=this.value==='replace'?'block':'none'">
          <option value="block"><?= t('spike_admin.fw_action_block', [], 'Block post') ?></option>
          <option value="replace"><?= t('spike_admin.fw_action_replace', [], 'Replace with') ?></option>
          <option value="flag"><?= t('spike_admin.fw_action_flag', [], 'Flag for review') ?></option>
        </select>
      </div>
      <div>
        <div id="fw-replace-wrap" style="display:none;margin-bottom:6px;">
          <input type="text" id="fw-replacement" class="sa-input" placeholder="<?= t('spike_admin.fw_replace_placeholder', [], 'e.g. ***') ?>" value="***">
        </div>
        <button onclick="addForbiddenWord()" class="sa-btn sa-btn-gold" style="height:36px;padding:0 16px;">
          <i class="fas fa-plus"></i> <?= t('spike_admin.add', [], 'Add') ?>
        </button>
        <span class="sa-status" id="fw-status"></span>
      </div>
    </div>
  </div>

  <?php if (empty($forbidden_words_list)): ?>
    <div class="sa-empty-state"><?= t('spike_admin.fw_empty', [], 'No forbidden words configured.') ?></div>
  <?php else: ?>
    <table class="sa-table sa-table--words">
      <thead>
        <tr>
          <th><?= t('spike_admin.col_word', [], 'Word') ?></th>
          <th><?= t('spike_admin.col_scope', [], 'Scope') ?></th>
          <th><?= t('spike_admin.col_action', [], 'Action') ?></th>
          <th><?= t('spike_admin.col_replacement', [], 'Replacement') ?></th>
          <th><?= t('spike_admin.col_added_by', [], 'Added by') ?></th>
          <th><?= t('spike_admin.col_date', [], 'Date') ?></th>
          <th style="text-align:right;"><?= t('spike_admin.col_delete', [], 'Del') ?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($forbidden_words_list as $fw): ?>
      <tr>
        <td class="sa-td-word"><?= h($fw['word']) ?></td>
        <td><span class="sa-scope-badge"><?= h($fw['scope']) ?></span></td>
        <td><span class="sa-action-badge sa-action-<?= $fw['action'] ?>"><?= h($fw['action']) ?></span></td>
        <td class="sa-td-mono"><?= $fw['action']==='replace'?h($fw['replacement']):'—' ?></td>
        <td class="sa-td-user"><?= h($fw['added_by_name']??'—') ?></td>
        <td class="sa-td-date"><?= date('d.m.y',strtotime($fw['created_at'])) ?></td>
        <td class="acp-s-f6e3d7fe">
          <button class="sa-btn sa-btn-red sa-btn-xs" onclick="deleteForbiddenWord(<?= $fw['id'] ?>,this)">
            <i class="fas fa-trash"></i>
          </button>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div id="sa-tools" class="sa-panel">
  <div class="sa-tools-row">
    <div class="sa-tool-card">
      <div class="sa-tool-info">
        <div class="sa-tool-name">
          <i class="fas fa-sync"></i><?= t('spike_admin.tile_recalc', [], 'Reload Post Count') ?>
        </div>
        <div class="sa-tool-desc"><?= t('spike_admin.recalc_description', [], 'Synchronizes all user post counters with the actual number of posts stored in the database.') ?></div>
      </div>
      <div style="text-align:right;">
        <button onclick="ajaxCall(null,'recalc','masf-status')" class="sa-btn sa-btn-blue" style="padding:8px 16px;white-space:nowrap;">
          <i class="fas fa-play"></i> <?= t('spike_admin.recalc_btn', [], 'RECALCULATE NOW') ?>
        </button>
        <div><span class="sa-status" id="masf-status"></span></div>
      </div>
    </div>

    <div class="sa-tool-card sa-tool-card--danger">
      <div class="sa-tool-info">
        <div class="sa-tool-name sa-tool-name--danger">
          <i class="fas fa-skull-crossbones"></i><?= t('spike_admin.tile_purge', [], 'Post Purge') ?>
        </div>
        <div class="sa-tool-desc"><?= t('spike_admin.purge_description', [], 'Delete one or more posts by specific users. Enter usernames separated by commas. Optionally restrict to a specific board.') ?></div>
      </div>
      <div>
        <button onclick="document.getElementById('sa-purge-overlay').classList.add('active')" class="sa-btn sa-btn-red" style="padding:8px 16px;white-space:nowrap;">
          <i class="fas fa-skull"></i> <?= t('spike_admin.btn_open', [], 'OPEN') ?>
        </button>
      </div>
    </div>

    <div class="sa-tool-card">
      <div class="sa-tool-info">
        <div class="sa-tool-name">
          <i class="fas fa-broom"></i> <?= t('spike_admin.tool_clear_read_markers', [], 'Clear Read Markers') ?>
        </div>
        <div class="sa-tool-desc"><?= t('spike_admin.tool_clear_read_markers_desc', [], 'Deletes all unread markers older than 90 days to keep the table clean.') ?></div>
      </div>
      <div style="text-align:right;">
        <button onclick="ajaxCall(null,'cleanup_read_markers','cleanup-status')" class="sa-btn sa-btn-blue" style="padding:8px 16px;white-space:nowrap;">
          <i class="fas fa-play"></i> <?= t('spike_admin.btn_run', [], 'RUN') ?>
        </button>
        <div><span class="sa-status" id="cleanup-status"></span></div>
      </div>
    </div>
  </div>
</div>

<div id="sa-settings" class="sa-panel">
  <div class="sa-form-panel">
    <h4><i class="fas fa-sliders-h"></i> <?= t('spike_admin.settings_title', [], 'Spike Forum Settings') ?></h4>
    <div class="sa-form-grid sa-form-grid-2">
      <div class="sa-field">
        <label class="sa-label"><?= t('spike_admin.setting_spam_cooldown', [], 'Spam Cooldown (seconds)') ?></label>
        <input type="number" name="settings[spam_cooldown]" class="sa-input" value="<?= (int)($spike_settings['spam_cooldown']??30) ?>">
      </div>
      <div class="sa-field">
        <label class="sa-label"><?= t('spike_admin.setting_min_priv_links', [], 'Min. Priv for Links') ?></label>
        <input type="number" name="settings[spam_min_bs_links]" class="sa-input" value="<?= (int)($spike_settings['spam_min_bs_links']??1) ?>">
      </div>
      <div class="sa-field">
        <label class="sa-label"><?= t('spike_admin.setting_max_attachment', [], 'Max Attachment Size (bytes)') ?></label>
        <input type="number" name="settings[max_attachment_size]" class="sa-input" value="<?= (int)($spike_settings['max_attachment_size']??2097152) ?>">
      </div>
      <div class="sa-field">
        <label class="sa-label"><?= t('spike_admin.setting_attachment_path', [], 'Attachment Upload Path') ?></label>
        <input type="text" name="settings[attachment_path]" class="sa-input" value="<?= h($spike_settings['attachment_path']??'uploads/forum/') ?>">
      </div>
      <div class="sa-field">
        <label class="sa-label"><?= t('spike_admin.setting_allowed_mime', [], 'Allowed MIME Types') ?></label>
        <input type="text" name="settings[allowed_mime_types]" class="sa-input" value="<?= h($spike_settings['allowed_mime_types']??'image/jpeg,image/png,image/gif') ?>">
      </div>
      <div class="sa-field">
        <label class="sa-label"><?= t('spike_admin.setting_search_min', [], 'Search Min. Length') ?></label>
        <input type="number" name="settings[search_min_length]" class="sa-input" value="<?= (int)($spike_settings['search_min_length']??3) ?>">
      </div>
      <div class="sa-field">
        <label class="sa-label"><?= t('spike_admin.setting_search_max', [], 'Search Max Results') ?></label>
        <input type="number" name="settings[search_max_results]" class="sa-input" value="<?= (int)($spike_settings['search_max_results']??30) ?>">
      </div>
      </div>
      <div class="sa-form-grid sa-form-grid-4" style="margin-top:14px;">
        <?php $toggles = [
          ['reactions_enabled','Reactions'],['polls_enabled','Polls'],['attachments_enabled','Attachments'],
          ['smilies_enabled','Smilies'],['edit_history_enabled','Edit History'],['subscription_notify','Subscription Notifs'],
          ['stats_strip_enabled','Stats Strip'],['latest_posts_enabled','Latest Posts Sidebar'],
          ['search_enabled','Forum Search'],['unread_enabled','Unread Indicator'],
          ['ignore_system_enabled','Ignore System'],['tagging_enabled','Thread Tags'],
        ]; ?>
        <?php foreach($toggles as [$key,$label]): ?>
        <div class="sa-field">
          <label class="sa-label"><?= $label ?></label>
         <select name="settings[<?= $key ?>]" class="sa-select">
  <option value="1"<?= ($spike_settings[$key]??'1')==='1'?' selected':'' ?>>
    <?= t('global.enabled', [], 'Enabled') ?>
  </option>
  <option value="0"<?= ($spike_settings[$key]??'1')==='0'?' selected':'' ?>>
    <?= t('global.disabled', [], 'Disabled') ?>
  </option>
</select>
</div>
<?php endforeach; ?>
</div>
<div style="margin-top:18px;display:flex;align-items:center;gap:14px;">
  <button type="button" onclick="saveSettings()" class="sa-btn sa-btn-gold" style="padding:8px 22px;">
    <i class="fas fa-save"></i> <?= t('spike_admin.save_settings', [], 'Save Settings') ?>
  </button>
  <span class="sa-status" id="settings-status"></span>
</div>
</div>
</div>

</div><div id="sa-purge-overlay" class="sa-overlay">
  <div class="sa-overlay-box">
    <div class="sa-overlay-head"><span><i class="fas fa-skull-crossbones"></i> <?= t('spike_admin.purge_title', [], 'Post Purge') ?></span><button onclick="document.getElementById('sa-purge-overlay').classList.remove('active');" class="sa-btn sa-btn-red" style="border:none;padding:4px 8px;"><i class="fas fa-times"></i></button></div>
    <div class="sa-overlay-body">
      <div class="sa-form-grid sa-form-grid-2" style="margin-bottom:14px;">
        <div class="sa-field" style="grid-column:span 2;"><label class="sa-label"><?= t('spike_admin.purge_label_users', [], 'Target User(s) — comma separated') ?></label><input type="text" id="purge-users" class="sa-input" placeholder="<?= t('spike_admin.purge_placeholder_users', [], 'e.g. John, Jane, ToxicUser99') ?>"></div>
        <div class="sa-field"><label class="sa-label"><?= t('spike_admin.purge_label_board', [], 'Restrict to Board (optional)') ?></label>
          <select id="purge-board" class="sa-select"><option value=""><?= t('spike_admin.purge_all_boards', [], '— All Boards —') ?></option>
          <?php foreach($all_cats as $pc): echo "<optgroup label='".h($pc['title'])."'>"; $spb=$db->prepare("SELECT id,title FROM spike_boards WHERE cat_id=? ORDER BY pos ASC"); $spb->execute([$pc['id']]); while($pb=$spb->fetch()) echo "<option value='{$pb['id']}'>".h($pb['title'])."</option>"; echo "</optgroup>"; endforeach; ?>
          </select>
        </div>
        <div class="sa-field"><label class="sa-label"><?= t('spike_admin.purge_label_since', [], 'Delete posts newer than (optional)') ?></label><input type="date" id="purge-since" class="sa-input"></div>
      </div>
      <div class="sa-purge-preview" id="purge-preview"><?= t('spike_admin.purge_preview_placeholder', [], '— preview will appear here —') ?></div>
      <div style="display:flex;align-items:center;justify-content:space-between;margin-top:6px;">
        <button onclick="purgePreview()" class="sa-btn sa-btn-blue" style="padding:7px 16px;"><i class="fas fa-search"></i> <?= t('spike_admin.purge_btn_preview', [], 'PREVIEW') ?></button>
        <div style="display:flex;align-items:center;gap:10px;"><span class="sa-status" id="purge-status"></span><button onclick="purgeExecute()" class="sa-btn sa-btn-red" id="purge-exec-btn" disabled style="opacity:0.3;padding:7px 16px;"><i class="fas fa-skull"></i> <?= t('spike_admin.purge_btn_execute', [], 'EXECUTE PURGE') ?></button></div>
      </div>
    </div>
  </div>
</div>

<div id="sa-move-overlay" class="sa-overlay">
  <div class="sa-overlay-box" style="max-width:400px;">
    <div class="sa-overlay-head"><span><?= t('spike_admin.arch_label_move_to_cat', [], 'Move to Category') ?></span><button onclick="document.getElementById('sa-move-overlay').classList.remove('active');" class="sa-btn" style="border:none;color:var(--border-2);padding:4px 8px;"><i class="fas fa-times"></i></button></div>
    <div class="sa-overlay-body">
      <div class="sa-field"><label class="sa-label"><?= t('spike_admin.arch_label_move_to_cat', [], 'Move to Category') ?></label><select id="move-board-cat" class="sa-select"><?php foreach($all_cats as $mc): ?><option value="<?= $mc['id'] ?>"><?= h($mc['title']) ?></option><?php endforeach; ?></select></div>
      <input type="hidden" id="move-board-id" value="">
      <div style="margin-top:16px;text-align:right;"><button onclick="moveBoardConfirm()" class="sa-btn sa-btn-gold" style="padding:7px 18px;"><i class="fas fa-arrow-right"></i> Move</button></div>
    </div>
  </div>
</div>

<script>
const spikeToken = '<?= $spike_token ?>';

function saTab(e, id) {
    document.querySelectorAll('.sa-tab').forEach(t=>t.classList.remove('active'));
    document.querySelectorAll('.sa-panel').forEach(p=>p.classList.remove('active'));
    e.target.closest('.sa-tab').classList.add('active');
    document.getElementById(id).classList.add('active');
}

function ajaxCall(formId,action,statusId){
    const status=statusId?document.getElementById(statusId):null;
    const fd=formId?new FormData(document.getElementById(formId)):new FormData();
    fd.append('ajax_action',action);fd.append('csrf_token',spikeToken);
    if(status){status.innerHTML='SENDING…';status.style.color='var(--blue)';}
    fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).then(r=>r.text()).then(data=>{
        const ok=data.trim().toLowerCase().includes('success')||data.includes('"ok":true');
        if(status){status.innerHTML=ok?'✓ DONE':'ERROR';status.style.color=ok?'var(--gold)':'var(--red)';if(ok)setTimeout(()=>status.innerHTML='',3000);}
        if(action==='recalc'&&ok)setTimeout(()=>location.reload(),1000);
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

function toggleBoardApproval(id,btn){
    const fd=new FormData();fd.append('ajax_action','toggle_board_approval');fd.append('csrf_token',spikeToken);fd.append('board_id',id);
    fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.ok){btn.classList.toggle('active',d.active);}}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

function inlineUpdate(field,id,value){const fd=new FormData();fd.append('ajax_action','inline_update');fd.append('csrf_token',spikeToken);fd.append('field',field);fd.append('record_id',id);fd.append('value',value);fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});}
function createCat(){const title=document.getElementById('new-cat-title').value.trim();const status=document.getElementById('new-cat-status');if(!title){status.innerHTML='Required';status.style.color='var(--red)';return;}const fd=new FormData();fd.append('ajax_action','create_cat');fd.append('csrf_token',spikeToken);fd.append('cat_title',title);fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).then(r=>r.text()).then(d=>{status.innerHTML=d.includes('SUCCESS')?'✓ Created':'Error';status.style.color=d.includes('SUCCESS')?'var(--gold)':'var(--red)';if(d.includes('SUCCESS'))setTimeout(()=>location.reload(),700);}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});}
function createBoard(){const catId=document.getElementById('new-board-cat').value;const title=document.getElementById('new-board-title').value.trim();const desc=document.getElementById('new-board-desc').value.trim();const graphic=document.getElementById('new-board-graphic').value.trim();const status=document.getElementById('new-board-status');if(!title){status.innerHTML='Required';status.style.color='var(--red)';return;}const fd=new FormData();fd.append('ajax_action','create_board');fd.append('csrf_token',spikeToken);fd.append('target_cat_id',catId);fd.append('board_title',title);fd.append('board_desc',desc);fd.append('board_graphic',graphic);fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).then(r=>r.text()).then(d=>{status.innerHTML=d.includes('SUCCESS')?'✓ Created':'Error';status.style.color=d.includes('SUCCESS')?'var(--gold)':'var(--red)';if(d.includes('SUCCESS'))setTimeout(()=>location.reload(),700);}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});}
function deleteCat(id){const fd=new FormData();fd.append('ajax_action','delete_cat');fd.append('csrf_token',spikeToken);fd.append('cat_id',id);fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).then(()=>location.reload()).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});}
function deleteBoard(id){const fd=new FormData();fd.append('ajax_action','delete_board');fd.append('csrf_token',spikeToken);fd.append('board_id',id);fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).then(()=>location.reload()).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});}
function saMoveBoardPrompt(boardId,currentCatId){document.getElementById('move-board-id').value=boardId;document.getElementById('move-board-cat').value=currentCatId;document.getElementById('sa-move-overlay').classList.add('active');}
function moveBoardConfirm(){const boardId=document.getElementById('move-board-id').value;const newCatId=document.getElementById('move-board-cat').value;const fd=new FormData();fd.append('ajax_action','move_board');fd.append('csrf_token',spikeToken);fd.append('board_id',boardId);fd.append('new_cat_id',newCatId);fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).then(()=>location.reload()).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});}

function handleReport(id,status){
    const fd=new FormData();
    fd.append('ajax_action','handle_report');
    fd.append('csrf_token',spikeToken);
    fd.append('report_id',id);
    fd.append('new_status',status);
    fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.ok)location.reload();}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

function markReviewing(id, btn){
    const fd=new FormData();
    fd.append('ajax_action','handle_report');
    fd.append('csrf_token',spikeToken);
    fd.append('report_id',id);
    fd.append('new_status','reviewing');
    fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(d.ok){
            const tr = btn.closest('tr');
            if(tr) {
                const statusBadge = tr.querySelector('.sa-status-badge');
                if(statusBadge) {
                    statusBadge.className = 'sa-status-badge sa-status-reviewing';
                    statusBadge.textContent = 'reviewing';
                }
                btn.remove();
            }
        }
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

function addForbiddenWord(){const word=document.getElementById('fw-word').value.trim();const scope=document.getElementById('fw-scope').value;const action_type=document.getElementById('fw-action').value;const replacement=document.getElementById('fw-replacement')?.value||'***';const status=document.getElementById('fw-status');if(!word){status.innerHTML='Enter a word';status.style.color='var(--red)';return;}const fd=new FormData();fd.append('ajax_action','add_forbidden_word');fd.append('csrf_token',spikeToken);fd.append('word',word);fd.append('scope',scope);fd.append('action_type',action_type);fd.append('replacement',replacement);fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.ok){status.innerHTML='✓ Added';status.style.color='var(--gold)';setTimeout(()=>location.reload(),700);}else{status.innerHTML='Error: '+d.error;status.style.color='var(--red)';}}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});}
function deleteForbiddenWord(id,btn){if(!confirm('Delete this word?'))return;const fd=new FormData();fd.append('ajax_action','delete_forbidden_word');fd.append('csrf_token',spikeToken);fd.append('word_id',id);fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.ok)btn.closest('tr').remove();}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});}
function saveSettings(){const fd=new FormData();fd.append('ajax_action','save_settings');fd.append('csrf_token',spikeToken);document.querySelectorAll('#sa-settings [name^="settings["]').forEach(el=>fd.append(el.name,el.value));const status=document.getElementById('settings-status');status.innerHTML='Saving…';status.style.color='var(--blue)';fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.ok){status.innerHTML='✓ Saved';status.style.color='var(--gold)';setTimeout(()=>status.innerHTML='',3000);}else{status.innerHTML='Error';status.style.color='var(--red)';}}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});}
function purgePreview(){const users=document.getElementById('purge-users').value.trim();const board=document.getElementById('purge-board').value;const since=document.getElementById('purge-since').value;const prev=document.getElementById('purge-preview');const status=document.getElementById('purge-status');if(!users){status.innerHTML='Enter usernames';status.style.color='var(--red)';return;}const fd=new FormData();fd.append('ajax_action','purge_preview');fd.append('csrf_token',spikeToken);fd.append('purge_users',users);fd.append('purge_board',board);fd.append('purge_since',since);prev.innerHTML='<span style="color:var(--border-2);">Loading...</span>';fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).then(r=>r.text()).then(data=>{prev.innerHTML=data;const btn=document.getElementById('purge-exec-btn');const empty=data.includes('0 posts');btn.disabled=empty;btn.style.opacity=empty?'0.3':'1';status.innerHTML='';}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});}
function purgeExecute(){if(!confirm('EXECUTE PURGE?'))return;const users=document.getElementById('purge-users').value.trim();const board=document.getElementById('purge-board').value;const since=document.getElementById('purge-since').value;const status=document.getElementById('purge-status');const fd=new FormData();fd.append('ajax_action','purge_execute');fd.append('csrf_token',spikeToken);fd.append('purge_users',users);fd.append('purge_board',board);fd.append('purge_since',since);fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).then(r=>r.text()).then(data=>{status.innerHTML=data;status.style.color='var(--gold)';document.getElementById('purge-preview').innerHTML='— purge completed —';document.getElementById('purge-exec-btn').disabled=true;document.getElementById('purge-exec-btn').style.opacity='0.3';}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});}

function updatePfPreview() {
    const label = document.getElementById('pf-label')?.value||'Preview';
    const color = document.getElementById('pf-color-input')?.value||'#c5a059';
    const bg    = document.getElementById('pf-bg-input')?.value||'transparent';
    const hex   = document.getElementById('pf-color-hex');
    const prev  = document.getElementById('pf-preview');
    if (hex) hex.textContent = color;
    if (prev) { prev.style.color=color; prev.style.background=bg; prev.textContent=label||'Preview'; }
}
document.getElementById('pf-label')?.addEventListener('input', updatePfPreview);

function createPrefix(){
    const label=document.getElementById('pf-label').value.trim();
    const color=document.getElementById('pf-color-input').value;
    const bg   =document.getElementById('pf-bg-input').value;
    const status=document.getElementById('pf-status');
    if(!label){status.innerHTML='Label required';status.style.color='var(--red)';return;}
    const fd=new FormData();fd.append('ajax_action','create_prefix');fd.append('csrf_token',spikeToken);
    fd.append('label',label);fd.append('color',color);fd.append('bg_color',bg);
    fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(d.ok){status.innerHTML='✓ Created';status.style.color='var(--gold)';setTimeout(()=>location.reload(),700);}
        else{status.innerHTML='Error: '+(d.error||'?');status.style.color='var(--red)';}
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}
function deletePrefix(id,btn){
    const fd=new FormData();fd.append('ajax_action','delete_prefix');fd.append('csrf_token',spikeToken);fd.append('prefix_id',id);
    fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.ok)btn.closest('tr').remove();}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}
function togglePrefix(id,btn){
    const fd=new FormData();fd.append('ajax_action','toggle_prefix');fd.append('csrf_token',spikeToken);fd.append('prefix_id',id);
    fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(d.ok){btn.classList.toggle('active',d.active);btn.textContent=d.active?'ON':'OFF';}
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}
function inlineUpdatePrefix(id,field,value){
    const fd=new FormData();fd.append('ajax_action','update_prefix');fd.append('csrf_token',spikeToken);
    fd.append('prefix_id',id);fd.append('field',field);fd.append('value',value);
    fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

function addSmiley(){
    const code=document.getElementById('sm-code').value.trim();
    const emoji=document.getElementById('sm-emoji').value.trim();
    const title=document.getElementById('sm-title').value.trim();
    const status=document.getElementById('sm-status');
    if(!code){status.innerHTML='Code required';status.style.color='var(--red)';return;}
    const fd=new FormData();fd.append('ajax_action','create_smiley');fd.append('csrf_token',spikeToken);
    fd.append('code',code);fd.append('emoji',emoji);fd.append('title',title);
    fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(d.ok){status.innerHTML='✓ Added';status.style.color='var(--gold)';setTimeout(()=>location.reload(),700);}
        else{status.innerHTML='Error: '+(d.error||'?');status.style.color='var(--red)';}
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}
function deleteSmiley(id,btn){
    const fd=new FormData();fd.append('ajax_action','delete_smiley');fd.append('csrf_token',spikeToken);fd.append('smiley_id',id);
    fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.ok)btn.closest('.sa-smiley-card').remove();}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}
function toggleSmiley(id,btn){
    const fd=new FormData();fd.append('ajax_action','toggle_smiley');fd.append('csrf_token',spikeToken);fd.append('smiley_id',id);
    fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(d.ok){btn.classList.toggle('active',d.active);btn.textContent=d.active?'ON':'OFF';}
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

let mergeSearchTimer={};
function mergeSearch(target,q){
    clearTimeout(mergeSearchTimer[target]);
    if(q.length<2){document.getElementById((target==='move-target'?'move':'merge')+'-'+target.replace('move-target','target')+'-results').style.display='none';return;}
    mergeSearchTimer[target]=setTimeout(()=>{
        const fd=new FormData();fd.append('ajax_action','search_threads');fd.append('csrf_token',spikeToken);fd.append('q',q);
        fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
            if(!data.ok)return;
            const elId=target==='move-target'?'move-target-results':`merge-${target}-results`;
            const el=document.getElementById(elId);
            if(!el)return;
            el.style.display=data.threads.length?'block':'none';
            el.innerHTML=data.threads.map(t=>`<div class="sa-merge-thread-item" onclick="selectMergeThread('${target}',${t.id},'${escJs(t.title)}')"><span class="sa-merge-thread-title">${escJs(t.title)}</span><span class="sa-merge-thread-meta">#${t.id}</span></div>`).join('');
        }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
    },300);
}
function selectMergeThread(target,id,title){
    const prefix=target==='move-target'?'move-target':'merge-'+target;
    document.getElementById(prefix+'-id').value=id;
    document.getElementById(prefix+'-label').textContent=title.substring(0,50)+(title.length>50?'…':'');
    document.getElementById(prefix+'-selected').style.display='flex';
    document.getElementById(prefix+'-results').style.display='none';
    if(target==='source'||target==='target') updateMergeArrow();
    if(target==='source'||target==='target') checkMergeReady();
}
function clearMergeSelect(target){
    const prefix=target==='move-target'?'move-target':'merge-'+target;
    document.getElementById(prefix+'-id').value='0';
    document.getElementById(prefix+'-selected').style.display='none';
    if(target==='source'||target==='target') updateMergeArrow();
    checkMergeReady();
}
function updateMergeArrow(){
    const srcId=document.getElementById('merge-source-id').value;
    const tgtId=document.getElementById('merge-target-id').value;
    const arrow=document.getElementById('merge-direction-indicator');
    if(!arrow)return;
    arrow.style.display=(srcId>'0'||tgtId>'0')?'flex':'none';
    const srcLabel=document.getElementById('merge-source-label')?.textContent||'Source';
    const tgtLabel=document.getElementById('merge-target-label')?.textContent||'Target';
    document.getElementById('merge-arrow-source').textContent=srcLabel;
    document.getElementById('merge-arrow-target').textContent=tgtLabel;
}
function checkMergeReady(){
    const srcId=parseInt(document.getElementById('merge-source-id')?.value||0);
    const tgtId=parseInt(document.getElementById('merge-target-id')?.value||0);
    const btn=document.getElementById('merge-exec-btn');
    if(btn)btn.disabled=!(srcId>0&&tgtId>0&&srcId!==tgtId);
}
function executeMerge(){
    const srcId=document.getElementById('merge-source-id').value;
    const tgtId=document.getElementById('merge-target-id').value;
    const srcTitle=document.getElementById('merge-source-label').textContent;
    if(!confirm(`Merge "${srcTitle}" into target thread? This cannot be undone.`))return;
    const status=document.getElementById('merge-status');
    status.innerHTML='Merging…';status.style.color='var(--blue)';
    const fd=new FormData();fd.append('ajax_action','merge_threads');fd.append('csrf_token',spikeToken);
    fd.append('source_id',srcId);fd.append('target_id',tgtId);
    fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(d.ok){status.innerHTML='✓ Merged';status.style.color='var(--gold)';setTimeout(()=>location.reload(),1200);}
        else{status.innerHTML='Error: '+(d.error||'?');status.style.color='var(--red)';}
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}
function previewPost(){
    const pid=parseInt(document.getElementById('move-post-id').value||0);
    const prev=document.getElementById('move-post-preview');
    if(!pid){prev.innerHTML='';return;}
    const fd=new FormData();fd.append('ajax_action','preview_post');fd.append('csrf_token',spikeToken);fd.append('post_id',pid);
    fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(d.ok){
            prev.innerHTML=`<strong style="color:var(--parch-muted);">${escJs(d.author)}</strong> in <em style="color:var(--border-2);">${escJs(d.thread_title)}</em> — ${escJs(d.date)}`;
            document.getElementById('move-exec-btn').disabled=parseInt(document.getElementById('move-target-id').value||0)<=0;
        } else {prev.innerHTML='Post not found';document.getElementById('move-exec-btn').disabled=true;}
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}
document.getElementById('move-post-id')?.addEventListener('input',previewPost);
document.getElementById('move-target-id')?.addEventListener('change',()=>{
    const pid=parseInt(document.getElementById('move-post-id').value||0);
    const tid=parseInt(document.getElementById('move-target-id').value||0);
    document.getElementById('move-exec-btn').disabled=!(pid>0&&tid>0);
});
function executeMove(){
    const pid=document.getElementById('move-post-id').value;
    const tid=document.getElementById('move-target-id').value;
    if(!confirm('Move this post?'))return;
    const status=document.getElementById('move-status');
    status.innerHTML='Moving…';status.style.color='var(--blue)';
    const fd=new FormData();fd.append('ajax_action','move_post');fd.append('csrf_token',spikeToken);
    fd.append('post_id',pid);fd.append('target_thread_id',tid);
    fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(d.ok){status.innerHTML='✓ Moved';status.style.color='var(--gold)';}
        else{status.innerHTML='Error: '+(d.error||'?');status.style.color='var(--red)';}
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

function clearSearchLog(){
    if(!confirm('Clear search log?'))return;
    const fd=new FormData();fd.append('ajax_action','clear_search_log');fd.append('csrf_token',spikeToken);
    fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        const s=document.getElementById('search-log-status');
        if(s){s.innerHTML=d.ok?'✓ Cleared':'Error';s.style.color=d.ok?'var(--gold)':'var(--red)';}
    }).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});
}

function escJs(s){return String(s||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'&quot;').replace(/</g,'&lt;');}

const catContainer=document.getElementById('category-sort-container');
let draggedCat=null;
if(catContainer){
catContainer.addEventListener('dragstart',e=>{if(e.target.classList.contains('cat-wrapper')){draggedCat=e.target;e.target.classList.add('dragging');}});
catContainer.addEventListener('dragend',e=>{if(!draggedCat)return;draggedCat.classList.remove('dragging');const order=[...catContainer.querySelectorAll(':scope > .cat-wrapper')].map(i=>i.dataset.id);const fd=new FormData();fd.append('ajax_action','sort_cats');fd.append('csrf_token',spikeToken);order.forEach((id,i)=>fd.append('order['+i+']',id));fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});draggedCat=null;});
catContainer.addEventListener('dragover',e=>{e.preventDefault();const after=getDragAfter(catContainer,e.clientY,'.cat-wrapper');if(draggedCat){if(!after)catContainer.appendChild(draggedCat);else catContainer.insertBefore(draggedCat,after);}});
}

let draggedBoard=null,draggedBoardOriginCat=null;
function initBoardSort(){document.querySelectorAll('.board-sort-container').forEach(container=>{container.addEventListener('dragstart',e=>{if(e.target.classList.contains('board-item')){draggedBoard=e.target;draggedBoardOriginCat=container.dataset.catid;e.target.classList.add('dragging');e.stopPropagation();}});container.addEventListener('dragend',e=>{if(!draggedBoard)return;draggedBoard.classList.remove('dragging');const newCatId=container.dataset.catid;if(newCatId!==draggedBoardOriginCat){const fd=new FormData();fd.append('ajax_action','move_board');fd.append('csrf_token',spikeToken);fd.append('board_id',draggedBoard.dataset.id);fd.append('new_cat_id',newCatId);fetch('acp.php?s=spike_admin',{method:'POST',body:fd}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});}const order=[...container.querySelectorAll('.board-item')].map(i=>i.dataset.id);const fd2=new FormData();fd2.append('ajax_action','sort_boards');fd2.append('csrf_token',spikeToken);fd2.append('target_cat_id',newCatId);order.forEach((id,i)=>fd2.append('order['+i+']',id));fetch('acp.php?s=spike_admin',{method:'POST',body:fd2}).catch(function(e){console.error('fetch failed:',e);alert('Action failed: '+(e&&e.message?e.message:e));});draggedBoard=null;draggedBoardOriginCat=null;e.stopPropagation();});container.addEventListener('dragover',e=>{e.preventDefault();const after=getDragAfter(container,e.clientY,'.board-item');if(draggedBoard){if(!after)container.appendChild(draggedBoard);else container.insertBefore(draggedBoard,after);}e.stopPropagation();});});}
function getDragAfter(container,y,sel){return[...container.querySelectorAll(sel+':not(.dragging)')].reduce((closest,child)=>{const offset=y-child.getBoundingClientRect().top-child.getBoundingClientRect().height/2;if(offset<0&&offset>closest.offset)return{offset,element:child};return closest;},{offset:Number.NEGATIVE_INFINITY}).element;}
initBoardSort();

function saBoardGraphicPrompt(boardId, currentGraphic) {
    const value = window.prompt('Board graphic path or URL. Leave empty to remove the custom graphic.', currentGraphic || '');
    if (value === null) return;

    const fd = new FormData();
    fd.append('ajax_action', 'save_board_graphic');
    fd.append('board_id', String(boardId));
    fd.append('graphic', value.trim());
    fd.append('csrf_token', '<?= $spike_token ?>');

    fetch('acp.php?s=spike_admin', {method: 'POST', body: fd})
        .then(r => r.json())
        .then(data => {
            if (!data.ok) throw new Error(data.error || 'Could not save board graphic.');
            window.location.reload();
        })
        .catch(err => alert(err.message || String(err)));
}

</script>
<?php
$ai_ext = __DIR__ . '/spike_admin_ai_extension.php';
if (file_exists($ai_ext)) require_once $ai_ext;
?>