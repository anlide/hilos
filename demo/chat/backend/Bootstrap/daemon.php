<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\Chat\Hilos;
use Demo\Chat\Core\Daemon\ChatDaemonManager;
use Demo\Chat\Core\Frontend\HtmlCache;
use Demo\Chat\Core\Frontend\HtmlResolver;
use Demo\Chat\Http\ChatAttachmentDownloadHandler;
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
use Hilos\Utils\Logger;

/**
 * Daemon - Entry point for WebSocket test daemon.
 *
 * Designed to run in Docker container under docker.php management.
 * Provides WebSocket test functionality with heartbeat monitoring.
 * Includes HTTP server for status endpoint and Worker server for worker communication.
 */

// Project root (demo/chat): .env lives here, not under Bootstrap/
$projectRoot = dirname(__DIR__, 2);
Hilos::initEnv($projectRoot);

// Test Docker stack should prefer tests/.env over the default project .env.
$appEnv = Hilos::$env[EnvConstants::APP_ENV];
$testEnvPath = $projectRoot . '/tests/.env';
if ($appEnv === 'test' && file_exists($testEnvPath)) {
    Hilos::loadEnv($testEnvPath);
}

// Enable debug logging (optional - uncomment to enable)
#Logger::setDebugEnabled(true);

try {
    // Initialize database connection, schema and Hilos context.
    Database::initialize();
    Hilos::initAnalytics();

    // Create chat daemon manager instance first (initializes Hilos::$sr)
    $daemon = new ChatDaemonManager();

    // Create HTTP server
    $httpServer = new HttpServer(
        Hilos::$env[EnvConstants::HTTP_STATUS_HOST],
        Hilos::$env->int(EnvConstants::HTTP_STATUS_PORT),
    );

    // Set log file for daemon-side logging
    Logger::setLogFile(Hilos::$env[EnvConstants::DAEMON_LOG_FILE]);

    // Create Worker server
    $workerScript = __DIR__ . '/worker.php';
    $workerServer = new ChatWorkerServer(
        Hilos::$env[EnvConstants::WORKER_COMM_HOST],
        Hilos::$env->int(EnvConstants::WORKER_COMM_PORT),
        $workerScript,
        __DIR__,
        $daemon->getAgentManagerDaemon(),
    );

    // Create WebSocket server
    $webSocketServer = new ChatWebSocketServer(
        Hilos::$env[EnvConstants::WEBSOCKET_HOST],
        Hilos::$env->int(EnvConstants::WEBSOCKET_PORT),
    );

    // Create HTTP router
    $router = new HttpRouter();

    // Initialize status
    $status = new DaemonStatus();

    // Setup routes
    $router->addRoute('GET', '/chat/attachment', static fn (array $args): array => ChatAttachmentDownloadHandler::handle($args));

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

    $frontendDistPath = Hilos::$env[EnvConstants::FRONTEND_DIST_PATH];
    if ($frontendDistPath === '') {
        $frontendDistPath = __DIR__ . '/../../frontend/dist';
    }
    if (is_dir($frontendDistPath)) {
        $htmlResolver = new HtmlResolver();
        $htmlCache = new HtmlCache($frontendDistPath);
        $frontendHtmlServer = new FrontendHtmlServer(
            Hilos::$env[EnvConstants::FRONTEND_HTML_HOST],
            Hilos::$env->int(EnvConstants::FRONTEND_HTML_PORT),
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
