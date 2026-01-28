<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Socket\Client;

use Demo\WebSocketTest\Constants\ChatSignalConstants;
use Demo\WebSocketTest\Constants\PageConstants;
use Demo\WebSocketTest\DTO\WebSocketFrameSignalDTO;
use Demo\WebSocketTest\DTO\WebSocketHandshakeSignalDTO;
use Demo\WebSocketTest\DTO\WebSocketSubscribeSignalDTO;
use Demo\WebSocketTest\DTO\WebSocketUnsubscribeSignalDTO;
use Demo\WebSocketTest\DTO\WebSocketUpdateSubscriptionSignalDTO;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Utils\Helpers\JsonHelper;

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
     * Get client ID
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

        $actionName = ChatSignalConstants::MESSAGE;
        $decoded = JsonHelper::tryDecode($payload);
        if (is_array($decoded) && isset($decoded['type']) && is_string($decoded['type'])) {
            $type = strtolower($decoded['type']);
            if ($type === SignalTypeConstants::PAGE_SUBSCRIBE || $type === SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION) {
                $page = $decoded['page'] ?? null;
                if (!is_string($page) || $page === '') {
                    throw new \RuntimeException("Page is required for {$type} signal");
                }

                if ($type === SignalTypeConstants::PAGE_SUBSCRIBE) {
                    $params = is_array($decoded['params'] ?? null) ? $decoded['params'] : [];
                    $subscribeDto = new WebSocketSubscribeSignalDTO(
                        clientId: $this->clientId,
                        page: $page,
                        groups: [],
                        params: $params,
                    );

                    $this->signalRouter->queueSignal(
                        new SignalSource(SignalSource::WEBSOCKET),
                        new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
                        new SignalName($page),
                        $subscribeDto,
                    );
                    return;
                }

                $updateDto = new WebSocketUpdateSubscriptionSignalDTO(
                    clientId: $this->clientId,
                    page: $page,
                    groups: null,
                );

                $this->signalRouter->queueSignal(
                    new SignalSource(SignalSource::WEBSOCKET),
                    new SignalType(SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION),
                    new SignalName($page),
                    $updateDto,
                );
                return;
            }

            $actionName = match ($type) {
                ChatSignalConstants::RENAME => ChatSignalConstants::RENAME,
                ChatSignalConstants::MESSAGE => ChatSignalConstants::MESSAGE,
                default => throw new \RuntimeException("Unknown websocket action type: {$type}"),
            };
        }

        $this->signalRouter->queueSignal(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::ACTION),
            new SignalName($actionName),
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
        $this->clientId = $acceptKey;

        $dto = new WebSocketHandshakeSignalDTO(
            clientId: $this->clientId,
            headers: $headers,
            acceptKey: $acceptKey,
            cookies: $cookies,
            clientIp: $clientIp,
            queryParams: $queryParams,
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
