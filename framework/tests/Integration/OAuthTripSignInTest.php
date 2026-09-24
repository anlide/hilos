<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\DTO\AuthSessionGrantSignalData;
use Hilos\Auth\OAuth\DTO\OAuthTripOpenedSignalData;
use Hilos\Auth\Session\DTO\OAuthResumeActionDTO;
use Hilos\Auth\Session\DTO\OAuthResumeReplyDTO;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosOAuthTrip as StateHilosOAuthTrip;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\State\Item\OAuthPendingLogin;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\HilosOAuthTrip as ViewHilosOAuthTrip;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Integration tests for a provider sign-in that ends in a session, against the session table
 * (HIL-1044).
 *
 * A sign-in rotates the session token and hands the ticket for the new cookie to the tab that
 * started it alone (HIL-582). The three cases are the three moments that ticket can be handed
 * over: at once to a tab that is on the wire, later to a tab that was away when the sign-in
 * arrived, and again to a tab whose first ticket died with its connection.
 */
final class OAuthTripSignInTest extends HilosSessionIntegrationTestCase
{
    private const string CREATED_AT = '2026-09-01 09:15:00';

    /** Cookie the trip is started under. */
    private const string SESSION_TOKEN = 'aa00000000000000000000000000aa44';

    /** Connection the callback came from, which the second case treats as gone. */
    private const string DROPPED_ACCEPT_KEY = 'accept-dropped';

    /** Connection of the same browser on the wire from the start. */
    private const string LIVE_ACCEPT_KEY = 'accept-live';

    /** Connection the browser comes back on, still carrying its old cookie. */
    private const string RETURNING_ACCEPT_KEY = 'accept-returning';

    private const string TRIP_KEY = 'fedcba9876543210fedcba9876543210';

    private const int USER_ID = 42;

    private ?SignalRouter $previousSignalRouter = null;

    private ?RtContext $previousRt = null;

    /**
     * @throws DatabaseException When a stub statement or the schema reset fails
     * @throws HilosException When the runtime context cannot be built
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previousSignalRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        Hilos::$sr = new SignalRouter();
        $rt = new OAuthTripSignInTestRtContext();
        $rt->mountFeatureRuntime([new AuthFeature()]);
        $rt->configure();
        $rt->bindStateCollectionNames();
        Hilos::$rt = $rt;
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        RtTruthSourceRegistry::registerDaemon(StateHilosOAuthTrip::RT_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(StateHilosSessionRotation::RT_COLLECTION);
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateHilosOAuthTrip::RT_COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(StateHilosSessionRotation::RT_COLLECTION);
        SourceChangeBus::reset();
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;

        parent::tearDown();
    }

    /**
     * @throws HilosException When the grant fails
     * @throws DatabaseException When seeding or reading the rows fails
     */
    public function testASignInOwedToALiveTabIsAppliedWithThatTabAsTheInitiator(): void
    {
        self::seedSession(self::SESSION_TOKEN, null, self::CREATED_AT, null);
        $sessionId = Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)?->id;
        $agent = new OAuthTripSignInTestAgent();
        $this->open($agent, self::LIVE_ACCEPT_KEY);

        $this->grant($agent);

        $frame = $this->lastStateFrame();
        $this->assertNotNull($frame);
        $this->assertSame([self::LIVE_ACCEPT_KEY], $frame->acceptKeys);
        $this->assertSame(self::USER_ID, $frame->userId);
        $this->assertNotNull($frame->rotationTicket, 'The tab that started the sign-in is handed the new cookie');
        $this->assertNull(self::sessionRow(self::SESSION_TOKEN), 'The planted token no longer names the session');
        $this->assertSame(StateHilosOAuthTrip::ENDING_SIGNED_IN, $this->trip()?->ending);
        $this->assertSame($sessionId, $this->trip()?->sessionId);
    }

    /**
     * @throws HilosException When the grant or the presentation fails
     * @throws DatabaseException When seeding or reading the rows fails
     */
    public function testAHeldSignInIsAppliedOnTheTabThatPresentsItsKey(): void
    {
        self::seedSession(self::SESSION_TOKEN, null, self::CREATED_AT, null);
        $agent = new OAuthTripSignInTestAgent();
        $this->open($agent, self::DROPPED_ACCEPT_KEY);
        $this->grant($agent);
        $this->assertNull($this->lastStateFrame(), 'Nobody was there to hand the ticket to');
        $row = self::sessionRow(self::SESSION_TOKEN);
        $this->assertNotNull($row, 'The session is not rotated while its tab is away');
        $this->assertNull($row['user_id'], 'Nor signed in');

        $reply = $this->resume($agent, self::RETURNING_ACCEPT_KEY);

        $this->assertTrue($reply->known);
        $frame = $this->lastStateFrame();
        $this->assertNotNull($frame);
        $this->assertSame([self::RETURNING_ACCEPT_KEY], $frame->acceptKeys);
        $this->assertSame(self::USER_ID, $frame->userId);
        $this->assertNotNull($frame->rotationTicket);
        $this->assertNull(self::sessionRow(self::SESSION_TOKEN));
        $this->assertSame(StateHilosOAuthTrip::ENDING_SIGNED_IN, $this->trip()?->ending);
    }

    /**
     * @throws HilosException When the grant or the presentation fails
     * @throws DatabaseException When seeding or reading the rows fails
     */
    public function testASignInWhoseTicketWasLostIsHandedAFreshOneForTheSameSession(): void
    {
        self::seedSession(self::SESSION_TOKEN, null, self::CREATED_AT, null);
        $agent = new OAuthTripSignInTestAgent();
        $this->open($agent, self::LIVE_ACCEPT_KEY);
        $this->grant($agent);
        $first = $this->lastStateFrame();
        $this->assertNotNull($first);

        // The tab lost its connection before trading the ticket and came back with the old
        // cookie - the one the trip was started under.
        $reply = $this->resume($agent, self::RETURNING_ACCEPT_KEY);

        $this->assertTrue($reply->known);
        $frame = $this->lastStateFrame();
        $this->assertNotNull($frame);
        $this->assertSame([self::RETURNING_ACCEPT_KEY], $frame->acceptKeys);
        $this->assertSame($first->sessionToken, $frame->sessionToken, 'Nothing is rotated a second time');
        $this->assertSame(self::USER_ID, $frame->userId);
        $this->assertNotNull($frame->rotationTicket);
        $this->assertNotSame($first->rotationTicket, $frame->rotationTicket);
        $this->assertNotNull(Hilos::$rt?->hilosSessionRotations[(string)$frame->rotationTicket]);
    }

    /**
     * @param OAuthTripSignInTestAgent $agent Holder under test
     * @param string $acceptKey Connection the callback came from
     */
    private function open(OAuthTripSignInTestAgent $agent, string $acceptKey): void
    {
        $agent->onSignalAgent(
            new AgentSignalData(data: new OAuthTripOpenedSignalData(
                StateHilosOAuthTrip::hashKey(self::TRIP_KEY),
                ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN),
                $acceptKey,
                OAuthPendingLogin::MODE_LOGIN,
                'oauth:github',
            )),
            'test',
            HilosSignalConstants::HILOS_OAUTH_TRIP_OPENED,
        );
    }

    /**
     * @param OAuthTripSignInTestAgent $agent Holder under test
     * @throws HilosException When the grant fails
     */
    private function grant(OAuthTripSignInTestAgent $agent): void
    {
        $agent->onSignalAgent(
            new AgentSignalData(data: new AuthSessionGrantSignalData(
                sessionToken: self::SESSION_TOKEN,
                userId: self::USER_ID,
                acceptKey: self::DROPPED_ACCEPT_KEY,
                tripKeyHash: StateHilosOAuthTrip::hashKey(self::TRIP_KEY),
            )),
            'test',
            HilosSignalConstants::HILOS_AUTH_SESSION_GRANT,
        );
    }

    /**
     * @param OAuthTripSignInTestAgent $agent Holder under test
     * @param string $acceptKey Connection presenting the key
     * @return OAuthResumeReplyDTO What the holder answered
     * @throws HilosException When the presentation fails
     */
    private function resume(OAuthTripSignInTestAgent $agent, string $acceptKey): OAuthResumeReplyDTO
    {
        $reply = $agent->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_OAUTH_RESUME,
            new OAuthResumeActionDTO(self::TRIP_KEY),
        );
        $this->assertInstanceOf(OAuthResumeReplyDTO::class, $reply);

        return $reply;
    }

    /**
     * @return ?SessionStateSignalData The last session state frame queued since the last drain
     */
    private function lastStateFrame(): ?SessionStateSignalData
    {
        $frame = null;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $payload = $signal->data;
            if ($payload instanceof AgentSignalData && $payload->data instanceof SessionStateSignalData) {
                $frame = $payload->data;
            }
        }

        return $frame;
    }

    /**
     * @return ?ViewHilosOAuthTrip The trip under test, or null when it is gone
     */
    private function trip(): ?ViewHilosOAuthTrip
    {
        return Hilos::$rt?->hilosOAuthTrips[StateHilosOAuthTrip::hashKey(self::TRIP_KEY)];
    }
}

/**
 * Runtime with the sign-in feature and two connections of the trip's browser, both on the old
 * cookie: the one that stayed, and the one it comes back on.
 */
final class OAuthTripSignInTestRtContext extends RtContext
{
    public function configure(): void
    {
        $connections = OAuthTripSignInTestConnections::init();
        $connections->add(OAuthTripSignInTestConnection::create('accept-live', null, 'aa00000000000000000000000000aa44'));
        $connections->add(OAuthTripSignInTestConnection::create('accept-returning', null, 'aa00000000000000000000000000aa44'));
        $this->_stateCollections[OAuthTripSignInTestConnections::RT_COLLECTION] = $connections;
    }
}

/**
 * Sessions library of a fixture project, which needs nothing beyond being instantiable.
 */
final class OAuthTripSignInTestAgent extends AbstractSessionsLibraryAgent
{
    public function onStop(): void
    {
    }
}

/**
 * Session-stage connection collection of the fixture project.
 */
final class OAuthTripSignInTestConnections extends HilosSessionConnections
{
    /** @var string Runtime collection name this fixture mounts under */
    public const string RT_COLLECTION = 'oauthTripSignInTestConnections';

    public const string STATE_CLASS = OAuthTripSignInTestConnection::class;
}

/**
 * Session-stage connection row of the fixture project, adding nothing of its own.
 */
final class OAuthTripSignInTestConnection extends HilosSessionConnection
{
    protected function initOwn(): void
    {
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     */
    protected function hydrateOwn(array $row): void
    {
    }

    /**
     * @return array<string, mixed> Own fields, of which this fixture has none
     */
    protected function ownToArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $diff Incoming field changes
     */
    protected function applyOwnDiff(array $diff): void
    {
    }
}
