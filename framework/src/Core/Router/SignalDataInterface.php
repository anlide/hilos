<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

/**
 * SignalDataInterface - Interface for signal data
 *
 * All signal data DTOs must implement this interface.
 * Signal data DTOs must extend BaseDTO and implement SignalDataInterface.
 * This interface ensures that all signal data can be serialized to array.
 */
interface SignalDataInterface
{
    /**
     * Convert signal data to array
     *
     * @return array Signal data as array
     */
    public function toArray(): array;
}
