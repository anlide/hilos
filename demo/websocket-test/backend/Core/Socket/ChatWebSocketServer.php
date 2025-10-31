<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Socket;

use Hilos\Socket\Server\WebSocketServer;
use Demo\WebSocketTest\Core\Socket\ChatWebSocketClient;

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
     */
    public function acceptConnection(): ?ChatWebSocketClient
    {
        if (!$this->isRunning) {
            return null;
        }

        $clientSocket = @socket_accept($this->serverSocket);
        if ($clientSocket === false) {
            return null;
        }
        
        socket_set_nonblock($clientSocket);
        $chatClient = new ChatWebSocketClient($clientSocket);
        
        // Set callback to broadcast messages
        $chatClient->setOnMessageCallback(function(string $message) use ($chatClient) {
            $this->broadcastMessage($chatClient, $message);
        });

        $this->clients[] = $chatClient;
        $this->chatClients[] = $chatClient;
        
        return $chatClient;
    }

    /**
     * Broadcast message to all connected clients except sender
     *
     * @param ChatWebSocketClient $sender Sending client
     * @param string $message Message content
     */
    private function broadcastMessage(ChatWebSocketClient $sender, string $message): void
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
}

