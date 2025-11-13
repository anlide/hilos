<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../vendor/autoload.php';

use Hilos\API\Router\HttpRouter;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Constants\HttpConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\Master\DaemonStatus;
use Hilos\Database\Database;
use Hilos\DTO\DaemonStatusDTO;
use Hilos\Exception\Worker\AgentDaemonFactoryNotConfiguredException;
use Hilos\Logging\Logger\Logger;
use Hilos\Socket\Server\HttpServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Utils\Env;

/**
 * Simple Daemon - simplified daemon implementation
 *
 * Designed to run in Docker container under docker.php management.
 * Provides basic heartbeat functionality for health monitoring.
 * Includes HTTP server for status endpoint and Worker server for worker communication.
 */

// Initialize environment
Env::init();

// Initialize database connection and schema
Database::initialize();

// Enable debug logging (optional - uncomment to enable)
// Logger::setDebugEnabled(true);

try {
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
        protected function onTick(): void {
            $currentTime = microtime(true) * 1000;

            // Send heartbeat every 5 seconds with millisecond precision
            if (($currentTime - $this->lastHeartbeat) >= $this->heartbeatInterval) {
                Logger::info("Daemon heartbeat - " . date('Y-m-d H:i:s'));
                $this->lastHeartbeat = $currentTime;
            }
        }

        protected function createAgentManagerDaemon(): AgentManagerDaemon
        {
            // TODO: Implement createAgentManagerDaemon() method.
            throw new AgentDaemonFactoryNotConfiguredException();
        }
    };

    // Get signal router from daemon
    $signalRouter = $daemon->getSignalRouter();

    // Create HTTP server
    $httpServer = new HttpServer(
        Env::get(EnvConstants::HTTP_STATUS_HOST),
        Env::getInt(EnvConstants::HTTP_STATUS_PORT),
        $signalRouter,
    );

    // Create Worker server
    $workerScript = __DIR__ . '/worker.php';
    $projectRoot = dirname(realpath(__DIR__), 3);
    $workerServer = new class(
        Env::get(EnvConstants::WORKER_COMM_HOST),
        Env::getInt(EnvConstants::WORKER_COMM_PORT),
        $workerScript,
        $projectRoot,
        $signalRouter,
    ) extends WorkerServer {
        protected function onStart(): void
        {
            // Framework daemon has no specific startup logic
        }

        protected function getAgentDaemonFactoryClass(): string
        {
            // Framework daemon doesn't use agent daemons
            // This will throw AgentDaemonFactoryNotConfiguredException if agent daemon is requested
            // For framework daemon, agent daemon functionality is not needed
            throw new AgentDaemonFactoryNotConfiguredException();
        }

        protected function onInitialWorkersReady(): void
        {
            // TODO: Implement onInitialWorkersReady() method.
        }
    };

    // Create HTTP router
    $router = new HttpRouter();

    // Initialize status
    $status = new DaemonStatus();

    // Setup routes
    $router->addRoute('GET', '/status', function($args) use ($status, $workerServer) {
        $status->update();

        // Get worker information from WorkerServer
        $regularCount = $workerServer->getRegularWorkersCount();
        $monopolisticCount = $workerServer->getMonopolisticWorkersCount();
        $maxRegular = $workerServer->getMaxRegularWorkers();

        // Create DTO from status
        // Note: If daemon responds, it's running by definition
        $dto = new DaemonStatusDTO(
            uptime: $status->getUptime(),
            memory: $status->memoryUsage,
            cpu: $status->cpuUsage,
            timestamp: time(),
            workersRegular: $regularCount,
            workersMonopolistic: $monopolisticCount,
            workersMaxRegular: $maxRegular,
        );

        return [
            HttpConstants::RESPONSE_KEY_STATUS => HttpConstants::HTTP_OK,
            HttpConstants::RESPONSE_KEY_HEADERS => [HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_JSON],
            HttpConstants::RESPONSE_KEY_BODY => $dto->toJson(),
        ];
    });

    // Register servers and router with daemon
    $daemon->registerServer($httpServer);
    $daemon->registerServer($workerServer);
    $daemon->registerHttpRouter($router);

    // Start daemon main loop
    $daemon->run();

} catch (\Throwable $e) {
    Logger::error("Daemon failed: " . $e->getMessage());
    exit(ExitCode::ERROR);
}
