<?php
// SPDX-License-Identifier: GPL-3.0-only
// ── Access control ──────────────────────────────────────────
// CLI: allowed
// HTTP: localhost only (internal use)
if (PHP_SAPI !== 'cli') {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remote, ['127.0.0.1', '::1', 'localhost'])) {
        http_response_code(403);
        die("Forbidden: cron worker is CLI-only.\n");
    }
}

define('IN_CMS', true);
define('CRON_WORKER', true);

// ── Bootstrap ────────────────────────────────────────────────
$db_path = __DIR__ . '/includes/db.php';
if (!file_exists($db_path)) {
    error_log("[cron_ai_worker] db.php not found at: $db_path");
    exit(1);
}
require_once $db_path;

// Load AiSuggestionApplyHandler
$handler_path = __DIR__ . '/includes/AiSuggestionApplyHandler.php';
if (!file_exists($handler_path)) {
    error_log("[cron_ai_worker] AiSuggestionApplyHandler.php not found");
    exit(1);
}
require_once $handler_path;

// Load BotSettings + BotEventDispatcher
$settings_path    = __DIR__ . '/includes/botsettings.php';
$dispatcher_path  = __DIR__ . '/includes/BotEventDispatcher.php';
$hasDispatcher    = file_exists($settings_path) && file_exists($dispatcher_path);
if ($hasDispatcher) {
    require_once $settings_path;
    require_once $dispatcher_path;
}

// ── Lock file: prevents parallel execution ────────────────────
$lock_file = sys_get_temp_dir() . '/aldhran_ai_worker.lock';
$lock_fp   = fopen($lock_file, 'w');

if (!flock($lock_fp, LOCK_EX | LOCK_NB)) {
    fclose($lock_fp);
    exit(0);
}

// Release the lock even on fatal errors or unexpected exits.
register_shutdown_function(function() use ($lock_fp) {
    @flock($lock_fp, LOCK_UN);
    @fclose($lock_fp);
});

// ── Configuration ────────────────────────────────────────────
$BATCH_SIZE   = 5;   // Tasks per run
$MAX_RUNTIME  = 55;  // Seconds (safely below one minute)
$SYSTEM_USER  = 0;   // System user ID (not a real user)
$SYSTEM_PRIV  = 5;   // Super admin privileges for the apply handler

$start_time = time();

// ── Worker log helper ────────────────────────────────────────
function worker_log(string $message): void {
    $ts = date('Y-m-d H:i:s');
    $line = "[{$ts}] [cron_ai_worker] {$message}";
    error_log($line);
    if (PHP_SAPI === 'cli') echo $line . "\n";
}

worker_log("Worker started. Batch size: {$BATCH_SIZE}");

// ── Initialize handlers and dispatcher ────────────────────────
$applyHandler = new AiSuggestionApplyHandler($db, $SYSTEM_USER, $SYSTEM_PRIV);
$botSettings  = $hasDispatcher ? new BotSettings($db) : null;
$dispatcher   = $hasDispatcher ? new BotEventDispatcher($db, $botSettings) : null;

// ── Task types processed by this worker ───────────────────────
$SUPPORTED_TASK_TYPES = [
    'apply_suggestion',  // Apply accepted AI suggestion
    'bot_broadcast',     // Send broadcast via Discord bot
    'expire_cleanup',    // Clean up expired suggestions
];

// ── Main loop ─────────────────────────────────────────────────
$processed = 0;
$errors    = 0;

try {
    // Load queued tasks (priority ASC = higher priority first)
    $stmt = $db->prepare("
        SELECT *
        FROM   cms_ai_tasks
        WHERE  status   = 'queued'
          AND  task_type IN (" . implode(',', array_fill(0, count($SUPPORTED_TASK_TYPES), '?')) . ")
          AND  attempts < max_attempts
        ORDER  BY priority ASC, queued_at ASC
        LIMIT  ?
    ");
    $params = array_merge($SUPPORTED_TASK_TYPES, [$BATCH_SIZE]);
    $stmt->execute($params);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    worker_log("Found " . count($tasks) . " queued tasks.");

    foreach ($tasks as $task) {
       // Check the runtime limit
        if ((time() - $start_time) >= $MAX_RUNTIME) {
            worker_log("Max runtime reached, stopping.");
            break;
        }

        $task_id   = $task['task_id'];
        $task_type = $task['task_type'];

        // Set to 'processing'
        $db->prepare("
            UPDATE cms_ai_tasks
            SET    status     = 'processing',
                   started_at = NOW(),
                   attempts   = attempts + 1
            WHERE  task_id = ?
        ")->execute([$task_id]);

        worker_log("Processing task [{$task_id}] type=[{$task_type}] attempt=" . ($task['attempts'] + 1));

        try {
            $result = null;

            // ── apply_suggestion ──────────────────────────────
            if ($task_type === 'apply_suggestion') {
                $result = $applyHandler->applyFromTask($task);

                if ($result['ok']) {
                    // Notify bot
                    if ($dispatcher) {
                        $payload = json_decode($task['payload'] ?? '{}', true);
                        $sugg_id = (int)($payload['suggestion_id'] ?? 0);
                        if ($sugg_id) {
                            $sugg_stmt = $db->prepare("SELECT * FROM cms_ai_suggestions WHERE id = ?");
                            $sugg_stmt->execute([$sugg_id]);
                            $sugg_row = $sugg_stmt->fetch(PDO::FETCH_ASSOC);
                            if ($sugg_row) {
                                try {
                                    $dispatcher->onAiTaskDone($task);
                                } catch (\Throwable $e) {
                                    worker_log("Bot dispatch failed for task [{$task_id}]: " . $e->getMessage());
                                }
                            }
                        }
                    }
                    worker_log("Task [{$task_id}] applied successfully: " . $result['message']);
                } else {
                    worker_log("Task [{$task_id}] apply failed: " . $result['message']);
                    throw new \RuntimeException($result['message']);
                }
            }

            // ── bot_broadcast ─────────────────────────────────
            elseif ($task_type === 'bot_broadcast') {
                $payload = json_decode($task['payload'] ?? '{}', true);
                $message = $payload['message'] ?? '';

                if ($message && $dispatcher) {
                    $br_result = $dispatcher->onBroadcast($message, $SYSTEM_USER);
                    if (($br_result['status'] ?? '') !== 'ok') {
                        throw new \RuntimeException("Broadcast failed: " . ($br_result['message'] ?? '?'));
                    }
                    $result = ['ok' => true, 'message' => 'Broadcast sent.'];
                    worker_log("Task [{$task_id}] broadcast sent.");
                } else {
                    $result = ['ok' => false, 'message' => 'No message or dispatcher unavailable.'];
                    throw new \RuntimeException($result['message']);
                }
            }

            // ── expire_cleanup ────────────────────────────────
            elseif ($task_type === 'expire_cleanup') {
                $expired = $db->exec("
                    UPDATE cms_ai_suggestions
                    SET    status = 'expired'
                    WHERE  status = 'pending'
                      AND  expires_at IS NOT NULL
                      AND  expires_at < NOW()
                ");
                $result = ['ok' => true, 'message' => "Expired {$expired} suggestions."];
                worker_log("Task [{$task_id}] cleanup: {$expired} suggestions expired.");
            }

            // ── Finalize task: done ──────────────────────────
            $db->prepare("
                UPDATE cms_ai_tasks
                SET    status      = 'done',
                       result      = ?,
                       finished_at = NOW()
                WHERE  task_id = ?
            ")->execute([json_encode($result), $task_id]);

            $processed++;

        } catch (\Throwable $e) {
            $errors++;
            $attempts    = (int)$task['attempts'] + 1;
            $max_attempts = (int)$task['max_attempts'];
            $new_status  = $attempts >= $max_attempts ? 'failed' : 'queued';

            worker_log("Task [{$task_id}] error (attempt {$attempts}/{$max_attempts}): " . $e->getMessage());

            $db->prepare("
                UPDATE cms_ai_tasks
                SET    status        = ?,
                       error_message = ?,
                       finished_at   = IF(? = 'failed', NOW(), NULL)
                WHERE  task_id = ?
            ")->execute([$new_status, $e->getMessage(), $new_status, $task_id]);

            // If permanently failed: mark the associated suggestion as expired
            if ($new_status === 'failed' && $task_type === 'apply_suggestion') {
                try {
                    $payload = json_decode($task['payload'] ?? '{}', true);
                    $sugg_id = (int)($payload['suggestion_id'] ?? 0);
                    if ($sugg_id) {
                        $fail_note = ' [Worker failed after ' . $attempts . ' attempts: ' . substr($e->getMessage(), 0, 100) . ']';
                        $db->prepare("
                            UPDATE cms_ai_suggestions
                            SET    status     = 'expired',
                                   review_note = CONCAT(COALESCE(review_note,''), ?)
                            WHERE  id = ? AND status = 'accepted'
                        ")->execute([$fail_note, $sugg_id]);
                        worker_log("Suggestion #{$sugg_id} marked expired due to permanent task failure.");
                    }
                } catch (\Throwable $inner) {
                    worker_log("Failed to expire suggestion: " . $inner->getMessage());
                }
            }
        }
    }

    // ── Store daily statistics in the settings table ───────────
    try {
        $today_done = (int)$db->query("
            SELECT COUNT(*) FROM cms_ai_tasks
            WHERE status = 'done' AND DATE(finished_at) = CURDATE()
        ")->fetchColumn();

        $db->prepare("
            INSERT INTO settings (setting_key, value)
            VALUES ('ai_tasks_today', ?)
            ON DUPLICATE KEY UPDATE value = VALUES(value)
        ")->execute([$today_done]);
    } catch (\Throwable $e) {
        // Settings table update is optional
    }

} catch (\Throwable $e) {
    worker_log("Fatal error: " . $e->getMessage());
    flock($lock_fp, LOCK_UN);
    fclose($lock_fp);
    exit(1);
}

// The lock is released via register_shutdown_function()

$runtime = time() - $start_time;
worker_log("Worker finished. Processed: {$processed}, Errors: {$errors}, Runtime: {$runtime}s");

exit(0);
