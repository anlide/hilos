<?php

declare(strict_types=1);

namespace Demo\Tasks\Tests\Integration;

use Demo\Tasks\Agents\TasksAgent;
use Demo\Tasks\Hilos;
use Demo\Tasks\Runtime\View\Context\TasksRtContext;
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
final class TasksConnectionPresenceTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    /** Session token of the first seeded socket; two sockets of one user sit on two sessions. */
    private const string SESSION_TOKEN_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** Session token of the second seeded socket. */
    private const string SESSION_TOKEN_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();
        RtTruthSourceRegistry::register(TasksRtContext::connections, true, self::TEST_AGENT_ID);
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
        Hilos::$rt->connections->actions->register('tasks-ak-1', 7, self::SESSION_TOKEN_A);

        $connection = Hilos::$rt->connections['tasks-ak-1'];
        $this->assertNotNull($connection);
        $this->assertSame(7, $connection->userId);
        $this->assertSame(1, count(Hilos::$rt->connections->forUser(7)));
    }

    /**
     * register tracks a connection whose session carries no user at all.
     *
     * The ordinary state of a visitor since HIL-610: the row exists, it names the
     * browser, and it names nobody. Presence is a question about accounts, so this
     * row answers none - it must simply not break the ones that do.
     *
     * @throws HilosException On runtime error
     */
    public function testRegisterTracksAnonymousConnection(): void
    {
        Hilos::$rt->connections->actions->register('tasks-ak-anon', null, self::SESSION_TOKEN_A);

        $connection = Hilos::$rt->connections['tasks-ak-anon'];
        $this->assertNotNull($connection);
        $this->assertNull($connection->userId);
        $this->assertSame(self::SESSION_TOKEN_A, $connection->sessionToken);
    }

    /**
     * summaryForUser reports the active session count and online/offline presence.
     *
     * @throws HilosException On runtime error
     */
    public function testSummaryForUserReflectsActiveSessions(): void
    {
        Hilos::$rt->connections->actions->register('tasks-ak-1', 7, self::SESSION_TOKEN_A);
        Hilos::$rt->connections->actions->register('tasks-ak-2', 7, self::SESSION_TOKEN_B);

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
        Hilos::$rt->connections->actions->register('tasks-ak-1', 7, self::SESSION_TOKEN_A);
        Hilos::$rt->connections['tasks-ak-1']?->actions->unregister();

        $this->assertNull(Hilos::$rt->connections['tasks-ak-1']);
        $this->assertSame(0, count(Hilos::$rt->connections->forUser(7)));
    }

    /**
     * The agent close hook unregisters the closing connection.
     *
     * @throws HilosException On runtime error
     */
    public function testAgentConnectionCloseUnregisters(): void
    {
        Hilos::$rt->connections->actions->register('tasks-ak-1', 7, self::SESSION_TOKEN_A);

        new TasksAgent()->onSignalConnectionClose(new WebSocketCloseSignalDTO('tasks-ak-1'), '', '');

        $this->assertNull(Hilos::$rt->connections['tasks-ak-1']);
    }

    /**
     * The handshake registers a runtime connection carrying the session.
     *
     * A new cookie resolves to a fresh anonymous session, and since HIL-610 that is
     * where it stays - so the row is checked to carry the token and no user. The
     * token is what makes it a socket of a session rather than a socket of a user,
     * and it is now the only identity such a row has.
     *
     * @throws HilosException On runtime or database error
     */
    public function testHandshakeRegistersConnection(): void
    {
        $previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        try {
            $token = RandomHelper::hex(16);

            new TasksAgent()->onSignalHandshake(
                new WebSocketHandshakeSignalDTO(
                    headers: [],
                    acceptKey: 'tasks-ak-handshake',
                    cookies: [],
                    clientIp: '127.0.0.1',
                    queryParams: RequestQueryParams::empty(),
                    sessionToken: $token,
                ),
                '',
                '',
            );

            $connection = Hilos::$rt->connections['tasks-ak-handshake'];
            $this->assertNotNull($connection);
            $this->assertSame($token, $connection->sessionToken);

            $this->assertNull($connection->userId);

            $session = Hilos::$db->sessions->findByToken($token);
            $this->assertNotNull($session);
            $this->assertNull($session->userId);
        } finally {
            Hilos::$sr = $previousRouter;
        }
    }
}
