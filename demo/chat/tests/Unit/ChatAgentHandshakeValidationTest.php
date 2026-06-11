<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\CookieNames;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\MissingRequiredParameterException;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ChatAgent handshake session token cookie validation.
 */
final class ChatAgentHandshakeValidationTest extends TestCase
{
    public function testHandshakeWithoutSessionTokenCookieThrows(): void
    {
        $this->expectException(MissingRequiredParameterException::class);
        $this->expectExceptionMessage(CookieNames::SESSION_TOKEN . ' cookie is required');

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

    public function testHandshakeWithEmptySessionTokenCookieThrows(): void
    {
        $this->expectException(EmptyValueException::class);
        $this->expectExceptionMessage(CookieNames::SESSION_TOKEN . ' cookie cannot be empty');

        (new ChatAgent())->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: 'unit-ak',
                cookies: [CookieNames::SESSION_TOKEN => ''],
                clientIp: '127.0.0.1',
                queryParams: RequestQueryParams::empty(),
            ),
            '',
            '',
        );
    }

    public function testHandshakeWithInvalidSessionTokenCookieFormatThrows(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(CookieNames::SESSION_TOKEN . ' cookie must be a 32-character lowercase hex token');

        (new ChatAgent())->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: 'unit-ak',
                cookies: [CookieNames::SESSION_TOKEN => 'short'],
                clientIp: '127.0.0.1',
                queryParams: RequestQueryParams::empty(),
            ),
            '',
            '',
        );
    }
}
