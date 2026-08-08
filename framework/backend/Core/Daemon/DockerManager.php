<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Daemon\Exception\InvalidScriptPathException;
use Hilos\Core\Exception\MissingRequiredParameterException;
use Hilos\Core\Exception\Process\CouldNotStartException;
use Hilos\Core\Exception\Process\FailedToClosePipeException;
use Hilos\Core\Exception\Process\FailedToGetStatusException;
use Hilos\Core\Exception\Process\FailedToReadStdOutException;
use Hilos\Core\Exception\Process\FailedToSetNonBlockingException;
use Hilos\Core\Exception\Process\FailedToSetStdErrException;
use Hilos\Core\Exception\Process\FailedToTerminateProcessException;
use Hilos\Core\Process;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Log\LogRotationAgent;
use Hilos\Log\LogRotator;
use Hilos\Utils\Exception\LogRotationException;
use Hilos\Utils\Logger;

/**
 * Watchdog that runs the daemon process inside a Docker container: it starts
 * daemon.php, monitors its health, restarts it on failure, and shuts it down
 * gracefully on stop.
 */
class DockerManager extends BaseManager
{
    /** How much of the daemon error log the failed-start escalation quotes. */
    private const int ERROR_LOG_TAIL_BYTES = 2000;

    /** @var bool Flag for daemon restart mode */
    private bool $shouldRestart = false;

    /** @var ?Process Shared Process variable for the class */
    private ?Process $process = null;

    /** @var ?float Timestamp of last error-based restart attempt */
    private ?float $lastErrorRestartTime = null;

    /** @var ?float Timestamp when current process was started */
    private ?float $processStartTime = null;

    /** @var bool Flag to track if restart interval message was logged for current restart */
    private bool $restartIntervalLogged = false;

    /** @var int Daemon starts that died before reaching the minimum uptime, in a row */
    private int $consecutiveFailedStarts = 0;

    /**
     * Run Docker watchdog - main method
     * Starts daemon.php as daemon and monitors its health
     *
     * @param string $daemonScript Path to daemon.php script
     * @throws InvalidScriptPathException If script path validation fails
     * @throws MissingRequiredParameterException If required process-control functions are unavailable
     * @throws FailedToGetStatusException If process status cannot be retrieved
     * @throws CouldNotStartException If daemon process cannot be started
     * @throws FailedToSetNonBlockingException If non-blocking mode cannot be set
     * @throws FailedToReadStdOutException If stdout data cannot be read
     * @throws FailedToSetStdErrException If stderr data cannot be read
     * @throws FailedToTerminateProcessException If the process cannot be terminated
     * @throws FailedToClosePipeException If pipes cannot be closed
     * @throws EnvException If required env values are missing or invalid
     * @throws LogRotationException If log rotation fails
     */
    public function runDockerWatchdog(string $daemonScript): void
    {
        // Validate script path
        $daemonScript = $this->validateScriptPath($daemonScript);

        // Check the availability of required functions
        $this->checkRequiredFunctions(self::PROCESS_FUNCTIONS);

        // Setup error handling and signal handlers
        $this->setupErrorHandling();
        $this->setupSignalHandlers();

        // Rotate logs before starting processes
        $this->rotateLogs();

        Logger::info("Docker watchdog started");

        // Main loop
        while ($this->shouldExit === false || $this->process !== null) {
            $loopStartTime = microtime(true);

            // If the process is not running, and we don't need to exit, start daemon
            if ($this->shouldStartDaemon()) {
                $this->startDaemon($daemonScript);
            }

            $this->tickDaemon();

            $this->sleepWithPreciseTiming($loopStartTime);

            // Process signals
            pcntl_signal_dispatch();
        }

        Logger::info("Docker watchdog stopped");
    }


    /**
     * Atomic check for daemon start necessity
     * Prevents race condition between $shouldExit and $process checks
     * Implements minimum restart interval for error-based restarts
     *
     * @return bool True if daemon should be started
     * @throws EnvException If restart interval env value is missing or invalid
     */
    private function shouldStartDaemon(): bool
    {
        if ($this->shouldExit || $this->process !== null) {
            return false;
        }

        // If this is an explicit restart signal, always allow restart
        if ($this->shouldRestart) {
            return true;
        }

        // For error-based restarts, check minimum interval
        if ($this->lastErrorRestartTime !== null) {
            $minRestartInterval = Hilos::$env->int(EnvConstants::DAEMON_MIN_RESTART_INTERVAL);
            $timeSinceLastRestart = microtime(true) - $this->lastErrorRestartTime;

            if ($timeSinceLastRestart < $minRestartInterval) {
                // Log message only once per restart attempt
                if (!$this->restartIntervalLogged) {
                    $remainingWaitTime = $minRestartInterval - $timeSinceLastRestart;
                    Logger::info(sprintf(
                        "Skipping daemon restart: only %.2f seconds passed since last restart (minimum %d seconds required). Waiting %.2f more seconds.",
                        $timeSinceLastRestart,
                        $minRestartInterval,
                        $remainingWaitTime
                    ));
                    $this->restartIntervalLogged = true;
                }
                return false;
            } else {
                // Enough time has passed - reset flag for next restart attempt
                $this->restartIntervalLogged = false;
            }
        }

        return true;
    }

    /**
     * Check daemon process state
     * Handles process termination and outputs appropriate messages
     *
     * @throws FailedToGetStatusException If process status cannot be retrieved
     * @throws FailedToReadStdOutException If stdout data cannot be read
     * @throws FailedToSetStdErrException If stderr data cannot be read
     * @throws FailedToTerminateProcessException If the process cannot be terminated
     * @throws FailedToClosePipeException If pipes cannot be closed
     * @throws EnvException If the restart interval or failed-start threshold env value is missing or invalid
     */
    private function tickDaemon(): void
    {
        if ($this->process === null) {
            return;
        }

        $this->process->tick();

        // Check if the daemon is running
        $status = $this->process->getStatus();
        if ($status[Process::STATUS_RUNNING] !== true) {
            $uptime = $this->processStartTime === null ? 0.0 : microtime(true) - $this->processStartTime;
            $this->process = null;
            $this->processStartTime = null;

            if ($this->shouldExit) {
                Logger::info("Daemon process has stopped");
            } else {
                if ($this->shouldRestart) {
                    Logger::info("Daemon process has stopped for restart");
                } else {
                    // Error-based restart - record timestamp and reset logging flag
                    $this->lastErrorRestartTime = microtime(true);
                    $this->restartIntervalLogged = false;
                    Logger::error("Daemon process has stopped unexpectedly");
                    $this->recordFailedStart($uptime);
                }
            }
        } elseif ($this->processStartTime !== null && $this->lastErrorRestartTime !== null) {
            // Process is running successfully - check if it worked long enough to reset restart protection
            $processUptime = microtime(true) - $this->processStartTime;
            $minRestartInterval = Hilos::$env->int(EnvConstants::DAEMON_MIN_RESTART_INTERVAL);

            if ($processUptime >= $minRestartInterval) {
                // Process has been running successfully for minimum interval - reset restart protection
                $this->lastErrorRestartTime = null;
                $this->restartIntervalLogged = false;
                $this->consecutiveFailedStarts = 0;
            }
        }
    }

    /**
     * Counts a daemon start that died early and shouts once the run of failures gets long.
     *
     * The watchdog deliberately keeps retrying — the cause may be external and temporary
     * (a full disk, a database that is not up yet) — so the escalation is a loud log
     * record rather than giving up. Without it a node can restart every
     * {@see EnvConstants::DAEMON_MIN_RESTART_INTERVAL} seconds forever while staying
     * silent about it.
     *
     * @param float $uptime How long the failed start survived, in seconds
     * @throws EnvException If the failed-start threshold env value is missing or invalid
     */
    private function recordFailedStart(float $uptime): void
    {
        $this->consecutiveFailedStarts++;
        $threshold = Hilos::$env->int(EnvConstants::DAEMON_FAILED_START_THRESHOLD);
        if ($threshold <= 0 || $this->consecutiveFailedStarts < $threshold) {
            return;
        }

        Logger::error(
            "Daemon failed to start {$this->consecutiveFailedStarts} times in a row"
            . ' (last attempt survived ' . number_format($uptime, 2) . 's).'
            . ' Watchdog keeps retrying. Last daemon errors: ' . $this->readErrorLogTail(),
        );
    }

    /**
     * Reads the tail of the daemon error log for the escalation record.
     *
     * The daemon's stderr is redirected straight to that file rather than to a pipe,
     * so the watchdog cannot read it from the process: {@see Process::getStdErr()} only
     * returns what a pipe descriptor buffered. Reading the file is what actually puts
     * the reason in front of whoever sees the escalation.
     *
     * @return string Tail of the error log, or a note when it cannot be read
     * @throws EnvException If the daemon error log env value is missing or invalid
     */
    private function readErrorLogTail(): string
    {
        $path = Hilos::$env[EnvConstants::DAEMON_ERROR_LOG_FILE];
        // warning-suppressed: the error log may not exist yet, the escalation says it is not readable
        $size = @filesize($path);
        if ($size === false) {
            return '(error log is not readable)';
        }

        $offset = max(0, $size - self::ERROR_LOG_TAIL_BYTES);
        // warning-suppressed: the log can rotate away between the size and the read, the escalation says the tail is empty
        $tail = @file_get_contents($path, false, null, $offset);
        if ($tail === false || trim($tail) === '') {
            return '(error log is empty)';
        }

        return trim($tail);
    }

    /**
     * Start daemon process
     *
     * The daemon's stdout and stderr are opened as {@see Process::DESCRIPTOR_FILE} straight
     * into DAEMON_LOG_FILE and DAEMON_ERROR_LOG_FILE, so `proc_open` creates no pipes 1 and 2
     * and the watchdog never sees the daemon's output: the daemon writes its own logs. Only
     * stdin stays a pipe, and it is the one the watchdog actually uses.
     *
     * @param string $script Path to daemon script
     * @throws CouldNotStartException If daemon process cannot be started
     * @throws FailedToSetNonBlockingException If non-blocking mode cannot be set
     * @throws LogRotationException If log directory cannot be created
     * @throws EnvException If daemon log env values are missing or invalid
     */
    private function startDaemon(string $script): void
    {
        Logger::info("Starting daemon process...");
        $startTime = microtime(true);

        // A daemon that died without stopping its workers leaves them holding its
        // listening sockets, and the next daemon then cannot bind. Orphans are
        // re-parented to PID 1 — this watchdog — so sweeping our own children first
        // makes "the daemon starts alone" an invariant instead of a race.
        new OrphanReaper()->reap();

        // Create log directories if they don't exist
        $logDir = dirname(Hilos::$env[EnvConstants::DAEMON_LOG_FILE]);
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0700, true)) {
                throw new LogRotationException("Cannot create log directory: $logDir");
            }
        }

        // Create Process object with stdout and stderr redirected to files
        $this->process = new Process(
            'php',
            [$script],
            getcwd(),
            [Process::DESCRIPTOR_PIPE, Process::PIPE_READ], // stdin
            [Process::DESCRIPTOR_FILE, Hilos::$env[EnvConstants::DAEMON_LOG_FILE], Process::PIPE_APPEND], // stdout - to log file
            [Process::DESCRIPTOR_FILE, Hilos::$env[EnvConstants::DAEMON_ERROR_LOG_FILE], Process::PIPE_APPEND], // stderr - to error log file
        );

        // Log startup time
        $startupTime = microtime(true) - $startTime;
        $this->processStartTime = microtime(true);

        $this->shouldRestart = false;

        Logger::info("Daemon started (startup time: " . number_format($startupTime * 1000, 2) . "ms)");
    }

    /**
     * Initiate daemon process stop.
     *
     * Stops the current daemon process. No-op if no process is running.
     */
    private function initiateDaemonStop(): void
    {
        if ($this->process === null) {
            return;
        }

        Logger::info("Stopping daemon process...");

        try {
            $this->process->stop();
        } catch (FailedToGetStatusException $e) {
            Logger::error('Failed to get status while stopping daemon: ' . $e->getMessage());
        } catch (FailedToTerminateProcessException $e) {
            Logger::error('Failed to terminate daemon process: ' . $e->getMessage());
        }
    }

    // Implementation of abstract methods from BaseManager

    /**
     * Get manager name for logging.
     *
     * @return string Manager name
     */
    protected function getManagerName(): string
    {
        return "Docker watchdog";
    }

    /**
     * Log error message (process error log + container stdout).
     *
     * @param string $message Error message to log
     */
    protected function logError(string $message): void
    {
        Logger::error($message);
    }

    /**
     * Log exception message (process error log + container stdout).
     *
     * @param string $message Exception message to log
     */
    protected function logException(string $message): void
    {
        Logger::error($message);
    }

    /**
     * Log shutdown message (process error log + container stdout).
     *
     * @param string $message Shutdown message to log
     */
    protected function logShutdown(string $message): void
    {
        Logger::error($message);
    }

    /**
     * Handle error event
     */
    protected function onError(): void
    {
        $this->initiateDaemonStop();
    }

    /**
     * Handle exception event
     */
    protected function onException(): void
    {
        $this->shouldExit = true;
        $this->initiateDaemonStop();
    }

    /**
     * Handle shutdown event
     */
    protected function onShutdown(): void
    {
        $this->shouldExit = true;
        $this->initiateDaemonStop();
    }

    /**
     * Handle shutdown signal event
     */
    protected function onShutdownSignal(): void
    {
        $this->initiateDaemonStop();
    }

    /**
     * Handle restart signal event
     */
    protected function onRestartSignal(): void
    {
        $this->shouldRestart = true;
        $this->initiateDaemonStop();
    }

    /**
     * Rotate log files — move all existing logs under the log root into the archive.
     *
     * Delegates to {@see LogRotator}, which creates the timestamped archive batch and moves each
     * live `*.log` file there. Invoked before starting processes so the live log directory is
     * clean; the same rotator serves the runtime {@see LogRotationAgent}.
     *
     * @throws LogRotationException If log directory operations fail
     */
    private function rotateLogs(): void
    {
        LogRotator::fromEnv()->rotate();
    }

    /**
     * Validate script path
     *
     * @param string $script Path to script
     * @return string Validated path
     * @throws InvalidScriptPathException If the path is invalid
     */
    private function validateScriptPath(string $script): string
    {
        $realPath = realpath($script);
        if ($realPath === false) {
            throw new InvalidScriptPathException("Script path does not exist: $script");
        }

        if (!is_file($realPath)) {
            throw new InvalidScriptPathException("Path is not a file: $script");
        }

        $extension = pathinfo($realPath, PATHINFO_EXTENSION);
        if ($extension !== 'php') {
            throw new InvalidScriptPathException("Script must be a PHP file: $script");
        }

        return $realPath;
    }
}
