<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Utils\Helpers\StringHelper;
use Hilos\Utils\Constants\CliConstants;
use Hilos\Utils\DTO\DaemonStatusDTO;

/**
 * CliMonitorManager - manages real-time daemon monitoring
 *
 * Provides interactive monitoring of daemon process with live updates:
 * - Terminal support checking
 * - Real-time status display
 * - Memory and CPU usage tracking
 * - Heartbeat monitoring
 * - Async HTTP requests to daemon status endpoint
 */
class CliMonitorManager extends BaseManager
{
    /** @var ?DaemonStatusDTO Last daemon status from HTTP request */
    private ?DaemonStatusDTO $lastDaemonStatus = null;

    /** @var bool Whether daemon is reachable */
    private bool $daemonOnline = false;

    /** @var float Last successful status fetch timestamp */
    private float $lastStatusFetchTime = 0.0;

    /** @var float Last UI update timestamp in milliseconds */
    private float $lastUiUpdate = 0.0;

    /** @var float UI update interval in milliseconds (1000ms = 1 second) */
    private float $uiUpdateInterval = 1000.0;

    /** @var float HTTP request delay after completion in milliseconds */
    private float $httpRequestDelay = 350.0;

    /** @var float Time when HTTP request completed in milliseconds */
    private float $lastHttpCompletion = 0.0;

    /** @var float Time when last HTTP attempt started in milliseconds */
    private float $lastHttpAttempt = 0.0;

    /** @var HttpState HTTP request state */
    private HttpState $httpState;

    /** @var resource|null HTTP socket */
    private $httpSocket = null;

    /** @var string HTTP response buffer */
    private string $httpResponseBuffer = '';

    /** @var float HTTP request start time for timeout tracking */
    private float $httpStartTime = 0.0;

    /** @var float Maximum HTTP request timeout in milliseconds */
    private float $httpTimeout = 2000.0;

    /**
     * Run monitor - main method
     *
     * Starts the interactive monitoring loop with real-time updates.
     * Main loop runs at 10ms (0.01s) intervals.
     * UI updates every 1 second.
     * HTTP requests every 350ms after completion.
     */
    public function run(): void
    {
        // Check the availability of required functions
        $this->checkRequiredFunctions();

        // Check terminal support
        if (!$this->checkTerminalSupport()) {
            return;
        }

        // Setup error handling and signal handlers
        $this->setupErrorHandling();
        $this->setupSignalHandlers();

        $this->logMessage("Starting Hilos Daemon Monitor...");
        $this->logMessage("Press Ctrl+C to exit");
        $this->logMessage("");

        // Initialize state
        $this->httpState = HttpState::IDLE;
        
        // Initialize timers
        $currentTimeMs = microtime(true) * 1000;
        $this->lastUiUpdate = $currentTimeMs;
        $this->lastHttpCompletion = $currentTimeMs;
        $this->lastHttpAttempt = 0.0; // Allow immediate first attempt

        // Main monitoring loop - 10ms ticks
        while (!$this->shouldExit) {
            $loopStartTime = microtime(true);
            $currentTimeMs = $loopStartTime * 1000;

            // Process async HTTP state machine
            $this->processHttpStateMachine($currentTimeMs);

            // Update UI every 1 second
            if (($currentTimeMs - $this->lastUiUpdate) >= $this->uiUpdateInterval) {
                $this->updateDisplay();
                $this->lastUiUpdate = $currentTimeMs;
            }

            // Process signals
            pcntl_signal_dispatch();

            // Sleep for 10ms with precise timing (10000 microseconds = 10ms)
            $this->sleepWithPreciseTiming($loopStartTime, 10000);
        }

        // Cleanup
        $this->closeHttpSocket();
        $this->logMessage("Monitoring stopped.");
    }

    /**
     * Check terminal support for interactive monitoring
     *
     * Validates that the current terminal supports interactive features
     * required for real-time monitoring displays.
     *
     * @return bool True if terminal supports monitoring
     */
    private function checkTerminalSupport(): bool
    {
        // Check if TTY is available
        if (!posix_isatty(STDOUT)) {
            $this->logMessage("ERROR: Terminal not supported for interactive monitoring.");
            $this->logMessage("This command requires a TTY (terminal) to work properly.");
            $this->logMessage("");
            $this->logMessage("Solutions:");
            $this->logMessage("1. On Windows: Use PowerShell script: scripts/monitor.ps1");
            $this->logMessage("2. On Linux: Ensure you're running in a terminal");
            $this->logMessage("3. For production monitoring: Use daemon:status command");
            $this->logMessage("");
            return false;
        }

        // Check TERM variable
        $term = getenv('TERM');
        if (!$term || $term === 'dumb') {
            $this->logMessage("WARNING: Terminal capabilities limited (TERM=$term).");
            $this->logMessage("Monitor may not display correctly.");
            $this->logMessage("");
        }

        return true;
    }


    /**
     * Update display with new monitoring data
     *
     * Clears the screen and redraws the monitoring display with current data.
     */
    private function updateDisplay(): void
    {
        // Clear screen (cross-platform)
        if (getenv('TERM') && getenv('TERM') !== 'dumb') {
            system('clear');
        } else {
            // Fallback for cases without TERM
            echo "\033[2J\033[H";
        }

        // Header
        echo "=== HILOS DAEMON MONITOR ===\n";
        echo "Last update: " . date('Y-m-d H:i:s') . "\n";
        echo "Press Ctrl+C to exit\n\n";

        // Daemon status table
        echo "+------------------+---------------------+\n";
        echo "| Metric           | Value               |\n";
        echo "+------------------+---------------------+\n";
        printf("| %-16s | %-19s |\n", "Status", $this->getDaemonStatus());
        printf("| %-16s | %-19s |\n", "PID", $this->getDaemonPid());
        printf("| %-16s | %-19s |\n", "Uptime", $this->getDaemonUptime());
        printf("| %-16s | %-19s |\n", "Memory Usage", $this->getDaemonMemory());
        printf("| %-16s | %-19s |\n", "CPU Usage", $this->getDaemonCpu());
        echo "+------------------+---------------------+\n";

        // Flush output buffer
        flush();
    }

    /**
     * Process HTTP state machine for async requests
     *
     * @param float $currentTimeMs Current time in milliseconds
     */
    private function processHttpStateMachine(float $currentTimeMs): void
    {
        // Check for timeout in any active state
        if ($this->httpState !== HttpState::IDLE && $this->httpStartTime > 0) {
            if (($currentTimeMs - $this->httpStartTime) >= $this->httpTimeout) {
                // Timeout - force completion
                $this->completeHttpRequest(false);
                return;
            }
        }

        switch ($this->httpState) {
            case HttpState::IDLE:
                // STRICT PROTECTION: Check both completion AND last attempt
                // Ensure minimum 350ms between ANY attempts (success or failure)
                $timeSinceCompletion = $currentTimeMs - $this->lastHttpCompletion;
                $timeSinceAttempt = $currentTimeMs - $this->lastHttpAttempt;
                
                if ($timeSinceCompletion >= $this->httpRequestDelay && 
                    $timeSinceAttempt >= $this->httpRequestDelay) {
                    $this->startHttpRequest($currentTimeMs);
                }
                break;

            case HttpState::CONNECTING:
                $this->processHttpConnecting();
                break;

            case HttpState::SENDING:
                $this->processHttpSending();
                break;

            case HttpState::RECEIVING:
                $this->processHttpReceiving();
                break;
        }
    }

    /**
     * Start new HTTP request
     *
     * @param float $currentTimeMs Current time in milliseconds
     */
    private function startHttpRequest(float $currentTimeMs): void
    {
        // Record attempt time BEFORE doing anything
        $this->lastHttpAttempt = $currentTimeMs;
        $this->httpStartTime = $currentTimeMs;

        $host = CliConstants::MONITOR_DAEMON_HOST;
        $port = CliConstants::HTTP_STATUS_PORT;

        // Create non-blocking socket
        $this->httpSocket = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            0,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
        );

        if ($this->httpSocket === false) {
            $this->completeHttpRequest(false);
            return;
        }

        // Set non-blocking mode
        stream_set_blocking($this->httpSocket, false);

        $this->httpState = HttpState::CONNECTING;
        $this->httpResponseBuffer = '';
    }

    /**
     * Process connecting state
     */
    private function processHttpConnecting(): void
    {
        if ($this->httpSocket === null || !is_resource($this->httpSocket)) {
            $this->completeHttpRequest(false);
            return;
        }

        // Check if socket is writable (connected)
        $read = null;
        $write = [$this->httpSocket];
        $except = null;

        $result = @stream_select($read, $write, $except, 0, 0);

        if ($result === false) {
            $this->completeHttpRequest(false);
            return;
        }

        if ($result > 0 && !empty($write)) {
            // Connected! Switch to sending
            $this->httpState = HttpState::SENDING;
        }
    }

    /**
     * Process sending state
     */
    private function processHttpSending(): void
    {
        if ($this->httpSocket === null || !is_resource($this->httpSocket)) {
            $this->completeHttpRequest(false);
            return;
        }

        $host = CliConstants::MONITOR_DAEMON_HOST;
        $path = CliConstants::HTTP_STATUS_PATH;

        $request = "GET {$path} HTTP/1.1\r\n";
        $request .= "Host: {$host}\r\n";
        $request .= "Connection: close\r\n";
        $request .= "\r\n";

        $written = @fwrite($this->httpSocket, $request);

        if ($written === false) {
            $this->completeHttpRequest(false);
            return;
        }

        // Switch to receiving
        $this->httpState = HttpState::RECEIVING;
    }

    /**
     * Process receiving state
     */
    private function processHttpReceiving(): void
    {
        if ($this->httpSocket === null || !is_resource($this->httpSocket)) {
            $this->completeHttpRequest(false);
            return;
        }

        // Check if socket is readable
        $read = [$this->httpSocket];
        $write = null;
        $except = null;

        $result = @stream_select($read, $write, $except, 0, 0);

        if ($result === false) {
            $this->completeHttpRequest(false);
            return;
        }

        if ($result > 0 && !empty($read)) {
            // Read available data
            $chunk = @fread($this->httpSocket, 8192);

            if ($chunk === false) {
                $this->completeHttpRequest(false);
                return;
            }

            if ($chunk === '') {
                // EOF - parse response
                $this->parseHttpResponse();
                return;
            }

            $this->httpResponseBuffer .= $chunk;
        }

        // Check if connection closed (only if socket is still valid)
        if (is_resource($this->httpSocket) && @feof($this->httpSocket)) {
            $this->parseHttpResponse();
        }
    }

    /**
     * Parse HTTP response
     */
    private function parseHttpResponse(): void
    {
        if (empty($this->httpResponseBuffer)) {
            $this->completeHttpRequest(false);
            return;
        }

        // Split headers and body
        $parts = explode("\r\n\r\n", $this->httpResponseBuffer, 2);
        if (count($parts) < 2) {
            $this->completeHttpRequest(false);
            return;
        }

        $body = $parts[1];

        // Parse JSON to DTO
        try {
            $this->lastDaemonStatus = DaemonStatusDTO::fromJson($body);
            $this->daemonOnline = true;
            $this->lastStatusFetchTime = microtime(true);
            $this->completeHttpRequest(true);
        } catch (\Throwable $e) {
            // Failed to parse DTO
            $this->completeHttpRequest(false);
        }
    }

    /**
     * Complete HTTP request
     *
     * @param bool $success Whether request was successful
     */
    private function completeHttpRequest(bool $success): void
    {
        if (!$success) {
            $this->daemonOnline = false;
        }

        $this->closeHttpSocket();
        $this->httpState = HttpState::IDLE;
        $this->lastHttpCompletion = microtime(true) * 1000;
        $this->httpResponseBuffer = '';
        $this->httpStartTime = 0.0;
    }

    /**
     * Close HTTP socket
     */
    private function closeHttpSocket(): void
    {
        if ($this->httpSocket !== null && is_resource($this->httpSocket)) {
            @fclose($this->httpSocket);
        }
        $this->httpSocket = null;
    }

    /**
     * Get daemon status value
     */
    private function getDaemonStatus(): string
    {
        if (!$this->daemonOnline || $this->lastDaemonStatus === null) {
            return 'OFFLINE';
        }

        return $this->lastDaemonStatus->running ? 'RUNNING' : 'STOPPED';
    }

    /**
     * Get daemon uptime value
     */
    private function getDaemonUptime(): string
    {
        if (!$this->daemonOnline || $this->lastDaemonStatus === null) {
            return 'N/A';
        }

        return StringHelper::formatUptime($this->lastDaemonStatus->uptime);
    }

    /**
     * Get daemon memory usage
     */
    private function getDaemonMemory(): string
    {
        if (!$this->daemonOnline || $this->lastDaemonStatus === null) {
            return 'N/A';
        }

        return StringHelper::formatBytes($this->lastDaemonStatus->memory);
    }

    /**
     * Get daemon CPU usage
     */
    private function getDaemonCpu(): string
    {
        if (!$this->daemonOnline || $this->lastDaemonStatus === null) {
            return 'N/A';
        }

        return round($this->lastDaemonStatus->cpu, 1) . '%';
    }

    /**
     * Get daemon PID
     */
    private function getDaemonPid(): string
    {
        if (!$this->daemonOnline || $this->lastDaemonStatus === null) {
            return 'N/A';
        }

        return (string)$this->lastDaemonStatus->pid;
    }

    // Implementation of abstract methods from BaseManager

    /**
     * Get manager name for logging
     */
    protected function getManagerName(): string
    {
        return "CLI Monitor";
    }

    /**
     * Log error message (console + error_log)
     */
    protected function logError(string $message): void
    {
        $timestamped = "[" . date('Y-m-d H:i:s') . "] " . $message;
        error_log($timestamped);
        $this->logMessage($message);
    }

    /**
     * Log exception message (console + error_log)
     */
    protected function logException(string $message): void
    {
        $timestamped = "[" . date('Y-m-d H:i:s') . "] " . $message;
        error_log($timestamped);
        $this->logMessage($message);
    }

    /**
     * Log shutdown message (console + error_log)
     */
    protected function logShutdown(string $message): void
    {
        $timestamped = "[" . date('Y-m-d H:i:s') . "] " . $message;
        error_log($timestamped);
        $this->logMessage($message);
    }

    /**
     * Handle error event - sets exit flag
     */
    protected function onError(): void
    {
        $this->shouldExit = true;
    }

    /**
     * Handle exception event - sets exit flag
     */
    protected function onException(): void
    {
        $this->shouldExit = true;
    }

    /**
     * Handle shutdown event - sets exit flag
     */
    protected function onShutdown(): void
    {
        $this->shouldExit = true;
    }

    /**
     * Handle shutdown signal event - sets exit flag
     */
    protected function onShutdownSignal(): void
    {
        $this->shouldExit = true;
    }

    /**
     * Handle restart signal event - no specific action needed
     */
    protected function onRestartSignal(): void
    {
        // Monitor doesn't support restart, just shutdown
        $this->shouldExit = true;
    }
}

