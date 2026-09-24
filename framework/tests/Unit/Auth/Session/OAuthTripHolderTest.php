<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Auth\Session;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\DTO\AuthSessionGrantSignalData;
use Hilos\Auth\Library\DTO\OAuthCallbackActionDTO;
use Hilos\Auth\OAuth\DTO\OAuthResultSignalData;
use Hilos\Auth\OAuth\DTO\OAuthTripEndedSignalData;
use Hilos\Auth\OAuth\DTO\OAuthTripOpenedSignalData;
use Hilos\Auth\Session\DTO\OAuthResumeActionDTO;
use Hilos\Auth\Session\DTO\OAuthResumeReplyDTO;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Auth\Code\DTO\CodeSendProgressSignalData;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\DTO\AgentsGoneSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt as StateHilosCodeSendAttempt;
use Hilos\Runtime\State\Item\HilosOAuthTrip as StateHilosOAuthTrip;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\OAuthPendingLogin;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Collection\HilosOAuthTrips;
use Hilos\Runtime\View\Item\HilosOAuthTrip as ViewHilosOAuthTrip;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Unit tests for how the session holder ends a provider sign-in a tab is waiting on (HIL-1044).
 *
 * Every ending of the exchange reaches the holder, and the holder is the one that knows which
 * connection the tab is on now. What is pinned here is the part that needs no database: the first
 * ending wins, an ending reaches a live connection at once and waits on the record for a dead one,
 * a sign-in owed to a dead connection is held rather than applied, and a key presented by a new
 * connection moves the outcome there - but only under the cookie the trip was started with.
 *
 * Applying a sign-in writes the session row, so that half is pinned against a real database by
 * the integration suite's OAuthTripSignInTest.
 */
final class OAuthTripHolderTest extends TestCase
{
    private const string PROVIDER = 'oauth:github';

    /** Cookie token the trips below are started under. */
    private const string SESSION_TOKEN = 'aaaabbbbccccddddeeeeffff00001111';

    /** Cookie token of another browser. */
    private const string OTHER_SESSION_TOKEN = '2222333344445555666677778888999a';

    /** Connection the callback came from, which the cases treat as gone. */
    private const string DEAD_ACCEPT_KEY = 'accept-dropped';

    /** Connection of the same browser that is on the wire. */
    private const string LIVE_ACCEPT_KEY = 'accept-live';

    /** Connection of another browser that is on the wire. */
    private const string STRANGER_ACCEPT_KEY = 'accept-stranger';

    private const string TRIP_KEY = '0123456789abcdef0123456789abcdef';

    private const int USER_ID = 42;

    /** Long enough that the millisecond clock has certainly moved on. */
    private const int PAST_ANY_WINDOW_US = 2000;

    private ?SignalRouter $previousSignalRouter = null;

    private ?RtContext $previousRt = null;

    /** @var ?class-string<Hilos> Facade class bound before the case declared the sign-in feature */
    private ?string $boundAppClass = null;

    protected function setUp(): void
    {
        $this->previousSignalRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->previousRt = Hilos::$rt;
        Hilos::$rt = new OAuthTripHolderTestRtContext();
        Hilos::$rt->mountFeatureRuntime([new AuthFeature()]);
        Hilos::$rt->configure();
        Hilos::$rt->bindStateCollectionNames();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        RtTruthSourceRegistry::registerDaemon(StateHilosOAuthTrip::RT_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(StateHilosCodeSendAttempt::RT_COLLECTION);
    }

    protected function tearDown(): void
    {
        if ($this->boundAppClass !== null) {
            new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->boundAppClass);
            $this->boundAppClass = null;
        }
        RtTruthSourceRegistry::unregisterDaemon(StateHilosCodeSendAttempt::RT_COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(StateHilosOAuthTrip::RT_COLLECTION);
        SourceChangeBus::reset();
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;
    }

    public function testAnEndingReachesTheTabAtOnceWhileItsConnectionIsAlive(): void
    {
        $agent = new OAuthTripHolderTestAgent();
        $this->open($agent, self::LIVE_ACCEPT_KEY);

        $this->end($agent, OAuthResultSignalData::REASON_LOGIN_FAILED);

        $results = $this->drainResults();
        $this->assertCount(1, $results);
        $this->assertSame(self::LIVE_ACCEPT_KEY, $results[0]->acceptKey);
        $this->assertSame(OAuthResultSignalData::REASON_LOGIN_FAILED, $results[0]->reason);
        $this->assertSame(OAuthResultSignalData::REASON_LOGIN_FAILED, $this->trip()?->ending);
    }

    public function testTheFirstEndingWinsAndALateOneIsDropped(): void
    {
        $agent = new OAuthTripHolderTestAgent();
        $this->open($agent, self::LIVE_ACCEPT_KEY, OAuthPendingLogin::MODE_LINK);
        $this->end($agent, OAuthResultSignalData::REASON_LINK_OK);
        $this->drainResults();

        $this->end($agent, OAuthResultSignalData::REASON_LINK_FAILED);

        // The tab was told the link went through; a failure reported after it - an agent
        // concluded dead a moment after it answered - must not tell it otherwise.
        $this->assertSame([], $this->drainResults());
        $this->assertSame(OAuthResultSignalData::REASON_LINK_OK, $this->trip()?->ending);
    }

    public function testAnEndingWithNoRecordIsStillAnsweredOnTheConnectionItNames(): void
    {
        $agent = new OAuthTripHolderTestAgent();

        $this->end($agent, OAuthResultSignalData::REASON_LOGIN_FAILED, self::LIVE_ACCEPT_KEY);

        // An order the holder did not expect must end in an answer, not in a spinner.
        $results = $this->drainResults();
        $this->assertCount(1, $results);
        $this->assertSame(self::LIVE_ACCEPT_KEY, $results[0]->acceptKey);
    }

    public function testAnEndingOwedToADeadConnectionWaitsOnTheRecord(): void
    {
        $agent = new OAuthTripHolderTestAgent();
        $this->open($agent, self::DEAD_ACCEPT_KEY);

        $this->end($agent, OAuthResultSignalData::REASON_LOGIN_FAILED);

        $this->assertSame([], $this->drainResults());
        $this->assertSame(OAuthResultSignalData::REASON_LOGIN_FAILED, $this->trip()?->ending);
    }

    public function testASignInOwedToADeadConnectionIsHeldNotApplied(): void
    {
        $agent = new OAuthTripHolderTestAgent();
        $this->open($agent, self::DEAD_ACCEPT_KEY);
        $this->drain();

        $this->grant($agent);

        // Applying it would rotate the token and hand the ticket to nobody; the browser would
        // come back with its old cookie as a guest while its signed-in session belonged to no one.
        $this->assertSame([], $this->drainStateFrames());
        $this->assertSame(StateHilosOAuthTrip::ENDING_GRANTED, $this->trip()?->ending);
        $this->assertSame(self::USER_ID, $this->trip()?->userId);
    }

    public function testASignInArrivingAfterTheTripEndedIsDropped(): void
    {
        $agent = new OAuthTripHolderTestAgent();
        $this->open($agent, self::LIVE_ACCEPT_KEY);
        $this->end($agent, OAuthResultSignalData::REASON_LOGIN_FAILED);
        $this->drain();

        $this->grant($agent);

        // The tab was told the sign-in failed; signing the session in behind that sentence
        // would be the one outcome worse than either.
        $this->assertSame([], $this->drainStateFrames());
        $this->assertSame(OAuthResultSignalData::REASON_LOGIN_FAILED, $this->trip()?->ending);
    }

    public function testAKeyTheHolderDoesNotKeepIsNotKnown(): void
    {
        $agent = new OAuthTripHolderTestAgent();

        $reply = $this->resume($agent, self::LIVE_ACCEPT_KEY);

        $this->assertFalse($reply->known);
    }

    public function testAKeyPresentedUnderAnotherCookieOpensNothing(): void
    {
        $agent = new OAuthTripHolderTestAgent();
        $this->open($agent, self::DEAD_ACCEPT_KEY);
        $this->end($agent, OAuthResultSignalData::REASON_LOGIN_FAILED);

        $reply = $this->resume($agent, self::STRANGER_ACCEPT_KEY);

        // A key that leaked without the session it was minted under is worth nothing.
        $this->assertFalse($reply->known);
        $this->assertSame([], $this->drainResults());
        $this->assertSame(self::DEAD_ACCEPT_KEY, $this->trip()?->acceptKey);
    }

    public function testAPresentedKeyHandsAStoredEndingToTheNewConnection(): void
    {
        $agent = new OAuthTripHolderTestAgent();
        $this->open($agent, self::DEAD_ACCEPT_KEY);
        $this->end($agent, OAuthResultSignalData::REASON_LOGIN_FAILED);

        $reply = $this->resume($agent, self::LIVE_ACCEPT_KEY);

        $this->assertTrue($reply->known);
        $results = $this->drainResults();
        $this->assertCount(1, $results);
        $this->assertSame(self::LIVE_ACCEPT_KEY, $results[0]->acceptKey);
    }

    public function testAPresentedKeyMovesAnOutcomeStillComingToTheNewConnection(): void
    {
        $agent = new OAuthTripHolderTestAgent();
        $this->open($agent, self::DEAD_ACCEPT_KEY);

        $reply = $this->resume($agent, self::LIVE_ACCEPT_KEY);
        $this->assertTrue($reply->known);
        $this->assertSame([], $this->drainResults());

        $this->end($agent, OAuthResultSignalData::REASON_LOGIN_FAILED);

        $results = $this->drainResults();
        $this->assertCount(1, $results);
        $this->assertSame(self::LIVE_ACCEPT_KEY, $results[0]->acceptKey);
    }

    public function testTheSweepReclaimsEndedTripsAndNeverOneStillGoing(): void
    {
        $agent = new OAuthTripHolderTestAgent();
        $this->open($agent, self::LIVE_ACCEPT_KEY);
        $agent->onSignalAgent(
            new AgentSignalData(data: new OAuthTripOpenedSignalData(
                StateHilosOAuthTrip::hashKey(str_repeat('f', 32)),
                ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN),
                self::LIVE_ACCEPT_KEY,
                OAuthPendingLogin::MODE_LOGIN,
                self::PROVIDER,
            )),
            'test',
            HilosSignalConstants::HILOS_OAUTH_TRIP_OPENED,
        );
        $this->end($agent, OAuthResultSignalData::REASON_LOGIN_FAILED);
        usleep(self::PAST_ANY_WINDOW_US);

        // A trip still going is ended by a fact and never by the clock - that is the leaf.
        $this->assertSame(1, $this->trips()->actions->forgetEnded(1));
        $this->assertNull($this->trip());
        $this->assertNotNull($this->trips()[StateHilosOAuthTrip::hashKey(str_repeat('f', 32))]);
    }

    public function testAGoneOAuthAgentEndsLoginsAndLinksAlike(): void
    {
        $this->declareSignInSurface();
        $agent = new OAuthTripHolderTestAgent();
        $this->open($agent, self::LIVE_ACCEPT_KEY, OAuthPendingLogin::MODE_LINK);

        $this->gone($agent, HilosAgentType::HILOS_OAUTH);

        // Every answer the tab was waiting on went with the agent, so the fact ends the wait.
        $results = $this->drainResults();
        $this->assertCount(1, $results);
        $this->assertSame(OAuthResultSignalData::REASON_LINK_FAILED, $results[0]->reason);
    }

    public function testAGoneUsersLibraryEndsALoginButNotALink(): void
    {
        $this->declareSignInSurface();
        $agent = new OAuthTripHolderTestAgent();
        $this->open($agent, self::LIVE_ACCEPT_KEY);
        $linkKeyHash = StateHilosOAuthTrip::hashKey(str_repeat('e', 32));
        $agent->onSignalAgent(
            new AgentSignalData(data: new OAuthTripOpenedSignalData(
                $linkKeyHash,
                ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN),
                self::LIVE_ACCEPT_KEY,
                OAuthPendingLogin::MODE_LINK,
                self::PROVIDER,
            )),
            'test',
            HilosSignalConstants::HILOS_OAUTH_TRIP_OPENED,
        );

        $this->gone($agent, HilosAgentType::HILOS_USERS_LIBRARY);

        // A link settles in the OAuth agent alone; the library is not on its way.
        $this->assertSame(OAuthResultSignalData::REASON_LOGIN_FAILED, $this->trip()?->ending);
        $this->assertNull($this->trips()[$linkKeyHash]?->ending);
    }

    public function testAGoneCodeAgentFailsTheSendsItCarriedButNotTheLetters(): void
    {
        $this->declareSignInSurface();
        $agent = new OAuthTripHolderTestAgent();
        $attempts = Hilos::$rt?->hilosCodeSendAttempts;
        $this->assertNotNull($attempts);
        $attempts->actions->start('hash-phone', 'ticket-phone', 'telegram');
        $attempts->actions->start('hash-mail', 'ticket-mail', StateHilosCodeSendAttempt::CHANNEL_EMAIL);

        $this->gone($agent, HilosAgentType::HILOS_AUTH_CODE);

        // The letter is carried by the mail queue, which outlives a fall and sends again.
        $this->assertSame(StateHilosCodeSendAttempt::STATE_FAILED, $attempts['hash-phone']?->state);
        $this->assertSame(StateHilosCodeSendAttempt::STATE_QUEUED, $attempts['hash-mail']?->state);
        $published = array_values(array_filter(
            $this->drain(),
            static fn(mixed $payload): bool => $payload instanceof WebSocketSignalData
                && $payload->data instanceof CodeSendProgressSignalData,
        ));
        $this->assertCount(1, $published);
    }

    public function testAnAgentUnrelatedToSigningInEndsNothing(): void
    {
        $this->declareSignInSurface();
        $agent = new OAuthTripHolderTestAgent();
        $this->open($agent, self::LIVE_ACCEPT_KEY);

        $this->gone($agent, 'chat');

        $this->assertNull($this->trip()?->ending);
    }

    public function testAHolderThatStartsToOpenSignInsEndsThemAll(): void
    {
        $this->declareSignInSurface();
        $agent = new OAuthTripHolderTestAgent();
        $this->open($agent, self::DEAD_ACCEPT_KEY);

        // The rows outlived the worker that fell; the frames that could end them did not.
        $agent->onStart();

        $this->assertSame(OAuthResultSignalData::REASON_LOGIN_FAILED, $this->trip()?->ending);
    }

    public function testAKeyNoTabMintsIsRefusedWhereItEntersFromTheWire(): void
    {
        $this->expectException(InvalidFormatException::class);

        OAuthCallbackActionDTO::fromArray([
            'provider' => self::PROVIDER,
            'code' => 'code-1',
            'state' => 'state-1',
            'tripKey' => '',
        ]);
    }

    public function testAPresentedKeyNoTabMintsIsRefusedToo(): void
    {
        $this->expectException(InvalidFormatException::class);

        OAuthResumeActionDTO::fromArray([OAuthResumeActionDTO::tripKey => 'NOT-HEX']);
    }

    /**
     * Opens the trip under test, as the users library does on accepting the callback.
     *
     * @param OAuthTripHolderTestAgent $agent Holder under test
     * @param string $acceptKey Connection the callback came from
     * @param string $mode Flow mode of the exchange
     */
    private function open(
        OAuthTripHolderTestAgent $agent,
        string $acceptKey,
        string $mode = OAuthPendingLogin::MODE_LOGIN,
    ): void {
        $agent->onSignalAgent(
            new AgentSignalData(data: new OAuthTripOpenedSignalData(
                StateHilosOAuthTrip::hashKey(self::TRIP_KEY),
                ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN),
                $acceptKey,
                $mode,
                self::PROVIDER,
            )),
            'test',
            HilosSignalConstants::HILOS_OAUTH_TRIP_OPENED,
        );
    }

    /**
     * Reports an ending of the trip under test, as the agent carrying the exchange does.
     *
     * @param OAuthTripHolderTestAgent $agent Holder under test
     * @param string $reason How it ended
     * @param string $acceptKey Connection the callback came from
     */
    private function end(
        OAuthTripHolderTestAgent $agent,
        string $reason,
        string $acceptKey = self::DEAD_ACCEPT_KEY,
    ): void {
        $agent->onSignalAgent(
            new AgentSignalData(data: new OAuthTripEndedSignalData(
                StateHilosOAuthTrip::hashKey(self::TRIP_KEY),
                $acceptKey,
                self::PROVIDER,
                $reason,
            )),
            'test',
            HilosSignalConstants::HILOS_OAUTH_TRIP_ENDED,
        );
    }

    /**
     * Tells the holder one agent of a type is gone, as the master does.
     *
     * @param OAuthTripHolderTestAgent $agent Holder under test
     * @param string $agentType Type of the agent that is gone
     */
    private function gone(OAuthTripHolderTestAgent $agent, string $agentType): void
    {
        $agent->onSignalAgent(
            new AgentSignalData(data: new AgentsGoneSignalData([$agentType], AgentsGoneSignalData::REASON_WORKER_DIED)),
            'test',
            HilosSignalConstants::HILOS_AGENTS_GONE,
        );
    }

    /**
     * Binds a facade that declares the sign-in feature, which is what the holder asks before it
     * touches anything a sign-in leaves behind.
     */
    private function declareSignInSurface(): void
    {
        $this->boundAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, OAuthTripHolderTestHilos::class);
    }

    /**
     * Asks the holder to sign the trip's session in, as the users library does.
     *
     * @param OAuthTripHolderTestAgent $agent Holder under test
     */
    private function grant(OAuthTripHolderTestAgent $agent): void
    {
        $agent->onSignalAgent(
            new AgentSignalData(data: new AuthSessionGrantSignalData(
                sessionToken: self::SESSION_TOKEN,
                userId: self::USER_ID,
                acceptKey: self::DEAD_ACCEPT_KEY,
                tripKeyHash: StateHilosOAuthTrip::hashKey(self::TRIP_KEY),
            )),
            'test',
            HilosSignalConstants::HILOS_AUTH_SESSION_GRANT,
        );
    }

    /**
     * Presents the trip's key from one connection.
     *
     * @param OAuthTripHolderTestAgent $agent Holder under test
     * @param string $acceptKey Connection presenting the key
     * @return OAuthResumeReplyDTO What the holder answered
     */
    private function resume(OAuthTripHolderTestAgent $agent, string $acceptKey): OAuthResumeReplyDTO
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
     * @return list<OAuthResultSignalData> Result frames queued for a tab since the last drain
     */
    private function drainResults(): array
    {
        $results = [];
        foreach ($this->drain() as $payload) {
            if ($payload instanceof WebSocketSignalData && $payload->data instanceof OAuthResultSignalData) {
                $results[] = $payload->data;
            }
        }

        return $results;
    }

    /**
     * @return list<SessionStateSignalData> Session state frames queued since the last drain
     */
    private function drainStateFrames(): array
    {
        $frames = [];
        foreach ($this->drain() as $payload) {
            if ($payload instanceof AgentSignalData && $payload->data instanceof SessionStateSignalData) {
                $frames[] = $payload->data;
            }
        }

        return $frames;
    }

    /**
     * @return list<mixed> Payloads of every signal queued since the last drain
     */
    private function drain(): array
    {
        $payloads = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $payloads[] = $signal->data;
        }

        return $payloads;
    }

    /**
     * @return HilosOAuthTrips Mounted collection under test
     */
    private function trips(): HilosOAuthTrips
    {
        $trips = Hilos::$rt?->hilosOAuthTrips;
        $this->assertInstanceOf(HilosOAuthTrips::class, $trips);

        return $trips;
    }

    /**
     * @return ?ViewHilosOAuthTrip The trip under test, or null when it is gone
     */
    private function trip(): ?ViewHilosOAuthTrip
    {
        return $this->trips()[StateHilosOAuthTrip::hashKey(self::TRIP_KEY)];
    }
}

/**
 * Runtime with the sign-in feature and three connection rows: two of the trip's browser, of which
 * one is on the wire, and one of a stranger.
 */
final class OAuthTripHolderTestRtContext extends RtContext
{
    public function configure(): void
    {
        $connections = OAuthTripHolderTestConnections::init();
        $connections->add(OAuthTripHolderTestConnection::create('accept-live', null, 'aaaabbbbccccddddeeeeffff00001111'));
        $connections->add(OAuthTripHolderTestConnection::create('accept-stranger', null, '2222333344445555666677778888999a'));
        $this->_stateCollections[OAuthTripHolderTestConnections::RT_COLLECTION] = $connections;
    }
}

/**
 * Facade of a fixture project that draws a sign-in surface.
 */
abstract class OAuthTripHolderTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::AUTH];
}

/**
 * Sessions library of a fixture project, which needs nothing beyond being instantiable.
 */
final class OAuthTripHolderTestAgent extends AbstractSessionsLibraryAgent
{
    public function onStop(): void
    {
    }
}

/**
 * Session-stage connection collection of the fixture project.
 */
final class OAuthTripHolderTestConnections extends HilosSessionConnections
{
    /** @var string Runtime collection name this fixture mounts under */
    public const string RT_COLLECTION = 'oauthTripHolderTestConnections';

    public const string STATE_CLASS = OAuthTripHolderTestConnection::class;
}

/**
 * Session-stage connection row of the fixture project, adding nothing of its own.
 */
final class OAuthTripHolderTestConnection extends HilosSessionConnection
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
