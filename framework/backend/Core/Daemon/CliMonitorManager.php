<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\API\AsyncHttpClient;
use Hilos\API\DTO\AsyncHttpResponse;
use Hilos\Constants\ApiEndpoint;
use Hilos\Constants\DaemonConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\CLI\DTO\DaemonStatusDTO;
use Hilos\Core\Daemon\Master\DaemonStatus;
use Hilos\Core\Exception\MissingRequiredParameterException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Utils\Helpers\StringHelper;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Utils\Logger;

/**
 * Interactive CLI monitor for a running daemon. Polls the daemon status
 * endpoint over async HTTP and renders live status, memory, CPU, and heartbeat
 * updates in the terminal.
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
     * Run monitor - main method.
     *
     * Starts the interactive monitoring loop with real-time updates.
     * Main loop runs at 10ms (0.01s) intervals.
     * UI updates every 1 second.
     * HTTP requests every 350ms after completion.
     *
     * @throws MissingRequiredParameterException When required process-control functions are unavailable
     * @throws EnvException When daemon status env values are missing or invalid
     */
    public function run(): void
    {
        // Check the availability of required functions
        $this->checkRequiredFunctions(['posix_isatty']);

        // Check terminal support
        if (!$this->checkTerminalSupport()) {
            return;
        }

        // Setup error handling and signal handlers
        $this->setupErrorHandling();
        $this->setupSignalHandlers();

        Logger::info("Starting Hilos Daemon Monitor...");
        Logger::info("Press Ctrl+C to exit");

        // Initialize HTTP client
        $host = Hilos::$env[EnvConstants::HILOS_DAEMON_HOST];
        $port = Hilos::$env->int(EnvConstants::HTTP_STATUS_PORT);

        $httpClient = new AsyncHttpClient($host, $port, ApiEndpoint::STATUS);
        $httpClient->timeout = 400.0;  // 0.4 seconds timeout

        // Initialize timers
        $currentTimeMs = microtime(true) * TimeConstants::MS_PER_SECOND;

        $lastUiUpdate = $currentTimeMs;

        $lastHttpCompletion = $currentTimeMs - $this->httpRequestDelay; // Allow first request immediately

        // Main monitoring loop - 10ms ticks
        while (!$this->shouldExit) {
            $loopStartTime = microtime(true);
            $currentTimeMs = $loopStartTime * TimeConstants::MS_PER_SECOND;

            try {
                if (!$httpClient->isBusy()) {
                    $timeSinceLastRequest = $currentTimeMs - $lastHttpCompletion;
                    if ($timeSinceLastRequest >= $this->httpRequestDelay) {
                        $httpClient->startNewRequest($currentTimeMs);
                    }
                }

                $httpClient->tick($currentTimeMs);

                if ($httpClient->hasResult()) {
                    $this->processHttpResult($httpClient->consumeResult());
                    $lastHttpCompletion = $currentTimeMs;
                }
            } catch (HilosException $e) {
                $httpClient->reset();
                $this->daemonStatus = null;
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
        Logger::info("Monitoring stopped.");
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
            Logger::info("ERROR: Terminal not supported for interactive monitoring.");
            Logger::info("This command requires a TTY (terminal) to work properly.");
            Logger::info("");
            Logger::info("Solutions:");
            Logger::info("1. On Windows: Use PowerShell script from your project (e.g., demo/chat/scripts/monitor.ps1)");
            Logger::info("2. On Linux: Ensure you're running in a terminal");
            Logger::info("3. For production monitoring: Use daemon:status command");
            Logger::info("");
            return false;
        }

        // Check TERM variable
        $term = Hilos::$env[EnvConstants::TERM];
        if (!$term || $term === 'dumb') {
            Logger::info("WARNING: Terminal capabilities limited (TERM=$term).");
            Logger::info("Monitor may not display correctly.");
            Logger::info("");
        }

        return true;
    }

    /**
     * Update display with new monitoring data.
     *
     * Clears the screen and redraws the monitoring display with current data.
     */
    private function updateDisplay(): void
    {
        // Clear screen (cross-platform)
        $term = Hilos::$env[EnvConstants::TERM];
        if ($term !== '' && $term !== 'dumb') {
            system('clear');
        } else {
            // Fallback for cases without TERM
            echo "\033[2J\033[H";
        }

        // Header
        echo "=== HILOS DAEMON MONITOR ===\n";
        echo "Last update: " . TimeHelper::getSqlDateTime() . "\n";
        echo "Press Ctrl+C to exit\n\n";

        // Daemon status table
        echo "+--------------------+---------------------+\n";
        echo "| Metric             | Value               |\n";
        echo "+--------------------+---------------------+\n";
        printf("| %-18s | %-19s |\n", "Status", $this->getStatusValue());
        printf("| %-18s | %-19s |\n", "Uptime", $this->getUptimeValue());
        printf("| %-18s | %-19s |\n", "Memory Usage", $this->getMemoryValue());
        printf("| %-18s | %-19s |\n", "CPU Usage", $this->getCpuValue());
        printf("| %-18s | %-4s / %-12s |\n", "Workers Regular", $this->getWorkersRegularValue(), $this->getWorkersMaxRegularValue());
        printf("| %-18s | %-19s |\n", "Workers Mono", $this->getWorkersMonopolisticValue());
        echo "+--------------------+---------------------+\n";

        // Flush output buffer
        flush();
    }

    /**
     * Process HTTP result from async client.
     *
     * @param AsyncHttpResponse $response Completed daemon status response
     */
    private function processHttpResult(AsyncHttpResponse $response): void
    {
        try {
            $dto = DaemonStatusDTO::fromJson($response->body);
            $this->daemonStatus = DaemonStatus::fromDTO($dto);
        } catch (\Throwable $e) {
            $this->daemonStatus = null;
        }
    }

    /**
     * Get daemon status value.
     *
     * @return string STATUS_ONLINE or STATUS_OFFLINE or VALUE_NOT_AVAILABLE
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
     * Get daemon uptime value.
     *
     * @return string Formatted uptime or VALUE_NOT_AVAILABLE
     */
    private function getUptimeValue(): string
    {
        if ($this->daemonStatus === null) {
            return DaemonConstants::VALUE_NOT_AVAILABLE;
        }

        return StringHelper::formatUptime($this->daemonStatus->getUptime());
    }

    /**
     * Get daemon memory usage.
     *
     * @return string Formatted memory or VALUE_NOT_AVAILABLE
     */
    private function getMemoryValue(): string
    {
        if ($this->daemonStatus === null) {
            return DaemonConstants::VALUE_NOT_AVAILABLE;
        }

        return StringHelper::formatBytes($this->daemonStatus->memoryUsage);
    }

    /**
     * Get daemon CPU usage.
     *
     * @return string CPU percentage or VALUE_NOT_AVAILABLE
     */
    private function getCpuValue(): string
    {
        if ($this->daemonStatus === null) {
            return DaemonConstants::VALUE_NOT_AVAILABLE;
        }

        return round($this->daemonStatus->cpuUsage, 1) . '%';
    }

    /**
     * Get regular workers count.
     *
     * @return string Workers count or VALUE_NOT_AVAILABLE
     */
    private function getWorkersRegularValue(): string
    {
        if ($this->daemonStatus === null) {
            return DaemonConstants::VALUE_NOT_AVAILABLE;
        }

        return (string)$this->daemonStatus->workersRegular;
    }

    /**
     * Get monopolistic workers count.
     *
     * @return string Workers count or VALUE_NOT_AVAILABLE
     */
    private function getWorkersMonopolisticValue(): string
    {
        if ($this->daemonStatus === null) {
            return DaemonConstants::VALUE_NOT_AVAILABLE;
        }

        return (string)$this->daemonStatus->workersMonopolistic;
    }

    /**
     * Get maximum regular workers count.
     *
     * @return string Max workers count or VALUE_NOT_AVAILABLE
     */
    private function getWorkersMaxRegularValue(): string
    {
        if ($this->daemonStatus === null) {
            return DaemonConstants::VALUE_NOT_AVAILABLE;
        }

        return (string)$this->daemonStatus->workersMaxRegular;
    }

    // Implementation of abstract methods from BaseManager

    /**
     * Get manager name for logging.
     *
     * @return string Manager name
     */
    protected function getManagerName(): string
    {
        return "CLI Monitor";
    }

    /**
     * Log error message (console + error_log).
     *
     * @param string $message Error message to log
     */
    protected function logError(string $message): void
    {
        Logger::errorLog($message);
    }

    /**
     * Log exception message (console + error_log).
     *
     * @param string $message Exception message to log
     */
    protected function logException(string $message): void
    {
        Logger::errorLog($message);
    }

    /**
     * Log shutdown message (console + error_log).
     *
     * @param string $message Shutdown message to log
     */
    protected function logShutdown(string $message): void
    {
        Logger::errorLog($message);
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
