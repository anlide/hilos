<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\BaseDTO;

/**
 * SignalData - Generic implementation of signal data.
 *
 * Can represent empty signal data or store arbitrary data as array.
 * Used as fallback when specific SignalDataInterface implementation is not available.
 */
class SignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * Creates signal data with optional payload.
     *
     * @param array<string, mixed> $data Optional data to store
     */
    public function __construct(
        private array $data = [],
    ) {
    }

    /**
     * Converts DTO to array.
     *
     * @return array<string, mixed> Stored data
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new static($data);
    }
}
