<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\Socket\WebSocket\DTO\WebSocketAcceptKeySignalDTO;

/**
 * Agent-signal payload that can convert validation failures into a page action error.
 */
interface ActionErrorSignalDataInterface extends SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    /**
     * Action name to pass into AbstractPage::onActionException().
     *
     * @return string Action name for the error path
     */
    public function getActionErrorName(): string;

    /**
     * Payload data used to recreate the matching ActionPayloadDTO.
     *
     * @return array<string, mixed> Raw payload for the failed action
     */
    public function getActionErrorPayload(): array;
}
