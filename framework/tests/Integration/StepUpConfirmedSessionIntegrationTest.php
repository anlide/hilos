<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\StepUp\DTO\StepUpConfirmedSignalData;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt as StateHilosCodeSendAttempt;
use Hilos\Runtime\State\Item\HilosProfileFlow as StateHilosProfileFlow;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\State\Item\HilosSessionToastStack as StateHilosSessionToastStack;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use ReflectionProperty;

/**
 * How the session holder tells a tab that connects which operations its session has confirmed (HIL-1330).
 *
 * The writer's frame to every tab is pinned by StepUpIntegrationTest; what is pinned here is the handshake:
 * a tab that was away when the confirmation landed - a background tab of a phone - is told on connecting,
 * and it alone, because the others heard of it when it was written. A session with nothing confirmed is
 * told nothing at all, and a session with nobody in it is not asked.
 */
final class StepUpConfirmedSessionIntegrationTest extends HilosSessionIntegrationTestCase
{
    public const string SESSION_TOKEN = '0123456789abcdef0123456789a01330';

    public const string GUEST_SESSION_TOKEN = 'fedcba9876543210fedcba9876501330';

    public const string TAB_A = 'accept-confirmed-tab-a';

    public const string TAB_B = 'accept-confirmed-tab-b';

    public const int USER_ID = 1330;

    private const string CREATED_AT = '2026-10-08 10:00:00';

    /** Long enough that no case outlives the confirmation it seeds. */
    private const int CONFIRMATION_LIFETIME_SECONDS = 900;

    private ?SignalRouter $previousSignalRouter = null;

    private ?RtContext $previousRt = null;

    /** @var ?class-string<Hilos> Facade class bound before the case declared the sign-in feature */
    private ?string $boundAppClass = null;

    /**
     * @throws DatabaseException When a stub statement fails
     * @throws HilosException When the runtime context cannot be built
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previousSignalRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new StepUpConfirmedSessionTestRtContext();
        Hilos::$rt->mountFeatureRuntime([new AuthFeature()]);
        Hilos::$rt->configure();
        Hilos::$rt->bindStateCollectionNames();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        RtTruthSourceRegistry::registerDaemon(StateHilosProfileFlow::RT_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(StateHilosCodeSendAttempt::RT_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(StateHilosSessionRotation::RT_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(StateHilosSessionToastStack::RT_COLLECTION);
        $this->boundAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, StepUpConfirmedSessionTestHilos::class);
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->boundAppClass);
        RtTruthSourceRegistry::unregisterDaemon(StateHilosSessionToastStack::RT_COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(StateHilosSessionRotation::RT_COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(StateHilosCodeSendAttempt::RT_COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(StateHilosProfileFlow::RT_COLLECTION);
        SourceChangeBus::reset();
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;

        parent::tearDown();
    }

    /**
     * A tab that connects to a session with live confirmations is told them, and no other tab is.
     *
     * @throws HilosException When the seed or the handshake fails
     */
    public function testAHandshakeTellsTheConnectingTabAloneWhatItsSessionConfirmed(): void
    {
        self::seedSession(self::SESSION_TOKEN, self::USER_ID, self::CREATED_AT, null);
        $this->seedConfirmation(self::SESSION_TOKEN, StepUpOperationKey::EXPORT_DATA);
        $this->seedConfirmation(self::SESSION_TOKEN, StepUpOperationKey::CHANGE_EMAIL);

        $this->handshake(self::TAB_B, self::SESSION_TOKEN);

        $frames = $this->confirmedFrames();
        self::assertCount(1, $frames);
        self::assertSame(self::TAB_B, $frames[0]->targetAcceptKey);
        self::assertNull($frames[0]->targetSessionTokenHash);
        self::assertInstanceOf(StepUpConfirmedSignalData::class, $frames[0]->data);
        self::assertSame([StepUpOperationKey::CHANGE_EMAIL, StepUpOperationKey::EXPORT_DATA], $frames[0]->data->operations);
    }

    /**
     * A session with nothing confirmed is sent no frame: an empty list would take nothing away.
     *
     * @throws HilosException When the seed or the handshake fails
     */
    public function testAHandshakeOfASessionWithNothingConfirmedSendsNoFrame(): void
    {
        self::seedSession(self::SESSION_TOKEN, self::USER_ID, self::CREATED_AT, null);

        $this->handshake(self::TAB_A, self::SESSION_TOKEN);

        self::assertSame([], $this->confirmedFrames());
    }

    /**
     * A session with nobody in it is not asked, whatever rows its hash may still match.
     *
     * @throws HilosException When the seed or the handshake fails
     */
    public function testAHandshakeOfAGuestSessionSendsNoFrame(): void
    {
        self::seedSession(self::SESSION_TOKEN, self::USER_ID, self::CREATED_AT, null);
        self::seedSession(self::GUEST_SESSION_TOKEN, null, self::CREATED_AT, null);
        $this->seedConfirmation(self::GUEST_SESSION_TOKEN, StepUpOperationKey::EXPORT_DATA);

        $this->handshake(self::TAB_A, self::GUEST_SESSION_TOKEN);

        self::assertSame([], $this->confirmedFrames());
    }

    /**
     * Writes one live confirmation of the session's person straight into the table.
     *
     * @param string $sessionToken Browser the row belongs to
     * @param string $operation Operation key
     * @throws DatabaseException When the insert fails
     */
    private function seedConfirmation(string $sessionToken, string $operation): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_step_up` (`session_token_hash`, `user_id`, `operation`, `confirmed_until`) '
            . 'VALUES (?, ?, ?, ?)',
            [
                ProtectedModeRuntime::hashSessionToken($sessionToken),
                self::USER_ID,
                $operation,
                date('Y-m-d H:i:s', time() + self::CONFIRMATION_LIFETIME_SECONDS),
            ],
        );
    }

    /**
     * Connects one tab of a session, as the master does on a socket's handshake.
     *
     * @param string $acceptKey Tab connecting
     * @param string $sessionToken Session cookie the tab presents
     * @throws HilosException When the handshake fails
     */
    private function handshake(string $acceptKey, string $sessionToken): void
    {
        new StepUpConfirmedSessionTestHolder()->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: $acceptKey,
                cookies: [],
                clientIp: null,
                sessionToken: $sessionToken,
            ),
            'test',
            'handshake',
        );
    }

    /**
     * @return list<WebSocketSignalData> Every confirmation frame queued since the last call, in order
     */
    private function confirmedFrames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if (
                $signal->signalName->getName() === HilosSignalConstants::HILOS_STEP_UP_CONFIRMED
                && $signal->data instanceof WebSocketSignalData
            ) {
                $frames[] = $signal->data;
            }
        }

        return $frames;
    }
}

/**
 * Runtime holding two tabs of one browser of the person.
 */
final class StepUpConfirmedSessionTestRtContext extends RtContext
{
    public function configure(): void
    {
        $connections = StepUpConfirmedSessionTestConnections::init();
        $connections->add(StepUpConfirmedSessionTestConnection::create(
            StepUpConfirmedSessionIntegrationTest::TAB_A,
            StepUpConfirmedSessionIntegrationTest::USER_ID,
            StepUpConfirmedSessionIntegrationTest::SESSION_TOKEN,
        ));
        $connections->add(StepUpConfirmedSessionTestConnection::create(
            StepUpConfirmedSessionIntegrationTest::TAB_B,
            StepUpConfirmedSessionIntegrationTest::USER_ID,
            StepUpConfirmedSessionIntegrationTest::SESSION_TOKEN,
        ));
        $this->_stateCollections[StepUpConfirmedSessionTestConnections::RT_COLLECTION] = $connections;
    }
}

/**
 * Facade of a fixture project that draws a sign-in surface.
 */
abstract class StepUpConfirmedSessionTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::AUTH];
}

/**
 * Sessions library of a fixture project, which needs nothing beyond being instantiable.
 */
final class StepUpConfirmedSessionTestHolder extends AbstractSessionsLibraryAgent
{
    public function onStop(): void
    {
    }
}

/**
 * Session-stage connection collection of the fixture project.
 */
final class StepUpConfirmedSessionTestConnections extends HilosSessionConnections
{
    /** @var string Runtime collection name this fixture mounts under */
    public const string RT_COLLECTION = 'stepUpConfirmedSessionTestConnections';

    public const string STATE_CLASS = StepUpConfirmedSessionTestConnection::class;
}

/**
 * Session-stage connection row of the fixture project, adding nothing of its own.
 */
final class StepUpConfirmedSessionTestConnection extends HilosSessionConnection
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
