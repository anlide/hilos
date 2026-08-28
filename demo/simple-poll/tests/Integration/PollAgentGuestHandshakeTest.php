<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Tests\Integration;

use Demo\SimplePoll\Agents\PollAgent;
use Demo\SimplePoll\Database\Object\Collection\Guests as ObjectGuests;
use Demo\SimplePoll\Database\PollDbContext;
use Demo\SimplePoll\Hilos;
use Demo\SimplePoll\Runtime\View\Context\PollRtContext;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Proves the handshake leaves a visitor outside the user table (HIL-611).
 *
 * The whole of the change lives in one handler, so these cases drive it the way
 * the daemon does - with a token it has already resolved - and read back what it
 * left behind: no account, a guest row, and a connection that names nobody.
 *
 * Requires the test DB reset (composer run test:db-reset).
 */
final class PollAgentGuestHandshakeTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    private ?SignalRouter $previousRouter = null;

    protected function setUp(): void
    {
        parent::setUp();
        RtTruthSourceRegistry::register(PollRtContext::connections, true, self::TEST_AGENT_ID);
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
     * A new cookie gets a guest row, an anonymous connection, and no account.
     *
     * @throws HilosException On database or runtime failure
     */
    public function testAnonymousHandshakeNamesAGuestAndMintsNoUser(): void
    {
        $token = RandomHelper::hex(16);
        $usersBefore = count(Hilos::$db->users->listAll());

        $this->handshake($token, 'poll-guest-ak');

        self::assertCount($usersBefore, Hilos::$db->users->listAll(), 'A visitor mints no user');

        $guest = $this->guests()->findBySessionToken($token);
        self::assertNotNull($guest);
        self::assertMatchesRegularExpression('/^Guest\d{4}$/', $guest->name);

        $connection = Hilos::$rt->connections['poll-guest-ak'];
        self::assertNotNull($connection);
        self::assertNull($connection->userId);
        self::assertSame($token, $connection->sessionToken);
    }

    /**
     * Coming back on the same cookie keeps the name the first visit assigned.
     *
     * @throws HilosException On database or runtime failure
     */
    public function testReturningVisitorKeepsTheSameGuestName(): void
    {
        $token = RandomHelper::hex(16);

        $this->handshake($token, 'poll-guest-ak-1');
        $first = $this->guests()->findBySessionToken($token);
        self::assertNotNull($first);

        $this->handshake($token, 'poll-guest-ak-2');
        $second = $this->guests()->findBySessionToken($token);
        self::assertNotNull($second);

        self::assertSame($first->id, $second->id);
        self::assertSame($first->name, $second->name);
    }

    /**
     * A session that gained an account loses the guest row it used to have.
     *
     * This is the lazy cleanup after `admin:create`: the command binds the account
     * to the browser, and the next handshake is what clears the name that browser
     * was known by while it had none.
     *
     * @throws HilosException On database or runtime failure
     */
    public function testHandshakeOfAnAccountClearsItsGuestRow(): void
    {
        $token = RandomHelper::hex(16);

        $this->handshake($token, 'poll-guest-ak-3');
        self::assertNotNull($this->guests()->findBySessionToken($token));

        $admin = Hilos::$db->users->actions->registerAdmin();
        Hilos::$db->sessions->findByToken($token)?->actions->bindUser((int)$admin->id);

        $this->handshake($token, 'poll-guest-ak-4');

        self::assertNull($this->guests()->findBySessionToken($token));

        $connection = Hilos::$rt->connections['poll-guest-ak-4'];
        self::assertNotNull($connection);
        self::assertSame((int)$admin->id, $connection->userId);
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
        $this->deliverHandshake(new PollAgent(), new WebSocketHandshakeSignalDTO(
            headers: [],
            acceptKey: $acceptKey,
            cookies: [],
            clientIp: '127.0.0.1',
            queryParams: RequestQueryParams::empty(),
            sessionToken: $sessionToken,
        ));
    }

    /**
     * Reads the guest rows straight off the object collection.
     *
     * The lookup by token is the persistence layer's, not the demo API's: nothing
     * in the product asks "who is this token's guest" outside the handshake, so
     * that question has no view-collection method to ask it with.
     *
     * @return ObjectGuests Guest object collection
     */
    private function guests(): ObjectGuests
    {
        $collection = Hilos::$db->getObjectCollection(PollDbContext::guests);
        self::assertInstanceOf(ObjectGuests::class, $collection);

        return $collection;
    }
}
