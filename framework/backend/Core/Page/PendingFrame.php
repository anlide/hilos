<?php

declare(strict_types=1);

namespace Hilos\Core\Page;

use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketTableViewportSignalDTO;

/**
 * One frame held before its guards run, waiting to learn who is behind its connection (HIL-599).
 *
 * The connection is identified in the worker that owns the WebSocket lifecycle and judged
 * in the worker serving the page, so a frame can arrive before the identity crosses the RT
 * sync. Judging it then would call a signed-in person a guest and answer 401. The frame is
 * held here instead and dispatched again once the answer lands, or once the deadline says
 * it will not ({@see PageSignalRouter::releasePendingFrames}).
 *
 * A record and not a closure, for the same reason as {@see DeferredAction}: only the raw
 * frame is kept, so the resumed dispatch walks the very same steps as one that never
 * waited, and nothing downstream can tell the two apart.
 */
final class PendingFrame
{
    /**
     * @param string $acceptKey Accept key of the connection whose identity is awaited
     * @param PendingFrameKind $kind Which door the frame was stopped at
     * @param WebSocketPageSubscribeSignalDTO|WebSocketPageUpdateSubscriptionSignalDTO|WebSocketActionSignalDTO|WebSocketTableViewportSignalDTO $data
     *     Frame as it arrived
     * @param string $source Signal source the frame was dispatched with
     * @param string $name Signal name the frame was dispatched with (page name for the page doors)
     * @param float $deadline Unix seconds after which the frame is judged whether or not the identity arrived
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly PendingFrameKind $kind,
        public readonly WebSocketPageSubscribeSignalDTO|WebSocketPageUpdateSubscriptionSignalDTO
            |WebSocketActionSignalDTO|WebSocketTableViewportSignalDTO $data,
        public readonly string $source,
        public readonly string $name,
        public readonly float $deadline,
    ) {
    }
}
