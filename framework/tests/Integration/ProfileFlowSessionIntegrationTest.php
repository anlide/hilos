<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Code\DTO\CodeSendStepSignalData;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\DTO\ProfileFlowStepSignalData;
use Hilos\Auth\Session\DTO\ImpersonateStopActionDTO;
use Hilos\Auth\Session\DTO\LogoutActionDTO;
use Hilos\Auth\Session\DTO\ProfileFlowsSignalData;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Auth\StepUp\StepUpOperationKey;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Feature\Definition\AuthFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
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
use Hilos\Utils\Helpers\TimeHelper;
use ReflectionProperty;

/**
 * How the session holder keeps a profile window's step where it reads and writes session rows (HIL-1182).
 *
 * The record itself and the frame it travels on are pinned by the unit suite's ProfileFlowHolderTest;
 * what is pinned here needs the session table: the tab that submitted a step is answered only after
 * every tab of the session was told where the window is, a tab that connects is told the windows of its
 * session, and a change of person - signing out, a takeover ending - takes every window of the session
 * away and tells its tabs.
 *
 * The connections are a fixture project's session-stage rows: two tabs of one browser and one of another
 * browser of the same person, so "every tab of the session" and "not another browser" are both real here.
 */
final class ProfileFlowSessionIntegrationTest extends HilosSessionIntegrationTestCase
{
    public const string SESSION_TOKEN = '0123456789abcdef0123456789a01182';

    public const string OTHER_SESSION_TOKEN = 'fedcba9876543210fedcba9876501182';

    public const string TAB_A = 'accept-flow-tab-a';

    public const string TAB_B = 'accept-flow-tab-b';

    public const string OTHER_BROWSER_TAB = 'accept-flow-other-browser';

    public const int USER_ID = 1182;

    /** The administrator behind a takeover the case ends. */
    private const int ADMINISTRATOR = 1183;

    private const string REQUEST_ID = 'request-1182';

    /** Ticket of the profile code send whose line a change of person takes away. */
    private const string TICKET = 'ticket-1186';

    private const string CURRENT = 'current@example.test';

    private const string CREATED_AT = '2026-10-01 10:00:00';

    /** Long enough that no case outlives the code its flow stands on. */
    private const int CODE_LIFETIME_MS = 900_000;

    private ?SignalRouter $previousSignalRouter = null;

    private ?RtContext $previousRt = null;

    /** @var ?class-string<Hilos> Facade class bound before the case declared the sign-in feature */
    private ?string $boundAppClass = null;

    /**
     * @throws DatabaseException When a stub statement or seed fails
     * @throws HilosException When the runtime context cannot be built
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previousSignalRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new ProfileFlowSessionTestRtContext();
        Hilos::$rt->mountFeatureRuntime([new AuthFeature()]);
        Hilos::$rt->configure();
        Hilos::$rt->bindStateCollectionNames();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        RtTruthSourceRegistry::registerDaemon(StateHilosProfileFlow::RT_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(StateHilosCodeSendAttempt::RT_COLLECTION);
        // A sign-out rotates the token and forgets the toast stack; the library claims both at start.
        RtTruthSourceRegistry::registerDaemon(StateHilosSessionRotation::RT_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(StateHilosSessionToastStack::RT_COLLECTION);
        $this->boundAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, ProfileFlowSessionTestHilos::class);

        self::seedSession(self::OTHER_SESSION_TOKEN, self::USER_ID, self::CREATED_AT, null);
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
     * The submitting tab moves on the same frame its neighbours move on, so its answer goes last.
     *
     * @throws HilosException When the seed or the frame fails
     */
    public function testTheSubmittingTabIsAnsweredAfterEveryTabWasTold(): void
    {
        self::seedSession(self::SESSION_TOKEN, self::USER_ID, self::CREATED_AT, null);
        $holder = new ProfileFlowSessionTestHolder();

        $this->step($holder, StateHilosProfileFlow::STEP_CURRENT_SENT, self::REQUEST_ID);

        $signals = $this->drain();
        $list = $this->indexOfList($signals);
        $answer = null;
        foreach ($signals as $index => $signal) {
            if ($signal->data instanceof AgentSignalData && $signal->data->data instanceof SessionStateSignalData
                && $signal->data->data->requestId === self::REQUEST_ID) {
                $answer = $index;
                self::assertSame([self::TAB_A], $signal->data->data->acceptKeys);
                self::assertSame(HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_REQUEST, $signal->data->data->action);
                self::assertNull($signal->data->data->outcome);
            }
        }
        self::assertNotNull($list, 'Every tab of the session is told the list');
        self::assertNotNull($answer, 'The submitting tab is answered');
        self::assertLessThan($answer, $list);
    }

    /**
     * A tab that connects - a second tab, a reload - opens its windows on the step already reached.
     *
     * @throws HilosException When the seed, the frame or the handshake fails
     */
    public function testAHandshakeTellsTheTabWhereTheWindowsOfItsSessionAre(): void
    {
        self::seedSession(self::SESSION_TOKEN, self::USER_ID, self::CREATED_AT, null);
        $holder = new ProfileFlowSessionTestHolder();
        $this->step($holder, StateHilosProfileFlow::STEP_CURRENT_PROVEN);
        $this->drain();

        $this->handshake($holder, self::TAB_B, self::SESSION_TOKEN);

        self::assertSame(
            [[
                'operation' => StepUpOperationKey::CHANGE_EMAIL,
                'step' => StateHilosProfileFlow::STEP_CURRENT_PROVEN,
                'address' => self::CURRENT,
                'target' => null,
            ]],
            $this->lastList($this->drain(), self::SESSION_TOKEN)?->flows,
        );
    }

    /**
     * Another browser of the same person sees nothing, and is told so rather than left guessing.
     *
     * @throws HilosException When the seed, the frame or the handshake fails
     */
    public function testAHandshakeOfASessionWithNothingGoingIsToldTheEmptyList(): void
    {
        self::seedSession(self::SESSION_TOKEN, self::USER_ID, self::CREATED_AT, null);
        $holder = new ProfileFlowSessionTestHolder();
        $this->step($holder, StateHilosProfileFlow::STEP_CURRENT_PROVEN);
        $this->drain();

        $this->handshake($holder, self::OTHER_BROWSER_TAB, self::OTHER_SESSION_TOKEN);

        self::assertSame([], $this->lastList($this->drain(), self::OTHER_SESSION_TOKEN)?->flows);
    }

    /**
     * A proof belongs to the person who gave it: signing out leaves the session's windows nothing to continue.
     *
     * @throws HilosException When the seed, the frame or the sign-out fails
     */
    public function testSigningOutTakesTheSessionsFlowsAwayAndTellsItsTabs(): void
    {
        self::seedSession(self::SESSION_TOKEN, self::USER_ID, self::CREATED_AT, null);
        $holder = new ProfileFlowSessionTestHolder();
        $this->step($holder, StateHilosProfileFlow::STEP_CURRENT_PROVEN);
        $this->step($holder, StateHilosProfileFlow::STEP_CURRENT_PROVEN, null, self::OTHER_SESSION_TOKEN);
        $this->drain();

        $holder->onAgentAction(self::TAB_A, HilosSignalConstants::HILOS_LOGOUT, LogoutActionDTO::fromArray([]));

        self::assertNull($this->flowOf(self::SESSION_TOKEN));
        self::assertSame([], $this->lastList($this->drain(), self::SESSION_TOKEN)?->flows);
        self::assertNotNull($this->flowOf(self::OTHER_SESSION_TOKEN), 'Another browser keeps its own flow');
    }

    /**
     * Ending a takeover gives the session back to the administrator, who proved none of it.
     *
     * @throws HilosException When the seed, the frame or the stop fails
     */
    public function testEndingATakeoverTakesTheSessionsFlowsAway(): void
    {
        self::seedSession(self::SESSION_TOKEN, self::USER_ID, self::CREATED_AT, null, self::ADMINISTRATOR);
        $holder = new ProfileFlowSessionTestHolder();
        $this->step($holder, StateHilosProfileFlow::STEP_CURRENT_PROVEN);
        $this->drain();

        $holder->onAgentAction(self::TAB_A, HilosSignalConstants::HILOS_IMPERSONATE_STOP, new ImpersonateStopActionDTO());

        self::assertNull($this->flowOf(self::SESSION_TOKEN));
        self::assertSame([], $this->lastList($this->drain(), self::SESSION_TOKEN)?->flows);
    }

    /**
     * A profile window's send line belongs to the person who asked, so a sign-out takes it with the flows (HIL-1186).
     *
     * @throws HilosException When the seed, the frame or the sign-out fails
     */
    public function testSigningOutTakesTheSessionsSendLineAway(): void
    {
        self::seedSession(self::SESSION_TOKEN, self::USER_ID, self::CREATED_AT, null);
        $holder = new ProfileFlowSessionTestHolder();
        $this->openSendLine($holder);
        self::assertNotNull($this->sendLineOf(self::SESSION_TOKEN));

        $holder->onAgentAction(self::TAB_A, HilosSignalConstants::HILOS_LOGOUT, LogoutActionDTO::fromArray([]));

        self::assertNull($this->sendLineOf(self::SESSION_TOKEN));
    }

    /**
     * Ending a takeover gives the session back to the administrator, who asked for no code.
     *
     * @throws HilosException When the seed, the frame or the stop fails
     */
    public function testEndingATakeoverTakesTheSessionsSendLineAway(): void
    {
        self::seedSession(self::SESSION_TOKEN, self::USER_ID, self::CREATED_AT, null, self::ADMINISTRATOR);
        $holder = new ProfileFlowSessionTestHolder();
        $this->openSendLine($holder);

        $holder->onAgentAction(self::TAB_A, HilosSignalConstants::HILOS_IMPERSONATE_STOP, new ImpersonateStopActionDTO());

        self::assertNull($this->sendLineOf(self::SESSION_TOKEN));
    }

    /**
     * Reports one step of the email window of a session, as the users library does.
     *
     * @param ProfileFlowSessionTestHolder $holder Holder under test
     * @param string $step Step reached
     * @param ?string $requestId Request id of the submit to answer, or null when nothing waits on it
     * @param string $sessionToken Session the window belongs to
     * @throws HilosException When the holder fails to write the step or to answer
     */
    private function step(
        ProfileFlowSessionTestHolder $holder,
        string $step,
        ?string $requestId = null,
        string $sessionToken = self::SESSION_TOKEN,
    ): void {
        $holder->onSignalAgent(
            new AgentSignalData(data: new ProfileFlowStepSignalData(
                $sessionToken,
                self::USER_ID,
                StepUpOperationKey::CHANGE_EMAIL,
                $step,
                self::CURRENT,
                null,
                TimeHelper::nowMs() + self::CODE_LIFETIME_MS,
                self::TAB_A,
                $requestId,
                $requestId === null ? null : HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_REQUEST,
            )),
            'test',
            HilosSignalConstants::HILOS_PROFILE_FLOW_STEP,
        );
    }

    /**
     * Connects one tab of a session, as the master does on a socket's handshake.
     *
     * @param ProfileFlowSessionTestHolder $holder Holder under test
     * @param string $acceptKey Tab connecting
     * @param string $sessionToken Session cookie the tab presents
     * @throws HilosException When the handshake fails
     */
    private function handshake(ProfileFlowSessionTestHolder $holder, string $acceptKey, string $sessionToken): void
    {
        $holder->onSignalHandshake(
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
     * @param list<SignalDTO> $signals Signals queued, in order
     * @return ?int Index of the first list of profile flows among them, or null when there is none
     */
    private function indexOfList(array $signals): ?int
    {
        foreach ($signals as $index => $signal) {
            if ($signal->signalName->getName() === HilosSignalConstants::HILOS_PROFILE_FLOWS) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<SignalDTO> $signals Signals queued, in order
     * @param string $sessionToken Session the list must be addressed to
     * @return ?ProfileFlowsSignalData The last list told to that session, or null when none was
     */
    private function lastList(array $signals, string $sessionToken): ?ProfileFlowsSignalData
    {
        $last = null;
        foreach ($signals as $signal) {
            if (
                $signal->data instanceof WebSocketSignalData
                && $signal->data->data instanceof ProfileFlowsSignalData
                && $signal->data->targetSessionTokenHash === ProtectedModeRuntime::hashSessionToken($sessionToken)
            ) {
                $last = $signal->data->data;
            }
        }

        return $last;
    }

    /**
     * Opens the password window's send line of the session, as the users library does when it orders a code.
     *
     * @param ProfileFlowSessionTestHolder $holder Holder under test
     * @throws HilosException When the holder fails to write the line
     */
    private function openSendLine(ProfileFlowSessionTestHolder $holder): void
    {
        $holder->onSignalAgent(
            new AgentSignalData(data: CodeSendStepSignalData::queued(
                self::TICKET,
                ProtectedModeRuntime::hashSessionToken(self::SESSION_TOKEN),
                StateHilosCodeSendAttempt::CHANNEL_EMAIL,
                StepUpOperationKey::CHANGE_PASSWORD,
            )),
            'test',
            HilosSignalConstants::HILOS_CODE_SEND_STEP,
        );
    }

    /**
     * @param string $sessionToken Session cookie token
     * @return ?string Ticket of the session's send line, or null when it has none
     * @throws HilosException When the runtime cannot be read
     */
    private function sendLineOf(string $sessionToken): ?string
    {
        return Hilos::$rt->hilosCodeSendAttempts[ProtectedModeRuntime::hashSessionToken($sessionToken)]?->ticket;
    }

    /**
     * @param string $sessionToken Session whose email window is asked about
     * @return ?string Step the window is on, or null when the session has no flow
     * @throws HilosException When the collection cannot be read
     */
    private function flowOf(string $sessionToken): ?string
    {
        return Hilos::$rt?->hilosProfileFlows[StateHilosProfileFlow::idFor(
            ProtectedModeRuntime::hashSessionToken($sessionToken),
            StepUpOperationKey::CHANGE_EMAIL,
        )]?->step;
    }

    /**
     * @return list<SignalDTO> Every signal queued since the last drain, in order
     */
    private function drain(): array
    {
        $signals = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $signals[] = $signal;
        }

        return $signals;
    }
}

/**
 * Runtime holding two tabs of one browser and one tab of another browser of the same person.
 */
final class ProfileFlowSessionTestRtContext extends RtContext
{
    public function configure(): void
    {
        $connections = ProfileFlowSessionTestConnections::init();
        $connections->add(ProfileFlowSessionTestConnection::create(
            ProfileFlowSessionIntegrationTest::TAB_A,
            ProfileFlowSessionIntegrationTest::USER_ID,
            ProfileFlowSessionIntegrationTest::SESSION_TOKEN,
        ));
        $connections->add(ProfileFlowSessionTestConnection::create(
            ProfileFlowSessionIntegrationTest::TAB_B,
            ProfileFlowSessionIntegrationTest::USER_ID,
            ProfileFlowSessionIntegrationTest::SESSION_TOKEN,
        ));
        $connections->add(ProfileFlowSessionTestConnection::create(
            ProfileFlowSessionIntegrationTest::OTHER_BROWSER_TAB,
            ProfileFlowSessionIntegrationTest::USER_ID,
            ProfileFlowSessionIntegrationTest::OTHER_SESSION_TOKEN,
        ));
        $this->_stateCollections[ProfileFlowSessionTestConnections::RT_COLLECTION] = $connections;
    }
}

/**
 * Facade of a fixture project that draws a sign-in surface.
 */
abstract class ProfileFlowSessionTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::AUTH];
}

/**
 * Sessions library of a fixture project, which needs nothing beyond being instantiable.
 */
final class ProfileFlowSessionTestHolder extends AbstractSessionsLibraryAgent
{
    public function onStop(): void
    {
    }
}

/**
 * Session-stage connection collection of the fixture project.
 */
final class ProfileFlowSessionTestConnections extends HilosSessionConnections
{
    /** @var string Runtime collection name this fixture mounts under */
    public const string RT_COLLECTION = 'profileFlowSessionTestConnections';

    public const string STATE_CLASS = ProfileFlowSessionTestConnection::class;
}

/**
 * Session-stage connection row of the fixture project, adding nothing of its own.
 */
final class ProfileFlowSessionTestConnection extends HilosSessionConnection
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
