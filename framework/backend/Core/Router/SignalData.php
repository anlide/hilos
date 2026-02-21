<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

use Hilos\BaseDTO;

/**
 * SignalData - Generic implementation of signal data
 *
 * Can represent empty signal data or store arbitrary data as array.
 * Used as fallback when specific SignalDataInterface implementation is not available.
 */
class SignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * SignalData constructor
     *
     * @param array $data Optional data to store
     */
    public function __construct(
        private array $data = [],
    ) {
    }

    /**
     * Convert DTO to array
     *
     * @return array Stored data array
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Create DTO from array
     *
     * @param array $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new self($data);
    }
}
