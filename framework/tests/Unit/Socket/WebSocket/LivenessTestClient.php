<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\WebSocket;

use Hilos\Core\Http\RequestQueryParams;
use Hilos\Socket\Client\WebSocketClient;

/**
 * Real client with only its clock and peer address controlled by the test; its close is the framework's own.
 *
 * Shared by the liveness tests of the socket and of its owner, which drive the same handshake and the
 * same tick from opposite ends of the transition.
 */
final class LivenessTestClient extends WebSocketClient
{
    public int $now = 0;

    protected function deliveryNowMs(): int
    {
        return $this->now;
    }

    protected function getClientIp(): string
    {
        return '127.0.0.1';
    }

    /**
     * @param array<string, string> $headers Unused handshake headers
     * @param string $acceptKey Unused connection key
     * @param array<string, string> $cookies Unused cookies
     * @param ?string $clientIp Unused client address
     * @param RequestQueryParams $queryParams Unused query
     */
    protected function onHandshake(
        array $headers,
        string $acceptKey,
        array $cookies,
        ?string $clientIp,
        RequestQueryParams $queryParams,
    ): void {
    }
}
