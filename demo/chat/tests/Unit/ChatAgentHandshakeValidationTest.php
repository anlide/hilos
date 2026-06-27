<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Agents\ChatAgent;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ChatAgent handshake session token validation.
 *
 * The daemon resolves the token on the 101 and carries it on the DTO; the
 * worker rejects an empty or malformed token inside the contained
 * ValidationException family before any database access.
 */
final class ChatAgentHandshakeValidationTest extends TestCase
{
    public function testHandshakeWithoutSessionTokenThrows(): void
    {
        $this->expectException(EmptyValueException::class);
        $this->expectExceptionMessage('session token is required');

        (new ChatAgent())->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: 'unit-ak',
                cookies: [],
                clientIp: '127.0.0.1',
                queryParams: RequestQueryParams::empty(),
            ),
            '',
            '',
        );
    }

    public function testHandshakeWithInvalidSessionTokenFormatThrows(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('session token must be a 32-character lowercase hex token');

        (new ChatAgent())->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: 'unit-ak',
                cookies: [],
                clientIp: '127.0.0.1',
                queryParams: RequestQueryParams::empty(),
                sessionToken: 'short',
            ),
            '',
            '',
        );
    }
}
