<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Tests\Unit;

use Demo\SimplePoll\Agents\PollAgent;
use Demo\SimplePoll\Constants\CookieNames;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PollAgent handshake cookie validation.
 *
 * The cookie-format guards reject a missing, empty, or malformed session token
 * before any database access. The durable find-or-register happy path is
 * covered by the integration suite (UsersActionsTest) and e2e.
 */
final class PollAgentHandshakeTest extends TestCase
{
    private ?SignalRouter $previousRouter = null;
    private ?AnalyticsCollector $previousCollector = null;

    protected function setUp(): void
    {
        // Isolate the global signal router and analytics collector the handshake
        // would reach after validation; the cookie guards throw before that.
        $this->previousRouter = Hilos::$sr;
        $this->previousCollector = Hilos::$ac;
        Hilos::$sr = new SignalRouter();
        Hilos::$ac = null;
    }

    protected function tearDown(): void
    {
        Hilos::$sr = $this->previousRouter;
        Hilos::$ac = $this->previousCollector;
    }

    public function testHandshakeWithoutSessionTokenCookieThrows(): void
    {
        // The contained family: the worker handshake dispatcher rejects
        // ValidationException without crashing (see WorkerManager).
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(CookieNames::SESSION_TOKEN . ' cookie is required');

        (new PollAgent())->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: 'poll-ak',
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

        (new PollAgent())->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: 'poll-ak',
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

        (new PollAgent())->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: 'poll-ak',
                cookies: [CookieNames::SESSION_TOKEN => 'short'],
                clientIp: '127.0.0.1',
                queryParams: RequestQueryParams::empty(),
            ),
            '',
            '',
        );
    }
}
