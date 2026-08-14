<?php
// SPDX-License-Identifier: GPL-3.0-only
declare(strict_types=1);

function daoc_bridge_config_secret(array $settings): string
{
    foreach ([
        'game_server_shared_secret',
        'game_server_bridge_secret',
        'game_server_console_secret',
        'igc_api_secret',
    ] as $key) {
        $value = trim((string)($settings[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return defined('ASP_KEY') ? trim((string)ASP_KEY) : '';
}

function daoc_bridge_config_content(string $cmsApiUrl, string $sharedSecret, int $bridgePort): string
{
    $cmsApiUrl = trim($cmsApiUrl);
    $sharedSecret = trim($sharedSecret);
    $scheme = strtolower((string)parse_url($cmsApiUrl, PHP_URL_SCHEME));

    if (preg_match('/[\r\n]/', $cmsApiUrl)
        || filter_var($cmsApiUrl, FILTER_VALIDATE_URL) === false
        || !in_array($scheme, ['http', 'https'], true)) {
        throw new InvalidArgumentException('The CMS API URL must be an absolute HTTP or HTTPS URL.');
    }

    if ($sharedSecret === ''
        || preg_match('/[\r\n]/', $sharedSecret)
        || stripos($sharedSecret, 'CHANGE_ME') !== false) {
        throw new InvalidArgumentException('Configure a valid shared secret before downloading the bridge configuration.');
    }

    if ($bridgePort < 1 || $bridgePort > 65535) {
        throw new InvalidArgumentException('The bridge port must be between 1 and 65535.');
    }

    return implode("\n", [
        'ConfigVersion=1',
        'CmsApiUrl=' . $cmsApiUrl,
        'SharedSecret=' . $sharedSecret,
        'BridgePort=' . $bridgePort,
        '',
    ]);
}
