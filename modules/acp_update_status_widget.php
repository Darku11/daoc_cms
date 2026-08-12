<?php
if (!defined('IN_ACP')) exit;

require_once __DIR__ . '/../includes/update_checker.php';

$updateStatus = daoc_cms_update_status(isset($_GET['refresh_updates']));
$release      = $updateStatus['official_release'] ?? null;
$commits      = array_slice($updateStatus['recent_commits'] ?? [], 0, 5);
$localVersion = (string) ($updateStatus['local_version'] ?? 'unknown');
$localSha     = $updateStatus['local_sha'] ?? null;
?>

<?php if (!empty($updateStatus['update_available']) && is_array($release)): ?>
<div class="d-warn" style="border-color:rgba(101,200,132,0.35);background:rgba(101,200,132,0.05);color:#65c884;">
    <i class="fas fa-cloud-download-alt"></i>
    <strong>DAoC CMS <?= h($release['tag']) ?> is available.</strong>
    Installed: <?= h($localVersion) ?>.
    <?php if (!empty($release['url'])): ?>
        <a href="<?= h($release['url']) ?>" target="_blank" rel="noopener noreferrer" style="color:#65c884;">View release →</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="d-cols" style="margin-bottom:18px;">
    <div class="d-window">
        <div class="d-window-bar">
            <span><i class="fas fa-code-branch"></i> CMS Version</span>
            <a href="acp.php?s=dashboard&refresh_updates=1">Check now &raquo;</a>
        </div>
        <div class="d-window-body acp-s-87f683d7">
            <div class="d-row">
                <div class="d-row-lbl">Installed version</div>
                <div class="d-row-val"><strong><?= h($localVersion) ?></strong></div>
            </div>

            <?php if (is_array($release)): ?>
                <div class="d-row">
                    <div class="d-row-lbl">
                        Latest developer-approved release
                        <?php if (!empty($release['prerelease'])): ?>
                            <span style="opacity:.6;">(pre-release)</span>
                        <?php endif; ?>
                    </div>
                    <div class="d-row-val">
                        <?php if (!empty($release['url'])): ?>
                            <a href="<?= h($release['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($release['tag']) ?></a>
                        <?php else: ?>
                            <?= h($release['tag']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="d-row">
                    <div class="d-row-lbl">Status</div>
                    <div class="d-row-val" style="color:<?= !empty($updateStatus['update_available']) ? '#65c884' : 'inherit' ?>;">
                        <?= !empty($updateStatus['update_available']) ? 'Update available' : 'Up to date' ?>
                    </div>
                </div>
            <?php elseif (!empty($updateStatus['reachable'])): ?>
                <div class="d-row">
                    <div class="d-row-lbl">Official release channel</div>
                    <div class="d-row-val">No published release yet</div>
                </div>
            <?php else: ?>
                <div class="d-row">
                    <div class="d-row-lbl">GitHub status</div>
                    <div class="d-row-val" style="color:#bfa267;">Unavailable</div>
                </div>
            <?php endif; ?>

            <div class="d-row">
                <div class="d-row-lbl">Release policy</div>
                <div class="d-row-val" style="font-size:.88em;max-width:58%;text-align:right;">
                    New versions appear here only after a GitHub Release has been published.
                </div>
            </div>
        </div>
    </div>

    <div class="d-window">
        <div class="d-window-bar">
            <span><i class="fas fa-code-commit"></i> Repository Commits</span>
            <a href="https://github.com/Darku11/daoc_cms/commits/main" target="_blank" rel="noopener noreferrer">View All &raquo;</a>
        </div>
        <div class="d-window-body acp-s-87f683d7">
            <?php if (empty($commits)): ?>
                <div class="d-row"><div class="d-row-lbl">No commit data available.</div></div>
            <?php else: ?>
                <?php foreach ($commits as $commit): ?>
                    <div class="d-row">
                        <div class="d-row-lbl" style="min-width:0;">
                            <?php if (!empty($commit['new'])): ?>
                                <span style="color:#65c884;font-size:.75em;font-weight:700;margin-right:6px;">NEW</span>
                            <?php endif; ?>
                            <a href="<?= h($commit['url']) ?>" target="_blank" rel="noopener noreferrer" title="<?= h($commit['sha']) ?>">
                                <code><?= h($commit['short']) ?></code>
                            </a>
                            <span title="<?= h($commit['message']) ?>"><?= h($commit['message']) ?></span>
                        </div>
                        <div class="d-row-val" style="white-space:nowrap;font-size:.82em;">
                            <?php
                            $commitTime = !empty($commit['date']) ? strtotime($commit['date']) : false;
                            echo $commitTime ? h(date('d.m. H:i', $commitTime)) : '—';
                            ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($localSha !== null): ?>
                <div class="d-row">
                    <div class="d-row-lbl">Local Git revision</div>
                    <div class="d-row-val"><code><?= h(substr((string) $localSha, 0, 7)) ?></code></div>
                </div>
            <?php else: ?>
                <div class="d-row">
                    <div class="d-row-lbl">Local Git revision</div>
                    <div class="d-row-val" style="font-size:.82em;">Not available (for example ZIP installs)</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
