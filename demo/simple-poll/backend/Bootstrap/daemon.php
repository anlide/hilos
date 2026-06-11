<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\SimplePoll\Core\Daemon\PollDaemonManager;
use Demo\SimplePoll\Core\Socket\Server\PollWebSocketServer;
use Demo\SimplePoll\Core\Socket\Server\PollWorkerServer;
use Demo\SimplePoll\Database\Database;
use Demo\SimplePoll\Hilos;
use Hilos\API\Router\HttpRouter;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ErrorConstants;
use Hilos\Constants\ExitCode;
use Hilos\Constants\HttpConstants;
use Hilos\Core\CLI\DTO\DaemonStatusDTO;
use Hilos\Core\Daemon\Master\DaemonStatus;
use Hilos\Socket\Server\HttpServer;
use Hilos\Utils\Logger;

/**
 * Daemon - Entry point for the simple-poll demo daemon.
 *
 * Designed to run in Docker container under docker.php management.
 * Includes HTTP server for status endpoint, Worker server for worker
 * communication, and the WebSocket server the frontend connects to.
 */

try {
    // Project root (demo/simple-poll): .env lives here, not under Bootstrap/
    $projectRoot = dirname(__DIR__, 2);
    Hilos::initEnv($projectRoot);

    // Test Docker stack loads tests/.env over the default project .env.
    if (Hilos::$env[EnvConstants::APP_ENV] === 'test') {
        Hilos::loadEnv($projectRoot . '/tests/.env');
    }

    // Initialize database connection, schema and Hilos context.
    Database::initialize();

    // Create poll daemon manager instance first (initializes Hilos::$sr)
    $daemon = new PollDaemonManager();

    // Create HTTP server
    $httpServer = new HttpServer(
        Hilos::$env[EnvConstants::HTTP_STATUS_HOST],
        Hilos::$env->int(EnvConstants::HTTP_STATUS_PORT),
    );

    // Set log file for daemon-side logging
    Logger::setLogFile(Hilos::$env[EnvConstants::DAEMON_LOG_FILE]);

    // Create Worker server
    $workerScript = __DIR__ . '/worker.php';
    $workerServer = new PollWorkerServer(
        Hilos::$env[EnvConstants::WORKER_COMM_HOST],
        Hilos::$env->int(EnvConstants::WORKER_COMM_PORT),
        $workerScript,
        __DIR__,
        $daemon->getAgentManagerDaemon(),
    );

    // Create WebSocket server
    $webSocketServer = new PollWebSocketServer(
        Hilos::$env[EnvConstants::WEBSOCKET_HOST],
        Hilos::$env->int(EnvConstants::WEBSOCKET_PORT),
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
    Logger::error("Poll Daemon failed: " . $e->getMessage(), [
        ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
        ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
        ErrorConstants::CONTEXT_KEY_TRACE => $e->getTraceAsString(),
    ]);
    exit(ExitCode::ERROR);
}
