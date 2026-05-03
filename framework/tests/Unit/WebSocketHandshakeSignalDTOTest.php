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
}
