<?php
if (!defined('IN_ACP')) exit;
?>
<div class="char-warning">
    <i class="fas fa-info-circle"></i>
    <span><strong>Core Server Info:</strong> Changes made here while a character is online will be overwritten by the server upon logout.</span>
</div>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'saved'): ?>
<div class="acp-s-d912cd33">
    <i class="fas fa-check"></i> Character sheet updated successfully.
</div>
<?php endif; ?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'qa_done'): ?>
<div class="acp-s-c6b4f7cb">
    <i class="fas fa-bolt"></i> <?= h($_GET['qa_msg'] ?? 'Quick action executed.') ?>
</div>
<?php endif; ?>

<?php if (isset($error_msg)): ?>
<div class="acp-s-166acd5e">
    <?= h($error_msg) ?>
</div>
<?php endif; ?>

<?php if (!$editChar): ?>
    <!-- ── LIST VIEW ─────────────────────────────────────── -->
    <div class="acp-s-dd29d519">
        <form method="GET" action="acp.php" class="acp-s-859fe1eb">
            <input type="hidden" name="s" value="char_editor">
            <input type="text" name="q" value="<?= h($_GET['q'] ?? '') ?>" placeholder="Search character or account name..." class="acp-s-e40a1ee5" >
            <button type="submit" class="acp-s-607f6f43"><i class="fas fa-search"></i></button>
        </form>
        <div class="acp-s-e5832a75">Showing top 50 matches</div>
    </div>

    <div class="char-grid">
        <?php foreach ($charList as $char): 
            $rData = getRealmData($char['Realm']);
        ?>
        <a href="acp.php?s=char_editor&edit=<?= urlencode($char['DOLCharacters_ID']) ?>" class="char-card" style="border-left: 3px solid <?= $rData['color'] ?>;">
            <div class="char-card-header">
                <div>
                    <h4 class="char-name"><?= h($char['Name']) ?></h4>
                    <div class="char-acc"><?= h($char['AccountName']) ?></div>
                </div>
                <div class="char-lvl">Lvl <?= (int)$char['Level'] ?></div>
            </div>
            <div class="char-meta">
                <span style="color: <?= $rData['color'] ?>;"><i class="fas <?= $rData['icon'] ?>"></i> <?= $rData['name'] ?></span>
                <span><i class="fas fa-clock"></i> <?= date('d.m.Y', strtotime($char['LastPlayed'] ?? 'now')) ?></span>
            </div>
        </a>
        <?php endforeach; ?>
        <?php if (empty($charList)): ?>
            <div class="acp-s-9af23ab7">No characters found in database.</div>
        <?php endif; ?>
    </div>

<?php else: 
    // ── EDIT VIEW ───────────────────────────────────────── 
    $rData = getRealmData($editChar['Realm']);
?>
    <div class="acp-s-57e800c6">
        <a href="acp.php?s=char_editor" class="acp-s-b290de19"><i class="fas fa-arrow-left"></i> Back to selection</a>
    </div>

    <!-- Hidden form for Quick Actions (independent of main form) -->
    <form id="qa-form" method="POST" action="acp.php?s=char_editor">
        <input type="hidden" name="csrf_token" value="<?= h(generateToken()) ?>">
        <input type="hidden" name="char_id" value="<?= h($editChar['DOLCharacters_ID']) ?>">
        <input type="hidden" name="quick_action" id="qa-action" value="">
        <input type="hidden" name="qa_value" id="qa-value" value="">
    </form>

    <form id="char-edit-form" method="POST" action="acp.php?s=char_editor">
        <input type="hidden" name="csrf_token" value="<?= h(generateToken()) ?>">
        <input type="hidden" name="update_char" value="1">
        <input type="hidden" name="char_id" value="<?= h($editChar['DOLCharacters_ID']) ?>">

        <!-- Header card with avatar -->
        <div class="sheet-box acp-s-3e223ccd">
            <div class="acp-s-9f45f8dd">
                <div class="char-avatar">
                    <i class="fas fa-user-shield" style="color: <?= $rData['color'] ?>;"></i>
                </div>
                <div>
                    <h2 class="acp-s-453038db"><?= h($editChar['Name']) ?></h2>
                    <div class="acp-s-fed5f0a9">
                        Account: <?= h($editChar['AccountName']) ?> &nbsp;|&nbsp; 
                        <span style="color: <?= $rData['color'] ?>;"><i class="fas <?= $rData['icon'] ?>"></i> <?= $rData['name'] ?></span>
                    </div>
                </div>
            </div>
            <div class="acp-s-9f45f8dd">
                <div id="unsaved-badge" class="unsaved-badge"><i class="fas fa-circle"></i> Unsaved</div>
                <button type="submit" onmouseover="this.style.color='#c5a059'; this.style.borderColor='#c5a059';" onmouseout="this.style.color='#888'; this.style.borderColor='#333';" class="acp-s-46ec2ece">
                    <i class="fas fa-save"></i> Save
                </button>
            </div>
        </div>

        <div class="sheet-wrap">
            <!-- Left Col: Attributes & Stats -->
            <div class="sheet-col-main">
                <div class="sheet-box">
                    <h3 class="sheet-title"><i class="fas fa-dumbbell"></i> Core Attributes</h3>
                    
                    <?php 
                    $stats = [
                        ['id'=>'stat_str', 'db'=>'Strength', 'lbl'=>'STR'],
                        ['id'=>'stat_con', 'db'=>'Constitution', 'lbl'=>'CON'],
                        ['id'=>'stat_dex', 'db'=>'Dexterity', 'lbl'=>'DEX'],
                        ['id'=>'stat_qui', 'db'=>'Quickness', 'lbl'=>'QUI'],
                        ['id'=>'stat_int', 'db'=>'Intelligence', 'lbl'=>'INT'],
                        ['id'=>'stat_pie', 'db'=>'Piety', 'lbl'=>'PIE'],
                        ['id'=>'stat_emp', 'db'=>'Empathy', 'lbl'=>'EMP'],
                        ['id'=>'stat_cha', 'db'=>'Charisma', 'lbl'=>'CHA']
                    ];
                    foreach ($stats as $s): 
                        $val = (int)$editChar[$s['db']];
                        $pct = min(100, max(0, ($val / 120) * 100)); // visual cap
                    ?>
                    <div class="stat-row">
                        <div class="stat-lbl"><?= h($s['lbl']) ?></div>
                        <div class="stat-bar-bg"><div class="stat-bar-fill" style="width: <?= $pct ?>%;"></div></div>
                        <input type="number" class="stat-input" name="<?= $s['id'] ?>" value="<?= $val ?>" min="0" max="400" oninput="this.previousElementSibling.firstElementChild.style.width = Math.min(100, (this.value/120)*100) + '%'">
                    </div>
                    <?php endforeach; ?>

                    <div class="live-stat-box">
                        Total Attribute Points: <span id="total-attr-points" class="live-stat-val">0</span>
                    </div>
                </div>
            </div>

            <!-- Middle Col: Progression & Wealth -->
            <div class="sheet-col-side">
                <div class="sheet-box">
                    <h3 class="sheet-title"><i class="fas fa-chart-line"></i> Progression</h3>
                    
                    <div class="acp-s-ae699fb3">
                        <div class="acp-s-d2dc0a7a">
                            <label class="acp-s-dbbf0b06">Level</label>
                            <input type="number" name="level" class="tracker-input acp-s-57d5cca0" value="<?= (int)$editChar['Level'] ?>" min="1" max="50" >
                        </div>
                        <div class="acp-s-161adebb">
                            <label class="acp-s-dbbf0b06">Experience</label>
                            <input type="number" name="experience" class="tracker-input acp-s-1291752b" value="<?= (int)$editChar['Experience'] ?>" >
                        </div>
                    </div>
                    
                    <div class="acp-s-0931344b">
                        <div class="acp-s-d2dc0a7a">
                            <label class="acp-s-dbbf0b06">Realm Points</label>
                            <input type="number" name="realm_points" class="tracker-input acp-s-1291752b" value="<?= (int)$editChar['RealmPoints'] ?>" >
                        </div>
                        <div class="acp-s-d2dc0a7a">
                            <label class="acp-s-dbbf0b06">Bounty Points</label>
                            <input type="number" name="bounty_points" class="tracker-input acp-s-1291752b" value="<?= (int)$editChar['BountyPoints'] ?>" >
                        </div>
                    </div>
                </div>

                <div class="sheet-box">
                    <h3 class="sheet-title"><i class="fas fa-coins"></i> Wealth</h3>
                    
                    <div class="money-grid">
                        <div class="money-box">
                            <div class="money-lbl c-mith"><i class="fas fa-gem"></i> Mithril</div>
                            <input type="number" name="money_mithril" value="<?= (int)$editChar['Mithril'] ?>" class="money-input c-mith" min="0">
                        </div>
                        <div class="money-box">
                            <div class="money-lbl c-plat"><i class="fas fa-coins"></i> Plat</div>
                            <input type="number" name="money_plat" value="<?= (int)$editChar['Platinum'] ?>" class="money-input c-plat" min="0">
                        </div>
                        <div class="money-box">
                            <div class="money-lbl c-gold"><i class="fas fa-coins"></i> Gold</div>
                            <input type="number" name="money_gold" value="<?= (int)$editChar['Gold'] ?>" class="money-input c-gold" min="0">
                        </div>
                        <div class="money-box">
                            <div class="money-lbl c-silv"><i class="fas fa-coins"></i> Silver</div>
                            <input type="number" name="money_silver" value="<?= (int)$editChar['Silver'] ?>" class="money-input c-silv" min="0">
                        </div>
                        <div class="money-box">
                            <div class="money-lbl c-copp"><i class="fas fa-coins"></i> Copper</div>
                            <input type="number" name="money_copper" value="<?= (int)$editChar['Copper'] ?>" class="money-input c-copp" min="0">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Col: Quick Actions -->
            <div class="sheet-col-qa">
                <div class="sheet-box acp-s-2c9e984f">
                    <h3 class="sheet-title acp-s-68258657"><i class="fas fa-bolt"></i> Quick Actions</h3>
                    
                    <button type="button" onclick="doQA('teleport')" class="qa-btn"><i class="fas fa-map-marker-alt"></i> Teleport</button>
                    <button type="button" onclick="doQA('full_heal')" class="qa-btn"><i class="fas fa-heart"></i> Full Heal</button>
                    <button type="button" onclick="doQA('reset_bind')" class="qa-btn"><i class="fas fa-home"></i> Reset Bind</button>
                    <button type="button" onclick="doQA('add_money')" class="qa-btn"><i class="fas fa-coins"></i> Add Money</button>
                    <button type="button" onclick="doQA('rename')" class="qa-btn"><i class="fas fa-id-card"></i> Rename</button>
                    
                    <div class="acp-s-0e590476"></div>
                    
                    <button type="button" onclick="doQA('kick')" class="qa-btn qa-btn-danger"><i class="fas fa-sign-out-alt"></i> Kick Character</button>
                </div>
            </div>
        </div>
    </form>

    <script>
    function doQA(action) {
        let val = '';
        if (action === 'teleport') {
            val = prompt("Enter target coordinates (e.g. RegionID,X,Y,Z):");
            if (val === null) return;
        } else if (action === 'add_money') {
            val = prompt("Enter amount:");
            if (val === null) return;
        } else if (action === 'rename') {
            val = prompt("Enter new character name:");
            if (val === null) return;
        } else {
            if (!confirm("Really perform this action?")) return;
        }

        document.getElementById('qa-action').value = action;
        document.getElementById('qa-value').value = val;
        document.getElementById('qa-form').submit();
    }

    document.addEventListener("DOMContentLoaded", function() {
        let isDirty = false;
        const form = document.getElementById('char-edit-form');
        const badge = document.getElementById('unsaved-badge');
        const statInputs = document.querySelectorAll('.stat-input');
        const totalAttrSpan = document.getElementById('total-attr-points');
        const allTracked = document.querySelectorAll('.stat-input, .tracker-input, .money-input');

        // Sum all stat inputs
        function updateTotal() {
            let sum = 0;
            statInputs.forEach(inp => { sum += (parseInt(inp.value) || 0); });
            totalAttrSpan.textContent = sum;
        }

        // Trigger on any tracked value change
        allTracked.forEach(el => {
            el.addEventListener('input', function() {
                if(!isDirty) {
                    isDirty = true;
                    badge.style.display = 'inline-block';
                }
                updateTotal();
            });
        });

        // Reset dirty flag so saving doesn't trigger the browser warning
        form.addEventListener('submit', function() {
            isDirty = false;
        });

        // Warn before closing tab with unsaved changes
        window.addEventListener('beforeunload', function(e) {
            if(isDirty) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        // Initial calculation on load
        updateTotal();
    });
    </script>
<?php endif; ?>