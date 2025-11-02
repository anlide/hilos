<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Hilos\Core\Process;
use Hilos\Exception\MissingEnvironmentVariableException;
use Hilos\Exception\Process\CouldNotStartException;
use Hilos\Exception\Process\FailedToClosePipeException;
use Hilos\Exception\Process\FailedToGetStatusException;
use Hilos\Exception\Process\FailedToReadStdOutException;
use Hilos\Exception\Process\FailedToSetNonBlockingException;
use Hilos\Exception\Process\FailedToSetStdErrException;
use Hilos\Exception\Process\FailedToTerminateProcessExceptionException;
use Hilos\Exception\SocketException;
use Hilos\Socket\Client\Interface\WorkerClientInterface;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Utils\Constants\EnvConstants;
use Hilos\Utils\Helpers\ArgumentHelper;
use Hilos\Utils\Env;

/**
 * WorkerServer - Worker communication server implementation
 *
 * Manages worker server socket and accepts incoming connections from workers.
 * Also manages worker processes lifecycle - starts, monitors and stops them.
 * Works with epoll in daemon main loop.
 */
class WorkerServer extends AbstractServer
{
    /** @var array<int, Process> Regular worker processes indexed by worker index */
    private array $regularWorkers = [];

    /** @var array<int, Process> Monopolistic worker processes indexed by worker index */
    private array $monopolisticWorkers = [];

    /** @var array<int> Available regular worker indices (sorted, can be reused) */
    private array $availableRegularIndices = [];

    /** @var array<int> Available monopolistic worker indices (sorted, can be reused) */
    private array $availableMonopolisticIndices = [];

    /** @var int Next regular worker index to assign if no available */
    private int $nextRegularWorkerIndex = 1;

    /** @var int Next monopolistic worker index to assign if no available */
    private int $nextMonopolisticWorkerIndex = 1;

    /** @var array<int, float> Worker indices waiting for graceful shutdown [workerIndex => startTime] */
    private array $workersWaitingShutdown = [];

    /** @var float Graceful shutdown timeout in seconds */
    private const float SHUTDOWN_TIMEOUT = 5.0;

    /** @var string Path to worker bootstrap script */
    private string $workerScript;

    /** @var string Working directory for worker processes */
    private string $workingDirectory;

    /** @var int Minimum number of regular workers */
    private int $minRegular;

    /** @var int Minimum number of monopolistic workers */
    private int $minMonopolistic;

    /** @var int Maximum number of regular workers */
    private int $maxRegular;

    /**
     * WorkerServer constructor
     *
     * @param string $host Host to bind
     * @param int $port Port to bind
     * @param string $workerScript Path to worker bootstrap script
     * @param string $workingDirectory Working directory for worker processes
     * @throws MissingEnvironmentVariableException
     */
    public function __construct(string $host, int $port, string $workerScript, string $workingDirectory)
    {
        parent::__construct($host, $port);
        
        $this->workerScript = $workerScript;
        $this->workingDirectory = $workingDirectory;
        
        // Get worker configuration from environment
        $this->minRegular = Env::getInt(EnvConstants::WORKER_MIN_REGULAR, 3);
        $this->minMonopolistic = Env::getInt(EnvConstants::WORKER_MIN_MONOPOLISTIC, 2);
        $this->maxRegular = Env::getInt(EnvConstants::WORKER_MAX_REGULAR, 10);
    }
    /**
     * Accept new worker connection
     *
     * @return ?WorkerClientInterface New worker client or null
     * @throws SocketException
     */
    public function acceptConnection(): ?WorkerClientInterface
    {
        /** @var ?WorkerClientInterface */
        return parent::acceptConnection();
    }

    /**
     * Called when a new worker client connection is accepted
     *
     * @param resource $socket Client socket
     * @return WorkerClientInterface Client instance
     */
    protected function onCreateClient($socket): WorkerClientInterface
    {
        return new WorkerClient($socket);
    }

    /**
     * Get server name for logging
     *
     * @return string Server name
     */
    public function getServerName(): string
    {
        return "Worker Server";
    }

    /**
     * Get count of active regular worker processes
     *
     * @return int Number of active regular workers
     */
    public function getRegularWorkersCount(): int
    {
        return count($this->regularWorkers);
    }

    /**
     * Get count of active monopolistic worker processes
     *
     * @return int Number of active monopolistic workers
     */
    public function getMonopolisticWorkersCount(): int
    {
        return count($this->monopolisticWorkers);
    }

    /**
     * Get maximum number of regular worker processes
     *
     * @return int Maximum number of regular workers
     */
    public function getMaxRegularWorkers(): int
    {
        return $this->maxRegular;
    }

    /**
     * Tick method - process clients and manage worker processes
     *
     * Overrides parent tick() to also manage worker processes lifecycle.
     * Checks running processes and starts missing ones.
     */
    public function tick(): void
    {
        // Process clients (read/write)
        parent::tick();

        // Check workers waiting for graceful shutdown
        $this->checkGracefulShutdown();

        // Tick all worker processes (check status, read output)
        $this->tickWorkerProcesses();

        // Ensure minimum number of workers are running
        try {
            $this->ensureMinWorkers();
        } catch (CouldNotStartException $e) {
            error_log("Failed to start worker process: " . $e->getMessage());
        } catch (FailedToSetNonBlockingException $e) {
            error_log("Failed to set non-blocking mode for worker process: " . $e->getMessage());
        }
    }

    /**
     * Tick all worker processes - check status and read output
     */
    private function tickWorkerProcesses(): void
    {
        // Tick regular workers
        foreach ($this->regularWorkers as $workerIndex => $process) {
            try {
                $process->tick();

                // Read and save stdout/stderr to files
                $this->saveWorkerOutput($process, 'regular', $workerIndex);

                // Check if process is still running
                $status = $process->getStatus();
                if (!$status[Process::STATUS_RUNNING]) {
                    // Worker died, remove from list and free index for reuse
                    echo "[" . date('Y-m-d H:i:s') . "] Worker #{$workerIndex} stopped [type=regular]\n";
                    unset($this->regularWorkers[$workerIndex]);
                    $this->availableRegularIndices[] = $workerIndex;
                    sort($this->availableRegularIndices); // Keep sorted for min selection
                }
            } catch (FailedToClosePipeException | FailedToTerminateProcessExceptionException $e) {
                unset($this->regularWorkers[$workerIndex]);
                $this->availableRegularIndices[] = $workerIndex;
                sort($this->availableRegularIndices);
            } catch (FailedToGetStatusException | FailedToReadStdOutException | FailedToSetStdErrException $e) {
                try {
                    $process->stop();
                } catch (FailedToGetStatusException | FailedToTerminateProcessExceptionException $e) {
                    // Ignore errors during halt
                }
                unset($this->regularWorkers[$workerIndex]);
                $this->availableRegularIndices[] = $workerIndex;
                sort($this->availableRegularIndices);
            }
        }

        // Tick monopolistic workers
        foreach ($this->monopolisticWorkers as $workerIndex => $process) {
            try {
                $process->tick();
                
                // Read and save stdout/stderr to files
                $this->saveWorkerOutput($process, 'monopolistic', $workerIndex);

                // Check if process is still running
                $status = $process->getStatus();
                if (!$status[Process::STATUS_RUNNING]) {
                    // Worker died, remove from list and free index for reuse
                    echo "[" . date('Y-m-d H:i:s') . "] Worker #{$workerIndex} stopped [type=monopolistic]\n";
                    unset($this->monopolisticWorkers[$workerIndex]);
                    $this->availableMonopolisticIndices[] = $workerIndex;
                    sort($this->availableMonopolisticIndices);
                }
            } catch (FailedToClosePipeException | FailedToTerminateProcessExceptionException $e) {
                unset($this->monopolisticWorkers[$workerIndex]);
                $this->availableMonopolisticIndices[] = $workerIndex;
                sort($this->availableMonopolisticIndices);
            } catch (FailedToGetStatusException | FailedToReadStdOutException | FailedToSetStdErrException $e) {
                try {
                    $process->stop();
                } catch (FailedToGetStatusException | FailedToTerminateProcessExceptionException $e) {
                    // Ignore errors during halt
                }
                // Process error, remove from list and free index for reuse
                unset($this->monopolisticWorkers[$workerIndex]);
                $this->availableMonopolisticIndices[] = $workerIndex;
                sort($this->availableMonopolisticIndices);
            }
        }
    }

    /**
     * Save worker stdout and stderr output to files
     *
     * @param Process $process Worker process
     * @param string $workerType Worker type ('regular' or 'monopolistic')
     * @param int $workerIndex Worker index
     */
    private function saveWorkerOutput(Process $process, string $workerType, int $workerIndex): void
    {
        // Determine log directory from daemon log file path (same directory)
        // Fallback to 'data/logs' if DAEMON_LOG_FILE is not set
        try {
            $daemonLogFile = Env::get(EnvConstants::DAEMON_LOG_FILE);
            $logDirectory = dirname($daemonLogFile);
        } catch (MissingEnvironmentVariableException) {
            // Fallback to default log directory if env variable is not set
            $logDirectory = 'data/logs';
        }

        // Ensure log directory exists
        if (!is_dir($logDirectory)) {
            if (!mkdir($logDirectory, 0755, true)) {
                error_log("Failed to create log directory: {$logDirectory}");
                return;
            }
        }

        // Read stdout and write to file
        $stdout = $process->getStdOut();
        if (!empty($stdout)) {
            $stdoutFile = $logDirectory . "/worker-{$workerType}-{$workerIndex}.log";
            $this->processWorkerOutput($stdout, $stdoutFile, $logDirectory, false);
        }

        // Read stderr and write to file
        $stderr = $process->getStdErr();
        if (!empty($stderr)) {
            $stderrFile = $logDirectory . "/worker-{$workerType}-{$workerIndex}.error.log";
            $this->processWorkerOutput($stderr, $stderrFile, $logDirectory, true);
        }
    }

    /**
     * Process worker output and extract agent logs
     *
     * Parses stdout/stderr to find agent log lines and writes them to separate agent log files.
     * Format: [AGENT_LOG]agentId|level|message
     *
     * @param string $output Worker output (stdout or stderr)
     * @param string $workerLogFile Worker log file path
     * @param string $logDirectory Log directory for agent logs
     * @param bool $isStderr Whether this is stderr (for error logs)
     */
    private function processWorkerOutput(string $output, string $workerLogFile, string $logDirectory, bool $isStderr): void
    {
        $agentLogMarker = '[AGENT_LOG]';
        $lines = explode("\n", $output);
        $workerLogContent = [];
        $agentLogs = [];

        // Process each line
        foreach ($lines as $line) {
            if (str_starts_with($line, $agentLogMarker)) {
                // This is an agent log line
                $this->parseAgentLogLine($line, $agentLogMarker, $agentLogs);
            } else {
                // Regular worker log line (skip empty lines)
                if ($line !== '') {
                    $workerLogContent[] = $line;
                }
            }
        }

        // Write worker log (without agent log lines)
        if (!empty($workerLogContent)) {
            $workerLogText = implode("\n", $workerLogContent);
            if (!empty(trim($workerLogText))) {
                file_put_contents($workerLogFile, $workerLogText . "\n", FILE_APPEND | LOCK_EX);
            }
        }

        // Write agent logs to separate files
        $this->writeAgentLogs($agentLogs, $logDirectory, $isStderr);
    }

    /**
     * Parse agent log line
     *
     * Format: [AGENT_LOG]agentId|level|message
     *
     * @param string $line Log line
     * @param string $marker Agent log marker
     * @param array<string, array<string, array<string>>> $agentLogs Output array for agent logs [agentId][level][] = message
     */
    private function parseAgentLogLine(string $line, string $marker, array &$agentLogs): void
    {
        // Remove marker
        $content = substr($line, strlen($marker));
        
        // Split by pipe: agentId|level|message
        $parts = explode('|', $content, 3);
        if (count($parts) < 3) {
            // Invalid format, skip
            return;
        }

        [$agentId, $level, $message] = $parts;
        $agentId = trim($agentId);
        $level = trim($level);
        $message = trim($message);

        if (empty($agentId) || empty($level) || empty($message)) {
            return;
        }

        // Store agent log
        if (!isset($agentLogs[$agentId])) {
            $agentLogs[$agentId] = [];
        }
        if (!isset($agentLogs[$agentId][$level])) {
            $agentLogs[$agentId][$level] = [];
        }
        $agentLogs[$agentId][$level][] = $message;
    }

    /**
     * Write agent logs to separate files
     *
     * @param array<string, array<string, array<string>>> $agentLogs Agent logs [agentId][level][] = message
     * @param string $logDirectory Log directory
     * @param bool $isStderr Whether this is from stderr
     */
    private function writeAgentLogs(array $agentLogs, string $logDirectory, bool $isStderr): void
    {
        foreach ($agentLogs as $agentId => $levels) {
            // Sanitize agent ID for filename (replace : and other special chars)
            $safeAgentId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $agentId);
            
            foreach ($levels as $level => $messages) {
                // Determine log file extension
                // ERROR level or stderr -> .error.log, otherwise -> .log
                $extension = ($level === 'ERROR' || $isStderr) ? '.error.log' : '.log';
                $agentLogFile = $logDirectory . "/agent-{$safeAgentId}{$extension}";

                // Write messages
                foreach ($messages as $message) {
                    file_put_contents($agentLogFile, $message . "\n", FILE_APPEND | LOCK_EX);
                }
            }
        }
    }

    /**
     * Ensure minimum number of workers are running
     *
     * Starts missing workers one at a time (not more than one per tick).
     * Process startup takes about a second, so we start one and wait for next tick.
     *
     * @throws CouldNotStartException If worker cannot be started
     * @throws FailedToSetNonBlockingException If non-blocking mode cannot be set
     */
    private function ensureMinWorkers(): void
    {
        // Start one regular worker if needed (not more than one per tick)
        if (count($this->regularWorkers) < $this->minRegular && count($this->regularWorkers) < $this->maxRegular) {
            $this->startRegularWorker();
            return; // Exit after starting one - next tick will check again
        }

        // Start one monopolistic worker if needed (not more than one per tick)
        if (count($this->monopolisticWorkers) < $this->minMonopolistic && $this->minMonopolistic > 0) {
            $this->startMonopolisticWorker();
            return; // Exit after starting one - next tick will check again
        }
    }

    /**
     * Start regular worker process
     *
     * Uses minimum available index, or next sequential if none available.
     *
     * @throws CouldNotStartException If worker cannot be started
     * @throws FailedToSetNonBlockingException If non-blocking mode cannot be set
     */
    private function startRegularWorker(): void
    {
        // Get minimum available index or use next sequential
        if (!empty($this->availableRegularIndices)) {
            $workerIndex = array_shift($this->availableRegularIndices);
        } else {
            $workerIndex = $this->nextRegularWorkerIndex++;
        }

        $process = new Process(
            'php',
            array_merge([$this->workerScript], ArgumentHelper::buildWorkerArgs($workerIndex)),
            $this->workingDirectory,
            [Process::DESCRIPTOR_PIPE, Process::PIPE_READ], // stdin
            [Process::DESCRIPTOR_PIPE, Process::PIPE_WRITE], // stdout
            [Process::DESCRIPTOR_PIPE, Process::PIPE_WRITE], // stderr
        );

        $this->regularWorkers[$workerIndex] = $process;
        
        // Log worker start to daemon.log (stdout)
        echo "[" . date('Y-m-d H:i:s') . "] Worker #{$workerIndex} started [type=regular]\n";
    }

    /**
     * Start monopolistic worker process
     *
     * Uses minimum available index, or next sequential if none available.
     *
     * @throws CouldNotStartException If worker cannot be started
     * @throws FailedToSetNonBlockingException If non-blocking mode cannot be set
     */
    private function startMonopolisticWorker(): void
    {
        // Get minimum available index or use next sequential
        if (!empty($this->availableMonopolisticIndices)) {
            $workerIndex = array_shift($this->availableMonopolisticIndices);
        } else {
            $workerIndex = $this->nextMonopolisticWorkerIndex++;
        }

        $process = new Process(
            'php',
            array_merge([$this->workerScript], ArgumentHelper::buildWorkerArgs($workerIndex, true)),
            $this->workingDirectory,
            [Process::DESCRIPTOR_PIPE, Process::PIPE_READ], // stdin
            [Process::DESCRIPTOR_PIPE, Process::PIPE_WRITE], // stdout
            [Process::DESCRIPTOR_PIPE, Process::PIPE_WRITE], // stderr
        );

        $this->monopolisticWorkers[$workerIndex] = $process;
        
        // Log worker start to daemon.log (stdout)
        echo "[" . date('Y-m-d H:i:s') . "] Worker #{$workerIndex} started [type=monopolistic]\n";
    }

    /**
     * Stop all worker processes with graceful shutdown
     * 
     * Sends SIGTERM to all workers and tracks them for graceful shutdown.
     * Actual termination will happen asynchronously in tick() method.
     * 
     * @throws SocketException
     */
    public function stop(): void
    {
        $currentTime = microtime(true);

        // Stop regular workers (send SIGTERM, track for graceful shutdown)
        foreach ($this->regularWorkers as $workerIndex => $process) {
            try {
                $process->stop(); // Send SIGTERM
                $this->workersWaitingShutdown[$workerIndex] = $currentTime;
            } catch (\Throwable $e) {
                error_log("Failed to stop regular worker #{$workerIndex}: " . $e->getMessage());
                // Force remove if stop failed
                unset($this->regularWorkers[$workerIndex]);
            }
        }

        // Stop monopolistic workers (send SIGTERM, track for graceful shutdown)
        foreach ($this->monopolisticWorkers as $workerIndex => $process) {
            try {
                $process->stop(); // Send SIGTERM
                $this->workersWaitingShutdown[$workerIndex] = $currentTime;
            } catch (\Throwable $e) {
                error_log("Failed to stop monopolistic worker #{$workerIndex}: " . $e->getMessage());
                // Force remove if stop failed
                unset($this->monopolisticWorkers[$workerIndex]);
            }
        }

        // Stop server (closes socket and clients)
        // Workers will be fully terminated in checkGracefulShutdown()
        parent::stop();
    }

    /**
     * Check workers waiting for graceful shutdown
     * 
     * Called in tick() to asynchronously check if workers have exited gracefully.
     * Force kills workers that exceed timeout.
     */
    private function checkGracefulShutdown(): void
    {
        if (empty($this->workersWaitingShutdown)) {
            return;
        }

        $currentTime = microtime(true);

        foreach ($this->workersWaitingShutdown as $workerIndex => $shutdownStartTime) {
            // Check if timeout exceeded
            if (($currentTime - $shutdownStartTime) >= self::SHUTDOWN_TIMEOUT) {
                // Timeout reached, force kill
                $this->forceKillWorker($workerIndex);
                unset($this->workersWaitingShutdown[$workerIndex]);
                continue;
            }

            // Check if worker still exists and is running
            $process = $this->regularWorkers[$workerIndex] ?? $this->monopolisticWorkers[$workerIndex] ?? null;
            if ($process === null) {
                // Worker already terminated
                unset($this->workersWaitingShutdown[$workerIndex]);
                continue;
            }

            // Check status
            try {
                $status = $process->getStatus();
                if (!$status[Process::STATUS_RUNNING]) {
                    // Worker exited gracefully
                    unset($this->workersWaitingShutdown[$workerIndex]);
                    // Remove from active workers
                    unset($this->regularWorkers[$workerIndex]);
                    unset($this->monopolisticWorkers[$workerIndex]);
                    // Free index for reuse
                    $this->availableRegularIndices[] = $workerIndex;
                    $this->availableMonopolisticIndices[] = $workerIndex;
                    sort($this->availableRegularIndices);
                    sort($this->availableMonopolisticIndices);
                }
            } catch (\Throwable $e) {
                // Error checking status, force kill
                $this->forceKillWorker($workerIndex);
                unset($this->workersWaitingShutdown[$workerIndex]);
            }
        }
    }

    /**
     * Force kill worker process
     * 
     * @param int $workerIndex Worker index
     */
    private function forceKillWorker(int $workerIndex): void
    {
        $process = $this->regularWorkers[$workerIndex] ?? $this->monopolisticWorkers[$workerIndex] ?? null;
        if ($process === null) {
            return;
        }

        try {
            $process->halt(); // Force kill with SIGKILL
        } catch (\Throwable $e) {
            error_log("Failed to force kill worker #{$workerIndex}: " . $e->getMessage());
        }

        // Remove from active workers
        unset($this->regularWorkers[$workerIndex]);
        unset($this->monopolisticWorkers[$workerIndex]);
        
        // Free index for reuse
        $this->availableRegularIndices[] = $workerIndex;
        $this->availableMonopolisticIndices[] = $workerIndex;
        sort($this->availableRegularIndices);
        sort($this->availableMonopolisticIndices);
    }
}

