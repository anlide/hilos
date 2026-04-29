<?php

declare(strict_types=1);

namespace Hilos\Core\Frontend;

use Hilos\Core\Router\SignalDataInterface;

/**
 * One already-addressed WebSocket delivery produced by a frontend projection.
 */
final class FrontendDelivery
{
    public function __construct(
        public readonly string $wireSignalName,
        public readonly SignalDataInterface $payload,
        public readonly string $targetAcceptKey,
    ) {
    }
}
