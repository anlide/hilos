<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\Delivery;

/** Scalar page lifetime shared by its pending request index entries, without retaining payloads. */
final class DeliveryRequestLifetime
{
    public bool $active = true;

    /** @param int $generation Original socket-local navigation generation */
    public function __construct(public readonly int $generation)
    {
    }
}
