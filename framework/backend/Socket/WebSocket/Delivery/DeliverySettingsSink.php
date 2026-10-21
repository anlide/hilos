<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\Delivery;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Client\WorkerClient;

/** Master-side receiver of effective settings from registered worker channels. */
interface DeliverySettingsSink
{
    /**
     * Runs on the master loop without database, filesystem or network reads.
     *
     * @param WorkerClient $worker Registered channel supplying this report
     * @param int $windowMs Positive delivery window in milliseconds
     * @throws InvalidFormatException When the reported window is not positive
     */
    public function onDeliverySettings(WorkerClient $worker, int $windowMs): void;
}
