<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Demo\Cluster\Core\Daemon\ClusterDaemonManager;
use Demo\Cluster\Core\Socket\Server\ClusterWorkerServer;
use Demo\Cluster\Database\Database;
use Demo\Cluster\Hilos;
use Hilos\API\Router\HttpRouter;
use Hilos\Cluster\Peer\PeerAddress;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ErrorConstants;
use Hilos\Constants\ExitCode;
use Hilos\Constants\HttpConstants;
use Hilos\Core\CLI\DTO\DaemonStatusDTO;
use Hilos\Core\Daemon\Master\DaemonStatus;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Server\HttpServer;
use Hilos\Utils\Logger;

/**
 * Daemon - Entry point for a cluster demo node daemon.
 *
 * Headless (no WebSocket, no frontend): each node runs the HTTP status server, the
 * worker server, the command server the harness inspects over, and — when cluster
 * mode is enabled — the peer transport that forms the mesh, runs consensus (on a
 * master), and executes placement. Role, identity, master set, and timeouts all
 * come from CLUSTER_* env, so the same daemon is a master or a data-plane node
 * purely by configuration.
 */

try {
    // Project root (demo/cluster): .env lives here, not under Bootstrap/
    $projectRoot = dirname(__DIR__, 2);
    Hilos::initEnv($projectRoot);

    // Test Docker stack loads tests/.env over the default project .env.
    if (Hilos::$env[EnvConstants::APP_ENV] === 'test') {
        Hilos::loadEnv($projectRoot . '/tests/.env');
    }

    // Initialize database connection, schema and Hilos context.
    Database::initialize();

    // Create cluster daemon manager instance first (initializes Hilos::$sr)
    $daemon = new ClusterDaemonManager();

    // Set log file for daemon-side logging
    Logger::setLogFile(Hilos::$env[EnvConstants::DAEMON_LOG_FILE]);

    // Create HTTP status server (readiness/health)
    $httpServer = new HttpServer(
        Hilos::$env[EnvConstants::HTTP_STATUS_HOST],
        Hilos::$env->int(EnvConstants::HTTP_STATUS_PORT),
    );

    // Create Worker server (also the placement executor / cross-node signal sink)
    $workerScript = __DIR__ . '/worker.php';
    $workerServer = new ClusterWorkerServer(
        Hilos::$env[EnvConstants::WORKER_COMM_HOST],
        Hilos::$env->int(EnvConstants::WORKER_COMM_PORT),
        $workerScript,
        __DIR__,
        $daemon->getAgentManagerDaemon(),
    );

    // Create command server (dedicated CLI <-> daemon channel; cluster:test:inspect)
    $commandServer = new CommandServer(
        Hilos::$env[EnvConstants::COMMAND_HOST],
        Hilos::$env->int(EnvConstants::COMMAND_PORT),
    );

    // Create HTTP router with a status endpoint
    $router = new HttpRouter();
    $status = new DaemonStatus();
    $router->addRoute('GET', '/status', function ($args) use ($status, $workerServer) {
        $status->update();

        $dto = new DaemonStatusDTO(
            uptime: $status->getUptime(),
            memory: $status->memoryUsage,
            cpu: $status->cpuUsage,
            timestamp: time(),
            workersRegular: $workerServer->getRegularWorkersCount(),
            workersMonopolistic: $workerServer->getMonopolisticWorkersCount(),
            workersMaxRegular: $workerServer->getMaxRegularWorkers(),
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
    $daemon->registerServer($commandServer);

    // Cluster peer transport: opened only when this daemon opts into cluster mode.
    // A non-cluster single-node daemon stays first-class and never binds the peer port.
    if (Hilos::$cluster->isEnabled()) {
        $peerServer = new PeerServer(
            Hilos::$env[EnvConstants::CLUSTER_PEER_HOST],
            Hilos::$env->int(EnvConstants::CLUSTER_PEER_PORT),
            Hilos::$cluster->identity(),
            PeerAddress::parseList(Hilos::$env[EnvConstants::CLUSTER_SEEDS]),
        );
        $daemon->registerServer($peerServer);
    }

    $daemon->registerHttpRouter($router);

    // Start daemon main loop
    $daemon->run();

} catch (\Throwable $e) {
    Logger::error("Cluster Daemon failed: " . $e->getMessage(), [
        ErrorConstants::CONTEXT_KEY_FILE => $e->getFile(),
        ErrorConstants::CONTEXT_KEY_LINE => $e->getLine(),
        ErrorConstants::CONTEXT_KEY_TRACE => $e->getTraceAsString(),
    ]);
    exit(ExitCode::ERROR);
}
