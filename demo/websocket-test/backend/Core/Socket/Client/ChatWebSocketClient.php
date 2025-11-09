<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Socket\Client;

use Demo\WebSocketTest\Constants\ChatSignalConstants;
use Demo\WebSocketTest\Constants\PageConstants;
use Demo\WebSocketTest\DTO\WebSocketFrameSignalDTO;
use Demo\WebSocketTest\DTO\WebSocketHandshakeSignalDTO;
use Demo\WebSocketTest\DTO\WebSocketUnsubscribeSignalDTO;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
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
     * Handle received WebSocket text frame
     *
     * Parses JSON payload and routes to appropriate signal type (subscribe, action, unsubscribe, update_subscription).
     * Falls back to legacy SIGNAL_FRAME if payload is not valid JSON or doesn't contain 'type' field.
     *
     * @param string $payload Frame payload (UTF-8 text, expected JSON)
     */
    protected function onFrame(string $payload): void
    {
        $dto = new WebSocketFrameSignalDTO(
            clientId: $this->clientId,
            payload: $payload,
        );

        // TODO: Use ChatSignalConstants::RENAME

        $this->signalRouter->queueSignal(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::ACTION),
            new SignalName(ChatSignalConstants::MESSAGE),
            $dto,
        );
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
        );

        $this->signalRouter->queueSignal(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::ACTION),
            new SignalName(ChatSignalConstants::FILE),
            $dto,
        );
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

        $this->signalRouter->queueSignal(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName(PageConstants::MAIN),
            $dto,
        );
    }

    /**
     * Called when socket connection is successfully closed
     *
     * Automatically unsubscribes user from all subscriptions (page and groups).
     */
    protected function onClose(): void
    {
        $pageDto = new WebSocketUnsubscribeSignalDTO(
            clientId: $this->clientId,
            page: true,
            groups: [],
        );

        $this->signalRouter->queueSignal(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_UNSUBSCRIBE),
            new SignalName(PageConstants::MAIN),
            $pageDto,
        );

        // Unsubscribe from all subscriptions (clear after sending signal)
        $this->signalRouter->unsubscribeFromAll($this->clientId);
    }
}
