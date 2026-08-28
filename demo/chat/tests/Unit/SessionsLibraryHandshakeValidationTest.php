<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Agents\Hilos\SessionsLibraryAgent;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the handshake's session token validation.
 *
 * The daemon resolves the token on the 101 and carries it on the DTO; the worker rejects an
 * empty or malformed token inside the contained ValidationException family before any
 * database access.
 *
 * Asked of the sessions library rather than of the chat agent since HIL-710: the handshake
 * is addressed to whoever owns the session set, and the refusal has to stand where the
 * cookie first arrives - the chat agent never sees a token at all now.
 */
final class SessionsLibraryHandshakeValidationTest extends TestCase
{
    public function testHandshakeWithoutSessionTokenThrows(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('Invalid session token format. Expected 32 lowercase hex characters.');

        new SessionsLibraryAgent()->onSignalHandshake(
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
        $this->expectExceptionMessage('Invalid session token format. Expected 32 lowercase hex characters.');

        new SessionsLibraryAgent()->onSignalHandshake(
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
