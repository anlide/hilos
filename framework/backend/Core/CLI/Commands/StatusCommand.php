<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\API\AsyncHttpClient;
use Hilos\Constants\ApiEndpoint;
use Hilos\Constants\DaemonConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Constants\HttpConstants;
use Hilos\Core\CLI\DTO\DaemonStatusDTO;
use Hilos\Core\Daemon\Master\DaemonStatus;
use Hilos\Utils\Env;
use Hilos\Utils\Exception\MissingEnvironmentVariableException;
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
     * Returns command name for CLI routing.
     *
     * @return string Command name (e.g. daemon:status)
     */
    public function getName(): string
    {
        return 'daemon:status';
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Display current daemon status and metrics';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: daemon:status

Description:
  Display current status and metrics of the running Hilos daemon.
  Shows uptime, memory usage, CPU usage, and worker counts.

Usage:
  php cli.php daemon:status

Examples:
  php cli.php daemon:status
  composer run daemon-status
HELP;
    }

    /**
     * Execute status command
     *
     * Displays current daemon status with real-time data from HTTP endpoint.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param array<int, string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     */
    public function execute(array $options, array $args): int
    {
        echo "Hilos Daemon Status\n";
        echo "==================\n\n";

        // Fetch daemon status via HTTP
        try {
            $this->fetchDaemonStatus();
        } catch (MissingEnvironmentVariableException $e) {
            echo "Error: " . $e->getMessage() . "\n\n";
            return ExitCode::CONFIG_ERROR;
        }

        // Display status table
        $this->displayStatusTable();

        echo "\n";

        return ExitCode::SUCCESS;
    }

    /**
     * Fetch daemon status via HTTP.
     *
     * Makes synchronous HTTP request to daemon status endpoint.
     *
     * @throws MissingEnvironmentVariableException If required env vars not set
     */
    private function fetchDaemonStatus(): void
    {
        $host = Env::get(EnvConstants::HILOS_DAEMON_HOST);
        $port = Env::getInt(EnvConstants::HTTP_STATUS_PORT);

        // Create HTTP client with longer timeout for status command
        $client = new AsyncHttpClient($host, $port, ApiEndpoint::STATUS);
        $client->timeout = 800.0;  // 0.8 seconds timeout

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
     * Display daemon status table to stdout.
     */
    private function displayStatusTable(): void
    {
        echo "+--------------------+---------------------+\n";
        echo "| Metric             | Value               |\n";
        echo "+--------------------+---------------------+\n";
        printf("| %-18s | %-19s |\n", "Status", $this->getStatusValue());
        printf("| %-18s | %-19s |\n", "Uptime", $this->getUptimeValue());
        printf("| %-18s | %-19s |\n", "Memory Usage", $this->getMemoryValue());
        printf("| %-18s | %-19s |\n", "CPU Usage", $this->getCpuValue());
        printf("| %-18s | %-4s | %-12s |\n", "Workers Regular", $this->getWorkersRegularValue(), $this->getWorkersMaxRegularValue());
        printf("| %-18s | %-19s |\n", "Workers Mono", $this->getWorkersMonopolisticValue());
        echo "+--------------------+---------------------+\n";
    }

    /**
     * Get formatted daemon status (online/offline).
     *
     * @return string STATUS_ONLINE or STATUS_OFFLINE
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
     * Get formatted daemon uptime string (HH:MM:SS).
     *
     * @return string Uptime string or VALUE_NOT_AVAILABLE
     */
    private function getUptimeValue(): string
    {
        if ($this->daemonStatus === null) {
            return DaemonConstants::VALUE_NOT_AVAILABLE;
        }

        return StringHelper::formatUptime($this->daemonStatus->getUptime());
    }

    /**
     * Get formatted daemon memory usage string.
     *
     * @return string Memory string (e.g. "15.2 MB") or VALUE_NOT_AVAILABLE
     */
    private function getMemoryValue(): string
    {
        if ($this->daemonStatus === null) {
            return DaemonConstants::VALUE_NOT_AVAILABLE;
        }

        return StringHelper::formatBytes($this->daemonStatus->memoryUsage);
    }

    /**
     * Get formatted daemon CPU usage string (percentage).
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
     * Get formatted regular workers count string.
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
     * Get formatted monopolistic workers count string.
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
     * Get formatted maximum regular workers count string.
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
}
