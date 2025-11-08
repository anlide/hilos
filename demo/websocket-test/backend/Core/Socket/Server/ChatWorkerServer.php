<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Socket\Server;

use Demo\WebSocketTest\Utils\Constants\ChatSignalConstants;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalData;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Utils\Constants\SignalTypeConstants;

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
            new SignalData(),
        );
    }
}
