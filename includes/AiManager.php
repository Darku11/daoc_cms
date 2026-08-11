<?php
class AiManager {

    private PDO         $db;
    private BotSettings $botSettings;
    private ?int        $userId;
    private int         $userPriv;

    private ?array $aiConfig = null;

    private string $lastError = '';

    public const PROVIDER_GEMINI    = 'gemini';
    public const PROVIDER_GROQ      = 'groq';
    public const PROVIDER_LMSTUDIO  = 'lm_studio';
    public const PROVIDER_OPENAI    = 'openai';
    public const PROVIDER_ANTHROPIC = 'anthropic';
    public const PROVIDER_NONE      = 'none';
    private const ALLOWED_MODULES = [
        'item_creator', 'mob_editor', 'suit_creator', 'error_log',
        'theme_editor', 'translation_editor', 'discord', 'core_architect',
        'dqc', 'warmap', 'faq_admin', 'spike_admin', 'um',
    ];

    private const DEFAULT_DAILY_LIMIT = 100;

    public function __construct(PDO $db, BotSettings $botSettings, ?int $userId = null, int $userPriv = 1) {
        $this->db          = $db;
        $this->botSettings = $botSettings;
        $this->userId      = $userId;
        $this->userPriv    = $userPriv;
    }

// ── Public API ─────────────────────────────────────────────

/**
 * Main request handler. Sends a prompt to the configured AI provider.
 *
 * @param string $module   Module context (e.g. 'item_creator')
 * @param string $action   Action type (e.g. 'balance_check')
 * @param array  $context  Context data for the prompt
 * @param array  $options  Options: save_suggestion, target_id, async
 */
    public function request(
        string $module,
        string $action,
        array  $context  = [],
        array  $options  = []
    ): array {
        if (!in_array($module, self::ALLOWED_MODULES)) {
            return $this->error("Module '{$module}' is not allowed.");
        }

        $cfg = $this->getConfig();
        if (!$cfg || $cfg['provider'] === self::PROVIDER_NONE) {
            return $this->error("AI is not configured.");
        }

        if (empty($cfg['api_key'])) {
            return $this->error("AI API key is not set or could not be decrypted.");
        }

        // Check daily limit
        if (!$this->checkDailyLimit($cfg)) {
            return $this->error("Daily AI call limit reached. Try again tomorrow.");
        }

        $prompt  = $this->buildPrompt($module, $action, $context);
        $task_id = null;

        // Async mode: enqueue task instead of running it directly
        if (!empty($options['async'])) {
            $task_id = $this->queueTask($module, $action, $context, $options);
            return [
                'status'  => 'queued',
                'task_id' => $task_id,
                'message' => 'Task queued for async processing.',
            ];
        }

        $start_ms     = round(microtime(true) * 1000);
        $raw_response = $this->callProvider($cfg, $prompt);
        $duration_ms  = round(microtime(true) * 1000) - $start_ms;

        if ($raw_response === null) {
            $this->logCall($module, $action, $prompt, '', $cfg['provider'], $duration_ms, 'error');
            $detail = $this->lastError ? " ({$this->lastError})" : '';
            return $this->error("AI provider returned no response. Check model name and API key.{$detail}");
        }

        $suggestion = $this->parseResponse($raw_response, $cfg['provider']);

        $log_id = $this->logCall($module, $action, $prompt, $suggestion, $cfg['provider'], $duration_ms, 'ok');

        // Save suggestion (if requested)
        $sugg_id = null;
        if (!empty($options['save_suggestion'])) {
            $sugg_id = $this->saveSuggestion(
                $module,
                $action,
                $suggestion,
                $context,
                $options['target_id'] ?? null,
                $log_id
            );
        }

        return [
            'status'        => 'ok',
            'result'        => ['suggestion' => $suggestion],
            'suggestion_id' => $sugg_id,
            'log_id'        => $log_id,
            'task_id'       => null,
            'provider'      => $cfg['provider'],
        ];
    }

    /**
     * Checks whether AI is configured and active.
     */
    public function isAvailable(): bool {
        $cfg = $this->getConfig();
        return $cfg !== null && $cfg['provider'] !== self::PROVIDER_NONE && !empty($cfg['api_key']);
    }

    // ── Config ─────────────────────────────────────────────────

    private function getConfig(): ?array {
        if ($this->aiConfig !== null) return $this->aiConfig;

        try {
            $row = $this->db->query(
                "SELECT is_active, ai_provider, ai_max_tokens, ai_temperature
                 FROM   cms_bot_settings WHERE id = 1 LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log("[AiManager] getConfig() DB error: " . $e->getMessage());
            return null;
        }

        if (!$row || !$row['is_active']) return null;

        $provider = $row['ai_provider'] ?? self::PROVIDER_NONE;
        if ($provider === self::PROVIDER_NONE) return null;

        $pk = null;
        try {
            $pk_stmt = $this->db->prepare(
                "SELECT api_key_enc, api_url, model
                 FROM   cms_ai_provider_keys WHERE provider = ? LIMIT 1"
            );
            $pk_stmt->execute([$provider]);
            $pk = $pk_stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log("[AiManager] getConfig() provider-key lookup failed: " . $e->getMessage());
        }

        $api_key = '';
        if ($pk && !empty($pk['api_key_enc'])) {
            $decrypted = $this->decryptKey($pk['api_key_enc']);
            if ($decrypted) {
                $api_key = $decrypted;
            } else {
                error_log("[AiManager] Failed to decrypt api_key_enc for provider '{$provider}' — check ALDHRAN_PEPPER constant.");
            }
        }
        $model = ($pk && !empty(trim($pk['model'] ?? '')))
            ? trim($pk['model'])
            : $this->defaultModel($provider);

        $this->aiConfig = [
            'provider'    => $provider,
            'api_key'     => $api_key,
            'api_url'     => $pk['api_url']            ?? '',
            'model'       => $model,
            'max_tokens'  => (int)($row['ai_max_tokens']  ?? 1000),
            'temperature' => (float)($row['ai_temperature'] ?? 0.7),
        ];

        return $this->aiConfig;
    }

    /**
     * Saves key/URL/model for ONE provider without affecting the other
     * providers. An empty $apiKey keeps the previously encrypted key
     * when the ACP form says "Leave blank to keep current."
     */
    public function saveProviderKey(string $provider, string $apiKey, ?string $apiUrl, ?string $model): bool {
        try {
            $enc = null;
            if ($apiKey !== '') {
                $enc = $this->encryptKey($apiKey);
            }

            if ($enc !== null) {
                $stmt = $this->db->prepare("
                    INSERT INTO cms_ai_provider_keys (provider, api_key_enc, api_url, model, updated_at)
                    VALUES (:provider, :key_enc, :api_url, :model, NOW())
                    ON DUPLICATE KEY UPDATE
                        api_key_enc = VALUES(api_key_enc),
                        api_url     = VALUES(api_url),
                        model       = VALUES(model),
                        updated_at  = NOW()
                ");
                return $stmt->execute([
                    ':provider' => $provider,
                    ':key_enc'  => $enc,
                    ':api_url'  => $apiUrl,
                    ':model'    => $model,
                ]);
            }

            $stmt = $this->db->prepare("
                INSERT INTO cms_ai_provider_keys (provider, api_url, model, updated_at)
                VALUES (:provider, :api_url, :model, NOW())
                ON DUPLICATE KEY UPDATE
                    api_url    = VALUES(api_url),
                    model      = VALUES(model),
                    updated_at = NOW()
            ");
            return $stmt->execute([
                ':provider' => $provider,
                ':api_url'  => $apiUrl,
                ':model'    => $model,
            ]);
        } catch (\Throwable $e) {
            error_log("[AiManager] saveProviderKey() failed for '{$provider}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Returns model/URL/"key set?" status for ALL saved providers.
     * The decrypted key is NEVER returned - only a boolean indicating
     * whether one is stored. This lets the ACP frontend show the correct
     * values when the provider dropdown changes without exposing the key.
     */
    public function getAllProviderConfigs(): array {
        $out = [];
        try {
            $rows = $this->db->query(
                "SELECT provider, api_url, model, api_key_enc, updated_at FROM cms_ai_provider_keys"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $out[$r['provider']] = [
                    'api_url'    => $r['api_url'] ?? '',
                    'model'      => $r['model'] ?? '',
                    'has_key'    => !empty($r['api_key_enc']),
                    'updated_at' => $r['updated_at'],
                ];
            }
        } catch (\Throwable $e) {
            error_log("[AiManager] getAllProviderConfigs() failed: " . $e->getMessage());
        }
        return $out;
    }

    private function defaultModel(string $provider): string {
        return match($provider) {
            self::PROVIDER_GEMINI    => 'gemini-2.0-flash',
            self::PROVIDER_GROQ      => 'llama-3.3-70b-versatile',
            self::PROVIDER_LMSTUDIO  => 'local-model',
            self::PROVIDER_OPENAI    => 'gpt-4o',
            self::PROVIDER_ANTHROPIC => 'claude-3-5-sonnet-20240620',
            default                  => '',
        };
    }

    // ── Prompt Builder ─────────────────────────────────────────

    private function buildPrompt(string $module, string $action, array $context): string {
        $system = $this->getSystemPrompt($module);
        $instruction = $context['instruction'] ?? "Analyze and provide a helpful suggestion.";
        unset($context['instruction']);

        // Serialize the context without its instruction field.
        $ctx_str = '';
        if (!empty($context)) {
            $ctx_str = "\n\nContext data:\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return "{$system}\n\nTask: {$action}\nModule: {$module}\n{$ctx_str}\n\nInstruction: {$instruction}";
    }

    private function getSystemPrompt(string $module): string {
        // Load module-specific prompt from DB (set via ACP → AI Config → Module System Prompts)
        try {
            $stmt = $this->db->prepare(
                "SELECT setting_value FROM cms_ai_settings
                 WHERE setting_key = 'system_prompt' AND provider = 'all' AND module_context = ?
                 LIMIT 1"
            );
            $stmt->execute([$module]);
            $db_prompt = $stmt->fetchColumn();
            if (!empty($db_prompt)) return trim($db_prompt);
        } catch (\Throwable $e) {
            // Fall back to the built-in prompts.
        }

        $base = "You are an AI assistant for the DAoC private server CMS. " .
                "You help administrators manage game content, balance items, analyze player data, and improve server quality. " .
                "Always be concise, specific, and actionable. Do not include disclaimers or excessive caveats.";

        $module_context = match($module) {
            'item_creator'       => " You specialize in DAoC item balance, stat utility calculations, and lore writing.",
            'mob_editor'         => " You specialize in DAoC mob spawn balance, AI aggro settings, and creature lore.",
            'suit_creator'       => " You specialize in DAoC gear set optimization and stat distribution across slots.",
            'error_log'          => " You specialize in PHP error analysis, debugging, and fix suggestions.",
            'theme_editor'       => " You specialize in CSS theming for dark-themed game server websites.",
            'translation_editor' => " You specialize in UI localization and translation quality for gaming interfaces.",
            'discord'            => " You answer questions about the server, DAoC game mechanics, and community topics.",
            'core_architect'     => " You specialize in game economy analysis, player retention, and server balance for DAoC.",
            'dqc'                => " You specialize in DAoC DataQuest design, reward balance, and NPC dialogue writing.",
            'warmap'             => " You specialize in DAoC RvR analysis, keep balance, and realm war dynamics.",
            'faq_admin'          => " You specialize in writing clear, helpful FAQ entries for DAoC server websites.",
            'spike_admin'        => " You specialize in gaming community forum management and announcement writing.",
            'um'                 => " You specialize in player account analysis, ban reason documentation, and standing assessment.",
            default              => "",
        };

        return $base . $module_context;
    }

    // ── Provider Calls ─────────────────────────────────────────

    private function callProvider(array $cfg, string $prompt): ?string {
        return match($cfg['provider']) {
            self::PROVIDER_GEMINI    => $this->callGemini($cfg, $prompt),
            self::PROVIDER_ANTHROPIC => $this->callAnthropic($cfg, $prompt),
            self::PROVIDER_GROQ      => $this->callOpenAiCompatible(
                                            $cfg,
                                            $prompt,
                                            'https://api.groq.com/openai/v1/chat/completions'
                                       ),
            self::PROVIDER_LMSTUDIO  => $this->callOpenAiCompatible(
                                            $cfg,
                                            $prompt,
                                            rtrim($cfg['api_url'], '/') . '/chat/completions'
                                       ),
            self::PROVIDER_OPENAI    => $this->callOpenAiCompatible(
                                            $cfg,
                                            $prompt,
                                            'https://api.openai.com/v1/chat/completions'
                                       ),
            default => null,
        };
    }

    private function callGemini(array $cfg, string $prompt): ?string {
        $model   = $cfg['model'] ?: 'gemini-2.0-flash';
        $api_key = $cfg['api_key'];
        $url     = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";

        $payload = [
            'contents'         => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'maxOutputTokens' => $cfg['max_tokens'],
                'temperature'     => $cfg['temperature'],
            ],
        ];

        $response = $this->httpPost($url, $payload, [
            'Content-Type: application/json',
        ]);

        if ($response === null) return null;

        $data = json_decode($response, true);

        // Handle Gemini-specific errors.
        if (isset($data['error'])) {
            error_log("[AiManager] Gemini API error: " . ($data['error']['message'] ?? json_encode($data['error'])));
            return null;
        }

        return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    }

    private function callAnthropic(array $cfg, string $prompt): ?string {
        $endpoint = 'https://api.anthropic.com/v1/messages';
        
        $payload = [
            'model'       => $cfg['model'],
            'max_tokens'  => $cfg['max_tokens'],
            'temperature' => $cfg['temperature'],
            'messages'    => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ];

        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $cfg['api_key'],
            'anthropic-version: 2023-06-01'
        ];

        $response = $this->httpPost($endpoint, $payload, $headers);
        if ($response === null) return null;

        $data = json_decode($response, true);

        // Handle Anthropic-specific errors.
        if (isset($data['error'])) {
            error_log("[AiManager] Anthropic API error: " . ($data['error']['message'] ?? json_encode($data['error'])));
            return null;
        }

        return $data['content'][0]['text'] ?? null;
    }

    private function callOpenAiCompatible(array $cfg, string $prompt, string $endpoint): ?string {
        $payload = [
            'model'       => $cfg['model'],
            'messages'    => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens'  => $cfg['max_tokens'],
            'temperature' => $cfg['temperature'],
        ];

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cfg['api_key'],
        ];

        $response = $this->httpPost($endpoint, $payload, $headers);
        if ($response === null) return null;

        $data = json_decode($response, true);

        // Handle errors from OpenAI-compatible providers.
        if (isset($data['error'])) {
            error_log("[AiManager] API error from {$endpoint}: " . ($data['error']['message'] ?? json_encode($data['error'])));
            return null;
        }

        return $data['choices'][0]['message']['content'] ?? null;
    }

    private function httpPost(string $url, array $payload, array $headers, int $timeout = 30): ?string {
        $ch = curl_init($url);

        // ── Determine the SSL CA bundle ───────────────────────────────────
        // Priority: 1) php.ini curl.cainfo, 2) standard Linux paths
        $ca_bundle   = '';
        $known_paths = [
            '/etc/ssl/certs/ca-certificates.crt',   // Debian/Ubuntu
            '/etc/pki/tls/certs/ca-bundle.crt',     // CentOS/RHEL/Fedora
            '/etc/ssl/ca-bundle.pem',               // openSUSE
            '/usr/local/share/certs/ca-root-nss.crt', // FreeBSD
            '/etc/ssl/cert.pem',                    // Alpine/macOS
        ];

        $ini_cainfo = ini_get('curl.cainfo');
        if (!empty($ini_cainfo) && file_exists($ini_cainfo)) {
            $ca_bundle = $ini_cainfo;
        } else {
            foreach ($known_paths as $path) {
                if (file_exists($path)) { $ca_bundle = $path; break; }
            }
        }

        $curl_opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($ca_bundle) {
            $curl_opts[CURLOPT_CAINFO] = $ca_bundle;
        } else {
            // No CA bundle found. PHP will fall back to the built-in cURL CA bundle if available.
			// If SSL validation still fails, install the system CA certificates:
			// apt install ca-certificates && update-ca-certificates
            error_log("[AiManager] WARNING: No CA bundle file found. Falling back to curl built-in bundle.");
        }

        curl_setopt_array($ch, $curl_opts);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            error_log("[AiManager] cURL error for {$url}: {$curl_err}");
            $this->lastError = "cURL error: {$curl_err}";
            return null;
        }
        if ($http_code < 200 || $http_code >= 300) {
            error_log("[AiManager] HTTP {$http_code} from {$url}: " . substr($response, 0, 500));
            $this->lastError = "HTTP {$http_code}: " . substr($response, 0, 300);
            return null;
        }

        return $response ?: null;
    }

    // ── Response Parser ────────────────────────────────────────

    private function parseResponse(string $raw, string $provider): string {
        // Strip a wrapping ```json ... ``` fence some models add around their output.
        // No /m modifier: only the actual start/end of the whole response, not every
        // line that happens to start with backticks (which would mangle embedded
        // code examples in the AI's answer, e.g. for the error_log module).
        $cleaned = preg_replace('/^```(?:json)?\s*/', '', $raw);
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        return trim($cleaned);
    }

    // ── Logging ────────────────────────────────────────────────

    private function logCall(
        string  $module,
        string  $action,
        string  $prompt,
        string  $response,
        string  $provider,
        int     $duration_ms = 0,
        string  $status      = 'ok'
    ): ?int {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO cms_ai_logs
                    (user_id, module_context, task_type, prompt_text, response_text,
                     provider, status, duration_ms, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $this->userId,
                $module,
                $action,
                substr($prompt, 0, 2000),
                substr($response, 0, 4000),
                $provider,
                $status,
                $duration_ms,
            ]);
            return (int)$this->db->lastInsertId();
        } catch (\Throwable $e) {
            error_log("[AiManager] Log failed: " . $e->getMessage());
            return null;
        }
    }

    private function saveSuggestion(
    string  $module,
    string  $action,
    string  $suggestion,
    array   $context,
    mixed   $target_id,
    ?int    $log_id
): ?int {
    try {
        $target_type = $context['target_type'] ?? $this->guessTargetType($module, $action);

        // Clean up context (remove instruction, it doesn't belong in original_data)
        $clean_context = $context;
        unset($clean_context['instruction']);

        $stmt = $this->db->prepare("
            INSERT INTO cms_ai_suggestions
                (module_context, action_type, suggestion_data, original_data,
                 target_id, target_type, status, user_id, log_id, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
        ");
        $stmt->execute([
            $module,
            $action,
            json_encode(['text' => $suggestion], JSON_UNESCAPED_UNICODE),
            !empty($clean_context) ? json_encode($clean_context, JSON_UNESCAPED_UNICODE) : null,
            $target_id ?: null,
            $target_type,
            $this->userId,
            $log_id,
        ]);
        return (int)$this->db->lastInsertId();
    } catch (\Throwable $e) {
        error_log("[AiManager] Suggestion save failed: " . $e->getMessage());
        return null;
    }
}

    private function guessTargetType(string $module, string $action): string {
        return match($module) {
            'item_creator'       => 'item',
            'mob_editor'         => 'mob',
            'suit_creator'       => 'suit',
            'dqc'                => 'quest',
            'error_log'          => 'error',
            'theme_editor'       => 'css_module',
            'translation_editor' => 'translation',
            'core_architect'     => 'economy_note',
            default              => 'generic',
        };
    }

    // ── Async Task Queue ───────────────────────────────────────

    private function queueTask(string $module, string $action, array $context, array $options): ?int {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO cms_ai_tasks
                    (task_type, module_context, action_type, payload, user_id, status, priority, max_attempts, queued_at)
                VALUES ('ai_request', ?, ?, ?, ?, 'queued', ?, 3, NOW())
            ");
            $stmt->execute([
                $module,
                $action,
                json_encode(['context' => $context, 'options' => $options]),
                $this->userId,
                (int)($options['priority'] ?? 5),
            ]);
            return (int)$this->db->lastInsertId();
        } catch (\Throwable $e) {
            error_log("[AiManager] Queue task failed: " . $e->getMessage());
            return null;
        }
    }

    // ── Daily Limit ────────────────────────────────────────────

    private function checkDailyLimit(array $cfg): bool {
        try {
            $limit_row = $this->db->query("
                SELECT setting_value FROM cms_ai_settings WHERE setting_key = 'daily_call_limit' LIMIT 1
            ")->fetch();
            $limit = $limit_row ? (int)$limit_row['setting_value'] : self::DEFAULT_DAILY_LIMIT;

            $count = (int)$this->db->query("
                SELECT COUNT(*) FROM cms_ai_logs WHERE DATE(created_at) = CURDATE()
            ")->fetchColumn();

            return $count < $limit;
        } catch (\Throwable $e) {
            return true;
        }
    }

    // ── Key Encryption (AES-256-CBC) ───────────────────────────

    private function getEncryptionKey(): string {
        $pepper_const = 'ALDHRAN_PEPPER';
        if (defined($pepper_const)) {
            return substr(hash('sha256', constant($pepper_const), true), 0, 32);
        }
        if (defined('APP_SECRET')) {
            return substr(hash('sha256', APP_SECRET, true), 0, 32);
        }
        error_log("[AiManager] WARNING: ALDHRAN_PEPPER is not defined. Using insecure fallback key!");
        return str_repeat('x', 32);
    }

    private function decryptKey(string $encrypted): ?string {
        try {
            $key     = $this->getEncryptionKey();
            $decoded = base64_decode($encrypted);
            if ($decoded === false || strlen($decoded) < 16) return null;
            $iv     = substr($decoded, 0, 16);
            $data   = substr($decoded, 16);
            $result = openssl_decrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            return $result !== false ? $result : null;
        } catch (\Throwable $e) {
            error_log("[AiManager] decryptKey() error: " . $e->getMessage());
            return null;
        }
    }

    public function encryptKey(string $plain): string {
        $key       = $this->getEncryptionKey();
        $iv        = random_bytes(16);
        $encrypted = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    // ── Helper ─────────────────────────────────────────────────

    private function error(string $message): array {
        error_log("[AiManager] {$message}");
        return ['status' => 'error', 'message' => $message];
    }
}
