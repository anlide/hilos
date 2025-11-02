<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Socket\Client;

use Demo\WebSocketTest\Utils\DTO\WebSocketCloseSignalDTO;
use Demo\WebSocketTest\Utils\DTO\WebSocketFrameSignalDTO;
use Demo\WebSocketTest\Utils\DTO\WebSocketHandshakeSignalDTO;
use Hilos\Socket\Client\WebSocketClient;

/**
 * ChatWebSocketClient - WebSocket client for chat demo
 *
 * Handles chat-specific WebSocket frame processing and sends signals to chat agent.
 */
class ChatWebSocketClient extends WebSocketClient
{
    /** @var string Client identifier (acceptKey from handshake) */
    private string $clientId = '';

    /**
     * Get client identifier
     *
     * @return string Client ID
     */
    public function getClientId(): string
    {
        return $this->clientId;
    }

    /**
     * Handle received WebSocket text frame
     *
     * @param string $payload Frame payload (UTF-8 text)
     */
    protected function onFrame(string $payload): void
    {
        $dto = new WebSocketFrameSignalDTO(
            clientId: $this->clientId,
            payload: $payload,
            isBinary: false,
        );

        $this->signalRouter->queueSignal('websocket', 'frame', $dto);
    }

    /**
     * Handle received WebSocket binary frame
     *
     * @param string $payload Frame payload (binary data)
     */
    protected function onFrameBinary(string $payload): void
    {
        $dto = new WebSocketFrameSignalDTO(
            clientId: $this->clientId,
            payload: $payload,
            isBinary: true,
        );

        $this->signalRouter->queueSignal('websocket', 'frame', $dto);
    }

    /**
     * Called when WebSocket handshake is completed
     *
     * @param array $headers All HTTP headers from handshake request
     * @param string $acceptKey Sec-WebSocket-Accept value (can be used as connection identifier)
     * @param array $cookies Parsed cookies
     * @param string $clientIp Client IP address
     */
    protected function onHandshake(
        array $headers,
        string $acceptKey,
        array $cookies,
        string $clientIp,
    ): void
    {
        $this->clientId = $acceptKey;

        $dto = new WebSocketHandshakeSignalDTO(
            clientId: $this->clientId,
            headers: $headers,
            acceptKey: $acceptKey,
            cookies: $cookies,
            clientIp: $clientIp,
        );

        $this->signalRouter->queueSignal('websocket', 'handshake', $dto);
    }

    /**
     * Called when socket connection is successfully closed
     */
    protected function onClose(): void
    {
        $dto = new WebSocketCloseSignalDTO(
            clientId: $this->clientId,
        );

        $this->signalRouter->queueSignal('websocket', 'close', $dto);
    }
}

