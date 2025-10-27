<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\API\AsyncHttpClient;
use Hilos\Core\Daemon\Master\DaemonStatus;
use Hilos\Utils\Constants\CliConstants;
use Hilos\Utils\Constants\DaemonConstants;
use Hilos\Utils\Constants\ExitCode;
use Hilos\Utils\Constants\HttpConstants;
use Hilos\Utils\DTO\DaemonStatusDTO;
use Hilos\Utils\Helpers\StringHelper;

/**
 * StatusCommand - Display daemon status
 *
 * Shows current daemon status including uptime, memory usage and other metrics.
 * Retrieves real-time data from running daemon via HTTP status endpoint.
 */
class StatusCommand implements CommandInterface
{
    /** @var ?DaemonStatus Daemon status */
    private ?DaemonStatus $daemonStatus = null;

    /**
     * Execute status command
     *
     * Displays current daemon status with real-time data from HTTP endpoint.
     *
     * @param array $options Command options
     * @param array $args Positional arguments (unused)
     * @return int Exit code (0)
     */
    public function execute(array $options, array $args): int
    {
        echo "Hilos Daemon Status\n";
        echo "==================\n\n";

        // Fetch daemon status via HTTP
        $this->fetchDaemonStatus();

        // Display status table
        $this->displayStatusTable();

        echo "\n";

        return ExitCode::SUCCESS;
    }

    /**
     * Fetch daemon status via HTTP
     *
     * Makes synchronous HTTP request to daemon status endpoint.
     */
    private function fetchDaemonStatus(): void
    {
        $host = CliConstants::MONITOR_DAEMON_HOST;
        $port = CliConstants::HTTP_STATUS_PORT;
        $path = CliConstants::HTTP_STATUS_PATH;

        // Create HTTP client with longer timeout for status command
        $client = new AsyncHttpClient($host, $port, $path);
        $client->setTimeout(800.0);  // 0.8 seconds timeout

        $currentTimeMs = microtime(true) * 1000;

        // Start request
        $client->startNewRequest($currentTimeMs);

        // Wait for result (synchronous polling)
        $maxWaitTime = 400.0; // 0.4 seconds max wait
        $startTime = $currentTimeMs;

        while (!$client->hasResult()) {
            $currentTimeMs = microtime(true) * 1000;

            // Check for overall timeout
            if (($currentTimeMs - $startTime) > $maxWaitTime) {
                $this->daemonStatus = null;
                return;
            }

            // Process HTTP state machine
            $client->tick($currentTimeMs);

            // Small delay to avoid busy-wait
            usleep(10000); // 10ms
        }

        // Get result
        $result = $client->getResult();

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
     * Display status table
     */
    private function displayStatusTable(): void
    {
        echo "+------------------+---------------------+\n";
        echo "| Metric           | Value               |\n";
        echo "+------------------+---------------------+\n";
        printf("| %-16s | %-19s |\n", "Status", $this->getStatusValue());
        printf("| %-16s | %-19s |\n", "Uptime", $this->getUptimeValue());
        printf("| %-16s | %-19s |\n", "Memory Usage", $this->getMemoryValue());
        printf("| %-16s | %-19s |\n", "CPU Usage", $this->getCpuValue());
        echo "+------------------+---------------------+\n";
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
}

