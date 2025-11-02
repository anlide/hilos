<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\WebSocketTest\Core\Daemon\WebSocketTestDaemon;
use Demo\WebSocketTest\Core\Socket\Server\ChatWebSocketServer;
use Demo\WebSocketTest\Core\Socket\Server\ChatWorkerServer;
use Hilos\API\Router\HttpRouter;
use Hilos\Core\Daemon\Master\DaemonStatus;
use Hilos\Socket\Server\HttpServer;
use Hilos\Utils\Constants\EnvConstants;
use Hilos\Utils\Constants\ExitCode;
use Hilos\Utils\Constants\HttpConstants;
use Hilos\Utils\DTO\DaemonStatusDTO;
use Hilos\Utils\Env;

/**
 * Daemon - Entry point for WebSocket test daemon
 *
 * Designed to run in Docker container under docker.php management.
 * Provides WebSocket test functionality with heartbeat monitoring.
 * Includes HTTP server for status endpoint and Worker server for worker communication.
 */

// Initialize environment (reads .env from local directory)
Env::init(__DIR__);

try {
    // Create HTTP server
    $httpServer = new HttpServer(
        Env::get(EnvConstants::HTTP_STATUS_HOST),
        Env::getInt(EnvConstants::HTTP_STATUS_PORT),
    );

    // Create Worker server
    $workerScript = __DIR__ . '/worker.php';
    $workerServer = new ChatWorkerServer(
        Env::get(EnvConstants::WORKER_COMM_HOST),
        Env::getInt(EnvConstants::WORKER_COMM_PORT),
        $workerScript,
        __DIR__, // Working directory for worker processes (Bootstrap folder)
    );

    // Create WebSocket server
    $webSocketServer = new ChatWebSocketServer(
        Env::get(EnvConstants::WEBSOCKET_HOST),
        Env::getInt(EnvConstants::WEBSOCKET_PORT),
    );

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

    // Create WebSocket test daemon instance
    $daemon = new WebSocketTestDaemon();

    // Register servers and router with daemon
    $daemon->registerServer($httpServer);
    $daemon->registerServer($workerServer);
    $daemon->registerServer($webSocketServer);
    $daemon->registerHttpRouter($router);

    // Start daemon main loop
    $daemon->run();

} catch (\Throwable $e) {
    echo "WebSocketTest Daemon failed: " . $e->getMessage() . "\n";
    exit(ExitCode::ERROR);
}

