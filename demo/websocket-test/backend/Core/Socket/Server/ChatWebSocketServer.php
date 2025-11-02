<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Socket\Server;

use Demo\WebSocketTest\Core\Socket\Client\ChatWebSocketClient;
use Hilos\Exception\SocketException;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Server\WebSocketServer;

/**
 * ChatWebSocketServer - WebSocket server for chat demo
 *
 * Extends base WebSocketServer with chat-specific functionality.
 */
class ChatWebSocketServer extends WebSocketServer
{
    /** @var array Active chat clients */
    private array $chatClients = [];

    /**
     * Accept new connection
     *
     * @return ?ChatWebSocketClient New client or null
     * @throws SocketException
     */
    public function acceptConnection(): ?ChatWebSocketClient
    {
        $chatClient = parent::acceptConnection();
        if ($chatClient === null) {
            return null;
        }

        // Set server instance for broadcasting messages
        $chatClient->setServer($this);

        $this->chatClients[] = $chatClient;
        
        return $chatClient;
    }

    /**
     * Called when a new chat WebSocket client connection is accepted
     *
     * @param resource $socket Client socket
     * @return WebSocketClientInterface Client instance
     */
    protected function onCreateClient($socket): WebSocketClientInterface
    {
        return new ChatWebSocketClient($socket);
    }

    /**
     * Broadcast message to all connected clients except sender
     *
     * @param ChatWebSocketClient $sender Sending client
     * @param string $message Message content
     */
    public function broadcastMessage(ChatWebSocketClient $sender, string $message): void
    {
        foreach ($this->chatClients as $client) {
            if ($client === $sender) {
                continue;
            }
            
            if ($client->shouldClose()) {
                continue;
            }
            
            $client->sendMessage($message);
        }
    }

    /**
     * Remove client from chat clients list
     *
     * @param mixed $client Client to remove
     */
    public function removeClient($client): void
    {
        parent::removeClient($client);
        
        $key = array_search($client, $this->chatClients, true);
        if ($key !== false) {
            unset($this->chatClients[$key]);
        }
    }

    /**
     * Called when server is started
     */
    protected function onStart(): void
    {
        // Chat WebSocket server has no specific startup logic
    }
}

