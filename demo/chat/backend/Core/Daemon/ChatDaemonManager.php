<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Daemon;

use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatCronConstants;
use Demo\Chat\Core\Daemon\Module\FrontendHtmlModule;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Socket\Server\ChatWebSocketServer;
use Demo\Chat\Core\Socket\Server\ChatWorkerServer;
use Demo\Chat\Hilos;
use Demo\Chat\Http\ChatAttachmentDownloadHandler;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonContext;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\Module\BuildTimestampModule;
use Hilos\Core\Daemon\Module\DaemonModule;
use Hilos\Core\Daemon\Module\PeerModule;
use Hilos\Core\Http\StatusHandler;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Server\HttpServer;
use Hilos\Socket\Server\ServerInterface;

/**
 * ChatDaemonManager - Main daemon manager for chat demo.
 *
 * Extends framework DaemonManager to provide chat functionality: declares the chat
 * server set, the status and attachment-download routes, and the peer/build-timestamp/
 * frontend-html modules the demo opts into.
 */
final class ChatDaemonManager extends DaemonManager
{
    /** @var ChatWorkerServer Worker server, stashed while composing so the status route can read its counts */
    private ChatWorkerServer $workerServer;

    /**
     * Initializes chat daemon manager.
     *
     * Sets shutdown timeout to 10 seconds.
     * Registers cron rules for chat cleanup and expired attachment drafts.
     */
    public function __construct()
    {
        parent::__construct();

        $this->shutdownTimeout = 10.0;

        // Register cron rule for chat history cleanup (every 30 minutes)
        // Cron expression: "*/30 * * * *" means every 30 minutes
        $this->addCronRule(ChatCronConstants::CLEANUP_HISTORY, '*/30 * * * *');
        $this->addCronRule(ChatCronConstants::CLEANUP_ATTACHMENT_DRAFTS, '*/15 * * * *');
    }

    /**
     * Create signal router instance.
     *
     * @return SignalRouter Chat signal router instance
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new ChatSignalRouter();
    }

    /**
     * Create agent manager daemon instance.
     *
     * @return AgentManagerDaemon Chat agent manager daemon instance
     */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new ChatAgentManagerDaemon();
    }

    /**
     * The chat server set: HTTP status, worker, WebSocket, and CLI command servers.
     *
     * @param DaemonContext $context Resolved path context
     * @return iterable<ServerInterface> Servers to register, in bind order
     * @throws EnvException When a server host/port env value cannot be read
     */
    protected function createServers(DaemonContext $context): iterable
    {
        $this->workerServer = new ChatWorkerServer(
            Hilos::$env[EnvConstants::WORKER_COMM_HOST],
            Hilos::$env->int(EnvConstants::WORKER_COMM_PORT),
            $context->workerScript(),
            $context->bootstrapDir,
            $this->getAgentManagerDaemon(),
        );

        return [
            new HttpServer(
                Hilos::$env[EnvConstants::HTTP_STATUS_HOST],
                Hilos::$env->int(EnvConstants::HTTP_STATUS_PORT),
            ),
            $this->workerServer,
            new ChatWebSocketServer(
                Hilos::$env[EnvConstants::WEBSOCKET_HOST],
                Hilos::$env->int(EnvConstants::WEBSOCKET_PORT),
            ),
            new CommandServer(
                Hilos::$env[EnvConstants::COMMAND_HOST],
                Hilos::$env->int(EnvConstants::COMMAND_PORT),
            ),
        ];
    }

    /**
     * The chat HTTP routes: the shared status endpoint and the attachment download.
     *
     * @param DaemonContext $context Resolved path context
     * @return iterable<array{0: string, 1: string, 2: callable}> Route triples [method, path, handler]
     */
    protected function httpRoutes(DaemonContext $context): iterable
    {
        return [
            [HttpConstants::METHOD_GET, '/status', new StatusHandler($this->workerServer)],
            [
                HttpConstants::METHOD_GET,
                '/chat/attachment',
                static fn (array $args): array => ChatAttachmentDownloadHandler::handle($args),
            ],
        ];
    }

    /**
     * The chat modules: cluster peer transport, build-timestamp exposure, frontend HTML.
     *
     * @param DaemonContext $context Resolved path context
     * @return iterable<DaemonModule> Modules to consider, checked via isActive() before register()
     * @throws EnvException When the frontend dist path cannot be resolved
     */
    protected function modules(DaemonContext $context): iterable
    {
        $distPath = $context->frontendDistPath();

        return [
            new PeerModule(),
            new BuildTimestampModule($distPath),
            new FrontendHtmlModule($distPath),
        ];
    }

    /**
     * Keeps the WebSocket server closed until the chat agent finishes onStart, so no client
     * subscribes a page before the agent has built its state.
     *
     * @return list<string> Required startup agent ids
     */
    protected function getRequiredReadinessAgents(): array
    {
        return [AgentType::CHAT];
    }
}
