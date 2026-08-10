<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad\Socket;

/**
 * Deliberately broken sample sitting DIRECTLY in its zone segment rather than in
 * a subdirectory of it — the shape `Socket` was taken whole for in phase 2b,
 * because `WebSocketFrameDTO` and `WorkerDTO` live beside the already-zoned `DTO`
 * subdirectories and no `Socket/Client`-style segment reaches them.
 *
 * It is the fixture that tells "the whole of Socket" from "Socket/Client": were
 * the zone ever matched by prefix or by subdirectory, this file would go quiet
 * and the fixture report would fail before any production file did.
 */
final class EmptySentinel
{
    /**
     * @param array<string, string> $headers Parsed request headers
     * @return string Protocol header, empty when the client sent none
     */
    public function protocol(array $headers): string
    {
        return $headers['sec-websocket-protocol'] ?? '';
    }
}
