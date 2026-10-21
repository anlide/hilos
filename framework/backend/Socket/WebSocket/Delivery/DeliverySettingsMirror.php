<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\Delivery;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Client\WorkerClient;

/** Last valid setting for this master process; no reader outside this process uses the mirror. */
final class DeliverySettingsMirror implements DeliverySettingsSink
{
    /** Effective window, including the floor before the first worker report. */
    private int $windowMs = DeliverySettingsCatalog::DEFAULT_WINDOW_MS;

    /**
     * @return int Current delivery window in milliseconds
     */
    public function getWindowMs(): int
    {
        return $this->windowMs;
    }

    /**
     * A rejected report leaves the last valid window intact.
     *
     * @param WorkerClient $worker Registered channel supplying this report
     * @param int $windowMs Positive delivery window in milliseconds
     * @throws InvalidFormatException When the reported window is not positive
     */
    public function onDeliverySettings(WorkerClient $worker, int $windowMs): void
    {
        if ($windowMs <= 0) {
            throw new InvalidFormatException('WebSocket delivery window must be positive');
        }

        $this->windowMs = $windowMs;
    }
}
