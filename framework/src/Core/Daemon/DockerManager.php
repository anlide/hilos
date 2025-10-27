<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Core\Process;
use Hilos\Exception\Process\CouldNotStart;
use Hilos\Exception\Process\FailedToClosePipe;
use Hilos\Exception\Process\FailedToGetStatus;
use Hilos\Exception\Process\FailedToReadStdOut;
use Hilos\Exception\Process\FailedToSetNonBlocking;
use Hilos\Exception\Process\FailedToSetStdErr;
use Hilos\Exception\Process\FailedToTerminateProcess;
use Hilos\Utils\Constants\CliConstants;
use RuntimeException;

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

    /**
     * Run Docker watchdog - main method
     * Starts daemon.php as daemon and monitors its health
     *
     * @param string $daemonScript Path to daemon.php script
     * @throws RuntimeException If required, functions are not available
     * @throws FailedToGetStatus If process status cannot be retrieved
     * @throws CouldNotStart If daemon process cannot be started
     * @throws FailedToSetNonBlocking If non-blocking mode cannot be set
     * @throws FailedToReadStdOut If stdout data cannot be read
     * @throws FailedToSetStdErr If stderr data cannot be read
     * @throws FailedToTerminateProcess If the process cannot be terminated
     * @throws FailedToClosePipe If pipes cannot be closed
     */
    public function runDockerWatchdog(string $daemonScript = __DIR__ . '/../../Bootstrap/daemon.php'): void
    {
        // Validate script path
        $daemonScript = $this->validateScriptPath($daemonScript);

        // Check the availability of required functions
        $this->checkRequiredFunctions();

        // Setup error handling and signal handlers
        $this->setupErrorHandling();
        $this->setupSignalHandlers();

        echo "Docker watchdog started.\n";

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

        echo "Docker watchdog stopped.\n";
    }


    /**
     * Atomic check for daemon start necessity
     * Prevents race condition between $shouldExit and $process checks
     *
     * @return bool True if daemon should be started
     */
    private function shouldStartDaemon(): bool
    {
        return !$this->shouldExit && $this->process === null;
    }

    /**
     * Check daemon process state
     * Handles process termination and outputs appropriate messages
     *
     * @throws FailedToGetStatus If process status cannot be retrieved
     * @throws FailedToReadStdOut If stdout data cannot be read
     * @throws FailedToSetStdErr If stderr data cannot be read
     * @throws FailedToTerminateProcess If the process cannot be terminated
     * @throws FailedToClosePipe If pipes cannot be closed
     */
    private function tickDaemon(): void
    {
        if ($this->process === null) {
            return;
        }

        $this->process->tick();
        $stdOut = $this->process->getStdOut();
        if (!empty($stdOut)) {
            echo "Daemon STDOUT: " . $stdOut . "\n";
        }

        $stdErr = $this->process->getStdErr();
        if (!empty($stdErr)) {
            echo "Daemon STDERR: " . $stdErr . "\n";
        }

        // Check if the daemon is running
        $status = $this->process->getStatus();
        if ($status[Process::STATUS_RUNNING] !== true) {
            $this->process = null;

            if ($this->shouldExit) {
                echo "Daemon process has stopped.\n";
            } else {
                if ($this->shouldRestart) {
                    echo "Daemon process has stopped for restart.\n";
                } else {
                    echo "Daemon process has stopped unexpectedly.\n";
                }
            }
        }
    }

    /**
     * Start daemon process
     *
     * @param string $script Path to daemon script
     * @throws CouldNotStart If daemon process cannot be started
     * @throws FailedToSetNonBlocking If non-blocking mode cannot be set
     * @throws RuntimeException If log directory cannot be created
     */
    private function startDaemon(string $script): void
    {
        echo "Starting daemon process...\n";
        $startTime = microtime(true);

        // Create log directories if they don't exist
        $logDir = dirname(CliConstants::DAEMON_LOG_FILE);
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0700, true)) {
                throw new RuntimeException("Cannot create log directory: $logDir");
            }
        }

        // Create Process object with stdout and stderr redirected to files
        $this->process = new Process(
            'php',
            [$script],
            getcwd(),
            [Process::DESCRIPTOR_PIPE, Process::PIPE_READ], // stdin
            [Process::DESCRIPTOR_FILE, CliConstants::DAEMON_LOG_FILE, Process::PIPE_APPEND], // stdout - to log file
            [Process::DESCRIPTOR_FILE, CliConstants::DAEMON_ERROR_LOG_FILE, Process::PIPE_APPEND], // stderr - to error log file
        );

        // Log startup time
        $startupTime = microtime(true) - $startTime;
        $this->shouldRestart = false;

        echo "Daemon started (startup time: " . number_format($startupTime * 1000, 2) . "ms).\n";
    }

    /**
     * Initiate daemon process stop
     */
    private function initiateDaemonStop(): void
    {
        if ($this->process === null) {
            return;
        }

        echo "Stopping daemon process...\n";

        try {
            $this->process->stop();
        } catch (FailedToGetStatus $e) {
            error_log('Failed to get status while stopping daemon: ' . $e->getMessage());
        } catch (FailedToTerminateProcess $e) {
            error_log('Failed to terminate daemon process: ' . $e->getMessage());
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
     */
    protected function logError(string $message): void
    {
        error_log($message, 3, CliConstants::DAEMON_ERROR_LOG_FILE);
        error_log($message);
    }

    /**
     * Log exception message (file + system log)
     */
    protected function logException(string $message): void
    {
        error_log($message, 3, CliConstants::DAEMON_ERROR_LOG_FILE);
        error_log($message);
    }

    /**
     * Log shutdown message (file + system log)
     */
    protected function logShutdown(string $message): void
    {
        error_log($message, 3, CliConstants::DAEMON_ERROR_LOG_FILE);
        error_log($message);
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
     * Validate script path
     *
     * @param string $script Path to script
     * @return string Validated path
     * @throws RuntimeException If the path is invalid
     */
    private function validateScriptPath(string $script): string
    {
        $realPath = realpath($script);
        if ($realPath === false) {
            throw new RuntimeException("Script path does not exist: $script");
        }

        if (!is_file($realPath)) {
            throw new RuntimeException("Path is not a file: $script");
        }

        $extension = pathinfo($realPath, PATHINFO_EXTENSION);
        if ($extension !== 'php') {
            throw new RuntimeException("Script must be a PHP file: $script");
        }

        return $realPath;
    }
}
