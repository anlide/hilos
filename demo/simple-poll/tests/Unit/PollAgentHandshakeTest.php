<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Tests\Unit;

use Demo\SimplePoll\Agents\PollAgent;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PollAgent handshake session token validation.
 *
 * The daemon resolves the token on the 101 and carries it on the DTO; the
 * worker rejects an empty or malformed token before any database access. The
 * durable find-or-register happy path is covered by the integration suite and e2e.
 */
final class PollAgentHandshakeTest extends TestCase
{
    private ?SignalRouter $previousRouter = null;
    private ?AnalyticsCollector $previousCollector = null;

    protected function setUp(): void
    {
        // Isolate the global signal router and analytics collector the handshake
        // would reach after validation; the token guards throw before that.
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

    public function testHandshakeWithoutSessionTokenThrows(): void
    {
        $this->expectException(EmptyValueException::class);
        $this->expectExceptionMessage('session token is required');

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

    public function testHandshakeWithInvalidSessionTokenFormatThrows(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('session token must be a 32-character lowercase hex token');

        (new PollAgent())->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: 'poll-ak',
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
