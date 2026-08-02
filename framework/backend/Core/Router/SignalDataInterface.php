<?php

declare(strict_types=1);

namespace Hilos\Core\Router;

/**
 * Transport payload for a routed signal.
 *
 * Signal data DTOs implement this interface so routers and WebSocket payloads
 * can serialize them without knowing the concrete DTO class.
 */
interface SignalDataInterface
{
    /**
     * Serializes this signal payload for transport.
     *
     * @return array<string, mixed> Signal payload
     */
    public function toArray(): array;

    /**
     * Restores a signal payload from its transport array.
     *
     * @param array<string, mixed> $data Signal payload
     * @return static Restored signal payload
     */
    public static function fromArray(array $data): static;
}
