<?php

declare(strict_types=1);

namespace Hilos\Core\Projection;

use Hilos\Core\Router\SignalDataInterface;

/**
 * One already-addressed WebSocket delivery produced by a page or group projection.
 */
final class ProjectionDelivery
{
    /**
     * Creates an addressed signal delivery for one WebSocket connection.
     *
     * @param string $wireSignalName Signal name to queue through the signal router
     * @param SignalDataInterface $payload Signal payload to send
     * @param string $targetAcceptKey WebSocket accept key that should receive the payload
     */
    public function __construct(
        public readonly string $wireSignalName,
        public readonly SignalDataInterface $payload,
        public readonly string $targetAcceptKey,
    ) {
    }
}
