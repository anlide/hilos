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
    /** @var array<int, Process> Regular worker processes indexed by worker ID */
    private array $regularWorkers = [];

    /** @var array<int, Process> Monopolistic worker processes indexed by worker ID */
    private array $monopolisticWorkers = [];

    /** @var int Next regular worker ID to assign */
    private int $nextRegularWorkerId = 1;

    /** @var int Next monopolistic worker ID to assign */
    private int $nextMonopolisticWorkerId = 1;

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
        foreach ($this->regularWorkers as $workerId => $process) {
            try {
                $process->tick();

                // Read and save stdout/stderr to files
                $this->saveWorkerOutput($process, 'regular', $workerId);

                // Check if process is still running
                $status = $process->getStatus();
                if (!$status[Process::STATUS_RUNNING]) {
                    // Worker died, remove from list
                    unset($this->regularWorkers[$workerId]);
                }
            } catch (FailedToClosePipeException | FailedToTerminateProcessExceptionException $e) {
                unset($this->regularWorkers[$workerId]);
            } catch (FailedToGetStatusException | FailedToReadStdOutException | FailedToSetStdErrException $e) {
                try {
                    $process->stop();
                } catch (FailedToGetStatusException | FailedToTerminateProcessExceptionException $e) {
                    // Ignore errors during halt
                }
                unset($this->regularWorkers[$workerId]);
            }
        }

        // Tick monopolistic workers
        foreach ($this->monopolisticWorkers as $workerId => $process) {
            try {
                $process->tick();
                
                // Read and save stdout/stderr to files
                $this->saveWorkerOutput($process, 'monopolistic', $workerId);

                // Check if process is still running
                $status = $process->getStatus();
                if (!$status[Process::STATUS_RUNNING]) {
                    // Worker died, remove from list
                    unset($this->monopolisticWorkers[$workerId]);
                }
            } catch (FailedToClosePipeException | FailedToTerminateProcessExceptionException $e) {
                unset($this->monopolisticWorkers[$workerId]);
            } catch (FailedToGetStatusException | FailedToReadStdOutException | FailedToSetStdErrException $e) {
                try {
                    $process->stop();
                } catch (FailedToGetStatusException | FailedToTerminateProcessExceptionException $e) {
                    // Ignore errors during halt
                }
                // Process error, remove from list
                unset($this->monopolisticWorkers[$workerId]);
            }
        }
    }

    /**
     * Save worker stdout and stderr output to files
     *
     * @param Process $process Worker process
     * @param string $workerType Worker type ('regular' or 'monopolistic')
     * @param int $workerId Worker ID
     */
    private function saveWorkerOutput(Process $process, string $workerType, int $workerId): void
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
            $stdoutFile = $logDirectory . "/worker-{$workerType}-{$workerId}.log";
            file_put_contents($stdoutFile, $stdout, FILE_APPEND | LOCK_EX);
        }

        // Read stderr and write to file
        $stderr = $process->getStdErr();
        if (!empty($stderr)) {
            $stderrFile = $logDirectory . "/worker-{$workerType}-{$workerId}.error.log";
            file_put_contents($stderrFile, $stderr, FILE_APPEND | LOCK_EX);
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
     * @throws CouldNotStartException If worker cannot be started
     * @throws FailedToSetNonBlockingException If non-blocking mode cannot be set
     */
    private function startRegularWorker(): void
    {
        $workerId = $this->nextRegularWorkerId++;

        $process = new Process(
            'php',
            array_merge([$this->workerScript], ArgumentHelper::buildWorkerArgs($workerId)),
            $this->workingDirectory,
            [Process::DESCRIPTOR_PIPE, Process::PIPE_READ], // stdin
            [Process::DESCRIPTOR_PIPE, Process::PIPE_WRITE], // stdout
            [Process::DESCRIPTOR_PIPE, Process::PIPE_WRITE], // stderr
        );

        $this->regularWorkers[$workerId] = $process;
    }

    /**
     * Start monopolistic worker process
     *
     * @throws CouldNotStartException If worker cannot be started
     * @throws FailedToSetNonBlockingException If non-blocking mode cannot be set
     */
    private function startMonopolisticWorker(): void
    {
        $workerId = $this->nextMonopolisticWorkerId++;

        $process = new Process(
            'php',
            array_merge([$this->workerScript], ArgumentHelper::buildWorkerArgs($workerId, true)),
            $this->workingDirectory,
            [Process::DESCRIPTOR_PIPE, Process::PIPE_READ], // stdin
            [Process::DESCRIPTOR_PIPE, Process::PIPE_WRITE], // stdout
            [Process::DESCRIPTOR_PIPE, Process::PIPE_WRITE], // stderr
        );

        $this->monopolisticWorkers[$workerId] = $process;
    }

    /**
     * Stop all worker processes
     * @throws SocketException
     */
    public function stop(): void
    {
        // Stop regular workers
        foreach ($this->regularWorkers as $workerId => $process) {
            try {
                $process->stop();
            } catch (\Throwable $e) {
                error_log("Failed to stop regular worker #{$workerId}: " . $e->getMessage());
            }
        }
        $this->regularWorkers = [];

        // Stop monopolistic workers
        foreach ($this->monopolisticWorkers as $workerId => $process) {
            try {
                $process->stop();
            } catch (\Throwable $e) {
                error_log("Failed to stop monopolistic worker #{$workerId}: " . $e->getMessage());
            }
        }
        $this->monopolisticWorkers = [];

        // Stop server (closes socket and clients)
        parent::stop();
    }
}

