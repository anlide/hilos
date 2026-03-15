<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\Chat\Core\Daemon\ChatDaemonManager;
use Demo\Chat\Core\Frontend\HtmlCache;
use Demo\Chat\Core\Frontend\HtmlResolver;
use Demo\Chat\Core\Socket\Server\ChatWebSocketServer;
use Demo\Chat\Core\Socket\Server\ChatWorkerServer;
use Demo\Chat\Core\Socket\Server\FrontendHtmlServer;
use Demo\Chat\Database\Database;
use Hilos\API\Router\HttpRouter;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ErrorConstants;
use Hilos\Constants\ExitCode;
use Hilos\Constants\HttpConstants;
use Hilos\Core\CLI\DTO\DaemonStatusDTO;
use Hilos\Core\Daemon\Master\DaemonStatus;
use Hilos\Socket\Server\HttpServer;
use Hilos\Utils\Env;
use Hilos\Utils\Logger;

/**
 * Daemon - Entry point for WebSocket test daemon.
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
    // Initialize database connection, schema and Hilos context.
    Database::initialize();

    // Create chat daemon manager instance first (initializes Hilos::$sr)
    $daemon = new ChatDaemonManager();

    // Create HTTP server
    $httpServer = new HttpServer(
        Env::get(EnvConstants::HTTP_STATUS_HOST),
        Env::getInt(EnvConstants::HTTP_STATUS_PORT),
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
        $daemon->getAgentManagerDaemon(),
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

    // Register servers and router with daemon
    $daemon->registerServer($httpServer);
    $daemon->registerServer($workerServer);
    $daemon->registerServer($webSocketServer);

    $frontendDistPath = Env::get(EnvConstants::FRONTEND_DIST_PATH, __DIR__ . '/../../frontend/dist');
    if (is_dir($frontendDistPath)) {
        $htmlResolver = new HtmlResolver();
        $htmlCache = new HtmlCache($frontendDistPath);
        $frontendHtmlServer = new FrontendHtmlServer(
            Env::get(EnvConstants::FRONTEND_HTML_HOST, '0.0.0.0'),
            Env::getInt(EnvConstants::FRONTEND_HTML_PORT, 8093),
            $htmlResolver,
            $htmlCache,
        );
        $daemon->registerServer($frontendHtmlServer);
    }

    $daemon->registerHttpRouter($router);

    // Start daemon main loop
    $daemon->run();

} catch (\Throwable $e) {
    Logger::error("Chat Daemon failed: " . $e->getMessage(), [
        ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
        ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
        ErrorConstants::CONTEXT_KEY_TRACE => $e->getTraceAsString(),
    ]);
    exit(ExitCode::ERROR);
}
