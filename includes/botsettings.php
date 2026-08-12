<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * BotSettings.php – DAoC CMS
 */
class BotSettings {

    private PDO $db;
    public array $data = [];

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->load();
    }

    // ── Public: reload settings (e.g. after save) ─────
    public function load(): void {
        try {
            $stmt = $this->db->query("SELECT * FROM cms_bot_settings WHERE id = 1");
            $row  = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->data = $row ?: [];
        } catch (\Throwable $e) {
            error_log("BotSettings::load() failed: " . $e->getMessage());
            $this->data = [];
        }
    }

    // ── Save settings ───────────────────────────────
    /**
     * ai_api_key_enc is intentionally not overwritten here.
     * Encryption runs via acp_bot_settings_view.php → AiManager::encryptKey().
     * save() here is only used for legacy/direct calls.
     */
    public function save(array $postData): bool {
        $sql = "UPDATE cms_bot_settings SET
                    ai_provider          = :ai_provider,
                    ai_api_url           = :ai_api_url,
                    ai_model             = :ai_model,
                    discord_token        = :discord_token,
                    socket_secret        = :socket_secret,
                    bot_host             = :bot_host,
                    bot_port             = :bot_port,
                    bot_channel_id       = :bot_channel_id,
                    admin_role_id        = :admin_role_id,
                    reboot_delay_default = :reboot_delay_default,
                    use_tls              = :use_tls,
                    is_active            = :is_active
                WHERE id = 1";

        // Note: ai_api_key and ai_api_key_enc are NOT set here.
        // Encryption and storage of the key happens exclusively via
        // acp_bot_settings_view.php (POST tab=ai) with AiManager::encryptKey().

        $stmt = $this->db->prepare($sql);
        $ok   = $stmt->execute([
            ':ai_provider'          => $postData['ai_provider']    ?? 'none',
            ':ai_api_url'           => $postData['ai_api_url']     ?? 'http://localhost:1234/v1',
            ':ai_model'             => $postData['ai_model']       ?? '',
            ':discord_token'        => $postData['discord_token']  ?? '',
            ':socket_secret'        => $postData['socket_secret']  ?? '',
            ':bot_host'             => $postData['bot_host']       ?? '127.0.0.1',
            ':bot_port'             => max(1, min(65535, (int)($postData['bot_port'] ?? 15000))),
            ':bot_channel_id'       => $postData['bot_channel_id']  ?? '',
            ':admin_role_id'        => $postData['admin_role_id']   ?? '',
            ':reboot_delay_default' => max(0, (int)($postData['reboot_delay_default'] ?? 5)),
            ':use_tls'              => isset($postData['use_tls']) ? 1 : 0,
            ':is_active'            => isset($postData['is_active']) ? 1 : 0,
        ]);

        if ($ok) $this->load();
        return $ok;
    }

    // ── AI Endpoint URL ───────────────────────────────────────
    /**
     * Provider string matching uses consistent strings matching
     * AiManager::PROVIDER_* to avoid mismatches (e.g. 'lmstudio' vs 'lm_studio').
     */
    public function getAiEndpoint(): string {
        $provider = $this->data['ai_provider'] ?? 'none';

        if ($provider === 'gemini') {
            $key = $this->data['ai_api_key'] ?? '';
            return "https://generativelanguage.googleapis.com/v1beta/models/"
                 . ($this->data['ai_model'] ?: 'gemini-2.0-flash')
                 . ":generateContent?key=" . urlencode($key);
        }

        if ($provider === 'groq') {
            return "https://api.groq.com/openai/v1/chat/completions";
        }

        // lm_studio or a custom OpenAI-compatible endpoint
        $base = rtrim($this->data['ai_api_url'] ?? 'http://localhost:1234/v1', '/');
        // Ensure /chat/completions isn't appended twice
        if (str_ends_with($base, '/chat/completions')) {
            return $base;
        }
        return $base . '/chat/completions';
    }

    // ── Send command to the Discord bot via socket ───────────────
    /**
     * @param string $command  e.g. 'broadcast', 'reboot', 'ping'
     * @param array  $params   Optional parameters
     * @param int    $timeout  Socket timeout in seconds
     * @return array ['status' => 'ok'|'error', 'result' => ..., 'message' => ...]
     */
    public function sendCommand(string $command, array $params = [], int $timeout = 3): array {
        if (empty($this->data['is_active'])) {
            return ['status' => 'error', 'message' => 'Bot is disabled.'];
        }

        $host   = $this->data['bot_host']      ?? '127.0.0.1';
        $port   = (int)($this->data['bot_port'] ?? 15000);
        $useTls = !empty($this->data['use_tls']);

        $payload = $this->buildPayload($command, $params);

        try {
            if ($useTls) {
                $response = $this->sendTls($host, $port, $payload, $timeout);
            } else {
                $response = $this->sendPlain($host, $port, $payload, $timeout);
            }
        } catch (\Throwable $e) {
            error_log("BotSettings::sendCommand() failed [$command]: " . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        if ($response === null) {
            return ['status' => 'error', 'message' => 'No response from bot (timeout).'];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return ['status' => 'error', 'message' => 'Invalid JSON response from bot.'];
        }

        return $decoded;
    }

    // ── Build payload ─────────────────────────────────
    private function buildPayload(string $command, array $params): string {
        $timestamp = time();
        $secret    = $this->data['socket_secret'] ?? '';

        // HMAC over command + timestamp (not the Discord token!)
        $signature = hash_hmac('sha256', $command . $timestamp, $secret);

        return json_encode([
            'version'   => '1.0',
            'action'    => $command,
            'params'    => $params,
            'meta'      => ['timestamp' => $timestamp],
            'signature' => $signature,
        ]) . "\n"; // Newline as frame delimiter
    }

    // ── Plain Socket (localhost / same-server) ────────────────
    private function sendPlain(string $host, int $port, string $payload, int $timeout): ?string {
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$fp) {
            throw new \RuntimeException("Socket connect failed: $errstr ($errno)");
        }

        stream_set_timeout($fp, $timeout);
        fwrite($fp, $payload);

        $response = '';
        $meta     = stream_get_meta_data($fp);

        while (!feof($fp) && !$meta['timed_out']) {
            $line = fgets($fp, 4096);
            if ($line === false) break;
            $response .= $line;
            // Stop once JSON looks complete (simple heuristic)
            if (substr(trim($response), -1) === '}') break;
            $meta = stream_get_meta_data($fp);
        }

        fclose($fp);
        return $response !== '' ? $response : null;
    }

    // ── TLS Socket (remote bot) ───────────────────────────────
    private function sendTls(string $host, int $port, string $payload, int $timeout): ?string {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'cafile'           => '/etc/ssl/certs/ca-certificates.crt',
            ],
        ]);

        $fp = @stream_socket_client(
            "ssl://$host:$port",
            $errno, $errstr, $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$fp) {
            throw new \RuntimeException("TLS connect failed: $errstr ($errno)");
        }

        stream_set_timeout($fp, $timeout);
        fwrite($fp, $payload);

        $response = '';
        $meta     = stream_get_meta_data($fp);

        while (!feof($fp) && !$meta['timed_out']) {
            $line = fgets($fp, 4096);
            if ($line === false) break;
            $response .= $line;
            if (substr(trim($response), -1) === '}') break;
            $meta = stream_get_meta_data($fp);
        }

        fclose($fp);
        return $response !== '' ? $response : null;
    }

    // ── Verify HMAC signature (for incoming responses) ────────
    public function verifySignature(string $command, int $timestamp, string $signature): bool {
        $secret   = $this->data['socket_secret'] ?? '';
        $expected = hash_hmac('sha256', $command . $timestamp, $secret);

        // Debug logging runs only when CMS_DEBUG is enabled to avoid
        // leaking signature material to the error log in production.
        if (defined('CMS_DEBUG') && CMS_DEBUG) {
            error_log(sprintf(
                "SIG DEBUG | cmd=%s ts=%d now=%d drift=%d secret_set=%s secret_len=%d expected=%s got=%s match=%s",
                $command,
                $timestamp,
                time(),
                time() - $timestamp,
                ($secret !== '' ? 'yes' : 'no'),
                strlen($secret),
                $expected,
                $signature,
                hash_equals($expected, $signature) ? 'yes' : 'no'
            ));
        }

        // Replay protection: timestamp must not be older than 60s
        if (abs(time() - $timestamp) > 60) {
            error_log("SIG DEBUG | REJECTED: timestamp drift too large ($command)");
            return false;
        }

        return hash_equals($expected, $signature);
    }

    // ── Helper methods ─────────────────────────────────────────
    public function isActive(): bool {
        return !empty($this->data['is_active']);
    }

    public function getProvider(): string {
        return $this->data['ai_provider'] ?? 'none';
    }

    /**
     * Checks whether ai_api_key_enc OR ai_api_key is set.
     */
    public function hasAiConfigured(): bool {
        return $this->getProvider() !== 'none'
            && (
                !empty($this->data['ai_api_key_enc'])
                || !empty($this->data['ai_api_key'])
            );
    }
}
