<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\LogRotationConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Daemon\Exception\InvalidScriptPathException;
use Hilos\Core\Exception\MissingRequiredParameterException;
use Hilos\Core\Exception\Process\CouldNotStartException;
use Hilos\Core\Exception\Process\FailedToClosePipeException;
use Hilos\Core\Exception\Process\FailedToGetStatusException;
use Hilos\Core\Exception\Process\FailedToSetNonBlockingException;
use Hilos\Core\Exception\Process\FailedToTerminateProcessException;
use Hilos\Core\Exception\ProcessException;
use Hilos\Core\Process;
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\NonProductionGate;
use Hilos\Fs\Exception\DirectoryCreateException;
use Hilos\Fs\FsPath;
use Hilos\Hilos;
use Hilos\Log\DaemonLogAddress;
use Hilos\Log\DaemonRawStream;
use Hilos\Log\LogRootOwnershipGuard;
use Hilos\Log\LogRotator;
use Hilos\Log\LogStoreAgent;
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
    /** @var list<string> The watchdog receives signals through libevent, not PCNTL dispatch */
    protected const array SIGNAL_FUNCTIONS = ['pcntl_sigprocmask'];

    /** How much of what the daemon printed the crash record quotes, across all its streams. */
    private const int DAEMON_OUTPUT_TAIL_BYTES = 2000;

    /** @var float Longest gap between child observations when no earlier work is due */
    private const float STATUS_CHECK_INTERVAL_SECONDS = 60.0;

    /** @var bool Flag for daemon restart mode */
    private bool $shouldRestart = false;

    /** @var ?Process Shared Process variable for the class */
    private ?Process $process = null;

    /** @var ?DaemonOutputQuote What the daemon has printed since this start, or null before the first start */
    private ?DaemonOutputQuote $outputQuote = null;

    /** @var ?float Monotonic seconds of the last unexpected daemon stop */
    private ?float $lastErrorRestartTime = null;

    /** @var ?float Monotonic seconds when the current process was started */
    private ?float $processStartTime = null;

    /** @var bool Whether normal shutdown has already asked the current daemon to stop */
    private bool $daemonStopRequested = false;

    private ?DaemonHangMonitor $hangMonitor = null;

    /** EOF disables only this child's read watch; silence and Process still decide its fate. */
    private bool $heartbeatReadEnded = false;

    /** Original quote captured before the watchdog writes the hang incident into its shared log. */
    private ?string $hangOutputTail = null;

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
     * @throws FailedToClosePipeException If pipes cannot be closed
     * @throws ProcessException If watchdog signal installation or event waiting fails
     * @throws EnvException If required env values are missing or invalid
     * @throws LogRotationException If log rotation fails
     */
    public function runDockerWatchdog(string $daemonScript): void
    {
        // Validate script path
        $daemonScript = $this->validateScriptPath($daemonScript);

        // Check the availability of required functions
        $this->checkRequiredFunctions(self::PROCESS_FUNCTIONS);

        // The watchdog owns its signal receiver before checking or starting any child.
        $this->setupErrorHandling();
        $wait = new WatchdogWait();
        try {
            // Before anything that consequence follows from, and before the rotation that silently
            // skips itself when the log directory is unknown.
            $this->announceMissingLogAddresses();
            $this->rotateLogs();

            // Resolve mail after rotation, so the line about an unconfigured mailbox stays live.
            // Startup failures still leave without an alert: only the loop catch mails the failure
            // (HIL-617). The outer finally only releases this process's signal watches.
            $this->alertMailer = WatchdogAlertMailer::fromEnv();

            Logger::info("Docker watchdog started");

            // The watchdog does not recover from its own failure. DockerApplication decides the exit code.
            try {
                while ($this->shouldExit === false || $this->process !== null) {
                    $this->applySignals($wait);
                    $wait->watchHeartbeat(null);
                    $this->tickDaemon();

                    if ($this->shouldStartDaemon()) {
                        $this->startDaemon($daemonScript, $wait);
                        // Observe a new descriptor on the next pass without waiting for a signal.
                        continue;
                    }
                    if ($this->shouldExit && $this->process === null) {
                        break;
                    }

                    if ($this->process !== null && !$this->heartbeatReadEnded) {
                        $wait->watchHeartbeat($this->process->getPipe(MasterHeartbeat::DESCRIPTOR));
                    }
                    $wait->wait($this->nextWaitSeconds());
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
        } finally {
            $wait->close();
        }
    }


    /**
     * Applies control signals outside libevent callbacks. SIGCHLD only wakes the
     * next Process observation; it never collects a child's status here.
     *
     * @param WatchdogWait $wait Watchdog's sole signal receiver
     * @throws ProcessException If pending events cannot be dispatched
     */
    private function applySignals(WatchdogWait $wait): void
    {
        $signals = $wait->consumeSignals();
        if (in_array(SIGTERM, $signals, true) || in_array(SIGINT, $signals, true)) {
            $this->handleShutdown();
        } elseif (in_array(SIGHUP, $signals, true)) {
            $this->handleRestart();
        }
        foreach ($signals as $signal) {
            if ($signal === SIGUSR1 || $signal === SIGUSR2) {
                $this->applyHeartbeatTestRequest($signal);
            }
        }
    }

    /** @param int $signal One addressed test request, applied only to the current child and phase */
    private function applyHeartbeatTestRequest(int $signal): void
    {
        if (!NonProductionGate::admitted()) {
            Logger::info("Watchdog test request $signal refused: environment is not admitted.");
            return;
        }
        if ($this->process === null || $this->hangMonitor === null || $this->shouldExit) {
            Logger::info("Watchdog test request $signal ignored: no eligible child.");
            return;
        }
        if ($signal === SIGUSR1 && $this->hangMonitor->staleAt() !== null) {
            $this->hangMonitor->bringStaleToNow($this->monotonicSeconds());
        } elseif ($signal === SIGUSR2 && $this->hangMonitor->killAt() !== null) {
            $this->hangMonitor->bringStopToNow($this->monotonicSeconds());
        } else {
            Logger::info("Watchdog test request $signal ignored: inactive deadline.");
            return;
        }
        Logger::info("Watchdog test request $signal applied to the current deadline.");
    }

    /**
     * @return float Seconds until the next restart, protection reset or fallback observation
     * @throws EnvException If the restart interval is missing or invalid
     */
    private function nextWaitSeconds(): float
    {
        $timeout = self::STATUS_CHECK_INTERVAL_SECONDS;
        $now = $this->monotonicSeconds();
        $hangDeadline = $this->hangMonitor?->killAt() ?? $this->hangMonitor?->staleAt();
        if ($hangDeadline !== null) {
            $timeout = min($timeout, $hangDeadline - $now);
        }
        if ($this->lastErrorRestartTime !== null) {
            $minRestartInterval = Hilos::$env[EnvConstants::DAEMON_MIN_RESTART_INTERVAL]->int();
            if ($this->process === null && !$this->shouldExit && !$this->shouldRestart) {
                $timeout = min($timeout, $this->lastErrorRestartTime + $minRestartInterval - $now);
            } elseif ($this->process !== null && $this->processStartTime !== null) {
                $timeout = min($timeout, $this->processStartTime + $minRestartInterval - $now);
            }
        }
        return max(0.0, $timeout);
    }

    /** @return float Monotonic seconds for watchdog durations and deadlines */
    private function monotonicSeconds(): float
    {
        return hrtime(true) / (TimeConstants::NS_PER_MILLISECOND * TimeConstants::MS_PER_SECOND);
    }

    /**
     * Checks the exit request, current descriptor and minimum restart interval.
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
            $minRestartInterval = Hilos::$env[EnvConstants::DAEMON_MIN_RESTART_INTERVAL]->int();
            $timeSinceLastRestart = $this->monotonicSeconds() - $this->lastErrorRestartTime;

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
     * @throws FailedToClosePipeException If pipes cannot be closed
     * @throws FailedToTerminateProcessException If hang termination cannot be requested
     * @throws ProcessException If the heartbeat pipe cannot be read
     * @throws EnvException If the restart interval or failed-start threshold env value is missing or invalid
     */
    private function tickDaemon(): void
    {
        if ($this->process === null) {
            return;
        }

        if ($this->shouldExit) {
            $this->hangMonitor?->suspend();
        }
        $this->readHeartbeat();
        $status = $this->process->observe();
        if ($status[Process::STATUS_RUNNING] !== true) {
            $uptime = $this->processStartTime === null ? 0.0 : $this->monotonicSeconds() - $this->processStartTime;
            $exitCode = $this->process->getExitCode();
            $termSignal = $this->process->getTermSignal();
            $this->process = null;
            $this->processStartTime = null;

            if ($this->hangOutputTail !== null) {
                Logger::error('Daemon process has stopped after main-loop hang: ' . DaemonCrashReason::renderStatus($exitCode, $termSignal));
                if (!$this->shouldExit && !$this->shouldRestart) {
                    $this->lastErrorRestartTime = $this->monotonicSeconds();
                    $this->restartIntervalLogged = false;
                    $this->recordFailedStart($uptime, $this->hangOutputTail);
                }
            } elseif ($this->shouldExit) {
                Logger::info("Daemon process has stopped");
            } else {
                if ($this->shouldRestart) {
                    Logger::info("Daemon process has stopped for restart");
                } else {
                    // Error-based restart - record timestamp and reset logging flag
                    $this->lastErrorRestartTime = $this->monotonicSeconds();
                    $this->restartIntervalLogged = false;
                    // Keep the tail read ahead of the watchdog's own line: the order is essential because
                    // the watchdog reads a file it also writes, and reading after logging would quote itself.
                    $outputTail = $this->readDaemonOutputTail();
                    Logger::error(
                        'Daemon process has stopped unexpectedly: '
                        . DaemonCrashReason::render($exitCode, $termSignal, $uptime, $outputTail),
                    );
                    $this->recordFailedStart($uptime, $outputTail);
                }
            }
            $this->hangMonitor = null;
            $this->heartbeatReadEnded = false;
            $this->hangOutputTail = null;
            $this->daemonStopRequested = false;
            return;
        }

        if ($this->shouldExit && !$this->daemonStopRequested) {
            $this->daemonStopRequested = true;
            $this->initiateDaemonStop($status);
        }

        $this->advanceHangStop($status, $this->monotonicSeconds());

        if ($this->processStartTime !== null && $this->lastErrorRestartTime !== null) {
            // Process is running successfully - check if it worked long enough to reset restart protection
            $processUptime = $this->monotonicSeconds() - $this->processStartTime;
            $minRestartInterval = Hilos::$env[EnvConstants::DAEMON_MIN_RESTART_INTERVAL]->int();

            if ($processUptime >= $minRestartInterval) {
                // Process has been running successfully for minimum interval - reset restart protection
                $this->lastErrorRestartTime = null;
                $this->restartIntervalLogged = false;
                $this->consecutiveFailedStarts = 0;
            }
        }
    }

    /** @throws ProcessException When the current child's nonblocking heartbeat read fails */
    private function readHeartbeat(): void
    {
        if ($this->heartbeatReadEnded) {
            return;
        }
        $pipe = $this->process->getPipe(MasterHeartbeat::DESCRIPTOR);
        if ($pipe === null) {
            $this->heartbeatReadEnded = true;
            return;
        }
        $marks = fread($pipe, MasterHeartbeat::READ_BYTES);
        if ($marks === false) {
            throw new ProcessException('Cannot read the daemon main-loop heartbeat.');
        }
        if ($marks !== '' && strspn($marks, MasterHeartbeat::MARK) === strlen($marks)) {
            $wasArmed = $this->hangMonitor?->staleAt() !== null;
            $this->hangMonitor?->markReceived($this->monotonicSeconds());
            if (!$wasArmed && $this->hangMonitor?->staleAt() !== null) {
                Logger::info('Daemon main-loop heartbeat received; hang detection armed');
            }
        }
        if (feof($pipe)) {
            $this->heartbeatReadEnded = true;
        }
    }

    /**
     * @param array<string, mixed> $status One live Process snapshot, shared with ordinary control
     * @param float $now Monotonic observation before hang diagnostics
     * @throws FailedToTerminateProcessException When the single TERM or KILL request fails
     */
    private function advanceHangStop(array $status, float $now): void
    {
        if ($this->hangMonitor === null) {
            return;
        }
        if (!$this->shouldExit && $this->hangMonitor->isStale($now)) {
            $this->hangOutputTail = $this->readDaemonOutputTail();
            Logger::error(sprintf(
                'Daemon main loop stalled: no completed pass for %.2f seconds; sending SIGTERM. Last daemon output: ',
                $this->hangMonitor->silenceSeconds($now),
            ) . $this->hangOutputTail);
            $this->process->stopFromStatus($status);
            $this->daemonStopRequested = true;
            $this->hangMonitor->beginStop($this->monotonicSeconds());
        }
        $killAt = $this->hangMonitor->killAt();
        if ($killAt !== null && $now >= $killAt) {
            Logger::error('Daemon main loop still stalled after stop grace; sending SIGKILL.');
            $this->process->killFromStatus($status);
            $this->hangMonitor->markKilled();
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
     * @param string $outputTail Tail of what the daemon printed, for the alert email
     * @throws EnvException If the failed-start threshold env value is missing or invalid
     */
    private function recordFailedStart(float $uptime, string $outputTail): void
    {
        $this->consecutiveFailedStarts++;
        $threshold = Hilos::$env[EnvConstants::DAEMON_FAILED_START_THRESHOLD]->int();
        if ($threshold <= 0 || $this->consecutiveFailedStarts < $threshold) {
            return;
        }

        Logger::error(
            "Daemon failed to start {$this->consecutiveFailedStarts} times in a row"
            . ' (last attempt survived ' . number_format($uptime, 2) . 's).'
            . ' Watchdog keeps retrying; each failure is logged with its own reason.',
        );

        if ($this->consecutiveFailedStarts === $threshold) {
            $this->alertMailer?->sendDaemonFailedStart($this->consecutiveFailedStarts, $uptime, $outputTail);
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
     * Reads what the daemon printed during this run for the crash record and alert email.
     *
     * Three streams are quoted:
     * 1. Node error log — captures deaths from unhandled E_WARNING where the error handler consumed the text;
     * 2. Stdout raw twin — captures PHP fatals bypassing shutdownHandler;
     * 3. Stderr raw twin — captures master environment failures printed to error_log.
     *
     * @return string Tail of what the daemon printed, or an indicator note
     */
    private function readDaemonOutputTail(): string
    {
        // Branch is unreachable in practice: crashes only happen after startDaemon has run.
        return $this->outputQuote?->render(self::DAEMON_OUTPUT_TAIL_BYTES) ?? DaemonOutputQuote::PRINTED_NOTHING;
    }

    /**
     * Start daemon process
     *
     * The daemon's stdout and stderr are opened as {@see Process::DESCRIPTOR_FILE} straight
     * into the raw pair beside DAEMON_LOG_FILE and DAEMON_ERROR_LOG_FILE, so `proc_open` creates
     * no pipes 1 and 2 and the watchdog never sees the daemon's output: the daemon writes its own
     * logs. Stdin reads /dev/null; the watchdog has no input to send.
     *
     * The files are the raw pair rather than the two the Logger writes because these descriptors
     * stay open for the life of the daemon: rotation renames a file away, the descriptor follows
     * the inode, and everything printed past the Logger would keep going into the closed batch
     * (HIL-480). The raw pair is the one runtime rotation leaves alone, so it is replaced here,
     * by the start rotation that runs a few lines before this one.
     *
     * An address the environment does not name ({@see DaemonLogAddress}) hands the child this
     * process's own descriptor instead of refusing the start: the daemon is the one that refuses,
     * and its list of missing names has to reach docker logs to be of any use. A descriptor rather
     * than a path to /dev/stdout, because in a container that path resolves to an anonymous pipe,
     * which open() answers with ENOENT.
     *
     * @param string $script Path to daemon script
     * @param WatchdogWait $wait Receiver checked again before opening the child
     * @throws CouldNotStartException If daemon process cannot be started
     * @throws FailedToSetNonBlockingException If non-blocking mode cannot be set
     * @throws ProcessException If pending events cannot be dispatched
     * @throws LogRotationException If log directory cannot be created
     */
    private function startDaemon(string $script, WatchdogWait $wait): void
    {
        Logger::info("Starting daemon process...");
        $startTime = $this->monotonicSeconds();

        // A daemon that died without stopping its workers leaves them holding its
        // listening sockets, and the next daemon then cannot bind. Orphans are
        // re-parented to PID 1 — this watchdog — so sweeping our own children first
        // makes "the daemon starts alone" an invariant instead of a race.
        new OrphanReaper()->reap();

        $this->applySignals($wait);
        if ($this->shouldExit) {
            return;
        }

        $logFile = DaemonLogAddress::configured(EnvConstants::DAEMON_LOG_FILE);
        $errorLogFile = DaemonLogAddress::configured(EnvConstants::DAEMON_ERROR_LOG_FILE);

        // Create log directories if they don't exist. Only when an address names one at all: an
        // unnamed address sends the stream to our own descriptor, and there is no directory in
        // that to create.
        if ($logFile !== null) {
            $logDir = dirname($logFile);
            try {
                FsPath::ensureDirectory($logDir, LogRootOwnershipGuard::LOG_ROOT_MODE);
            } catch (DirectoryCreateException $failure) {
                throw new LogRotationException("Cannot create log directory: $logDir", 0, $failure);
            }
        }

        // (1) The raw pair is opened Process::PIPE_APPEND (:401,:405) and survives a daemon restart,
        // while startup rotation runs once per watchdog run (:108), so without a mark the quote would
        // name an earlier crash. (2) The watchdog writes its own ERROR lines to the same daemon-error.log
        // (DockerApplication::run()), and taking the mark here leaves its previous line behind the quote.
        $this->outputQuote = DaemonOutputQuote::markedAt($logFile, $errorLogFile);

        // Create Process object with stdout and stderr redirected to files. An address that was
        // never named has no raw stream to sit beside, so there is nothing to hand pathFor(); the
        // child gets our own descriptor, which is what docker logs is reading.
        $this->applySignals($wait);
        if ($this->shouldExit) {
            return;
        }
        $this->process = new Process(
            'php',
            [$script, MasterHeartbeat::ARGUMENT],
            getcwd(),
            [Process::DESCRIPTOR_FILE, '/dev/null', Process::PIPE_READ], // stdin
            // stdout - to the raw output file, or our own stdout when unconfigured
            $logFile === null
                ? [Process::DESCRIPTOR_REDIRECT, 1]
                : [Process::DESCRIPTOR_FILE, DaemonRawStream::pathFor($logFile), Process::PIPE_APPEND],
            // stderr - to the raw error file, or our own stderr when unconfigured
            $errorLogFile === null
                ? [Process::DESCRIPTOR_REDIRECT, 2]
                : [Process::DESCRIPTOR_FILE, DaemonRawStream::pathFor($errorLogFile), Process::PIPE_APPEND],
            [MasterHeartbeat::DESCRIPTOR => [Process::DESCRIPTOR_PIPE, Process::PIPE_WRITE]],
        );

        $this->hangMonitor = new DaemonHangMonitor();
        $this->heartbeatReadEnded = false;
        $this->hangOutputTail = null;

        // Log startup time
        $this->processStartTime = $this->monotonicSeconds();
        $startupTime = $this->processStartTime - $startTime;

        $this->shouldRestart = false;
        $this->daemonStopRequested = false;

        Logger::info("Daemon started (startup time: " . number_format($startupTime * TimeConstants::MS_PER_SECOND, 2) . "ms)");
    }

    /**
     * Initiate daemon process stop.
     *
     * Stops the current daemon process. No-op if no process is running.
     *
     * @param ?array<string, mixed> $status Current snapshot, or null for an emergency stop that reads its own status
     */
    private function initiateDaemonStop(?array $status = null): void
    {
        if ($this->process === null) {
            return;
        }

        Logger::info("Stopping daemon process...");

        try {
            if ($status === null) {
                $this->process->stop();
            } else {
                $this->process->stopFromStatus($status);
            }
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

    /** Defers the stop to tickDaemon(), which uses that pass's process snapshot. */
    protected function onShutdownSignal(): void
    {
        $this->shouldRestart = false;
    }

    /** Records restart intent; tickDaemon() sends the stop from its current snapshot. */
    protected function onRestartSignal(): void
    {
        $this->shouldRestart = true;
    }

    /**
     * Says once, at startup, which daemon log addresses the environment leaves unset.
     *
     * At warning level and not error: the watchdog has no main log configured either, so a warning
     * is echoed to stdout ({@see Logger}) and lands in docker logs, without being written to an
     * error file that may not exist. An error level would read as a refusal, and refusing over
     * these names is exactly what this leaf removes.
     */
    private function announceMissingLogAddresses(): void
    {
        $missing = DaemonLogAddress::missingNames();
        if ($missing === []) {
            return;
        }

        Logger::warning(
            'Missing daemon log address, so that stream goes to the container log instead: '
            . implode(', ', $missing)
            . '. The daemon names every missing value when it starts.',
        );
    }

    /**
     * Rotate log files — move all existing logs under the log root into the archive.
     *
     * Delegates to {@see LogRotator}, which creates the timestamped archive batch and moves each
     * live `*.log` file there. Invoked before starting processes so the live log directory is
     * clean; the same rotator serves the runtime {@see LogStoreAgent}.
     *
     * This is the start-path rotator, which spares nothing: the previous daemon is dead, so the
     * raw output pair the runtime rotator keeps live is here just another archived file, and the
     * next {@see startDaemon()} opens it anew. The line about the batch is written here rather
     * than inside the rotator, which reports what it did and lets each caller speak in its own
     * journal.
     *
     * Failures are reported at error level so they reach the container log with the rest of the
     * watchdog's voice and the error file at the same time, the watchdog having no main log of its own.
     *
     * @throws LogRotationException If log directory operations fail
     */
    private function rotateLogs(): void
    {
        // Nothing to rotate while the log directory is unknown, and nothing to say about it
        // either: announceMissingLogAddresses() has already named it, just before this call.
        if (DaemonLogAddress::configured(EnvConstants::DAEMON_LOG_FILE) === null) {
            return;
        }

        $report = LogRotator::forStartup()->rotate();

        $batchDirName = $report->batchDirName;
        if ($batchDirName !== null && $report->movedCount > 0) {
            // Named as the staging directory and not the archive (HIL-870): the start path lands
            // there like every other rotation, and the carrier moves the batch on once the node is
            // up. Saying "archive" here would send an operator to a directory that is still empty.
            $stagingName = LogRotationConstants::LOG_STAGING_SUBDIR_NAME;
            Logger::info("Log rotation: moved {$report->movedCount} log file(s) to {$stagingName}/{$batchDirName}/");
        }

        foreach ($report->failedFiles as $failedFile) {
            Logger::error("Log rotation could not move {$failedFile}");
        }
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
