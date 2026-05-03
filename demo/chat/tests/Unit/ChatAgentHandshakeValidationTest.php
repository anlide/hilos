<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\HttpHeaders;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Http\Exception\MissingRequestQueryParamException;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ChatAgent handshake session token validation.
 */
final class ChatAgentHandshakeValidationTest extends TestCase
{
    public function testHandshakeWithoutSessionTokenThrows(): void
    {
        $this->expectException(MissingRequestQueryParamException::class);
        $this->expectExceptionMessage(HttpHeaders::SESSION_TOKEN . ' is required');

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

    public function testHandshakeWithEmptySessionTokenThrows(): void
    {
        $this->expectException(EmptyValueException::class);
        $this->expectExceptionMessage(HttpHeaders::SESSION_TOKEN . ' cannot be empty');

        (new ChatAgent())->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: 'unit-ak',
                cookies: [],
                clientIp: '127.0.0.1',
                queryParams: new RequestQueryParams([HttpHeaders::SESSION_TOKEN => '']),
            ),
            '',
            '',
        );
    }

    public function testHandshakeWithInvalidSessionTokenFormatThrows(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(HttpHeaders::SESSION_TOKEN . ' must be a 32-character lowercase hex token');

        (new ChatAgent())->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: 'unit-ak',
                cookies: [],
                clientIp: '127.0.0.1',
                queryParams: new RequestQueryParams([HttpHeaders::SESSION_TOKEN => 'short']),
            ),
            '',
            '',
        );
    }
}
