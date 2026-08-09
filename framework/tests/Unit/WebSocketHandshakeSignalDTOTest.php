<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Http\RequestQueryParams;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WebSocket handshake signal DTO.
 */
final class WebSocketHandshakeSignalDTOTest extends TestCase
{
    public function testRoundtripPreservesQueryParamsAsTypedCollection(): void
    {
        $original = new WebSocketHandshakeSignalDTO(
            headers: [],
            acceptKey: 'unit-ak',
            cookies: [],
            clientIp: '127.0.0.1',
            queryParams: new RequestQueryParams(['token' => 'abc']),
        );

        $restored = WebSocketHandshakeSignalDTO::fromArray($original->toArray());

        $this->assertSame('abc', $restored->queryParams->requireString('token'));
        $this->assertSame(['token' => 'abc'], $restored->toArray()[WebSocketHandshakeSignalDTO::QUERY_PARAMS]);
    }

    public function testPayloadWithoutClientIpReadsBackAsAbsent(): void
    {
        $restored = WebSocketHandshakeSignalDTO::fromArray([
            WebSocketHandshakeSignalDTO::ACCEPT_KEY => 'unit-ak',
        ]);

        $this->assertNull($restored->clientIp);
    }

    public function testRoundtripPreservesAbsentClientIp(): void
    {
        $original = new WebSocketHandshakeSignalDTO(
            headers: [],
            acceptKey: 'unit-ak',
            cookies: [],
            clientIp: null,
        );

        $restored = WebSocketHandshakeSignalDTO::fromArray($original->toArray());

        $this->assertNull($restored->clientIp);
    }

    public function testRoundtripPreservesSessionToken(): void
    {
        $original = new WebSocketHandshakeSignalDTO(
            headers: [],
            acceptKey: 'unit-ak',
            cookies: [],
            clientIp: '127.0.0.1',
            sessionToken: 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6',
        );

        $restored = WebSocketHandshakeSignalDTO::fromArray($original->toArray());

        $this->assertSame('a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6', $restored->sessionToken);
    }
}
