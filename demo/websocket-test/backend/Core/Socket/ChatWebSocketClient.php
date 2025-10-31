<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Socket;

use Hilos\Socket\Client\WebSocketClient;

/**
 * ChatWebSocketClient - WebSocket client for chat demo
 *
 * Handles chat-specific WebSocket frame processing.
 */
class ChatWebSocketClient extends WebSocketClient
{
    /** @var ?callable Callback for processing messages */
    private $onMessageCallback = null;

    /**
     * Set message callback
     *
     * @param callable $callback Callback function(message: string)
     */
    public function setOnMessageCallback(callable $callback): void
    {
        $this->onMessageCallback = $callback;
    }

    /**
     * Handle received WebSocket frame
     *
     * @param string $payload Frame payload
     * @param bool $isText Whether payload is text (true) or binary (false)
     */
    protected function onFrame(string $payload, bool $isText): void
    {
        if (!$isText) {
            // Only process text frames for chat
            return;
        }

        // Call callback if set
        if ($this->onMessageCallback !== null) {
            call_user_func($this->onMessageCallback, $payload);
        }
    }

    /**
     * Send chat message
     *
     * @param string $message Message to send
     */
    public function sendMessage(string $message): void
    {
        $this->sendFrame($message, true);
    }
}

