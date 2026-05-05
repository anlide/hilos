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
     */
    public function getActionErrorName(): string;

    /**
     * Payload data used to recreate the matching ActionPayloadDTO.
     *
     * @return array<string, mixed>
     */
    public function getActionErrorPayload(): array;
}
