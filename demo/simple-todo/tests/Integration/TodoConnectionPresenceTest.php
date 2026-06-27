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
        Hilos::$rt->connections->actions->register('todo-ak-1', 7);

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
        Hilos::$rt->connections->actions->register('todo-ak-1', 7);
        Hilos::$rt->connections->actions->register('todo-ak-2', 7);

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
        Hilos::$rt->connections->actions->register('todo-ak-1', 7);
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
        Hilos::$rt->connections->actions->register('todo-ak-1', 7);

        (new TodoAgent())->onSignalConnectionClose(new WebSocketCloseSignalDTO('todo-ak-1'), '', '');

        $this->assertNull(Hilos::$rt->connections['todo-ak-1']);
    }

    /**
     * The handshake registers a runtime connection for the resolved user.
     *
     * @throws HilosException On runtime or database error
     */
    public function testHandshakeRegistersConnection(): void
    {
        $previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        try {
            $token = RandomHelper::hex(16);
            $user = Hilos::$db->users->actions->register($token);

            (new TodoAgent())->onSignalHandshake(
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
            $this->assertSame((int)$user->id, $connection->userId);
        } finally {
            Hilos::$sr = $previousRouter;
        }
    }
}
