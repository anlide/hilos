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
    /** @var string Accept key identifier (from handshake) */
    private string $acceptKey = '';

    /**
     * Get client ID
     *
     * @return string Accept key
     */
    public function getAcceptKey(): string
    {
        return $this->acceptKey;
    }

    /**
     * Hook: resolve action name from parsed payload.
     *
     * @param string $payload
     * @param ?array<string,mixed> $decoded
     * @return ?string
     */
    protected function onActionParsed(string $payload, ?array $decoded): ?string
    {
        $actionName = ChatSignalConstants::MESSAGE;

        if (is_array($decoded) && isset($decoded['type']) && is_string($decoded['type'])) {
            $type = strtolower($decoded['type']);
            $actionName = match ($type) {
                ChatSignalConstants::RENAME => ChatSignalConstants::RENAME,
                ChatSignalConstants::MESSAGE => ChatSignalConstants::MESSAGE,
                default => throw new \RuntimeException("Unknown websocket action type: {$type}"),
            };
        }

        return $actionName;
    }

    /**
     * Handle received WebSocket binary frame
     *
     * @param string $payload Frame payload (binary data)
     */
    protected function onFrameBinary(string $payload): void
    {
        $dto = new WebSocketFrameSignalDTO(
            acceptKey: $this->acceptKey,
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
     * @param array $queryParams Query parameters (GET parameters) from request URL
     */
    protected function onHandshake(
        array $headers,
        string $acceptKey,
        array $cookies,
        string $clientIp,
        array $queryParams,
    ): void
    {
        $this->acceptKey = $acceptKey;

        $dto = new WebSocketHandshakeSignalDTO(
            headers: $headers,
            acceptKey: $acceptKey,
            cookies: $cookies,
            clientIp: $clientIp,
            queryParams: $queryParams,
        );

        $this->signalRouter->queueSignal(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::HANDSHAKE),
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
            acceptKey: $this->acceptKey,
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
        $this->signalRouter->unsubscribeFromAll($this->acceptKey);
    }
}
