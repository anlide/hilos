<?php

declare(strict_types=1);

namespace Hilos\Utils\DTO;

/**
 * BaseDTO - Abstract base class for all DTOs
 *
 * Provides common functionality for Data Transfer Objects:
 * - JSON serialization
 * - Array conversion
 * - Data validation
 */
abstract class BaseDTO
{
    /**
     * Convert DTO to array
     *
     * @return array DTO data as array
     */
    abstract public function toArray(): array;

    /**
     * Create DTO from array
     *
     * @param array $data Source data
     * @return static DTO instance
     */
    abstract public static function fromArray(array $data): static;

    /**
     * Convert DTO to JSON string
     *
     * @return string JSON representation
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    /**
     * Create DTO from JSON string
     *
     * @param string $json JSON string
     * @return static DTO instance
     */
    public static function fromJson(string $json): static
    {
        $data = json_decode($json, true);
        if ($data === null) {
            throw new \InvalidArgumentException('Invalid JSON provided');
        }
        return static::fromArray($data);
    }
}

