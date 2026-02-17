<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Daemon\Exception\InvalidScriptPathException;
use Hilos\Core\Exception\Process\CouldNotStartException;
use Hilos\Core\Exception\Process\FailedToClosePipeException;
use Hilos\Core\Exception\Process\FailedToGetStatusException;
use Hilos\Core\Exception\Process\FailedToReadStdOutException;
use Hilos\Core\Exception\Process\FailedToSetNonBlockingException;
use Hilos\Core\Exception\Process\FailedToSetStdErrException;
use Hilos\Core\Exception\Process\FailedToTerminateProcessExceptionException;
use Hilos\Core\Process;
use Hilos\Utils\Env;
use Hilos\Utils\Exception\LogRotationException;
use Hilos\Utils\Exception\MissingEnvironmentVariableException;
use Hilos\Utils\Logger;

/**
 * DockerManager - manages daemon process in Docker container
 *
 * Contains all logic from docker.php:
 * - Daemon process monitoring
 * - Automatic restart on failure
 * - Graceful shutdown
 */
class DockerManager extends BaseManager
{
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

    /**
     * Run Docker watchdog - main method
     * Starts daemon.php as daemon and monitors its health
     *
     * @param string $daemonScript Path to daemon.php script
     * @throws InvalidScriptPathException If script path validation fails
     * @throws FailedToGetStatusException If process status cannot be retrieved
     * @throws CouldNotStartException If daemon process cannot be started
     * @throws FailedToSetNonBlockingException If non-blocking mode cannot be set
     * @throws FailedToReadStdOutException If stdout data cannot be read
     * @throws FailedToSetStdErrException If stderr data cannot be read
     * @throws FailedToTerminateProcessExceptionException If the process cannot be terminated
     * @throws FailedToClosePipeException If pipes cannot be closed
     * @throws MissingEnvironmentVariableException
     * @throws LogRotationException If log rotation fails
     */
    public function runDockerWatchdog(string $daemonScript): void
    {
        // Validate script path
        $daemonScript = $this->validateScriptPath($daemonScript);

        // Check the availability of required functions
        $this->checkRequiredFunctions();

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
     * @throws MissingEnvironmentVariableException
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
            $minRestartInterval = Env::getInt(EnvConstants::DAEMON_MIN_RESTART_INTERVAL, 20);
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
     * @throws FailedToTerminateProcessExceptionException If the process cannot be terminated
     * @throws FailedToClosePipeException If pipes cannot be closed
     * @throws MissingEnvironmentVariableException If environment variable cannot be retrieved
     */
    private function tickDaemon(): void
    {
        if ($this->process === null) {
            return;
        }

        $this->process->tick();
        $stdOut = $this->process->getStdOut();
        if (!empty($stdOut)) {
            Logger::info("Daemon STDOUT: " . $stdOut);
        }

        $stdErr = $this->process->getStdErr();
        if (!empty($stdErr)) {
            Logger::error("Daemon STDERR: " . $stdErr);
        }

        // Check if the daemon is running
        $status = $this->process->getStatus();
        if ($status[Process::STATUS_RUNNING] !== true) {
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
                }
            }
        } elseif ($this->processStartTime !== null && $this->lastErrorRestartTime !== null) {
            // Process is running successfully - check if it worked long enough to reset restart protection
            $processUptime = microtime(true) - $this->processStartTime;
            $minRestartInterval = Env::getInt(EnvConstants::DAEMON_MIN_RESTART_INTERVAL, 20);

            if ($processUptime >= $minRestartInterval) {
                // Process has been running successfully for minimum interval - reset restart protection
                $this->lastErrorRestartTime = null;
                $this->restartIntervalLogged = false;
            }
        }
    }

    /**
     * Start daemon process
     *
     * @param string $script Path to daemon script
     * @throws CouldNotStartException If daemon process cannot be started
     * @throws FailedToSetNonBlockingException If non-blocking mode cannot be set
     * @throws LogRotationException If log directory cannot be created
     * @throws MissingEnvironmentVariableException
     */
    private function startDaemon(string $script): void
    {
        Logger::info("Starting daemon process...");
        $startTime = microtime(true);

        // Create log directories if they don't exist
        $logDir = dirname(Env::get(EnvConstants::DAEMON_LOG_FILE));
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
            [Process::DESCRIPTOR_FILE, Env::get(EnvConstants::DAEMON_LOG_FILE), Process::PIPE_APPEND], // stdout - to log file
            [Process::DESCRIPTOR_FILE, Env::get(EnvConstants::DAEMON_ERROR_LOG_FILE), Process::PIPE_APPEND], // stderr - to error log file
        );

        // Log startup time
        $startupTime = microtime(true) - $startTime;
        $this->processStartTime = microtime(true);

        $this->shouldRestart = false;

        Logger::info("Daemon started (startup time: " . number_format($startupTime * 1000, 2) . "ms)");
    }

    /**
     * Initiate daemon process stop
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
            Logger::errorLog('Failed to get status while stopping daemon: ' . $e->getMessage());
        } catch (FailedToTerminateProcessExceptionException $e) {
            Logger::errorLog('Failed to terminate daemon process: ' . $e->getMessage());
        }
    }

    // Implementation of abstract methods from BaseManager

    /**
     * Get manager name for logging
     */
    protected function getManagerName(): string
    {
        return "Docker watchdog";
    }

    /**
     * Log error message (file + system log)
     * @throws MissingEnvironmentVariableException
     */
    protected function logError(string $message): void
    {
        Logger::errorLog($message, 3, Env::get(EnvConstants::DAEMON_ERROR_LOG_FILE));
    }

    /**
     * Log exception message (file + system log)
     * @throws MissingEnvironmentVariableException
     */
    protected function logException(string $message): void
    {
        Logger::errorLog($message, 3, Env::get(EnvConstants::DAEMON_ERROR_LOG_FILE));
    }

    /**
     * Log shutdown message (file + system log)
     * @throws MissingEnvironmentVariableException
     */
    protected function logShutdown(string $message): void
    {
        Logger::errorLog($message, 3, Env::get(EnvConstants::DAEMON_ERROR_LOG_FILE));
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
     * Rotate log files - move all existing logs to archive directory
     *
     * Creates archive/{timestamp} directory and moves all .log files there.
     * This should be called before starting processes to ensure clean log directory.
     *
     * @throws LogRotationException If log directory operations fail
     */
    private function rotateLogs(): void
    {
        // Determine log directory from daemon log file path
        // DAEMON_LOG_FILE must be set in environment configuration
        $daemonLogFile = Env::get(EnvConstants::DAEMON_LOG_FILE);
        $logDirectory = dirname($daemonLogFile);

        // If log directory doesn't exist, nothing to rotate
        if (!is_dir($logDirectory)) {
            return;
        }

        // Find all .log files in the log directory
        $logFiles = glob($logDirectory . '/*.log');

        // If no log files found, nothing to rotate
        if (empty($logFiles)) {
            return;
        }

        // Create archive directory structure
        $archiveDir = $logDirectory . '/archive';
        if (!is_dir($archiveDir)) {
            if (!mkdir($archiveDir, 0755, true)) {
                throw new LogRotationException("Cannot create archive directory: $archiveDir");
            }
        }

        // Create timestamp directory (format: Y-m-d-H-i-s)
        $timestamp = date('Y-m-d-H-i-s');
        $timestampDir = $archiveDir . '/' . $timestamp;
        if (!mkdir($timestampDir, 0755, true)) {
            throw new LogRotationException("Cannot create timestamp directory: $timestampDir");
        }

        // Move all log files to archive
        $movedCount = 0;
        foreach ($logFiles as $logFile) {
            $filename = basename($logFile);
            $targetPath = $timestampDir . '/' . $filename;

            if (!rename($logFile, $targetPath)) {
                Logger::errorLog("Failed to move log file: $logFile to $targetPath");
                continue;
            }

            $movedCount++;
        }

        if ($movedCount > 0) {
            Logger::info("Log rotation: moved $movedCount log file(s) to archive/$timestamp/");
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
