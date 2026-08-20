<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Tests\Unit;

use Demo\SimpleTodo\Agents\TodoAgent;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TodoAgent handshake session token validation.
 *
 * The daemon resolves the token on the 101 and carries it on the DTO; the
 * worker rejects an empty or malformed token before any database access. What a
 * valid token goes on to do - name the guest behind an anonymous session, or clear
 * the name of one that gained an account - is covered by the integration suite and e2e.
 */
final class TodoAgentHandshakeTest extends TestCase
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
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('Invalid session token format. Expected 32 lowercase hex characters.');

        new TodoAgent()->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: 'todo-ak',
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

        new TodoAgent()->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: 'todo-ak',
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
