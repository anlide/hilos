<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\DTO\BaseDTO;

/**
 * SignalData - Empty implementation of signal data
 *
 * Represents empty signal data. Can be used when no data is needed for a signal.
 */
class SignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * SignalData constructor
     */
    public function __construct()
    {
    }

    /**
     * Convert DTO to array
     *
     * @return array Empty array
     */
    public function toArray(): array
    {
        return [];
    }

    /**
     * Create DTO from array
     *
     * @param array $data Source data (ignored)
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new self();
    }
}
