/**
 * DAoC CMS - SC Advisor Engine
 * Universal data pack and calculation logic
 */

const ScDataPack = {
    // Base caps for a level 50 template
    caps: {
        stat: 75,
        resist: 26,
        hits: 200,
        power: 26,
        cap_increase_stat: 26,
        cap_increase_resist: 5,
        skill: 11
    },

    // Map DOL bonus IDs to readable stats and categories
    // This list contains the most important IDs and can be extended by administrators.
    mapping: {
        // Core Stats
        1:  { name: "Strength",      type: "stat" },
        2:  { name: "Dexterity",     type: "stat" },
        3:  { name: "Constitution",  type: "stat" },
        4:  { name: "Quickness",     type: "stat" },
        5:  { name: "Intelligence",  type: "stat" },
        6:  { name: "Empathy",       type: "stat" },
        7:  { name: "Piety",         type: "stat" },
        8:  { name: "Charisma",      type: "stat" },
        
        // Hits & Power
        9:  { name: "Mana",          type: "power" },
        10: { name: "Hit Points",    type: "hits" },
        
        // Example resistances
        11: { name: "Crush Resist",  type: "resist" },
        12: { name: "Slash Resist",  type: "resist" },
        13: { name: "Thrust Resist", type: "resist" },
        14: { name: "Heat Resist",   type: "resist" },
        15: { name: "Cold Resist",   type: "resist" },
        16: { name: "Matter Resist", type: "resist" },
        
        // Cap Increases (ToA/Custom)
        190: { name: "Str Cap",      type: "cap_increase_stat" },
        191: { name: "Dex Cap",      type: "cap_increase_stat" },
        
        // Example melee and magic bonuses
        196: { name: "Melee Speed",  type: "utility" },
        197: { name: "Melee Damage", type: "utility" },
        198: { name: "Style Damage", type: "utility" },
        202: { name: "Casting Speed",type: "utility" },
        203: { name: "Spell Damage", type: "utility" }
    }
};

class ScCalculator {
    constructor(dataPack) {
        this.data = dataPack;
        this.reset();
    }

    reset() {
        this.totals = {};
        this.overcaps = {}; // ToA bonuses
    }

    // Expects an item array using the database JSON structure
    calculate(equippedItems) {
        this.reset();

        // 1. Apply all cap increases (ToA) first
        equippedItems.forEach(item => {
            this._extractBonuses(item, "cap_increase_stat");
            this._extractBonuses(item, "cap_increase_resist");
        });

        // 2. Apply regular stats
        equippedItems.forEach(item => {
            this._extractBonuses(item, "stat");
            this._extractBonuses(item, "resist");
            this._extractBonuses(item, "hits");
            this._extractBonuses(item, "power");
            this._extractBonuses(item, "skill");
            this._extractBonuses(item, "utility");
        });

        return this.generateReport();
    }

    _extractBonuses(item, targetType) {
        // Check Bonus1 through Bonus10
        for (let i = 1; i <= 10; i++) {
            let bonusType = item[`Bonus${i}Type`];
            let bonusVal  = item[`Bonus${i}`];

            if (bonusType > 0 && this.data.mapping[bonusType]) {
                let mapped = this.data.mapping[bonusType];
                
                if (mapped.type === targetType) {
                    if (!this.totals[mapped.name]) {
                        this.totals[mapped.name] = { value: 0, cap: this._getBaseCap(mapped.type) };
                    }
                    this.totals[mapped.name].value += bonusVal;
                }
            }
        }
    }

    _getBaseCap(type) {
        return this.data.caps[type] || 0;
    }

    generateReport() {
        let report = {};
        for (const [statName, data] of Object.entries(this.totals)) {
            // Calculate the dynamic cap (base plus overcap)
            let dynamicCap = data.cap;
            let overcapName = statName.split(' ')[0] + ' Cap'; // Example: derive "Str Cap" from "Strength"
            
            if (this.totals[overcapName]) {
                dynamicCap += this.totals[overcapName].value;
            }

            report[statName] = {
                total: data.value,
                cap: dynamicCap,
                isCapped: data.value >= dynamicCap,
                wasted: Math.max(0, data.value - dynamicCap)
            };
        }
        return report;
    }
}