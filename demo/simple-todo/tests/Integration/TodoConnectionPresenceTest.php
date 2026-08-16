<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Tests\Integration;

use Demo\SimpleTodo\Agents\TodoAgent;
use Demo\SimpleTodo\Hilos;
use Demo\SimpleTodo\Runtime\View\Context\TodoRtContext;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\HilosException;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Integration tests for the runtime connection presence source.
 * Requires test DB to be reset before run (composer run test:db-reset).
 */
final class TodoConnectionPresenceTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    /** Session token of the first seeded socket; two sockets of one user sit on two sessions. */
    private const string SESSION_TOKEN_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** Session token of the second seeded socket. */
    private const string SESSION_TOKEN_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();
        RtTruthSourceRegistry::register(TodoRtContext::connections, true, self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
    }

    protected function tearDown(): void
    {
        Hilos::$rt->connections->actions->clear();
        parent::tearDown();
    }

    /**
     * register tracks a connection under its accept key and owning user.
     *
     * @throws HilosException On runtime error
     */
    public function testRegisterTracksConnectionForUser(): void
    {
        Hilos::$rt->connections->actions->register('todo-ak-1', 7, self::SESSION_TOKEN_A);

        $connection = Hilos::$rt->connections['todo-ak-1'];
        $this->assertNotNull($connection);
        $this->assertSame(7, $connection->userId);
        $this->assertSame(1, count(Hilos::$rt->connections->forUser(7)));
    }

    /**
     * summaryForUser reports the active session count and online/offline presence.
     *
     * @throws HilosException On runtime error
     */
    public function testSummaryForUserReflectsActiveSessions(): void
    {
        Hilos::$rt->connections->actions->register('todo-ak-1', 7, self::SESSION_TOKEN_A);
        Hilos::$rt->connections->actions->register('todo-ak-2', 7, self::SESSION_TOKEN_B);

        $online = Hilos::$rt->connections->summaryForUser(7);
        $this->assertSame(2, $online->onlineSessionCount);
        $this->assertSame(HilosUserPresenceSummary::PRESENCE_ONLINE, $online->presence);

        $offline = Hilos::$rt->connections->summaryForUser(99);
        $this->assertSame(0, $offline->onlineSessionCount);
        $this->assertSame(HilosUserPresenceSummary::PRESENCE_OFFLINE, $offline->presence);
    }

    /**
     * unregister removes the connection from the runtime collection.
     *
     * @throws HilosException On runtime error
     */
    public function testUnregisterRemovesConnection(): void
    {
        Hilos::$rt->connections->actions->register('todo-ak-1', 7, self::SESSION_TOKEN_A);
        Hilos::$rt->connections['todo-ak-1']?->actions->unregister();

        $this->assertNull(Hilos::$rt->connections['todo-ak-1']);
        $this->assertSame(0, count(Hilos::$rt->connections->forUser(7)));
    }

    /**
     * The agent close hook unregisters the closing connection.
     *
     * @throws HilosException On runtime error
     */
    public function testAgentConnectionCloseUnregisters(): void
    {
        Hilos::$rt->connections->actions->register('todo-ak-1', 7, self::SESSION_TOKEN_A);

        new TodoAgent()->onSignalConnectionClose(new WebSocketCloseSignalDTO('todo-ak-1'), '', '');

        $this->assertNull(Hilos::$rt->connections['todo-ak-1']);
    }

    /**
     * The handshake registers a runtime connection carrying the session and its user.
     *
     * The user is not seeded any more (HIL-407): a new cookie resolves to a fresh
     * session, and registering the guest behind it is what the handshake does. So
     * the identity is read back off the session the handshake left, and the row is
     * checked to carry the token too - that is what makes it a socket of a session
     * rather than a socket of a user.
     *
     * @throws HilosException On runtime or database error
     */
    public function testHandshakeRegistersConnection(): void
    {
        $previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        try {
            $token = RandomHelper::hex(16);

            new TodoAgent()->onSignalHandshake(
                new WebSocketHandshakeSignalDTO(
                    headers: [],
                    acceptKey: 'todo-ak-handshake',
                    cookies: [],
                    clientIp: '127.0.0.1',
                    queryParams: RequestQueryParams::empty(),
                    sessionToken: $token,
                ),
                '',
                '',
            );

            $connection = Hilos::$rt->connections['todo-ak-handshake'];
            $this->assertNotNull($connection);
            $this->assertSame($token, $connection->sessionToken);

            $session = Hilos::$db->sessions->findByToken($token);
            $this->assertNotNull($session);
            $this->assertNotNull($session->userId);
            $this->assertSame($session->userId, $connection->userId);
        } finally {
            Hilos::$sr = $previousRouter;
        }
    }
}
