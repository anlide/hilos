<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Hilos\API\Router\HttpRouter;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\Master\DaemonStatus;
use Hilos\Socket\Server\HttpServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Utils\Constants\CliConstants;
use Hilos\Utils\Constants\ExitCode;
use Hilos\Utils\Constants\HttpConstants;
use Hilos\Utils\DTO\DaemonStatusDTO;

/**
 * Simple Daemon - simplified daemon implementation
 *
 * Designed to run in Docker container under docker.php management.
 * Provides basic heartbeat functionality for health monitoring.
 * Includes HTTP server for status endpoint and Worker server for worker communication.
 */

try {
    // Create HTTP server
    $httpServer = new HttpServer(
        CliConstants::HTTP_STATUS_HOST,
        CliConstants::HTTP_STATUS_PORT,
    );

    // Create Worker server
    $workerServer = new WorkerServer(
        CliConstants::WORKER_COMM_HOST,
        CliConstants::WORKER_COMM_PORT,
    );

    // Create HTTP router
    $router = new HttpRouter();

    // Initialize status
    $status = new DaemonStatus();

    // Setup routes
    $router->addRoute('GET', '/status', function($args) use ($status) {
        $status->update();
        
        // Create DTO from status
        // Note: If daemon responds, it's running by definition
        $dto = new DaemonStatusDTO(
            uptime: $status->getUptime(),
            memory: $status->memoryUsage,
            cpu: $status->cpuUsage,
            timestamp: time(),
        );
        
        return [
            HttpConstants::RESPONSE_KEY_STATUS => HttpConstants::HTTP_OK,
            HttpConstants::RESPONSE_KEY_HEADERS => [HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_JSON],
            HttpConstants::RESPONSE_KEY_BODY => $dto->toJson(),
        ];
    });

    // Create anonymous class to override tick() method
    $daemon = new class extends DaemonManager {
        /** @var float Last heartbeat timestamp in milliseconds */
        private float $lastHeartbeat = 0.0;

        /** @var float Heartbeat interval in milliseconds (5 seconds) */
        private float $heartbeatInterval = 5000.0;

        /**
         * Daemon tick implementation with heartbeat functionality
         *
         * Logs heartbeat message every 5 seconds for health monitoring.
         * Uses millisecond precision for accurate timing.
         */
        protected function tick(): void {
            $currentTime = microtime(true) * 1000;

            // Send heartbeat every 5 seconds with millisecond precision
            if (($currentTime - $this->lastHeartbeat) >= $this->heartbeatInterval) {
                $this->logMessage("Daemon heartbeat - " . date('Y-m-d H:i:s'));
                $this->lastHeartbeat = $currentTime;
            }
        }
    };

    // Register servers and router with daemon
    $daemon->registerServer($httpServer);
    $daemon->registerServer($workerServer);
    $daemon->registerHttpRouter($router);

    // Start daemon main loop
    $daemon->run();

} catch (\Throwable $e) {
    echo "Daemon failed: " . $e->getMessage() . "\n";
    exit(ExitCode::ERROR);
}
