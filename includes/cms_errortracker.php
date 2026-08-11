<?php
/**
 * cms_errortracker.php – DAoC CMS
 */
class ErrorTracker {
    private $db;

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
    }

    public function handleShutdown() {
        $error = error_get_last();
        if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $this->logToDb($error['type'], $error['message'], $error['file'], $error['line'], 'Fatal Shutdown');
        }
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