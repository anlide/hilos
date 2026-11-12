<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager\Core\Socket\Server;

use Demo\FileGalleryManager\Core\Socket\Client\FileGalleryManagerWebSocketClient;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\WebSocketServer;

/**
 * FileGalleryManagerWebSocketServer - WebSocket server for the file-gallery-manager demo.
 *
 * Extends base WebSocketServer; queues signals for dispatch through Hilos::$sr.
 *
 * @extends AbstractServer<FileGalleryManagerWebSocketClient>
 */
final class FileGalleryManagerWebSocketServer extends WebSocketServer
{
    /**
     * Called when a new file-gallery-manager WebSocket client connection is accepted.
     *
     * @param resource $socket Client socket
     * @return FileGalleryManagerWebSocketClient Client instance
     * @throws EnvException When the client ctor reads an invalid socket read buffer env value
     */
    protected function onCreateClient($socket): FileGalleryManagerWebSocketClient
    {
        return new FileGalleryManagerWebSocketClient($socket);
    }

    /**
     * Called when server is started. No demo-specific startup logic.
     */
    protected function onStart(): void
    {
        // FileGalleryManager WebSocket server has no specific startup logic
    }
}
