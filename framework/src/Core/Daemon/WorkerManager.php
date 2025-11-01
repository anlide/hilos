<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

/**
 * WorkerManager - Base worker process manager
 *
 * Main loop for worker processes. Extends BaseManager for error handling,
 * signal management and logging infrastructure.
 *
 * @abstract
 */
abstract class WorkerManager extends BaseManager
{
    /** @var int Worker ID */
    protected int $workerId;

    /**
     * WorkerManager constructor
     *
     * @param int $workerId Worker ID
     */
    public function __construct(int $workerId)
    {
        $this->workerId = $workerId;
    }

    /**
     * Run worker - main method
     *
     * Starts the worker main loop with error handling and signal processing.
     * Runs until shutdown signal is received.
     */
    public function run(): void
    {
        // Setup error handling and signal handlers
        $this->setupErrorHandling();
        $this->setupSignalHandlers();

        $this->logMessage("Worker #{$this->workerId} started");

        // Main loop
        while (!$this->shouldExit) {
            $loopStartTime = microtime(true);

            // Call tick method
            $this->tick();

            $this->sleepWithPreciseTiming($loopStartTime);

            // Process signals
            pcntl_signal_dispatch();
        }

        $this->logMessage("Worker #{$this->workerId} stopped");
    }

    /**
     * Tick method - called regularly in main loop
     *
     * Must be implemented in child classes to define worker-specific
     * work logic. Called on each loop iteration with precise timing.
     */
    abstract protected function tick(): void;

    /** @return string Manager name for logging */
    protected function getManagerName(): string
    {
        return "Worker #{$this->workerId}";
    }

    /** @param string $message Error message to log */
    protected function logError(string $message): void
    {
        $timestamped = "[" . date('Y-m-d H:i:s') . "] " . $message;
        error_log($timestamped);
        $this->logMessage($message);
    }

    /** @param string $message Exception message to log */
    protected function logException(string $message): void
    {
        $timestamped = "[" . date('Y-m-d H:i:s') . "] " . $message;
        error_log($timestamped);
        $this->logMessage($message);
    }

    /** @param string $message Shutdown message to log */
    protected function logShutdown(string $message): void
    {
        $timestamped = "[" . date('Y-m-d H:i:s') . "] " . $message;
        error_log($timestamped);
        $this->logMessage($message);
    }

    /** Handle error event - sets exit flag */
    protected function onError(): void
    {
        $this->shouldExit = true;
    }

    /** Handle exception event - sets exit flag */
    protected function onException(): void
    {
        $this->shouldExit = true;
    }

    /** Handle shutdown event - sets exit flag */
    protected function onShutdown(): void
    {
        $this->shouldExit = true;
    }

    /** Handle shutdown signal event - no additional logic needed */
    protected function onShutdownSignal(): void
    {
        // Worker-specific shutdown logic (none needed)
    }

    /** Handle restart signal event - no additional logic needed */
    protected function onRestartSignal(): void
    {
        // Worker-specific restart logic (none needed)
    }
}

