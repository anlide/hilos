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
}
