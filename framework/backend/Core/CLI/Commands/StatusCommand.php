<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\API\AsyncHttpClient;
use Hilos\Constants\ApiEndpoint;
use Hilos\Constants\DaemonConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Constants\TimeConstants;
use Hilos\Core\CLI\DTO\DaemonStatusDTO;
use Hilos\Core\Daemon\Master\DaemonStatus;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Utils\Helpers\StringHelper;

/**
 * StatusCommand - Display daemon status.
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
     * Execute status command.
     *
     * Displays current daemon status with real-time data from HTTP endpoint.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     */
    public function execute(array $options, array $args): int
    {
        echo "Hilos Daemon Status\n";
        echo "==================\n\n";

        // Fetch daemon status via HTTP
        try {
            $this->fetchDaemonStatus();
        } catch (EnvException $e) {
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
     * @throws EnvException When daemon status env values are missing or invalid
     */
    private function fetchDaemonStatus(): void
    {
        $host = Hilos::$env[EnvConstants::HILOS_DAEMON_HOST];
        $port = Hilos::$env->int(EnvConstants::HTTP_STATUS_PORT);

        try {
            $client = new AsyncHttpClient($host, $port, ApiEndpoint::STATUS);
            $client->timeout = 800.0;

            $currentTimeMs = microtime(true) * TimeConstants::MS_PER_SECOND;
            $client->startNewRequest($currentTimeMs);

            $maxWaitTime = 400.0;
            $startTime = $currentTimeMs;

            while (!$client->hasResult()) {
                $currentTimeMs = microtime(true) * TimeConstants::MS_PER_SECOND;
                if (($currentTimeMs - $startTime) > $maxWaitTime) {
                    $this->daemonStatus = null;
                    return;
                }

                $client->tick($currentTimeMs);
                usleep(10000);
            }

            $dto = DaemonStatusDTO::fromJson($client->consumeResult()->body);
            $this->daemonStatus = DaemonStatus::fromDTO($dto);
        } catch (\Throwable) {
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
