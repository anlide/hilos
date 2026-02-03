<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Socket\Server;

use Demo\WebSocketTest\Constants\ChatSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\DTO\Worker\SystemSignalDTO;
use Hilos\Socket\Server\WorkerServer;

/**
 * ChatWorkerServer - Worker server with chat-specific agent daemon factory
 *
 * Extends WorkerServer to provide chat-specific agent daemon creation.
 */
class ChatWorkerServer extends WorkerServer
{
    /**
     * Called when server is started
     */
    protected function onStart(): void
    {
        // Server initialization - workers are not ready yet
    }

    /**
     * Called when initial workers are ready
     *
     * Sends start signal to chat agent when workers are ready.
     */
    protected function onInitialWorkersReady(): void
    {
        $this->signalRouter->queueSignal(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::SYSTEM),
            new SignalName(ChatSignalConstants::START),
            new SystemSignalDTO(systemName: ChatSignalConstants::START),
        );
    }
}
