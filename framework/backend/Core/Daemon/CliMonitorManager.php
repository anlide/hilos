<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\API\AsyncCommandClient;
use Hilos\Constants\CliCommands;
use Hilos\Constants\DaemonConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\CLI\Commands\CommandChannelClientTrait;
use Hilos\Core\CLI\DTO\DaemonStatusDTO;
use Hilos\Core\Daemon\Master\DaemonStatus;
use Hilos\Core\Exception\MissingRequiredParameterException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Utils\Helpers\StringHelper;
use Hilos\Utils\Helpers\TimeHelper;
use Hilos\Utils\Logger;
use Throwable;

/**
 * Interactive CLI monitor for a running daemon. Polls `daemon:status` over the CLI command
 * channel and renders live status, memory, CPU, and heartbeat updates in the terminal.
 *
 * A poll and not a subscription: a stream would need a second protocol on top of a channel that
 * is request/reply, for a screen that repaints once a second anyway. It holds the async client
 * itself rather than taking {@see CommandChannelClientTrait}, whose one round-trip blocks for
 * five seconds - a budget that would freeze this display rather than refresh it.
 */
class CliMonitorManager extends BaseManager
{
    /** @var ?DaemonStatus Last daemon status */
    private ?DaemonStatus $daemonStatus = null;

    /** @var float UI update interval in milliseconds (1000ms = 1 second) */
    private float $uiUpdateInterval = 1000.0;

    /** @var string Word the Status row shows while no status is held */
    private string $statusWithoutAnswer = DaemonConstants::STATUS_OFFLINE;

    /** @var float Delay between one poll completing and the next starting, in milliseconds */
    private float $requestDelay = 350.0;

    /** @var float Wait budget for one poll's reply, in milliseconds */
    private float $requestTimeout = 400.0;

    /**
     * Run monitor - main method.
     *
     * Starts the interactive monitoring loop with real-time updates.
     * Main loop runs at 10ms (0.01s) intervals.
     * UI updates every 1 second.
     * Polls the daemon every 350ms after completion.
     *
     * @throws MissingRequiredParameterException When required process-control functions are unavailable
     * @throws EnvException When the command channel env values are missing or invalid
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

        // Initialize the command-channel client
        $host = Hilos::$env[EnvConstants::HILOS_DAEMON_HOST]->string();
        $port = Hilos::$env[EnvConstants::COMMAND_PORT]->int();

        $commandClient = new AsyncCommandClient($host, $port);

        // Initialize timers
        $currentTimeMs = microtime(true) * TimeConstants::MS_PER_SECOND;

        $lastUiUpdate = $currentTimeMs;

        $lastRequestCompletion = $currentTimeMs - $this->requestDelay; // Allow first request immediately

        $requestStartedAt = $currentTimeMs;

        // Main monitoring loop - 10ms ticks
        while (!$this->shouldExit) {
            $loopStartTime = microtime(true);
            $currentTimeMs = $loopStartTime * TimeConstants::MS_PER_SECOND;

            try {
                if (!$commandClient->isBusy()) {
                    $timeSinceLastRequest = $currentTimeMs - $lastRequestCompletion;
                    if ($timeSinceLastRequest >= $this->requestDelay) {
                        $commandClient->startRequest($this->statusRequest());
                        $requestStartedAt = $currentTimeMs;
                    }
                } elseif (($currentTimeMs - $requestStartedAt) > $this->requestTimeout) {
                    // The budget lives here because the channel client has no clock of its own,
                    // and a poll that runs past it is the case worth naming: the socket opened,
                    // so the daemon IS there, and it is the answer that never came.
                    $commandClient->reset();
                    $this->showNoStatus(DaemonConstants::STATUS_NOT_RESPONDING);
                    $lastRequestCompletion = $currentTimeMs;
                }

                $commandClient->tick();

                if ($commandClient->hasResult()) {
                    $this->processReply($commandClient->consumeResult());
                    $lastRequestCompletion = $currentTimeMs;
                }
            } catch (HilosException $e) {
                // Nothing accepted the connection, or it broke mid-poll: the channel is not there.
                $commandClient->reset();
                $this->showNoStatus(DaemonConstants::STATUS_OFFLINE);
                $lastRequestCompletion = $currentTimeMs;
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
        $term = Hilos::$env[EnvConstants::TERM]->string();
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
        $term = Hilos::$env[EnvConstants::TERM]->string();
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
     * Builds one status request for the command channel.
     *
     * The correlation id is drawn from the tolerant axis: it pairs one reply with one request
     * on a socket this process opened itself, so it only has to not collide with the poll
     * before it - nobody outside ever sees it, and there is nothing in it to guess.
     *
     * @return CommandRequestDTO Request asking the master for its status
     */
    private function statusRequest(): CommandRequestDTO
    {
        return new CommandRequestDTO(
            correlationId: RandomHelper::hex(8),
            command: CliCommands::DAEMON_STATUS,
            payload: [],
        );
    }

    /**
     * Takes one poll's reply and refreshes the status the display draws.
     *
     * A refusal, or a payload that is not a status, reads as NOT RESPONDING and not as OFFLINE:
     * something answered on that port, so the daemon is not the thing that is missing.
     *
     * @param CommandReplyDTO $reply Completed reply to the status poll
     */
    private function processReply(CommandReplyDTO $reply): void
    {
        try {
            $this->daemonStatus = DaemonStatus::fromDTO(DaemonStatusDTO::fromArray($reply->payload));
        } catch (Throwable $e) {
            $this->showNoStatus(DaemonConstants::STATUS_NOT_RESPONDING);
        }
    }

    /**
     * Drops the status the display holds and names what the Status row shows instead.
     *
     * The loop goes on either way: the monitor is watched while a daemon is restarted, so it
     * has to survive the gap rather than exit into it - Ctrl+C is the way out.
     *
     * @param string $word {@see DaemonConstants::STATUS_OFFLINE} or {@see DaemonConstants::STATUS_NOT_RESPONDING}
     */
    private function showNoStatus(string $word): void
    {
        $this->daemonStatus = null;
        $this->statusWithoutAnswer = $word;
    }

    /**
     * Get daemon status value.
     *
     * @return string STATUS_ONLINE, STATUS_NOT_RESPONDING or STATUS_OFFLINE
     */
    private function getStatusValue(): string
    {
        if ($this->daemonStatus !== null) {
            return DaemonConstants::STATUS_ONLINE;
        }

        // The same distinction the command draws: a channel that opened and then went quiet is
        // a daemon in trouble, and drawing it as OFFLINE would report it as simply absent.
        return $this->statusWithoutAnswer;
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
