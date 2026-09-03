<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\DTO\DismissSessionToastActionDTO;
use Hilos\Auth\Session\DTO\RaiseSessionToastSignalData;
use Hilos\Auth\Session\DTO\SessionToastExpiredActionDTO;
use Hilos\Auth\Session\DTO\SessionToastReadingActionDTO;
use Hilos\Auth\Session\SessionToastSeverity;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\State\Item\HilosSessionToastStack as StateHilosSessionToastStack;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * The road a session's toast travels, from the sender's raise to the tabs and back (HIL-768).
 *
 * The rule itself is pinned next door on the actions; what is pinned here is the LIBRARY around
 * it - that a raise becomes a frame addressed to the session rather than to a socket, that the
 * frame carries the whole stack, and that the three answers a tab can give arrive, are attributed
 * to the tab that gave them, and produce a new frame exactly when what is on screen changed.
 *
 * The connections are a fixture project's session-stage rows, which is what the library reads to
 * learn who is alive: two tabs of one browser and one of another, so "addressed to the session"
 * and "one tab answering for its neighbour" are both real here rather than assumed.
 */
final class SessionToastDeliveryIntegrationTest extends FrameworkIntegrationTestCase
{
    /**
     * @var list<string> Framework tables the handshake case needs. `hilos_setting` is the one
     *     framework collection loaded eagerly, so mounting the context reaches for it.
     */
    private const array TABLES = ['hilos_session', 'hilos_setting'];

    private const string SESSION_TOKEN = '0123456789abcdef0123456789abcdef';

    private const string OTHER_SESSION_TOKEN = 'fedcba9876543210fedcba9876543210';

    private const string TAB_A = 'accept-tab-a';

    private const string TAB_B = 'accept-tab-b';

    private const string MESSAGE = 'Backup "2026-09-03_11-00-00" is ready.';

    private ?DbContext $previousDb = null;

    protected function setUp(): void
    {
        parent::setUp();

        self::runStubs(down: true);
        self::runStubs(down: false);
        $this->previousDb = Hilos::$db;
        $db = new SessionToastDeliveryTestDbContext();
        $db->configure();
        Hilos::$db = $db;
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new SessionToastDeliveryTestRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        Hilos::$rt->configure();
        Hilos::$rt->bindStateCollectionNames();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        RtTruthSourceRegistry::registerDaemon(StateHilosSessionToastStack::RT_COLLECTION);
        // The tick sweeps the rotations too, and a sweep of a collection nobody claimed is
        // refused: the library claims both at start, so the fixture does the same.
        RtTruthSourceRegistry::registerDaemon(StateHilosSessionRotation::RT_COLLECTION);
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateHilosSessionToastStack::RT_COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(StateHilosSessionRotation::RT_COLLECTION);
        SourceChangeBus::reset();
        Hilos::$sr = null;
        Hilos::$rt = null;
        Hilos::$db = $this->previousDb;
        self::runStubs(down: true);

        parent::tearDown();
    }

    /**
     * Creates or drops the framework tables this case reads.
     *
     * @param bool $down Whether to run the teardown half of each stub
     * @throws DatabaseException When a stub statement fails
     */
    private static function runStubs(bool $down): void
    {
        // external-boundary: the neutral element of the name being built - the up file carries no suffix
        $suffix = $down ? '_down' : '';
        foreach (self::TABLES as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }

    public function testARaiseBecomesOneFrameAddressedToTheWholeSession(): void
    {
        $agent = new SessionToastDeliveryTestAgent();

        $agent->onSignalAgent(
            new AgentSignalData(data: $this->raise()),
            'test',
            HilosSignalConstants::HILOS_SESSION_TOAST_RAISE,
        );

        $frame = $this->lastToastFrame();
        $this->assertNotNull($frame);
        // Addressed by the hash of the cookie token, which is what reaches every tab of the
        // browser: the socket that asked for the backup may not be the one still open.
        $this->assertSame($this->sessionHash(), $frame->targetSessionTokenHash);
        $toasts = $frame->data->toArray()['toasts'] ?? [];
        $this->assertCount(1, $toasts);
        $this->assertSame(self::MESSAGE, $toasts[0][StateHilosSessionToastStack::TOAST_MESSAGE]);
        $this->assertSame('success', $toasts[0][StateHilosSessionToastStack::TOAST_SEVERITY]);
        $this->assertSame('/hilos/backup', $toasts[0][StateHilosSessionToastStack::TOAST_DESTINATION]);
        // What the browser answers with, and the only thing on the wire that is not drawn.
        $this->assertNotSame('', $toasts[0][StateHilosSessionToastStack::TOAST_KEY]);
    }

    public function testASessionWithNoLiveSocketIsRaisedNothing(): void
    {
        $agent = new SessionToastDeliveryTestAgent();

        $agent->onSignalAgent(
            new AgentSignalData(data: $this->raise(self::OTHER_SESSION_TOKEN)),
            'test',
            HilosSignalConstants::HILOS_SESSION_TOAST_RAISE,
        );

        // A toast lives only while somebody may be looking at it, so a browser that is gone gets
        // neither a row nor a frame - exactly as an unwatched failure says nothing today.
        $this->assertNull($this->lastToastFrame());
        $this->assertNull(Hilos::$rt?->hilosSessionToastStacks[$this->sessionHash(self::OTHER_SESSION_TOKEN)]);
    }

    public function testACountdownReportedInOneTabWaitsWhileTheOtherIsRead(): void
    {
        $agent = new SessionToastDeliveryTestAgent();
        $agent->onSignalAgent(
            new AgentSignalData(data: $this->raise()),
            'test',
            HilosSignalConstants::HILOS_SESSION_TOAST_RAISE,
        );
        $key = $this->raisedKey();
        $this->drainSignals();

        $agent->onAgentAction(self::TAB_B, HilosSignalConstants::HILOS_TOAST_READING, new SessionToastReadingActionDTO(true));
        $agent->onAgentAction(self::TAB_A, HilosSignalConstants::HILOS_TOAST_EXPIRED, new SessionToastExpiredActionDTO($key));

        // Nothing on screen changed, so nothing is said: a frame here would be the same list
        // twice, and the card is still under a cursor in the other window.
        $this->assertNull($this->lastToastFrame());
        $this->assertCount(1, Hilos::$rt?->hilosSessionToastStacks[$this->sessionHash()]?->toasts ?? []);
    }

    public function testLettingGoOfTheStackReleasesTheWaitingCountdownAndTellsBothTabs(): void
    {
        $agent = new SessionToastDeliveryTestAgent();
        $agent->onSignalAgent(
            new AgentSignalData(data: $this->raise()),
            'test',
            HilosSignalConstants::HILOS_SESSION_TOAST_RAISE,
        );
        $key = $this->raisedKey();
        $agent->onAgentAction(self::TAB_B, HilosSignalConstants::HILOS_TOAST_READING, new SessionToastReadingActionDTO(true));
        $agent->onAgentAction(self::TAB_A, HilosSignalConstants::HILOS_TOAST_EXPIRED, new SessionToastExpiredActionDTO($key));
        $this->drainSignals();

        $agent->onAgentAction(self::TAB_B, HilosSignalConstants::HILOS_TOAST_READING, new SessionToastReadingActionDTO(false));

        $frame = $this->lastToastFrame();
        $this->assertNotNull($frame);
        $this->assertSame($this->sessionHash(), $frame->targetSessionTokenHash);
        // The empty list is the legal frame that takes the last card away, and it goes to both
        // tabs - the one whose countdown finished and the one that was reading.
        $this->assertSame([], $frame->data->toArray()['toasts'] ?? null);
    }

    public function testClosingTheCardInOneTabEmptiesTheStackForBoth(): void
    {
        $agent = new SessionToastDeliveryTestAgent();
        $agent->onSignalAgent(
            new AgentSignalData(data: $this->raise()),
            'test',
            HilosSignalConstants::HILOS_SESSION_TOAST_RAISE,
        );
        $key = $this->raisedKey();
        $this->drainSignals();

        $agent->onAgentAction(self::TAB_A, HilosSignalConstants::HILOS_TOAST_DISMISS, new DismissSessionToastActionDTO($key));

        $frame = $this->lastToastFrame();
        $this->assertNotNull($frame);
        $this->assertSame([], $frame->data->toArray()['toasts'] ?? null);
        $this->assertNull(Hilos::$rt?->hilosSessionToastStacks[$this->sessionHash()]);
    }

    public function testAHandshakeIsAnsweredEvenWhenTheSessionIsOwedNothing(): void
    {
        $agent = new SessionToastDeliveryTestAgent();
        $this->drainSignals();

        $agent->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: self::TAB_A,
                cookies: [],
                clientIp: null,
                sessionToken: self::SESSION_TOKEN,
            ),
            'test',
            'handshake',
        );

        // Silence would be the same sentence as "you are owed nothing" seen from here and the
        // opposite one seen from the browser: a tab that comes back after its row was swept
        // still holds the card it had, with its countdown already reported, so nothing would
        // ever take it off. The empty frame is what takes it off.
        $frame = $this->lastToastFrame();
        $this->assertNotNull($frame);
        $this->assertSame($this->sessionHash(), $frame->targetSessionTokenHash);
        $this->assertSame([], $frame->data->toArray()['toasts'] ?? null);
    }

    public function testTheTickTakesTheRowOfASessionWhoseTabsAllClosed(): void
    {
        $agent = new SessionToastDeliveryTestAgent();
        $agent->onSignalAgent(
            new AgentSignalData(data: $this->raise()),
            'test',
            HilosSignalConstants::HILOS_SESSION_TOAST_RAISE,
        );
        $this->drainSignals();

        SessionToastDeliveryTestConnections::forget(self::TAB_A);
        SessionToastDeliveryTestConnections::forget(self::TAB_B);
        $agent->onTick();

        $this->assertNull(Hilos::$rt?->hilosSessionToastStacks[$this->sessionHash()]);
        // Nobody is left on the session, so the removal is not announced to anybody either.
        $this->assertNull($this->lastToastFrame());
    }

    /**
     * @param string $sessionToken Session the raise is addressed to
     * @return RaiseSessionToastSignalData Frame a sender queues when its run has finished
     */
    private function raise(string $sessionToken = self::SESSION_TOKEN): RaiseSessionToastSignalData
    {
        return new RaiseSessionToastSignalData(
            sessionTokenHash: $this->sessionHash($sessionToken),
            message: self::MESSAGE,
            severity: SessionToastSeverity::SUCCESS,
            source: 'Backup',
            destination: '/hilos/backup',
        );
    }

    /**
     * @param string $sessionToken Session cookie token
     * @return string Hash the stacks are keyed and addressed by
     */
    private function sessionHash(string $sessionToken = self::SESSION_TOKEN): string
    {
        return StateProtectedModeRuntime::hashSessionToken($sessionToken);
    }

    /**
     * @return string Key of the one card on the fixture session's stack
     */
    private function raisedKey(): string
    {
        $toasts = Hilos::$rt?->hilosSessionToastStacks[$this->sessionHash()]?->toasts ?? [];
        $this->assertCount(1, $toasts);

        return $toasts[0][StateHilosSessionToastStack::TOAST_KEY];
    }

    /**
     * Drains the queue and returns the last toast frame in it, ignoring the RT-sync traffic a
     * write leaves behind.
     *
     * @return ?WebSocketSignalData Last frame addressed to a session, or null when none was queued
     */
    private function lastToastFrame(): ?WebSocketSignalData
    {
        $frame = null;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalType->getType() !== SignalTypeConstants::WS_SESSION
                || $signal->signalName->getName() !== HilosSignalConstants::HILOS_SESSION_TOASTS
            ) {
                continue;
            }
            $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
            $frame = $signal->data;
        }

        return $frame;
    }

    /**
     * Empties the queue so the next assertion reads what the step under test put there.
     */
    private function drainSignals(): void
    {
        while (Hilos::$sr?->getNextQueuedSignal() !== null) {
        }
    }
}

/**
 * A framework database context with nothing but the framework's own collections.
 */
final class SessionToastDeliveryTestDbContext extends HilosDbContext
{
}

/**
 * Sessions library of a fixture project, which needs nothing beyond being instantiable.
 */
final class SessionToastDeliveryTestAgent extends AbstractSessionsLibraryAgent
{
    public function onStop(): void
    {
    }
}

/**
 * Runtime with the framework's own collections mounted and a fixture project's connections
 * beside them: two tabs of one browser, one of another.
 */
final class SessionToastDeliveryTestRtContext extends RtContext
{
    public function configure(): void
    {
        $connections = SessionToastDeliveryTestConnections::init();
        $connections->add(SessionToastDeliveryTestConnection::create(
            'accept-tab-a',
            7,
            '0123456789abcdef0123456789abcdef',
        ));
        $connections->add(SessionToastDeliveryTestConnection::create(
            'accept-tab-b',
            7,
            '0123456789abcdef0123456789abcdef',
        ));
        $connections->add(SessionToastDeliveryTestConnection::create('accept-stranger', 9, 'aaaabbbbccccddddaaaabbbbccccdddd'));
        $this->_stateCollections[SessionToastDeliveryTestConnections::RT_COLLECTION] = $connections;
        SessionToastDeliveryTestConnections::$mounted = $connections;
    }
}

/**
 * Session-stage connection collection of the fixture project.
 */
final class SessionToastDeliveryTestConnections extends HilosSessionConnections
{
    /** @var string Runtime collection name this fixture mounts under */
    public const string RT_COLLECTION = 'sessionToastDeliveryTestConnections';

    public const string STATE_CLASS = SessionToastDeliveryTestConnection::class;

    /** @var ?self The mounted instance, so a test can close a tab the way a disconnect would */
    public static ?self $mounted = null;

    /**
     * @param string $acceptKey Accept key of the connection that went away
     */
    public static function forget(string $acceptKey): void
    {
        self::$mounted?->remove($acceptKey);
    }
}

/**
 * Session-stage connection row of the fixture project, adding nothing of its own.
 */
final class SessionToastDeliveryTestConnection extends HilosSessionConnection
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
