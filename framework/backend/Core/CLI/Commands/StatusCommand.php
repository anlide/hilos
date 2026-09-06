<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\DaemonConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\DTO\DaemonStatusDTO;
use Hilos\Core\Daemon\Master\DaemonStatus;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Utils\Helpers\StringHelper;

/**
 * StatusCommand - Display daemon status.
 *
 * Shows current daemon status including uptime, memory usage and other metrics, read from the
 * running daemon over the CLI command channel - the one door the CLI has into the daemon. The
 * master answers `daemon:status` itself, because the figures are its own.
 *
 * The channel buys a distinction the HTTP status endpoint could not make: a daemon that is not
 * there and a daemon that is there and silent used to arrive as the same empty answer and were
 * both drawn as OFFLINE, so the command lied about a hung daemon. Now the first stays OFFLINE
 * and succeeds - "the daemon is not running" is an answer to the question this command asks,
 * and scripts calling it count on the zero - while the second reads NOT RESPONDING and fails.
 */
class StatusCommand implements CommandInterface
{
    use CommandChannelClientTrait;

    /** @var ?DaemonStatus Status the daemon reported; null when no reply arrived */
    private ?DaemonStatus $daemonStatus = null;

    /** @var ?CommandChannelFailure Why no reply arrived; null when one did */
    private ?CommandChannelFailure $failure = null;

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (e.g. daemon:status)
     */
    public function getName(): string
    {
        return CliCommands::DAEMON_STATUS;
    }

    /**
     * Declares the rule: the daemon does the work and this process only initiates it and prints.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::daemon();
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
  Asks the daemon over the CLI command channel.

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
     * Asks the running daemon for its status over the command channel and draws the table.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code: 0 when the daemon answered or is not running, non-zero otherwise
     */
    public function execute(array $options, array $args): int
    {
        echo "Hilos Daemon Status\n";
        echo "==================\n\n";

        try {
            $result = $this->sendCommand(CliCommands::DAEMON_STATUS, []);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n\n";

            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            $this->failure = $result->failure;
            // Printed on the ordinary "no daemon" outcome too, and not only on the timeout: the
            // commonest reason for an empty status is not a stopped daemon but a channel address
            // the operator did not expect, and the sentence is where that address is shown.
            $this->writeToStandardError($this->channelFailureText($result, CliCommands::DAEMON_STATUS));
            $this->printStatusTable();

            return $result->failure === CommandChannelFailure::TIMEOUT ? ExitCode::ERROR : ExitCode::SUCCESS;
        }

        if (!$result->reply->isOk()) {
            // The daemon named a reason - an installation whose master has no such branch says so -
            // and the operator reads it instead of a table of N/A that explains nothing.
            return $this->printRefusal($result->reply);
        }

        try {
            $this->daemonStatus = DaemonStatus::fromDTO(DaemonStatusDTO::fromArray($result->reply->payload));
        } catch (InvalidFormatException $e) {
            return $this->printToStandardError("The daemon answered daemon:status with no status in it: {$e->getMessage()}");
        }

        $this->printStatusTable();

        return ExitCode::SUCCESS;
    }

    /**
     * Draws the status table on stdout, with the blank line that closes the command's output.
     */
    private function printStatusTable(): void
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
        echo "\n";
    }

    /**
     * Get formatted daemon status (online / not responding / offline).
     *
     * @return string STATUS_ONLINE, STATUS_NOT_RESPONDING or STATUS_OFFLINE
     */
    private function getStatusValue(): string
    {
        if ($this->daemonStatus !== null) {
            return DaemonConstants::STATUS_ONLINE;
        }

        // A channel that opened and then went quiet is a daemon in trouble, not a daemon that
        // is not there; drawing both as OFFLINE is what this command used to get wrong.
        if ($this->failure === CommandChannelFailure::TIMEOUT) {
            return DaemonConstants::STATUS_NOT_RESPONDING;
        }

        return DaemonConstants::STATUS_OFFLINE;
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
