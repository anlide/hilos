<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Tests\Integration;

use Demo\OnlineTesting\Agents\OnlineTestingAgent;
use Demo\OnlineTesting\Hilos;
use Demo\OnlineTesting\Runtime\View\Context\OnlineTestingRtContext;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Proves what the handshake leaves behind in a demo whose visitors stay nameless.
 *
 * The session is the framework's and the socket is this demo's: the cases drive the handshake
 * the way the daemon does - with a token it has already resolved - and read back the
 * connection row and the handshake response. A visitor gets no account and no name; a
 * session that carries an account binds its socket to that account and says who it is.
 *
 * Requires the test DB reset (composer run test:db-reset).
 */
final class OnlineTestingAgentHandshakeTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    private ?SignalRouter $previousRouter = null;

    protected function setUp(): void
    {
        parent::setUp();
        RtTruthSourceRegistry::register(
            OnlineTestingRtContext::connections,
            TruthSourceKeys::all(),
            self::TEST_AGENT_ID,
        );
        Hilos::$rt->connections->actions->clear();
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = $this->previousRouter;
        Hilos::$rt->connections->actions->clear();
        parent::tearDown();
    }

    /**
     * A new cookie gets an anonymous connection, no account, and a response without one.
     *
     * @throws HilosException On database or runtime failure
     */
    public function testAnonymousHandshakeMintsNoUserAndAnswersWithoutAnAccount(): void
    {
        $token = RandomHelper::hex(16);
        $usersBefore = count(Hilos::$db->users->listAll());

        $this->handshake($token, 'online-testing-anon-ak');

        self::assertCount($usersBefore, Hilos::$db->users->listAll(), 'A visitor mints no user');

        $connection = Hilos::$rt->connections['online-testing-anon-ak'];
        self::assertNotNull($connection);
        self::assertNull($connection->userId);
        self::assertSame($token, $connection->sessionToken);

        $response = $this->lastHandshakeResponseFor('online-testing-anon-ak');
        self::assertNotNull($response, 'The handshake is answered even for a visitor');
        self::assertNull($response->selfId);
        self::assertNull($response->selfName);
    }

    /**
     * A session that gained an account binds its next socket to it and names it.
     *
     * The account is bound the way `admin:create` binds it - onto the session row - and the next
     * handshake on the same cookie is what carries it onto the socket and into the response.
     *
     * @throws HilosException On database or runtime failure
     */
    public function testHandshakeOfAnAccountBindsTheConnectionAndNamesThePerson(): void
    {
        $token = RandomHelper::hex(16);

        $this->handshake($token, 'online-testing-bind-ak-1');
        $this->drainSignals();

        $admin = Hilos::$db->users->actions->registerAdmin();
        Hilos::$db->sessions->findByToken($token)?->actions->bindUser((int)$admin->id);

        $this->handshake($token, 'online-testing-bind-ak-2');

        $connection = Hilos::$rt->connections['online-testing-bind-ak-2'];
        self::assertNotNull($connection);
        self::assertSame((int)$admin->id, $connection->userId);

        $response = $this->lastHandshakeResponseFor('online-testing-bind-ak-2');
        self::assertNotNull($response);
        self::assertSame((int)$admin->id, $response->selfId);
        self::assertSame($admin->name, $response->selfName);
    }

    /**
     * Drives one handshake, the way the daemon does once it has resolved the token.
     *
     * @param string $sessionToken Session token the handshake carries
     * @param string $acceptKey Accept key of the socket shaking hands
     * @throws HilosException On database or runtime failure
     */
    private function handshake(string $sessionToken, string $acceptKey): void
    {
        $this->deliverHandshake(new OnlineTestingAgent(), new WebSocketHandshakeSignalDTO(
            headers: [],
            acceptKey: $acceptKey,
            cookies: [],
            clientIp: '127.0.0.1',
            queryParams: RequestQueryParams::empty(),
            sessionToken: $sessionToken,
        ));
    }
}
