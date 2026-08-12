<?php
// SPDX-License-Identifier: GPL-3.0-only
/**
 * cms_errortracker.php – DAoC CMS
 */
class ErrorTracker {
    private $db;
    private bool $responseRendered = false;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->registerHandlers();
    }

    private function registerHandlers() {
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    public function handleError($errno, $errstr, $errfile, $errline) {
        if (!(error_reporting() & $errno)) return false;
        $this->logToDb($errno, $errstr, $errfile, $errline, debug_backtrace());
        return true;
    }

    public function handleException($e) {
        $this->logToDb($e->getCode(), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTrace());
        $this->renderPublicError();
    }

    public function handleShutdown() {
        $error = error_get_last();
        if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $this->logToDb($error['type'], $error['message'], $error['file'], $error['line'], 'Fatal Shutdown');
            $this->renderPublicError();
        }
    }

    private function renderPublicError(): void {
        if ($this->responseRendered || PHP_SAPI === 'cli') {
            return;
        }
        $this->responseRendered = true;

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store, max-age=0');
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>DAoC CMS - Server Error</title></head>'
            . '<body style="margin:0;background:#050505;color:#d7d2c7;font-family:Arial,sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px;box-sizing:border-box">'
            . '<main style="max-width:640px;border:1px solid #3a3324;background:#0b0b0d;padding:32px;text-align:center">'
            . '<h1 style="margin:0 0 16px;color:#c7a04a;font-family:serif">Something went wrong</h1>'
            . '<p style="margin:0;line-height:1.6">An unexpected server error occurred. Please try again later or contact the server staff.</p>'
            . '</main></body></html>';
    }

    private function logToDb($errno, $errstr, $errfile, $errline, $trace) {
        try {
            $sql = "INSERT INTO sys_error_log (errno, errstr, errfile, errline, stacktrace, request_uri) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $errno,
                $errstr,
                $errfile,
                $errline,
                json_encode($trace),
                $_SERVER['REQUEST_URI'] ?? 'CLI'
            ]);
        } catch (\Throwable $e) {
            // Avoid infinite loop if DB is unavailable during error handling
            error_log("ErrorTracker::logToDb() failed: " . $e->getMessage());
        }
    }
}
?>