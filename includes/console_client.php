<?php
// SPDX-License-Identifier: GPL-3.0-only
declare(strict_types=1);

if (defined('DAOC_CONSOLE_CLIENT_LOADED')) {
    return;
}
define('DAOC_CONSOLE_CLIENT_LOADED', true);

/**
 * Resolve the canonical AldhranConsole connection while preserving RC1/RC2
 * settings as upgrade fallbacks.
 *
 * @return array{base_url:string,secret:string}
 */
function aldhran_console_config(?array $settings = null): array
{
    $settings ??= $GLOBALS['cms_settings'] ?? [];

    $sharedSecret = trim((string)($settings['game_server_shared_secret'] ?? ''));
    $consoleSecret = trim((string)($settings['game_server_console_secret'] ?? ''));
    $legacySecret = trim((string)($settings['igc_api_secret'] ?? ''));

    $secret = $sharedSecret !== ''
        ? $sharedSecret
        : ($consoleSecret !== ''
            ? $consoleSecret
            : ($legacySecret !== ''
                ? $legacySecret
                : (defined('ASP_KEY') ? trim((string)ASP_KEY) : '')));

    // If an existing installation only configured the old IGC pair, keep that
    // URL together with its matching secret. Fresh/current installations use
    // game_server_console_host + game_server_console_port.
    if ($sharedSecret === '' && $consoleSecret === '' && $legacySecret !== '') {
        $legacyUrl = trim((string)($settings['igc_service_url'] ?? ''));
        if ($legacyUrl !== '') {
            return [
                'base_url' => rtrim($legacyUrl, '/'),
                'secret' => $secret,
            ];
        }
    }

    $host = trim((string)($settings['game_server_console_host'] ?? '127.0.0.1'));
    $port = (int)($settings['game_server_console_port'] ?? 5100);
    if ($port < 1 || $port > 65535) {
        $port = 5100;
    }

    if (preg_match('#^https?://#i', $host)) {
        $baseUrl = rtrim($host, '/');
    } else {
        $host = $host !== '' ? $host : '127.0.0.1';
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = '[' . trim($host, '[]') . ']';
        }
        $baseUrl = 'http://' . $host . ':' . $port;
    }

    return ['base_url' => $baseUrl, 'secret' => $secret];
}

/**
 * Call AldhranConsole through one hardened HTTP boundary shared by the ACP,
 * itemshop, Discord relay, restart endpoint and delivery worker.
 */
function aldhran_console_call(
    string $endpoint,
    array $payload = [],
    string $method = 'POST',
    int $timeoutSeconds = 8
): array {
    $config = aldhran_console_config();
    if ($config['secret'] === '') {
        return ['ok' => false, 'error' => 'AldhranConsole secret is not configured.'];
    }

    $method = strtoupper($method);
    if (!in_array($method, ['GET', 'POST'], true)) {
        return ['ok' => false, 'error' => 'Unsupported console request method.'];
    }

    $url = $config['base_url'] . '/' . ltrim($endpoint, '/');
    if ($method === 'GET' && $payload !== []) {
        $url .= '?' . http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
    }

    $handle = curl_init($url);
    if ($handle === false) {
        return ['ok' => false, 'error' => 'Could not initialize the console request.'];
    }

    $headers = [
        'Accept: application/json',
        'X-Aldhran-Secret: ' . $config['secret'],
    ];

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => min(5, max(1, $timeoutSeconds)),
        CURLOPT_TIMEOUT => max(1, min(30, $timeoutSeconds)),
        CURLOPT_HTTPHEADER => $headers,
    ];

    if ($method === 'POST') {
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            curl_close($handle);
            return ['ok' => false, 'error' => 'Could not encode the console request.'];
        }

        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $body;
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }

    curl_setopt_array($handle, $options);
    $raw = curl_exec($handle);
    $curlError = curl_error($handle);
    $httpCode = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if ($raw === false || $curlError !== '') {
        error_log('AldhranConsole request failed: ' . $curlError);
        return ['ok' => false, 'error' => 'AldhranConsole is unreachable.'];
    }

    try {
        $data = json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        error_log('AldhranConsole returned invalid JSON (HTTP ' . $httpCode . ').');
        return ['ok' => false, 'error' => 'AldhranConsole returned an invalid response.'];
    }

    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'AldhranConsole returned an invalid response.'];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return [
            'ok' => false,
            'error' => (string)($data['error'] ?? ('AldhranConsole returned HTTP ' . $httpCode . '.')),
        ];
    }

    return $data;
}
