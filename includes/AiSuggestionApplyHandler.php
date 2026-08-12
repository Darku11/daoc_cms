<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * AiSuggestionApplyHandler.php – DAoC CMS
 *
 * Called AFTER an admin sets an AI suggestion to 'accepted'
 * in acp_ai_suggestions_view.php.
 *
 * Base rule: AI NEVER changes anything directly.
 *             This handler is the only place where an
 *             accepted suggestion gets applied.
 *             Every application is logged to aldhran_logs.
 *
 * Usage in acp_ai_suggestions_view.php:
 *   After the UPDATE to 'accepted':
 *   require_once __DIR__ . '/../includes/AiSuggestionApplyHandler.php';
 *   $handler = new AiSuggestionApplyHandler($db, $currentUserId, $userPriv);
 *   $applyResult = $handler->apply($suggestionRow);
 *
 * Usage in the async task worker (cms_ai_tasks):
 *   $handler->applyFromTask($taskRow);
 */

class AiSuggestionApplyHandler {

    private PDO $db;
    private int $adminId;
    private int $adminPriv;

    public function __construct(PDO $db, int $adminId, int $adminPriv) {
        $this->db        = $db;
        $this->adminId   = $adminId;
        $this->adminPriv = $adminPriv;
    }

    // ============================================================
    // PUBLIC: Apply an accepted suggestion
    // ============================================================

    /**
     * Apply an accepted suggestion.
     * $row = complete row from cms_ai_suggestions.
     *
     * @return array ['ok' => bool, 'message' => string, 'applied' => array]
     */
    public function apply(array $row): array {
        // Defense-in-depth: this handler performs direct DB writes, so it must
        // not rely solely on the caller having checked privileges already.
        if ($this->adminPriv < 4) {
            return $this->fail("Insufficient privileges to apply AI suggestions.");
        }

        $module      = $row['module_context'] ?? '';
        $targetType  = $row['target_type']    ?? '';
        $targetId    = (int)($row['target_id'] ?? 0);
        $suggData    = json_decode($row['suggestion_data'] ?? '{}', true);
        $suggText    = $suggData['text'] ?? '';
        $suggJson    = $suggData['json'] ?? null;

        if (empty($module)) {
            return $this->fail("No module_context in suggestion #{$row['id']}");
        }

        // Module router
        $result = match($module) {
            'item_creator'       => $this->applyItemCreator($targetType, $targetId, $suggText, $suggJson, $row),
            'mob_editor'         => $this->applyMobEditor($targetType, $targetId, $suggText, $suggJson, $row),
            'suit_creator'       => $this->applySuitCreator($targetType, $targetId, $suggText, $suggJson, $row),
            'error_log'          => $this->applyErrorLog($targetType, $targetId, $suggText, $row),
            'theme_editor'       => $this->applyThemeEditor($targetType, $targetId, $suggText, $row),
            'translation_editor' => $this->applyTranslationEditor($targetType, $targetId, $suggText, $suggJson, $row),
            'discord'            => $this->applyDiscord($targetType, $suggText, $row),
            'core_architect'     => $this->applyCoreArchitect($targetType, $targetId, $suggText, $suggJson, $row),
            'dqc'                => $this->applyDqc($targetType, $targetId, $suggText, $suggJson, $row),
            default              => $this->fail("Unknown module: $module"),
        };

        // Log success
        if ($result['ok']) {
            aldhran_log(
                'AI_SUGGESTION_APPLIED',
                "Module: $module | Type: $targetType | Target: $targetId | Suggestion #{$row['id']}",
                $this->adminId,
                $targetId ?: null
            );
        }

        return $result;
    }

    /**
     * Apply a suggestion from a cms_ai_tasks row in the asynchronous worker.
     */
    public function applyFromTask(array $taskRow): array {
        $payload = json_decode($taskRow['payload'] ?? '{}', true);
        $suggId  = (int)($payload['suggestion_id'] ?? 0);

        if (!$suggId) {
            return $this->fail("apply_suggestion task has no suggestion_id");
        }

        $stmt = $this->db->prepare("SELECT * FROM cms_ai_suggestions WHERE id = ? AND status = 'accepted'");
        $stmt->execute([$suggId]);
        $row = $stmt->fetch();

        if (!$row) {
            return $this->fail("Suggestion #$suggId not found or not accepted");
        }

        return $this->apply($row);
    }

    // ============================================================
    // MODULE HANDLERS
    // ============================================================

    // ── 1. ITEM CREATOR ─────────────────────────────────────────
    private function applyItemCreator(string $type, int $id, string $text, ?array $json, array $row): array {
        switch ($type) {
            case 'generate_lore':
            case 'generate_description':
                if (!$id) return $this->ok("Item description suggestion stored (no target_id – manual apply needed)", ['text' => $text]);
                $stmt = $this->db->prepare("UPDATE cms_items SET description = :desc, updated_at = NOW(), updated_by = :uid WHERE id = :id");
                $stmt->execute([':desc' => $text, ':uid' => $this->adminId, ':id' => $id]);
                return $this->ok("Item #$id description updated from AI suggestion.", ['rows' => $stmt->rowCount()]);

            case 'suggest_stats':
                if (!$json || !$id) return $this->ok("Stat suggestion stored (manual apply needed)", ['text' => $text]);
                $allowed = ['damage_factor', 'armor_factor', 'bonus1_type', 'bonus1_val',
                            'bonus2_type', 'bonus2_val', 'bonus3_type', 'bonus3_val',
                            'bonus4_type', 'bonus4_val', 'bonus5_type', 'bonus5_val',
                            'proc_spell_id', 'max_charges', 'quality', 'condition_percent'];
                $sets  = [];
                $binds = [':id' => $id, ':uid' => $this->adminId];
                foreach ($json as $col => $val) {
                    if (!in_array($col, $allowed, true)) continue;
                    $sets[]          = "`$col` = :$col";
                    $binds[":$col"]  = $val;
                }
                if (empty($sets)) return $this->fail("suggest_stats: no valid fields in suggestion JSON");
                $sets[] = "updated_by = :uid";
                $sets[] = "updated_at = NOW()";
                $sql  = "UPDATE cms_items SET " . implode(', ', $sets) . " WHERE id = :id";
                $this->db->prepare($sql)->execute($binds);
                return $this->ok("Item #$id stats updated from AI suggestion.", ['fields' => array_keys($json)]);

            default:
                return $this->ok("Item Creator suggestion (type: $type) stored for manual review.", ['text' => $text]);
        }
    }

    // ── 2. MOB EDITOR ───────────────────────────────────────────
    private function applyMobEditor(string $type, int $id, string $text, ?array $json, array $row): array {
        switch ($type) {
            case 'suggest_spawn':
                if (!$json || !$id) return $this->ok("Spawn suggestion stored (manual apply needed)", ['text' => $text]);
                $allowed = ['level', 'max_level', 'faction', 'respawn_interval',
                            'guild_name', 'brain_class', 'path_id', 'spawn_point_x',
                            'spawn_point_y', 'spawn_point_z', 'spawn_heading'];
                $sets  = [];
                $binds = [':id' => $id];
                foreach ($json as $col => $val) {
                    if (!in_array($col, $allowed, true)) continue;
                    $sets[]         = "`$col` = :$col";
                    $binds[":$col"] = $val;
                }
                if (empty($sets)) return $this->fail("suggest_spawn: no valid fields");
                $sql = "UPDATE cms_mob_templates SET " . implode(', ', $sets) . " WHERE id = :id";
                $this->db->prepare($sql)->execute($binds);
                return $this->ok("Mob #$id spawn data updated.", ['fields' => array_keys($json)]);

            case 'generate_lore':
                if (!$id) return $this->ok("Mob lore stored (no target_id)", ['text' => $text]);
                $this->db->prepare("UPDATE cms_mob_templates SET lore_text = ?, updated_at = NOW() WHERE id = ?")
                         ->execute([$text, $id]);
                return $this->ok("Mob #$id lore updated.", []);

            case 'balance_check':
                // Balance check creates no direct DB change, but an admin note
                $this->db->prepare("INSERT INTO cms_admin_notes (user_id, target_type, target_id, note, created_at)
                                    VALUES (?, 'mob', ?, ?, NOW()) ON DUPLICATE KEY UPDATE note = VALUES(note), created_at = NOW()")
                         ->execute([$this->adminId, $id, "AI Balance Check: $text"]);
                return $this->ok("Mob #$id balance note saved.", ['note' => $text]);

            default:
                return $this->ok("Mob Editor suggestion (type: $type) noted.", ['text' => $text]);
        }
    }

    // ── 3. SUIT CREATOR ─────────────────────────────────────────
    private function applySuitCreator(string $type, int $id, string $text, ?array $json, array $row): array {
        switch ($type) {
            case 'suggest_stats':
            case 'balance_check':
                if (!$id) return $this->ok("Suit suggestion stored (manual apply needed)", ['text' => $text]);
                if ($json) {
                    $allowed = ['total_af', 'resist_slash', 'resist_thrust', 'resist_crush',
                                'resist_heat', 'resist_cold', 'resist_matter', 'resist_body',
                                'resist_spirit', 'resist_energy', 'stat_str', 'stat_con',
                                'stat_dex', 'stat_qui', 'stat_int', 'stat_pie', 'stat_emp'];
                    $sets  = [];
                    $binds = [':id' => $id];
                    foreach ($json as $col => $val) {
                        if (!in_array($col, $allowed, true)) continue;
                        $sets[]         = "`$col` = :$col";
                        $binds[":$col"] = $val;
                    }
                    if ($sets) {
                        $sql = "UPDATE cms_suits SET " . implode(', ', $sets) . " WHERE id = :id";
                        $this->db->prepare($sql)->execute($binds);
                        return $this->ok("Suit #$id stats updated.", ['fields' => array_keys($json)]);
                    }
                }
                return $this->ok("Suit suggestion stored (no valid JSON fields)", ['text' => $text]);

            default:
                return $this->ok("Suit Creator suggestion (type: $type) noted.", ['text' => $text]);
        }
    }

    // ── 4. ERROR LOG ────────────────────────────────────────────
    private function applyErrorLog(string $type, int $id, string $text, array $row): array {
        // Error log suggestions create no direct DB changes.
        // They're stored as an admin note (ticket system).
        $note = "AI Error Analysis (Task: {$row['task_id']}):\n$text";

        try {
            $this->db->prepare("INSERT INTO cms_admin_notes
                (user_id, target_type, target_id, note, created_at)
                VALUES (?, 'error_log', ?, ?, NOW())")
            ->execute([$this->adminId, $id ?: 0, $note]);
        } catch (\Throwable $e) {
            // The table is optional; failure is not fatal.
        }

        return $this->ok("Error Log analysis saved as admin note.", ['note_length' => strlen($text)]);
    }

    // ── 5. THEME EDITOR ─────────────────────────────────────────
    private function applyThemeEditor(string $type, int $id, string $text, array $row): array {
        switch ($type) {
            case 'suggest_css':
                // Apply a CSS variable or CSS block to aldhran_styles
                // Security check: only CSS properties allowed (no JS)
                if ($this->containsScript($text)) {
                    return $this->fail("Theme Editor: suggestion contains script tags – rejected for security.");
                }

                if ($id) {
                    // Target column: custom_css in aldhran_styles
                    $stmt = $this->db->prepare("UPDATE aldhran_styles SET custom_css = CONCAT(COALESCE(custom_css,''), '\n/* AI Suggestion */\n', ?) WHERE id = ?");
                    $stmt->execute([$text, $id]);
                    // Bump settings version so clients load the new CSS
                    aldhran_bump_settings_version();
                    return $this->ok("CSS suggestion appended to style #$id.", []);
                }

                // No target_id: write to the active theme
                $activeTheme = $this->db->query("SELECT value FROM settings WHERE setting_key = 'active_theme' LIMIT 1")->fetchColumn();
                if ($activeTheme) {
                    $this->db->prepare("UPDATE aldhran_styles SET custom_css = CONCAT(COALESCE(custom_css,''), '\n/* AI Suggestion */\n', ?) WHERE theme_slug = ?")
                             ->execute([$text, $activeTheme]);
                    aldhran_bump_settings_version();
                    return $this->ok("CSS suggestion appended to active theme '$activeTheme'.", []);
                }

                return $this->ok("CSS suggestion stored (no target theme found).", ['text' => $text]);

            case 'explain_variable':
                // Explanation → store as a note only
                return $this->ok("CSS variable explanation stored.", ['text' => $text]);

            default:
                return $this->ok("Theme Editor suggestion (type: $type) noted.", ['text' => $text]);
        }
    }

    // ── 6. TRANSLATION EDITOR ───────────────────────────────────
    private function applyTranslationEditor(string $type, int $id, string $text, ?array $json, array $row): array {
        $orig      = json_decode($row['original_data'] ?? '{}', true);
        $langCode  = $orig['lang_code']   ?? ($json['lang_code']   ?? null);
        // Support both old (trans_key/namespace) and current (var_key/var_context) schema naming
        $transKey  = $orig['var_key']     ?? ($orig['trans_key']   ?? ($json['var_key']     ?? ($json['trans_key']   ?? null)));
        $context   = $orig['var_context'] ?? ($orig['namespace']   ?? ($json['var_context'] ?? ($json['namespace']  ?? 'core')));

        if (!$transKey || !$langCode) {
            return $this->ok("Translation suggestion stored (missing lang_code or var_key – manual apply needed).", ['text' => $text]);
        }

        // Insert/update in cms_translations
        // Columns match cms_lang.php: var_key, var_value, var_context
        $this->db->prepare("INSERT INTO cms_translations (lang_code, var_key, var_value, var_context, updated_by, updated_at)
                            VALUES (?, ?, ?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE
                                var_value  = VALUES(var_value),
                                updated_by = VALUES(updated_by),
                                updated_at = NOW()")
                 ->execute([$langCode, $transKey, $text, $context, $this->adminId]);

        // Bump settings version so the language cache is invalidated
        aldhran_bump_settings_version();

        return $this->ok("Translation '$transKey' [$langCode] updated.", ['key' => $transKey, 'lang' => $langCode]);
    }

    // ── 7. DISCORD ──────────────────────────────────────────────
    private function applyDiscord(string $type, string $text, array $row): array {
        // Discord answers aren't written directly to the DB.
        // They're sent to the bot as a broadcast task.
        global $botSettings;
        if (!isset($botSettings)) {
            return $this->ok("Discord suggestion stored (bot not available).", ['text' => $text]);
        }

        // Send broadcast command to the Discord bot via socket
        $result = $botSettings->sendCommand('broadcast_ai', [
            'message'    => $text,
            'task_id'    => $row['task_id'],
            'suggestion' => $row['id'],
        ]);

        if (($result['status'] ?? '') === 'ok') {
            return $this->ok("Discord AI answer sent via bot broadcast.", ['result' => $result]);
        }

        return $this->ok("Discord suggestion stored (bot send failed: " . ($result['message'] ?? 'unknown') . ")", ['text' => $text]);
    }

    // ── 8. CORE ARCHITECT ───────────────────────────────────────
    private function applyCoreArchitect(string $type, int $id, string $text, ?array $json, array $row): array {
        // Core Architect suggestions are stored as analysis notes.
        // Direct economy changes always require manual confirmation.
        switch ($type) {
            case 'analyze_economy':
            case 'suggest_balance':
                $this->saveAnalysisNote('core_architect', $type, $id, $text, $row['task_id']);
                return $this->ok("Economy analysis note saved for Core Architect.", ['type' => $type]);

            default:
                return $this->ok("Core Architect suggestion (type: $type) noted.", ['text' => $text]);
        }
    }

    // ── 9. DQC (Dataquest Creator) ──────────────────────────────
    private function applyDqc(string $type, int $id, string $text, ?array $json, array $row): array {
        switch ($type) {
            case 'suggest_quest':
                if (!$id) return $this->ok("Quest suggestion stored (no target_id – manual apply needed)", ['text' => $text]);
                // Update the quest description and steps.
                $this->db->prepare("UPDATE cms_quests SET description = ?, updated_at = NOW(), updated_by = ? WHERE id = ?")
                         ->execute([$text, $this->adminId, $id]);
                return $this->ok("Quest #$id description updated from AI suggestion.", []);

            case 'generate_dialogue':
                if (!$id) return $this->ok("Dialogue suggestion stored (no target_id – manual apply needed)", ['text' => $text]);
                // Update dialogue text for NPC/quest
                $this->db->prepare("UPDATE cms_quest_dialogues SET dialogue_text = ?, updated_at = NOW() WHERE id = ?")
                         ->execute([$text, $id]);
                return $this->ok("Quest dialogue #$id updated from AI suggestion.", []);

            default:
                return $this->ok("DQC suggestion (type: $type) noted.", ['text' => $text]);
        }
    }

    // ============================================================
    // HELPERS
    // ============================================================

    private function ok(string $message, array $data = []): array {
        return ['ok' => true,  'message' => $message, 'applied' => $data];
    }

    private function fail(string $message): array {
        error_log("AiSuggestionApplyHandler: $message");
        return ['ok' => false, 'message' => $message, 'applied' => []];
    }

    private function containsScript(string $text): bool {
        if (preg_match('/<\s*script/i', $text)) return true;
        // CSS-specific injection vectors: <script> tags never execute inside a
        // CSS context, but these do.
        if (preg_match('/expression\s*\(/i', $text)) return true;
        if (preg_match('/url\s*\(\s*["\']?\s*javascript:/i', $text)) return true;
        if (preg_match('/@import/i', $text)) return true;
        return false;
    }

    private function saveAnalysisNote(string $module, string $type, int $targetId, string $text, mixed $taskId = null): void {
        $taskIdStr = $taskId !== null ? (string)$taskId : '';
        try {
            $this->db->prepare("INSERT INTO cms_admin_notes
                (user_id, target_type, target_id, note, created_at)
                VALUES (?, ?, ?, ?, NOW())")
            ->execute([
                $this->adminId,
                $module . '_' . $type,
                $targetId,
                $taskIdStr !== '' ? "[$taskIdStr] $text" : $text,
            ]);
        } catch (\Throwable $e) {
            // The table is optional.
        }
    }
}
