<?php
// SPDX-License-Identifier: GPL-3.0-only
if (!defined('IN_CMS')) {
    define('IN_CMS', true);
    require_once dirname(__DIR__) . '/includes/db.php';
}

aldhran_rate_limit('model_proxy_' . ($_SERVER['REMOTE_ADDR'] ?? '0'), 60, 60);

$modelId = (int)($_GET['model'] ?? 0);
if ($modelId <= 0 || $modelId > 99999) {
    http_response_code(400);
    exit;
}

$cacheDir  = __DIR__ . '/../assets/img/mobs/';
$cachePath = $cacheDir . 'model_' . $modelId . '.png';
$cacheUrl  = 'assets/img/mobs/model_' . $modelId . '.png';

// Create cache directory
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

// Cached image present?
if (file_exists($cachePath)) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=2592000'); // 30 days
    readfile($cachePath);
    exit;
}

// External sources (try in this order)
$sources = [
    "https://api.camelotherald.com/img/npcs/{$modelId}.png",
    "https://www.camelotherald.com/images/models/{$modelId}.gif",
];

$imageData = null;
foreach ($sources as $url) {
    $ctx = stream_context_create([
        'http' => [
            'timeout'         => 5,
            'user_agent'      => 'DAoC-CMS (DAoC Freeshard)',
            'ignore_errors'   => true,
        ]
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data && strlen($data) > 500) {
        // Check whether it's actually an image
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_buffer($finfo, $data);
        finfo_close($finfo);
        if (strpos($mime, 'image/') === 0) {
            $imageData = $data;
            break;
        }
    }
}

if ($imageData) {
    // Save locally
    file_put_contents($cachePath, $imageData);
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=2592000');
    echo $imageData;
} else {
    // Fallback: 1x1 transparent PNG
    http_response_code(404);
    header('Content-Type: image/png');
    // Minimal transparent 1x1 PNG
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
}
