<?php

declare(strict_types=1);

namespace Hilos;

/**
 * BaseDTO - Abstract base class for all DTOs.
 *
 * Provides common functionality for Data Transfer Objects:
 * JSON serialization, array conversion, data validation.
 */
abstract class BaseDTO
{
    /**
     * Converts DTO to associative array.
     *
     * @return array<string, mixed> DTO data as array
     */
    abstract public function toArray(): array;

    /**
     * Creates DTO instance from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    abstract public static function fromArray(array $data): static;

    /**
     * Converts DTO to JSON string.
     *
     * @return string JSON representation
     */
    public function toJson(): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        $json = json_encode($this->toArray(), $flags);
        if ($json === false) {
            return '{"error":"json_encode_failed"}';
        }

        return $json;
    }

    /**
     * Creates DTO instance from JSON string.
     *
     * @param string $json JSON string
     * @return static DTO instance
     * @throws HilosException If JSON string is invalid or cannot be decoded
     */
    public static function fromJson(string $json): static
    {
        $data = json_decode($json, true)
            ?? throw new HilosException('Invalid JSON provided');
        return static::fromArray($data);
    }
}
