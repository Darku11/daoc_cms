<?php
// SPDX-License-Identifier: GPL-3.0-only
function checkQuery($db, $result, $query) {
    if (!$result) {
        die("<div class='admin-box' style='border-color: red;'>
                <h3 style='color:red;'>" . t('herald_alt.sql_error', [], 'SQL Error in Herald') . "</h3>
                <p>" . t('herald_alt.query', [], 'Query: ') . "<code>$query</code></p>
                <p>" . t('herald_alt.error', [], 'Error: ') . $db->error . "</p>
             </div>");
    }
    return $result;
}

$q_keeps = "SELECT * FROM keep LIMIT 1";
$res_keeps = $db->query($q_keeps);

if ($res_keeps) {
    $row = $res_keeps->fetch_assoc();
    $hasGuild = isset($row['GuildID']) ? "k.GuildID" : "NULL as GuildID";
    
    $q_status = "
        SELECT k.Name, k.Realm, g.Name as GuildName, $hasGuild
        FROM keep k 
        LEFT JOIN guild g ON k.GuildID = g.GuildID 
        ORDER BY k.Realm ASC, k.Name ASC";
    
    $keeps_res = checkQuery($db, $db->query($q_status), $q_status);
} else {
    die("<div class='admin-box'>" . t('herald_alt.keep_not_found', [], 'Table \'keep\' not found. Please check your game server database.') . "</div>");
}

$realm_counts = [1 => 0, 2 => 0, 3 => 0];
$keep_details = [];
while ($k = $keeps_res->fetch_assoc()) {
    $r_id = (int)$k['Realm'];
    if (isset($realm_counts[$r_id])) $realm_counts[$r_id]++;
    $keep_details[] = $k;
}

// --- 2. RANKING LOGIC (TOP 10) ---
// Detect the available points column, usually RealmPoints or Experience.
$q_top = "SELECT Name, Class, Level, Realm, RealmPoints FROM dolcharacters WHERE Level > 0 ORDER BY RealmPoints DESC LIMIT 10";
$top_res = checkQuery($db, $db->query($q_top), $q_top);

$r_info = [
    1 => ['name' => t('herald_alt.realm.albion', [], 'Albion'), 'color' => '#e74c3c', 'icon' => 'fa-chess-rook'],
    2 => ['name' => t('herald_alt.realm.midgard', [], 'Midgard'), 'color' => '4a90e2', 'icon' => 'fa-hammer'],
    3 => ['name' => t('herald_alt.realm.hibernia', [], 'Hibernia'), 'color' => '#2ecc71', 'icon' => 'fa-leaf']
];
?>

<div class="admin-container">
    
    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 20px;">
        <?php foreach ($r_info as $id => $realm): ?>
        <div style="background: rgba(0,0,0,0.4); border-top: 3px solid <?php echo $realm['color']; ?>; padding: 25px; text-align: center;">
            <i class="fas <?php echo $realm['icon']; ?>" style="font-size: 2.5em; color: <?php echo $realm['color']; ?>; margin-bottom: 10px;"></i>
            <h2 style="margin: 0; font-family: 'Cinzel';"><?php echo strtoupper($realm['name']); ?></h2>
            <div style="font-size: 2em; color: #fff; margin: 10px 0;"><?php echo $realm_counts[$id]; ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div style="background:rgba(10,10,10,0.97); padding:20px 25px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:15px; border-bottom:1px solid rgba(197,160,89,0.1);">
        <h3 style="font-family:'Cinzel',serif; font-size:0.8em; color:var(--gold); text-transform:uppercase; letter-spacing:2px; margin:0;">
            <i class="fas fa-search" style="margin-right:10px;"></i><?= t('herald_alt.search_title', [], 'Character Search'); ?>
        </h3>
        <form action="index.php" method="GET" style="display:flex; gap:10px; flex:1; max-width:400px;">
            <input type="hidden" name="p" value="herald_char">
            <input type="text" name="name" placeholder="<?= t('herald_alt.search_placeholder', [], 'Enter character name...'); ?>" required style="flex:1; background:#050505; border:1px solid #1a1a1a; color:#ccc; padding:8px 12px; font-size:0.85em; outline:none; transition:border-color 0.2s;" onfocus="this.style.borderColor='rgba(197,160,89,0.3)'" onblur="this.style.borderColor='#1a1a1a'">
            <button type="submit" style="background:transparent; border:1px solid rgba(197,160,89,0.3); color:var(--gold); padding:8px 20px; font-family:'Cinzel',serif; font-size:0.7em; letter-spacing:1px; text-transform:uppercase; cursor:pointer; transition:background 0.2s;" onmouseover="this.style.background='rgba(197,160,89,0.1)'" onmouseout="this.style.background='transparent'">
                <?= t('herald_alt.search_btn', [], 'Search'); ?>
            </button>
        </form>
    </div>

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 30px;">
        
        <div class="admin-box">
            <h3 style="color: var(--gold); border-bottom: 1px solid #111; padding-bottom: 15px;"><?= t('herald_alt.top_combatants', [], 'TOP COMBATANTS'); ?></h3>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="text-align: left; color: #444; font-size: 0.7em;">
                        <th style="padding: 10px;"><?= t('herald_alt.table.name', [], 'NAME'); ?></th>
                        <th><?= t('herald_alt.table.class', [], 'CLASS'); ?></th>
                        <th><?= t('herald_alt.table.realm', [], 'REALM'); ?></th>
                        <th style="text-align: right;"><?= t('herald_alt.table.points', [], 'POINTS'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($char = $top_res->fetch_assoc()): ?>
                    <tr style="border-bottom: 1px solid #0a0a0a;">
                        <td style="padding: 15px 10px;">
                            <a href="?p=herald_char&name=<?php echo urlencode($char['Name']); ?>" style="color: #eee; font-weight: bold; text-decoration: none;">
                                <?php echo htmlspecialchars($char['Name']); ?>
                            </a>
                        </td>
                        <td style="color: #777;"><?php echo $char['Class']; ?></td>
                        <td style="color: <?php echo $r_info[(int)$char['Realm']]['color']; ?>;"><?php echo $r_info[(int)$char['Realm']]['name']; ?></td>
                        <td style="text-align: right; color: var(--gold);"><?php echo number_format($char['RealmPoints']); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <div class="admin-box">
            <h3 style="font-size: 0.8em; color: var(--gold); margin-bottom: 15px;"><?= t('herald_alt.frontier_status', [], 'FRONTIER STATUS'); ?></h3>
            <div style="max-height: 400px; overflow-y: auto;">
                <?php foreach ($keep_details as $keep): ?>
                    <div style="margin-bottom: 12px; font-size: 0.85em; border-left: 2px solid <?php echo $r_info[(int)$keep['Realm']]['color']; ?>; padding-left: 10px;">
                        <div style="color: #ccc;"><?php echo htmlspecialchars($keep['Name']); ?></div>
                        <div style="font-size: 0.75em; color: #555;">
                            <?= t('herald_alt.held_by', [], 'Held by: '); ?><?php echo htmlspecialchars($keep['GuildName'] ?? t('herald_alt.no_guild', [], 'No Guild')); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>