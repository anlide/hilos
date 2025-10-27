<?php

namespace Hilos\Core\Worker;

use Hilos\Logging\Logger\FileLogger;

/**
 * WorkerProcess - процесс воркера
 */
class WorkerProcess
{
    private int $workerId;
    private string $configPath;
    private FileLogger $logger;
    private bool $running = false;

    public function __construct(int $workerId, string $configPath, FileLogger $logger)
    {
        $this->workerId = $workerId;
        $this->configPath = $configPath;
        $this->logger = $logger;
    }

    /**
     * Start worker
     */
    public function start(): void
    {
        $this->logger->info("WorkerProcess #{$this->workerId} starting...");
        $this->running = true;
        
        // Main worker loop
        while ($this->running) {
            $this->logger->info("Worker #{$this->workerId} is working...");
            sleep(2);
        }
        
        $this->logger->info("WorkerProcess #{$this->workerId} stopped");
    }

    /**
     * Stop worker
     */
    public function stop(): void
    {
        $this->logger->info("WorkerProcess #{$this->workerId} stopping...");
        $this->running = false;
    }
}
