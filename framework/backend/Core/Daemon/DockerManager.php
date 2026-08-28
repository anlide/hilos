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
use Hilos\Core\Exception\Process\FailedToWriteStdInException;
use Hilos\Core\Process;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Log\LogRotationAgent;
use Hilos\Log\LogRotator;
use Hilos\Utils\Exception\LogRotationException;
use Hilos\Utils\Logger;
use Throwable;

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

    /** @var ?WatchdogAlertMailer Mailer for the two incidents worth an operator's attention, or null when unconfigured */
    private ?WatchdogAlertMailer $alertMailer = null;

    /** @var bool Whether the watchdog has already mailed its own death, so it is mailed exactly once */
    private bool $deathAlertSent = false;

    /** @var string What the last fatal error said, kept for the alert the shutdown hook sends */
    private string $lastFatalMessage = '';

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
     * @throws FailedToWriteStdInException If buffered input cannot be written to the daemon process
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

        // Resolve the alert mail once, at startup: an unconfigured mailbox is worth saying while
        // the node is healthy, and a watchdog on its way out must not be reading the environment.
        // After the rotation, not before it, or the line saying so is archived the moment it is written.
        // A failure before this line — the path check, the missing functions, the log rotation —
        // therefore leaves no mail at all: there is no mailer yet, and no catch either. That is the
        // price of the order above and not an oversight; building the mailer earlier to cover it, or
        // wrapping the file work in a `try`, is the watchdog growing handlers it has nobody to
        // supervise (HIL-617). Who restarts a watchdog is a question outside Hilos.
        $this->alertMailer = WatchdogAlertMailer::fromEnv();

        Logger::info("Docker watchdog started");

        // Main loop. The catch mails and rethrows rather than recovering: the watchdog is not
        // made resilient by the watchdog, and DockerApplication still decides the exit code.
        // The catch covers the loop only; the note above says what that leaves out.
        try {
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
        } catch (Throwable $e) {
            $this->alertWatchdogDeath(sprintf(
                '%s: %s in %s:%d',
                get_class($e),
                $e->getMessage(),
                basename($e->getFile()),
                $e->getLine(),
            ));

            throw $e;
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
                        'Skipping daemon restart: only %.2f seconds passed since last restart'
                            . ' (minimum %d seconds required). Waiting %.2f more seconds.',
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
     * @throws FailedToWriteStdInException If buffered input cannot be written to the daemon process
     * @throws EnvException If the restart interval, failed-start threshold or error log env value is
     *     missing or invalid
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
                    // The tail is read BEFORE the line below is written. The watchdog's own errors go
                    // to DAEMON_ERROR_LOG_FILE as well - DockerApplication points Logger there - so a
                    // tail read afterwards ends with this very sentence, while the escalation is about
                    // what the DAEMON said before it died, not about the watchdog noticing that it did.
                    $errorLogTail = $this->readErrorLogTail();
                    Logger::error("Daemon process has stopped unexpectedly");
                    $this->recordFailedStart($uptime, $errorLogTail);
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
     * @param string $errorLogTail Tail of the daemon error log, read before the watchdog logged
     *     the death itself into that same file
     * @throws EnvException If the failed-start threshold env value is missing or invalid
     */
    private function recordFailedStart(float $uptime, string $errorLogTail): void
    {
        $this->consecutiveFailedStarts++;
        $threshold = Hilos::$env->int(EnvConstants::DAEMON_FAILED_START_THRESHOLD);
        if ($threshold <= 0 || $this->consecutiveFailedStarts < $threshold) {
            return;
        }

        Logger::error(
            "Daemon failed to start {$this->consecutiveFailedStarts} times in a row"
            . ' (last attempt survived ' . number_format($uptime, 2) . 's).'
            . ' Watchdog keeps retrying. Last daemon errors: ' . $errorLogTail,
        );

        if ($this->consecutiveFailedStarts === $threshold) {
            $this->alertMailer?->sendDaemonFailedStart($this->consecutiveFailedStarts, $uptime, $errorLogTail);
        }
    }

    /**
     * Mails, at most once, that the watchdog is going away after a failure of its own.
     *
     * Both paths that can reach here — an exception out of the run loop, and a PHP fatal caught
     * by the shutdown handler — end the process, so the flag exists to keep a fatal raised while
     * the first alert is in flight from producing a second letter about the same death.
     *
     * @param string $reason What the watchdog failed on, as the caller rendered it
     */
    private function alertWatchdogDeath(string $reason): void
    {
        if ($this->deathAlertSent) {
            return;
        }

        $this->deathAlertSent = true;
        $this->alertMailer?->sendWatchdogExiting($reason);
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
     * Log shutdown message (process error log + container stdout), and keep it for the alert.
     *
     * The shutdown hook that follows has no access to the fatal error itself, so the sentence
     * assembled here is the only description of it the alert can carry.
     *
     * @param string $message Shutdown message to log
     */
    protected function logShutdown(string $message): void
    {
        $this->lastFatalMessage = $message;
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
     * Handle shutdown event - a PHP fatal error, which for this watchdog is the end of the run.
     *
     * This is the one death path where the process still gets to run code: an uncaught exception
     * cannot reach {@see onException()} here, because DockerApplication wraps the whole run in a
     * catch, and the run loop mails from that catch instead.
     *
     * The daemon is stopped first and mailed about second, because the send blocks for as long as
     * `WATCHDOG_ALERT_TIMEOUT_MS` allows: asking for the stop first costs nothing, and it keeps the
     * supervised process from outliving its watchdog by the length of a mail.
     */
    protected function onShutdown(): void
    {
        $this->shouldExit = true;
        $this->initiateDaemonStop();
        $this->alertWatchdogDeath($this->lastFatalMessage);
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
