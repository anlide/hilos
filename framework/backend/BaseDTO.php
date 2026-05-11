<?php

declare(strict_types=1);

namespace Hilos;

/**
 * Abstract base class for serializable DTOs.
 *
 * Concrete DTOs own their array shape and inherit shared JSON conversion.
 */
abstract class BaseDTO
{
    /**
     * Serializes the DTO to its array payload.
     *
     * @return array<string, mixed> DTO payload
     */
    abstract public function toArray(): array;

    /**
     * Restores a DTO instance from its array payload.
     *
     * @param array<string, mixed> $data DTO payload
     * @return static Restored DTO instance
     */
    abstract public static function fromArray(array $data): static;

    /**
     * Serializes the DTO payload to JSON.
     *
     * @return string JSON representation of the DTO payload
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
     * Restores a DTO instance from a JSON payload.
     *
     * @param string $json JSON-encoded DTO payload
     * @return static Restored DTO instance
     * @throws HilosException When JSON cannot be decoded into DTO data
     */
    public static function fromJson(string $json): static
    {
        $data = json_decode($json, true)
            ?? throw new HilosException('Invalid JSON provided');
        return static::fromArray($data);
    }
}
