<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\API\AsyncHttpClient;
use Hilos\Core\Daemon\Master\DaemonStatus;
use Hilos\Exception\MissingEnvironmentVariable;
use Hilos\Utils\Helpers\StringHelper;
use Hilos\Utils\Constants\CliConstants;
use Hilos\Utils\Constants\DaemonConstants;
use Hilos\Utils\Constants\EnvConstants;
use Hilos\Utils\Constants\HttpConstants;
use Hilos\Utils\DTO\DaemonStatusDTO;
use Hilos\Utils\Env;

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
    /** @var ?DaemonStatus Last daemon status */
    private ?DaemonStatus $daemonStatus = null;

    /** @var float UI update interval in milliseconds (1000ms = 1 second) */
    private float $uiUpdateInterval = 1000.0;

    /** @var float HTTP request delay after completion in milliseconds */
    private float $httpRequestDelay = 350.0;

    /**
     * Run monitor - main method
     *
     * Starts the interactive monitoring loop with real-time updates.
     * Main loop runs at 10ms (0.01s) intervals.
     * UI updates every 1 second.
     * HTTP requests every 350ms after completion.
     * @throws MissingEnvironmentVariable
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

        // Initialize HTTP client
        $host = Env::get(EnvConstants::HILOS_DAEMON_HOST);
        $port = Env::getInt(EnvConstants::HTTP_STATUS_PORT);
        $path = CliConstants::HTTP_STATUS_PATH;

        $httpClient = new AsyncHttpClient($host, $port, $path);
        $httpClient->timeout = 400.0;  // 0.4 seconds timeout

        // Initialize timers
        $currentTimeMs = microtime(true) * 1000;

        $lastUiUpdate = $currentTimeMs;

        $lastHttpCompletion = $currentTimeMs - $this->httpRequestDelay; // Allow first request immediately

        // Main monitoring loop - 10ms ticks
        while (!$this->shouldExit) {
            $loopStartTime = microtime(true);
            $currentTimeMs = $loopStartTime * 1000;

            // Start new HTTP request if client is ready and delay has passed
            if (!$httpClient->isBusy()) {
                $timeSinceLastRequest = $currentTimeMs - $lastHttpCompletion;
                if ($timeSinceLastRequest >= $this->httpRequestDelay) {
                    $httpClient->startNewRequest($currentTimeMs);
                }
            }

            // Process async HTTP client state machine
            $httpClient->tick($currentTimeMs);

            // Check for HTTP results
            if ($httpClient->hasResult()) {
                $this->processHttpResult($httpClient->getResult());
                $lastHttpCompletion = $currentTimeMs;
            }

            // Update UI every 1 second
            if (($currentTimeMs - $lastUiUpdate) >= $this->uiUpdateInterval) {
                $this->updateDisplay();
                $lastUiUpdate = $currentTimeMs;
            }

            // Process signals
            pcntl_signal_dispatch();

            // Sleep for 10ms with precise timing (10000 microseconds = 10ms)
            $this->sleepWithPreciseTiming($loopStartTime, 10000);
        }

        // Cleanup
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
        printf("| %-16s | %-19s |\n", "Status", $this->getStatusValue());
        printf("| %-16s | %-19s |\n", "Uptime", $this->getUptimeValue());
        printf("| %-16s | %-19s |\n", "Memory Usage", $this->getMemoryValue());
        printf("| %-16s | %-19s |\n", "CPU Usage", $this->getCpuValue());
        echo "+------------------+---------------------+\n";

        // Flush output buffer
        flush();
    }

    /**
     * Process HTTP result from async client
     *
     * @param array $result Result array with HttpConstants::RESPONSE_KEY_SUCCESS and HttpConstants::RESPONSE_KEY_BODY keys
     */
    private function processHttpResult(array $result): void
    {
        if ($result[HttpConstants::RESPONSE_KEY_SUCCESS] && $result[HttpConstants::RESPONSE_KEY_BODY] !== null) {
            // Parse JSON to DTO and convert to DaemonStatus
            try {
                $dto = DaemonStatusDTO::fromJson($result[HttpConstants::RESPONSE_KEY_BODY]);
                $this->daemonStatus = DaemonStatus::fromDTO($dto);
            } catch (\Throwable $e) {
                // Failed to parse DTO
                $this->daemonStatus = null;
            }
        } else {
            // Request failed
            $this->daemonStatus = null;
        }
    }

    /**
     * Get daemon status value
     */
    private function getStatusValue(): string
    {
        if ($this->daemonStatus === null) {
            return DaemonConstants::STATUS_OFFLINE;
        }

        // If we have status, daemon is online
        return DaemonConstants::STATUS_ONLINE;
    }

    /**
     * Get daemon uptime value
     */
    private function getUptimeValue(): string
    {
        if ($this->daemonStatus === null) {
            return DaemonConstants::VALUE_NOT_AVAILABLE;
        }

        return StringHelper::formatUptime($this->daemonStatus->getUptime());
    }

    /**
     * Get daemon memory usage
     */
    private function getMemoryValue(): string
    {
        if ($this->daemonStatus === null) {
            return DaemonConstants::VALUE_NOT_AVAILABLE;
        }

        return StringHelper::formatBytes($this->daemonStatus->memoryUsage);
    }

    /**
     * Get daemon CPU usage
     */
    private function getCpuValue(): string
    {
        if ($this->daemonStatus === null) {
            return DaemonConstants::VALUE_NOT_AVAILABLE;
        }

        return round($this->daemonStatus->cpuUsage, 1) . '%';
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

