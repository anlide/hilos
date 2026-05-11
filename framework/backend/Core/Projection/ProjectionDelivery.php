<?php

declare(strict_types=1);

namespace Hilos\Core\Projection;

use Hilos\Core\Router\SignalDataInterface;

/**
 * One already-addressed WebSocket delivery produced by a page or group projection.
 */
final class ProjectionDelivery
{
    public function __construct(
        public readonly string $wireSignalName,
        public readonly SignalDataInterface $payload,
        public readonly string $targetAcceptKey,
    ) {
    }
}
