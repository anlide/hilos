<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\WebSocketTest\Core\Daemon\ChatDaemonManager;
use Demo\WebSocketTest\Core\Socket\Server\ChatWebSocketServer;
use Demo\WebSocketTest\Core\Socket\Server\ChatWorkerServer;
use Demo\WebSocketTest\Database\Database;
use Hilos\API\Router\HttpRouter;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ErrorConstants;
use Hilos\Constants\ExitCode;
use Hilos\Constants\HttpConstants;
use Hilos\Core\Daemon\Master\DaemonStatus;
use Hilos\DTO\DaemonStatusDTO;
use Hilos\Logging\Logger\Logger;
use Hilos\Socket\Server\HttpServer;
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

// Enable debug logging (optional - uncomment to enable)
#Logger::setDebugEnabled(true);

try {
    // Initialize database connection, schema and Idea
    Database::initialize();

    // Create chat daemon manager instance first (creates signalRouter)
    $daemon = new ChatDaemonManager();

    // Get signal router from daemon
    $signalRouter = $daemon->getSignalRouter();

    // Create HTTP server
    $httpServer = new HttpServer(
        Env::get(EnvConstants::HTTP_STATUS_HOST),
        Env::getInt(EnvConstants::HTTP_STATUS_PORT),
        $signalRouter,
    );

    // Set log file for daemon-side logging
    Logger::setLogFile(Env::get(EnvConstants::DAEMON_LOG_FILE));

    // Create Worker server
    $workerScript = __DIR__ . '/worker.php';
    $workerServer = new ChatWorkerServer(
        Env::get(EnvConstants::WORKER_COMM_HOST),
        Env::getInt(EnvConstants::WORKER_COMM_PORT),
        $workerScript,
        __DIR__,
        $signalRouter,
        $daemon->getAgentManagerDaemon(),
    );

    // Create WebSocket server
    $webSocketServer = new ChatWebSocketServer(
        Env::get(EnvConstants::WEBSOCKET_HOST),
        Env::getInt(EnvConstants::WEBSOCKET_PORT),
        $signalRouter,
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

    // Register servers and router with daemon
    $daemon->registerServer($httpServer);
    $daemon->registerServer($workerServer);
    $daemon->registerServer($webSocketServer);
    $daemon->registerHttpRouter($router);

    // Start daemon main loop
    $daemon->run();

} catch (\Throwable $e) {
    Logger::error("WebSocketTest Daemon failed: " . $e->getMessage(), [
        ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
        ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
        ErrorConstants::CONTEXT_KEY_TRACE => $e->getTraceAsString(),
    ]);
    exit(ExitCode::ERROR);
}
