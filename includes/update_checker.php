<?php
if (!defined('IN_ACP')) exit;

const DAOC_CMS_GITHUB_REPO = 'Darku11/daoc_cms';
const DAOC_CMS_GITHUB_API  = 'https://api.github.com/repos/' . DAOC_CMS_GITHUB_REPO;

function daoc_cms_local_version(): string
{
    $versionFile = dirname(__DIR__) . '/VERSION';
    if (!is_file($versionFile)) return 'unknown';

    $version = trim((string) @file_get_contents($versionFile));
    return $version !== '' ? $version : 'unknown';
}

function daoc_cms_normalize_version(string $version): string
{
    $version = trim($version);
    $version = preg_replace('/^v/i', '', $version);
    $version = preg_replace_callback(
        '/[\s_-]*(rc|beta|alpha)[\s_-]*(\d+)/i',
        static fn(array $m): string => '-' . strtolower($m[1]) . $m[2],
        (string) $version
    );
    $version = preg_replace('/\s+/', '.', (string) $version);
    return trim((string) $version, '.-');
}

function daoc_cms_local_git_sha(): ?string
{
    $gitDir = dirname(__DIR__) . '/.git';
    $headFile = $gitDir . '/HEAD';
    if (!is_file($headFile)) return null;

    $head = trim((string) @file_get_contents($headFile));
    if ($head === '') return null;

    if (preg_match('/^[0-9a-f]{40}$/i', $head)) {
        return strtolower($head);
    }

    if (!str_starts_with($head, 'ref: ')) return null;

    $ref = trim(substr($head, 5));
    if ($ref === '' || str_contains($ref, '..')) return null;

    $refFile = $gitDir . '/' . $ref;
    if (is_file($refFile)) {
        $sha = trim((string) @file_get_contents($refFile));
        if (preg_match('/^[0-9a-f]{40}$/i', $sha)) return strtolower($sha);
    }

    $packedRefs = $gitDir . '/packed-refs';
    if (is_file($packedRefs)) {
        $lines = @file($packedRefs, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            if ($line[0] === '#' || $line[0] === '^') continue;
            [$sha, $packedRef] = array_pad(preg_split('/\s+/', trim($line), 2), 2, '');
            if ($packedRef === $ref && preg_match('/^[0-9a-f]{40}$/i', $sha)) {
                return strtolower($sha);
            }
        }
    }

    return null;
}

function daoc_cms_github_request(string $url): ?array
{
    if (!extension_loaded('curl')) return null;

    $ch = curl_init($url);
    if ($ch === false) return null;

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: DAoC-CMS-ACP',
        ],
    ]);

    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($body) || $http < 200 || $http >= 300) return null;

    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

function daoc_cms_update_status(bool $forceRefresh = false): array
{
    $root = dirname(__DIR__);
    $cacheDir = $root . '/cache';
    $cacheFile = $cacheDir . '/acp_github_status.json';
    $cacheTtl = 900;

    if (!$forceRefresh && is_file($cacheFile) && (time() - (int) @filemtime($cacheFile)) < $cacheTtl) {
        $cached = json_decode((string) @file_get_contents($cacheFile), true);
        if (is_array($cached)) return $cached;
    }

    $localVersion = daoc_cms_local_version();
    $localSha = daoc_cms_local_git_sha();

    $releases = daoc_cms_github_request(DAOC_CMS_GITHUB_API . '/releases?per_page=10');
    $commits  = daoc_cms_github_request(DAOC_CMS_GITHUB_API . '/commits?sha=main&per_page=8');

    $officialRelease = null;
    if (is_array($releases)) {
        foreach ($releases as $release) {
            if (!is_array($release) || !empty($release['draft'])) continue;
            $officialRelease = $release;
            break;
        }
    }

    $latestVersion = null;
    $updateAvailable = false;
    if ($officialRelease !== null) {
        $tag = (string) ($officialRelease['tag_name'] ?? '');
        if ($tag !== '') {
            $latestVersion = $tag;
            if ($localVersion !== 'unknown') {
                $updateAvailable = version_compare(
                    daoc_cms_normalize_version($tag),
                    daoc_cms_normalize_version($localVersion),
                    '>'
                );
            }
        }
    }

    $recentCommits = [];
    $localCommitSeen = $localSha === null;
    if (is_array($commits)) {
        foreach ($commits as $commit) {
            if (!is_array($commit)) continue;

            $sha = strtolower((string) ($commit['sha'] ?? ''));
            if ($localSha !== null && $sha === $localSha) {
                $localCommitSeen = true;
            }

            $message = (string) ($commit['commit']['message'] ?? '');
            $message = trim(strtok($message, "\r\n") ?: $message);
            $recentCommits[] = [
                'sha'     => $sha,
                'short'   => substr($sha, 0, 7),
                'message' => $message,
                'date'    => (string) ($commit['commit']['committer']['date'] ?? ''),
                'url'     => (string) ($commit['html_url'] ?? ''),
                'new'     => $localSha !== null && !$localCommitSeen,
            ];
        }
    }

    $result = [
        'checked_at'        => time(),
        'reachable'         => is_array($releases) || is_array($commits),
        'local_version'     => $localVersion,
        'local_sha'         => $localSha,
        'official_release'  => $officialRelease === null ? null : [
            'name'       => (string) ($officialRelease['name'] ?? ''),
            'tag'        => (string) ($officialRelease['tag_name'] ?? ''),
            'url'        => (string) ($officialRelease['html_url'] ?? ''),
            'published'  => (string) ($officialRelease['published_at'] ?? ''),
            'prerelease' => !empty($officialRelease['prerelease']),
        ],
        'latest_version'    => $latestVersion,
        'update_available'  => $updateAvailable,
        'recent_commits'    => $recentCommits,
        'has_new_commits'   => $localSha !== null && !$localCommitSeen,
    ];

    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    if (is_dir($cacheDir) && is_writable($cacheDir)) {
        @file_put_contents($cacheFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    return $result;
}
