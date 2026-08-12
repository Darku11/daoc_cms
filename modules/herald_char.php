<?php
// SPDX-License-Identifier: GPL-3.0-only
// --- DAoC CLASS ID MAPPING ---
if (!function_exists('getClassName')) {
    function getClassName($id) {
        $classes = [
            // base classes
            16 => 'Acolyte', 17 => 'AlbionRogue', 20 => 'Disciple', 15 => 'Elementalist',
            14 => 'Fighter', 57 => 'Forester', 52 => 'Guardian', 18 => 'Mage',
            51 => 'Magician', 38 => 'MidgardRogue', 36 => 'Mystic', 53 => 'Naturalist',
            37 => 'Seer', 54 => 'Stalker', 35 => 'Viking',
            // alb classes
            2 => 'Armsman', 13 => 'Cabalist', 6 => 'Cleric', 10 => 'Friar', 33 => 'Heretic',
            9 => 'Infiltrator', 11 => 'Mercenary', 4 => 'Minstrel', 12 => 'Necromancer',
            1 => 'Paladin', 19 => 'Reaver', 3 => 'Scout', 8 => 'Sorcerer', 5 => 'Theurgist',
            7 => 'Wizard', 60 => 'MaulerAlb',
            // mid classes
            31 => 'Berserker', 30 => 'Bonedancer', 26 => 'Healer', 25 => 'Hunter',
            29 => 'Runemaster', 32 => 'Savage', 23 => 'Shadowblade', 28 => 'Shaman',
            24 => 'Skald', 27 => 'Spiritmaster', 21 => 'Thane', 34 => 'Valkyrie',
            59 => 'Warlock', 22 => 'Warrior', 61 => 'MaulerMid',
            // hib classes
            55 => 'Animist', 39 => 'Bainshee', 48 => 'Bard', 43 => 'Blademaster',
            45 => 'Champion', 47 => 'Druid', 40 => 'Eldritch', 41 => 'Enchanter',
            44 => 'Hero', 42 => 'Mentalist', 49 => 'Nightshade', 50 => 'Ranger',
            56 => 'Valewalker', 58 => 'Vampiir', 46 => 'Warden', 62 => 'MaulerHib'
        ];
        return $classes[(int)$id] ?? t('herald_char.unknown_class', [], 'Unknown') . " ($id)";
    }
}

// Use the global PDO connection.
$char_name = isset($_GET['name']) ? trim($_GET['name']) : '';

if (empty($char_name)) {
    echo "<div class='admin-box'>" . t('herald_char.error.no_char', [], 'No character specified.') . "</div>";
    return;
}

// Sum realm kills with a prepared PDO query.
$stmt = $db->prepare("SELECT c.*, 
          (c.KillsAlbionPlayers + c.KillsMidgardPlayers + c.KillsHiberniaPlayers) AS TotalKills,
          g.GuildName 
          FROM dolcharacters c
          LEFT JOIN guild g ON c.GuildID = g.GuildID 
          WHERE c.Name = ?");
$stmt->execute([$char_name]);
$c = $stmt->fetch();

if (!$c) {
    echo "<div class='admin-box'>" . t('herald_char.error.not_found', [], 'Character not found in the chronicles.') . "</div>";
    return;
}

$r_info = [
    1 => ['name' => t('herald_char.realm.albion', [], 'Albion'), 'color' => '#4a90e2'],
    2 => ['name' => t('herald_char.realm.midgard', [], 'Midgard'), 'color' => '#e74c3c'],
    3 => ['name' => t('herald_char.realm.hibernia', [], 'Hibernia'), 'color' => '#2ecc71']
];
$r_color = $r_info[(int)$c['Realm']]['color'] ?? '#555';
?>

<div class="admin-container">
    <div style="margin-bottom: 20px;">
        <a href="?p=herald" style="color: #555; text-decoration: none; font-size: 0.75em; text-transform: uppercase; letter-spacing: 1px;">
            <i class="fas fa-chevron-left"></i> <?= t('herald_char.back', [], 'Back to Herald'); ?>
        </a>
    </div>

    <div class="admin-box" style="border-top: 3px solid <?php echo $r_color; ?>; padding: 40px; background: rgba(0,0,0,0.3);">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 40px; border-bottom: 1px solid #111; padding-bottom: 20px;">
            <div>
                <h1 style="margin: 0; font-family: 'Cinzel'; font-size: 2.5em; color: #eee;"><?php echo h($c['Name']); ?></h1>
                <div style="color: <?php echo $r_color; ?>; font-weight: bold; font-size: 1em; text-transform: uppercase; letter-spacing: 2px; margin-top: 5px;">
                    <?= t('herald_char.level', [], 'Level'); ?> <?php echo (int)$c['Level']; ?> <?php echo getClassName((int)$c['Class']); ?> 
                    <span style="color: #222; margin: 0 10px;">|</span> <?php echo $r_info[(int)$c['Realm']]['name']; ?>
                </div>
                <div style="color: #555; font-size: 0.85em; margin-top: 10px;">
                    <i class="fas fa-users" style="margin-right: 5px;"></i> <?= t('herald_char.guild', [], 'Guild:'); ?> <span style="color: #888;"><?php echo h($c['GuildName'] ?: t('herald_char.guild_none', [], 'None')); ?></span>
                </div>
            </div>
            
            <div style="text-align: right;">
                <div style="font-size: 0.7em; color: #555; text-transform: uppercase; letter-spacing: 1px;"><?= t('herald_char.realm_rank', [], 'Realm Rank'); ?></div>
                <div style="font-size: 2.5em; color: var(--gold); font-family: 'Cinzel'; line-height: 1;">
                    <?php echo h($c['Rank'] ?? '1L0'); ?>
                </div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
            
            <div style="display: flex; flex-direction: column; gap: 15px;">
                <div class="stats-card" style="padding: 15px; background: rgba(0,0,0,0.2); border: 1px solid #111;">
                    <label style="display:block; font-size: 0.7em; color: #444; text-transform: uppercase; margin-bottom: 5px;"><?= t('herald_char.total_rp', [], 'Total Realm Points'); ?></label>
                    <span style="font-size: 1.4em; color: #ccc; font-family: 'Courier New';"><?php echo number_format($c['RealmPoints']); ?></span>
                </div>
                <div class="stats-card" style="padding: 15px; background: rgba(0,0,0,0.2); border: 1px solid #111;">
                    <label style="display:block; font-size: 0.7em; color: #444; text-transform: uppercase; margin-bottom: 5px;"><?= t('herald_char.bounty_points', [], 'Bounty Points'); ?></label>
                    <span style="font-size: 1.4em; color: #ccc; font-family: 'Courier New';"><?php echo number_format($c['BountyPoints'] ?? 0); ?></span>
                </div>
            </div>

            <div style="display: flex; flex-direction: column; gap: 15px;">
                <div class="stats-card" style="padding: 15px; background: rgba(0,0,0,0.2); border: 1px solid #111;">
                    <label style="display:block; font-size: 0.7em; color: #444; text-transform: uppercase; margin-bottom: 5px;"><?= t('herald_char.total_kills', [], 'Total Kills'); ?></label>
                    <span style="font-size: 1.4em; color: #2ecc71; font-family: 'Courier New';"><?php echo number_format($c['TotalKills'] ?? 0); ?></span>
                </div>
                <div class="stats-card" style="padding: 15px; background: rgba(0,0,0,0.2); border: 1px solid #111;">
                    <label style="display:block; font-size: 0.7em; color: #444; text-transform: uppercase; margin-bottom: 5px;"><?= t('herald_char.total_deaths', [], 'Total Deaths'); ?></label>
                    <span style="font-size: 1.4em; color: #e74c3c; font-family: 'Courier New';"><?php echo number_format($c['DeathCount'] ?? 0); ?></span>
                </div>
            </div>

        </div>

        <div style="margin-top: 30px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; text-align: center;">
             <div style="padding: 10px; border: 1px solid #111; background: rgba(74, 144, 226, 0.05);">
                <div style="font-size: 0.6em; color: #4a90e2; text-transform: uppercase; letter-spacing: 1px;"><?= t('herald_char.kills_albion', [], 'Albion Kills'); ?></div>
                <div style="color: #eee; font-family: 'Courier New';"><?php echo number_format($c['KillsAlbionPlayers'] ?? 0); ?></div>
             </div>
             <div style="padding: 10px; border: 1px solid #111; background: rgba(231, 76, 60, 0.05);">
                <div style="font-size: 0.6em; color: #e74c3c; text-transform: uppercase; letter-spacing: 1px;"><?= t('herald_char.kills_midgard', [], 'Midgard Kills'); ?></div>
                <div style="color: #eee; font-family: 'Courier New';"><?php echo number_format($c['KillsMidgardPlayers'] ?? 0); ?></div>
             </div>
             <div style="padding: 10px; border: 1px solid #111; background: rgba(46, 204, 113, 0.05);">
                <div style="font-size: 0.6em; color: #2ecc71; text-transform: uppercase; letter-spacing: 1px;"><?= t('herald_char.kills_hibernia', [], 'Hibernia Kills'); ?></div>
                <div style="color: #eee; font-family: 'Courier New';"><?php echo number_format($c['KillsHiberniaPlayers'] ?? 0); ?></div>
             </div>
        </div>

        <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #111; text-align: center; font-size: 0.7em; color: #333; text-transform: uppercase; letter-spacing: 1px;">
            <?= t('herald_char.footer_sync', [], 'Data is synced automatically from the live game server.'); ?>
        </div>
    </div>
</div>
