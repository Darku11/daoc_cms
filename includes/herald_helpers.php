<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * Herald shared helpers — class mapping, realm data, RR titles.
 * Included by herald_view / herald_char_view / herald_guild.
 */
if (!defined('IN_CMS')) exit;

if (!function_exists('herald_class_map')) {
    function herald_class_map(): array {
        return [
            16=>'Acolyte',17=>'AlbionRogue',20=>'Disciple',15=>'Elementalist',
            14=>'Fighter',57=>'Forester',52=>'Guardian',18=>'Mage',
            51=>'Magician',38=>'MidgardRogue',36=>'Mystic',53=>'Naturalist',
            37=>'Seer',54=>'Stalker',35=>'Viking',
            2=>'Armsman',13=>'Cabalist',6=>'Cleric',10=>'Friar',33=>'Heretic',
            9=>'Infiltrator',11=>'Mercenary',4=>'Minstrel',12=>'Necromancer',
            1=>'Paladin',19=>'Reaver',3=>'Scout',8=>'Sorcerer',5=>'Theurgist',
            7=>'Wizard',60=>'MaulerAlb',
            31=>'Berserker',30=>'Bonedancer',26=>'Healer',25=>'Hunter',
            29=>'Runemaster',32=>'Savage',23=>'Shadowblade',28=>'Shaman',
            24=>'Skald',27=>'Spiritmaster',21=>'Thane',34=>'Valkyrie',
            59=>'Warlock',22=>'Warrior',61=>'MaulerMid',
            55=>'Animist',39=>'Bainshee',48=>'Bard',43=>'Blademaster',
            45=>'Champion',47=>'Druid',40=>'Eldritch',41=>'Enchanter',
            44=>'Hero',42=>'Mentalist',49=>'Nightshade',50=>'Ranger',
            56=>'Valewalker',58=>'Vampiir',46=>'Warden',62=>'MaulerHib',
        ];
    }
}

if (!function_exists('getClassName')) {
    function getClassName($id) {
        $classes = herald_class_map();
        return $classes[(int)$id] ?? t('herald.unknown_class', [], 'Unknown') . " ($id)";
    }
}

/** Font Awesome icon per class (archetype-based). */
if (!function_exists('herald_class_icon')) {
    function herald_class_icon($id): string {
        $tank = [2,1,19,11,22,21,31,32,52,44,43,45,46];        // armor/melee
        $stealth = [9,3,23,25,49,50,54,17,38];                  // rogues/archers
        $caster = [13,12,8,5,7,15,18,51,29,27,59,30,40,41,42,55,39,20]; // magic
        $healer = [6,10,26,28,47,48,37,36,53,16];               // support
        $id = (int)$id;
        if (in_array($id, $healer, true))  return 'fa-hand-holding-medical';
        if (in_array($id, $caster, true))  return 'fa-hat-wizard';
        if (in_array($id, $stealth, true)) return 'fa-user-ninja';
        if (in_array($id, $tank, true))    return 'fa-shield-halved';
        return 'fa-khanda';
    }
}

/** Realm metadata. Alb=red, Mid=blue, Hib=green (canonical). */
if (!function_exists('herald_realm_info')) {
    function herald_realm_info(): array {
        return [
            1 => ['name'=>t('herald.realm.albion',[], 'Albion'),   'color'=>'#c0392b','glow'=>'rgba(192,57,43,0.5)', 'icon'=>'fa-chess-rook','slug'=>'alb'],
            2 => ['name'=>t('herald.realm.midgard',[], 'Midgard'),  'color'=>'#2980b9','glow'=>'rgba(41,128,185,0.5)','icon'=>'fa-hammer',    'slug'=>'mid'],
            3 => ['name'=>t('herald.realm.hibernia',[],'Hibernia'), 'color'=>'#27ae60','glow'=>'rgba(39,174,96,0.5)', 'icon'=>'fa-leaf',      'slug'=>'hib'],
        ];
    }
}

/**
 * Realm rank from realm points (DAoC standard curve).
 * Returns ['rr'=>int 1..14, 'level'=>int 0..9, 'label'=>'RRxLy', 'pct'=>float progress to next RR].
 */
if (!function_exists('herald_realm_rank')) {
    function herald_realm_rank(int $rp): array {
        // Cumulative RP needed to reach each realm level (RRxL0). DAoC formula.
        $rrLevelRP = function(int $rr, int $l) {
            $lvl = ($rr - 1) * 10 + $l; // 0-based realm level
            if ($lvl <= 0) return 0;
            return (int)floor(25.0 / 3.0 * (pow($lvl,3) + 10*($lvl*$lvl) + 20*$lvl - 30) + 0.5);
        };
        $rank = 1; $level = 0;
        for ($rr = 1; $rr <= 14; $rr++) {
            for ($l = 0; $l <= 9; $l++) {
                if ($rp >= $rrLevelRP($rr, $l)) { $rank = $rr; $level = $l; }
                else break 2;
            }
        }
        $curBase  = $rrLevelRP($rank, $level);
        $nextBase = ($rank < 14 || $level < 9) ? $rrLevelRP($rank + ($level==9?1:0), $level==9?0:$level+1) : $curBase;
        $span = max(1, $nextBase - $curBase);
        $pct  = $nextBase > $curBase ? min(100, max(0, ($rp - $curBase) / $span * 100)) : 100;
        return ['rr'=>$rank, 'level'=>$level, 'label'=>"RR{$rank}L{$level}", 'pct'=>round($pct,1)];
    }
}