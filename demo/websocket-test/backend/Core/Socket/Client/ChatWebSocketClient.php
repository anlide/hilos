<?php

declare(strict_types=1);

namespace Demo\WebSocketTest\Core\Socket\Client;

use Demo\WebSocketTest\Utils\DTO\WebSocketActionSignalDTO;
use Demo\WebSocketTest\Utils\DTO\WebSocketCloseSignalDTO;
use Demo\WebSocketTest\Utils\DTO\WebSocketFrameBinarySignalDTO;
use Demo\WebSocketTest\Utils\DTO\WebSocketFrameSignalDTO;
use Demo\WebSocketTest\Utils\DTO\WebSocketHandshakeSignalDTO;
use Demo\WebSocketTest\Utils\DTO\WebSocketSubscribeSignalDTO;
use Demo\WebSocketTest\Utils\DTO\WebSocketUnsubscribeSignalDTO;
use Demo\WebSocketTest\Utils\DTO\WebSocketUpdateSubscriptionSignalDTO;
use Hilos\Core\Router\SignalSource;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Utils\Constants\SignalConstants;

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
        // Try to parse JSON payload
        $data = json_decode($payload, true);

        // If not valid JSON or missing 'type' field, treat as legacy frame signal
        if ($data === null || !isset($data['type'])) {
            $dto = new WebSocketFrameSignalDTO(
                clientId: $this->clientId,
                payload: $payload,
            );

            $signalSource = new SignalSource(SignalSource::SOURCE_WEBSOCKET);
            $this->signalRouter->queueSignal($signalSource, SignalConstants::SIGNAL_FRAME, SignalConstants::SIGNAL_FRAME, $dto);
            return;
        }

        // Route based on signal type
        $signalType = $data['type'];
        $signalSource = new SignalSource(SignalSource::SOURCE_WEBSOCKET);

        switch ($signalType) {
            case 'subscribe':
                // Subscribe to page if provided
                if (isset($data['page']) && $data['page'] !== null) {
                    $pageDto = new WebSocketSubscribeSignalDTO(
                        clientId: $this->clientId,
                        page: $data['page'],
                        groups: [],
                        params: $data['params'] ?? [],
                    );
                    $this->signalRouter->queueSignal($signalSource, SignalConstants::SIGNAL_SUBSCRIBE_PAGE, $data['page'], $pageDto);
                }
                // Subscribe to groups if provided
                if (!empty($data['groups']) && is_array($data['groups'])) {
                    foreach ($data['groups'] as $group) {
                        $groupDto = new WebSocketSubscribeSignalDTO(
                            clientId: $this->clientId,
                            page: null,
                            groups: [],
                            params: $data['params'] ?? [],
                        );
                        // Use group name as signalName, but DTO needs to have group field
                        // We'll use a workaround: put group in params for now
                        $groupDtoArray = $groupDto->toArray();
                        $groupDtoArray['group'] = $group;
                        $groupDto = WebSocketSubscribeSignalDTO::fromArray($groupDtoArray);
                        $this->signalRouter->queueSignal($signalSource, SignalConstants::SIGNAL_SUBSCRIBE_GROUP, $group, $groupDto);
                    }
                }
                break;

            case 'action':
                $dto = new WebSocketActionSignalDTO(
                    clientId: $this->clientId,
                    action: $data['action'] ?? '',
                    data: $data['data'] ?? [],
                    page: $data['page'] ?? null,
                    group: $data['group'] ?? null,
                );
                $this->signalRouter->queueSignal($signalSource, SignalConstants::SIGNAL_ACTION, SignalConstants::SIGNAL_ACTION, $dto);
                break;

            case 'unsubscribe':
                // Unsubscribe from page if requested
                if (isset($data['page']) && $data['page'] === true) {
                    $pageDto = new WebSocketUnsubscribeSignalDTO(
                        clientId: $this->clientId,
                        page: true,
                        groups: [],
                    );
                    $pageName = $this->signalRouter->getUserPage($this->clientId);
                    if ($pageName !== null) {
                        $this->signalRouter->queueSignal($signalSource, SignalConstants::SIGNAL_UNSUBSCRIBE_PAGE, $pageName, $pageDto);
                    }
                }
                // Unsubscribe from groups if provided
                if (!empty($data['groups']) && is_array($data['groups'])) {
                    foreach ($data['groups'] as $group) {
                        $groupDto = new WebSocketUnsubscribeSignalDTO(
                            clientId: $this->clientId,
                            page: false,
                            groups: [],
                        );
                        $groupDtoArray = $groupDto->toArray();
                        $groupDtoArray['group'] = $group;
                        $groupDto = WebSocketUnsubscribeSignalDTO::fromArray($groupDtoArray);
                        $this->signalRouter->queueSignal($signalSource, SignalConstants::SIGNAL_UNSUBSCRIBE_GROUP, $group, $groupDto);
                    }
                }
                break;

            case 'update_subscription':
                // Update page subscription if provided
                if (isset($data['page'])) {
                    $pageDto = new WebSocketUpdateSubscriptionSignalDTO(
                        clientId: $this->clientId,
                        page: $data['page'],
                        groups: null,
                    );
                    $pageName = $data['page'] !== null ? $data['page'] : ($this->signalRouter->getUserPage($this->clientId) ?? '');
                    if ($pageName !== '') {
                        $this->signalRouter->queueSignal($signalSource, SignalConstants::SIGNAL_UPDATE_SUBSCRIPTION_PAGE, $pageName, $pageDto);
                    }
                }
                // Update group subscription if provided
                if (isset($data['groups'])) {
                    if ($data['groups'] !== null && is_array($data['groups']) && !empty($data['groups'])) {
                        // Update first group (we support only one group now)
                        $group = $data['groups'][0];
                        $groupDto = new WebSocketUpdateSubscriptionSignalDTO(
                            clientId: $this->clientId,
                            page: null,
                            groups: null,
                        );
                        $groupDtoArray = $groupDto->toArray();
                        $groupDtoArray['group'] = $group;
                        $groupDto = WebSocketUpdateSubscriptionSignalDTO::fromArray($groupDtoArray);
                        $this->signalRouter->queueSignal($signalSource, SignalConstants::SIGNAL_UPDATE_SUBSCRIPTION_GROUP, $group, $groupDto);
                    }
                }
                break;

            default:
                // Unknown type, treat as legacy frame signal
                $dto = new WebSocketFrameSignalDTO(
                    clientId: $this->clientId,
                    payload: $payload,
                );
                $this->signalRouter->queueSignal($signalSource, SignalConstants::SIGNAL_FRAME, SignalConstants::SIGNAL_FRAME, $dto);
                break;
        }
    }

    /**
     * Handle received WebSocket binary frame
     *
     * @param string $payload Frame payload (binary data)
     */
    protected function onFrameBinary(string $payload): void
    {
        $dto = new WebSocketFrameBinarySignalDTO(
            clientId: $this->clientId,
            payload: $payload,
        );

        $signalSource = new SignalSource(SignalSource::SOURCE_WEBSOCKET);
        $this->signalRouter->queueSignal($signalSource, SignalConstants::SIGNAL_FRAME_BINARY, SignalConstants::SIGNAL_FRAME_BINARY, $dto);
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

        $signalSource = new SignalSource(SignalSource::SOURCE_WEBSOCKET);
        $this->signalRouter->queueSignal($signalSource, SignalConstants::SIGNAL_HANDSHAKE, SignalConstants::SIGNAL_HANDSHAKE, $dto);
    }

    /**
     * Called when socket connection is successfully closed
     *
     * Automatically unsubscribes user from all subscriptions (page and groups).
     */
    protected function onClose(): void
    {
        // Get user's subscriptions before clearing
        $page = $this->signalRouter->getUserPage($this->clientId);
        $groups = $this->signalRouter->getUserGroups($this->clientId);

        // Send unsubscribe signals if there were active subscriptions
        if ($page !== null) {
            $pageDto = new WebSocketUnsubscribeSignalDTO(
                clientId: $this->clientId,
                page: true,
                groups: [],
            );
            $signalSource = new SignalSource(SignalSource::SOURCE_WEBSOCKET);
            $this->signalRouter->queueSignal($signalSource, SignalConstants::SIGNAL_UNSUBSCRIBE_PAGE, $page, $pageDto);
        }

        if (!empty($groups)) {
            foreach (array_keys($groups) as $group) {
                $groupDto = new WebSocketUnsubscribeSignalDTO(
                    clientId: $this->clientId,
                    page: false,
                    groups: [],
                );
                $groupDtoArray = $groupDto->toArray();
                $groupDtoArray['group'] = $group;
                $groupDto = WebSocketUnsubscribeSignalDTO::fromArray($groupDtoArray);
                $signalSource = new SignalSource(SignalSource::SOURCE_WEBSOCKET);
                $this->signalRouter->queueSignal($signalSource, SignalConstants::SIGNAL_UNSUBSCRIBE_GROUP, $group, $groupDto);
            }
        }

        // Unsubscribe from all subscriptions (clear after sending signal)
        $this->signalRouter->unsubscribeFromAll($this->clientId);

        // Send close signal
        $closeDto = new WebSocketCloseSignalDTO(
            clientId: $this->clientId,
        );

        $signalSource = new SignalSource(SignalSource::SOURCE_WEBSOCKET);
        $this->signalRouter->queueSignal($signalSource, SignalConstants::SIGNAL_CLOSE, SignalConstants::SIGNAL_CLOSE, $closeDto);
    }
}
