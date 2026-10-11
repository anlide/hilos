<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Library\DTO\ThemeAdoptActionDTO;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Collection\HilosSessionConnections as StateSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection as StateSessionConnection;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\HilosSessionConnectionsActions;
use Hilos\Runtime\View\Actions\Item\HilosConnectionActions;
use Hilos\Runtime\View\Collection\HilosSessionConnections;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\HilosSessionConnection;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\DTO\UserThemePickWriteDoneSignalData;
use Hilos\Users\DTO\UserThemePickWriteSignalData;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Theme adoption crosses the real person owner, sessions library and framework handshake (HIL-1429).
 *
 * Two tokens of one person have three sockets; a stranger has a fourth. The harness carries
 * queued frames through the ordinary declarative router, with serialization at every agent hop.
 * Its minimal project host uses the framework's identity builder and stamped send, never a
 * hand-built handshake. This proves local delivery, not the peer transport reserved for HIL-1443.
 */
final class ThemeAccountSessionIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    private const int PERSON = 7;
    private const int STRANGER = 9;
    private const string TOKEN_A = '0123456789abcdef0123456789abcdef';
    private const string TOKEN_B = 'fedcba9876543210fedcba9876543210';
    private const string TOKEN_STRANGER = 'aaaabbbbccccddddaaaabbbbccccdddd';
    private const string TAB_A = 'theme-tab-a';
    private const string TAB_B = 'theme-tab-b';
    private const string TAB_C = 'theme-tab-c';
    private const string TAB_STRANGER = 'theme-tab-stranger';

    private ThemeAccountSessionTestUsersLibrary $users;
    private ThemeAccountSessionTestSessionsLibrary $sessions;
    private ThemeAccountSessionTestHost $host;
    private ?SignalRouter $previousRouter = null;
    private ?RtContext $previousRuntime = null;

    /**
     * @return iterable<string, array{string}> Each stored theme position
     */
    public static function chosenPositions(): iterable
    {
        foreach (['light', 'dark', 'system'] as $pick) {
            yield $pick => [$pick];
        }
    }

    /**
     * @param string $pick Stored account position
     * @throws HilosException When a fixture write or handshake fails
     */
    #[DataProvider('chosenPositions')]
    public function testNewAndResumedSessionsReceiveTheStoredAccountPick(string $pick): void
    {
        $this->asPerson(self::PERSON, static fn ($agent) => $agent->writeThemeForTest($pick));

        $this->handshake(self::TAB_A, self::TOKEN_A);
        $this->handshake(self::TAB_C, self::TOKEN_B);
        $frames = $this->carryFrames();

        $this->assertHandshakePicks($frames, $pick, [self::TAB_A, self::TAB_B, self::TAB_C]);
    }

    /** @throws HilosException When a handshake fails */
    public function testAnUnchosenAccountCarriesAnExplicitNullAndAGuestHasNoCurrentUser(): void
    {
        $this->handshake(self::TAB_A, self::TOKEN_A);
        $this->assertHandshakePicks($this->carryFrames(), null, [self::TAB_A, self::TAB_B]);

        Hilos::$db->sessions->findByToken(self::TOKEN_A)->actions->unbindUser();
        $this->handshake(self::TAB_A, self::TOKEN_A);
        $frames = $this->handshakeFrames($this->carryFrames());
        self::assertCount(2, $frames);
        foreach ($frames as $frame) {
            self::assertNull($frame->data->toArray()['entities']['currentUser']);
        }
    }

    /** @throws HilosException When an action or a delivery hop fails */
    public function testAdoptionRestatesBothTokensAndEveryTabWithoutTellingTheStranger(): void
    {
        $this->submitAdoption(self::TAB_A, 'dark', 'adopt-first');
        $frames = $this->carryFrames();

        $states = $this->framesNamed($frames, HilosSignalConstants::HILOS_SESSION_STATE);
        self::assertCount(2, $states);
        $keysByToken = [];
        foreach ($states as $signal) {
            self::assertInstanceOf(AgentSignalData::class, $signal->data);
            self::assertInstanceOf(SessionStateSignalData::class, $signal->data->data);
            self::assertSame(self::PERSON, $signal->data->data->userId);
            $keysByToken[$signal->data->data->sessionToken] = $signal->data->data->acceptKeys;
        }
        self::assertSame([
            self::TOKEN_A => [self::TAB_A, self::TAB_B],
            self::TOKEN_B => [self::TAB_C],
        ], $keysByToken);
        $this->assertHandshakePicks($frames, 'dark');
        $this->assertSilentSuccess($frames, 'adopt-first', self::TAB_A);
        self::assertSame('dark', Hilos::$db->users[self::PERSON]->themePick);
        self::assertNull(Hilos::$db->users[self::STRANGER]->themePick);
    }

    /** @throws HilosException When an action or a delivery hop fails */
    public function testTheFirstQueuedDeviceChoiceWinsAndBothRestatesCarryItsActualValue(): void
    {
        // Both devices submitted while the account was still null; the owner serializes them.
        $this->submitAdoption(self::TAB_C, 'light', 'device-first');
        $this->submitAdoption(self::TAB_A, 'dark', 'device-late');
        self::assertNull(Hilos::$db->users[self::PERSON]->themePick);

        $frames = $this->carryFrames();

        $asks = $this->framesNamed($frames, HilosSignalConstants::HILOS_USER_THEME_PICK_WRITE);
        self::assertCount(2, $asks);
        foreach ($asks as $signal) {
            self::assertInstanceOf(UserThemePickWriteSignalData::class, $signal->data->data);
            self::assertTrue($signal->data->data->adoptOnly);
            self::assertSame(self::PERSON, $signal->data->data->userId);
        }
        self::assertCount(2, $this->framesNamed($frames, HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE));
        $this->assertHandshakePicks($frames, 'light', [self::TAB_A, self::TAB_B, self::TAB_C], copies: 2);
        $this->assertSilentSuccess($frames, 'device-first', self::TAB_C);
        $this->assertSilentSuccess($frames, 'device-late', self::TAB_A);
        self::assertSame('light', Hilos::$db->users[self::PERSON]->themePick);
    }

    /**
     * @param string $pick Account position chosen before the browser asks
     * @throws HilosException When a fixture write or delivery hop fails
     */
    #[DataProvider('chosenPositions')]
    public function testAConditionalSkipStillRestatesTheAuthoritativeAccountPick(string $pick): void
    {
        $this->asPerson(self::PERSON, static fn ($agent) => $agent->writeThemeForTest($pick));
        $this->submitAdoption(self::TAB_C, $pick === 'dark' ? 'light' : 'dark', 'already-chosen');

        $frames = $this->carryFrames();

        self::assertCount(1, $this->framesNamed($frames, HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE));
        $this->assertHandshakePicks($frames, $pick);
        $this->assertSilentSuccess($frames, 'already-chosen', self::TAB_C);
        self::assertSame($pick, Hilos::$db->users[self::PERSON]->themePick);
    }

    /** @throws HilosException When a fixture write or a delivery hop fails */
    public function testAnExplicitOwnerWriteUsesTheSameSessionDelivery(): void
    {
        $this->submitAdoption(self::TAB_A, 'dark', 'adopt');
        $this->carryFrames();
        $this->users->sendToAgent(HilosSignalConstants::HILOS_USER_THEME_PICK_WRITE, new UserThemePickWriteSignalData(
            self::PERSON, 'system', HilosSignalConstants::HILOS_USER_THEME_PICK_ADOPT_DONE,
            self::TAB_A, 'explicit', HilosSignalConstants::HILOS_THEME_ADOPT, null,
        ));

        $frames = $this->carryFrames();

        $this->assertHandshakePicks($frames, 'system');
        self::assertSame('system', Hilos::$db->users[self::PERSON]->themePick);
    }

    /** @throws HilosException When an action or a delivery hop fails */
    public function testAClientSuppliedUserIdCannotRedirectAdoption(): void
    {
        $this->submitAdoption(self::TAB_A, 'dark', 'injected-person', [UserThemePickWriteSignalData::userId => self::STRANGER]);

        $frames = $this->carryFrames();

        $this->assertHandshakePicks($frames, 'dark');
        self::assertSame('dark', Hilos::$db->users[self::PERSON]->themePick);
        self::assertNull(Hilos::$db->users[self::STRANGER]->themePick);
    }

    /** @throws HilosException When an action or a delivery hop fails */
    public function testClosingTheInitiatorAfterAdmissionKeepsTheWriteOnTheVerifiedPerson(): void
    {
        $this->submitAdoption(self::TAB_A, 'dark', 'closed-tab');
        ExecutionContext::run(
            new ExecutionFrame(agentId: $this->host->getId()),
            static fn () => Hilos::$rt->themeConnections[self::TAB_A]->actions->unregister(),
        );

        $this->assertHandshakePicks($this->carryFrames(), 'dark', [self::TAB_B, self::TAB_C]);
        self::assertSame('dark', Hilos::$db->users[self::PERSON]->themePick);
        $this->handshake('theme-new-tab', self::TOKEN_A);
        $this->assertHandshakePicks($this->carryFrames(), 'dark', ['theme-new-tab', self::TAB_B]);
    }

    /** @return iterable<string, array{string}> Invalid identities on an otherwise live accept key */
    public static function refusedSessions(): iterable
    {
        foreach (['anonymous', 'signed-out-session', 'expired', 'deleted', 'impersonated', 'changed-session-person',
            'changed-connection-person', 'no-session-token', 'closed'] as $kind
        ) {
            yield $kind => [$kind];
        }
    }

    /**
     * @param string $kind Session or connection defect
     * @throws HilosException When the fixture mutation fails
     */
    #[DataProvider('refusedSessions')]
    public function testAdoptionRefusesAnAcceptKeyWithoutItsLiveOrdinaryOwnSession(string $kind): void
    {
        switch ($kind) {
            case 'anonymous':
                Hilos::$db->sessions->findByToken(self::TOKEN_A)->actions->unbindUser();
                ExecutionContext::run(
                    new ExecutionFrame(agentId: $this->host->getId()),
                    static fn () => Hilos::$rt->themeConnections[self::TAB_A]->actions->bindUser(null),
                );
                break;
            case 'signed-out-session':
                Hilos::$db->sessions->findByToken(self::TOKEN_A)->actions->unbindUser();
                break;
            case 'expired':
                Hilos::$db->sessions->findByToken(self::TOKEN_A)->actions->expire();
                break;
            case 'deleted':
                Hilos::$db->sessions->findByToken(self::TOKEN_A)->actions->delete();
                break;
            case 'impersonated':
                Hilos::$db->sessions->findByToken(self::TOKEN_A)->actions->setImpersonator(self::STRANGER);
                break;
            case 'changed-session-person':
                Hilos::$db->sessions->findByToken(self::TOKEN_A)->actions->bindUser(self::STRANGER);
                break;
            case 'changed-connection-person':
                ExecutionContext::run(
                    new ExecutionFrame(agentId: $this->host->getId()),
                    static fn () => Hilos::$rt->themeConnections[self::TAB_A]->actions->bindUser(self::STRANGER),
                );
                break;
            case 'no-session-token':
                ExecutionContext::run(new ExecutionFrame(agentId: $this->host->getId()), static function (): void {
                    Hilos::$rt->themeConnections[self::TAB_A]->actions->unregister();
                    Hilos::$rt->themeConnections->actions->register(self::TAB_A, self::PERSON);
                });
                break;
            case 'closed':
                ExecutionContext::run(
                    new ExecutionFrame(agentId: $this->host->getId()),
                    static fn () => Hilos::$rt->themeConnections[self::TAB_A]->actions->unregister(),
                );
                break;
        }
        $this->carryFrames();

        try {
            $this->submitAdoption(self::TAB_A, 'dark', 'refused');
            self::fail('Adoption requires the live ordinary session of this accept key');
        } catch (ValidationException $refusal) {
            self::assertStringContainsString('current signed-in session', $refusal->getMessage());
        }

        self::assertSame([], $this->carryFrames(), 'A refused request must neither ask the owner nor announce a session');
        self::assertNull(Hilos::$db->users[self::PERSON]->themePick);
        self::assertNull(Hilos::$db->users[self::STRANGER]->themePick);
    }

    /** @throws HilosException When the schema or fixture setup fails */
    protected function setUp(): void
    {
        parent::setUp();

        Database::sqlRun('INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, ?), (?, ?)', [
            self::PERSON, 'Theme person', self::STRANGER, 'Stranger',
        ]);
        foreach ([self::TOKEN_A => self::PERSON, self::TOKEN_B => self::PERSON, self::TOKEN_STRANGER => self::STRANGER] as $token => $userId) {
            Hilos::$db->sessions->actions->createAnonymous($token)->actions->bindUser($userId);
        }
        $this->previousRouter = Hilos::$sr;
        $this->previousRuntime = Hilos::$rt;
        Hilos::$sr = new ThemeAccountSessionTestRouter();
        Hilos::$rt = new ThemeAccountSessionTestRuntime();
        Hilos::$rt->mountFeatureRuntime([]);
        Hilos::$rt->configure();
        Hilos::$rt->bindStateCollectionNames();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        $this->users = new ThemeAccountSessionTestUsersLibrary();
        $this->sessions = new ThemeAccountSessionTestSessionsLibrary();
        $this->host = new ThemeAccountSessionTestHost();
        foreach ([$this->users, $this->sessions, $this->host] as $agent) {
            OwnershipDeclaration::claimAll($agent);
        }
        ExecutionContext::run(new ExecutionFrame(agentId: $this->host->getId()), static function (): void {
            foreach ([self::TAB_A => self::TOKEN_A, self::TAB_B => self::TOKEN_A, self::TAB_C => self::TOKEN_B,
                self::TAB_STRANGER => self::TOKEN_STRANGER] as $acceptKey => $token
            ) {
                $session = Hilos::$db->sessions->findByToken($token);
                Hilos::$rt->themeConnections->actions->register($acceptKey, $session->userId, $token, (int)$session->id);
            }
        });
        $this->carryFrames();
    }

    /** @throws HilosException When the parent schema teardown fails */
    protected function tearDown(): void
    {
        $this->releasePersonAgents();
        foreach ([$this->users, $this->sessions, $this->host] as $agent) {
            TruthSourceRegistry::unregisterAgent($agent->getId());
            RtTruthSourceRegistry::unregisterAgent($agent->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agent->getId()));
        }
        SourceChangeBus::reset();
        Hilos::$sr = $this->previousRouter;
        Hilos::$rt = $this->previousRuntime;

        parent::tearDown();
    }

    /**
     * @param string $userId Person addressed by the owner
     * @return ThemeAccountSessionTestPerson Real person owner with a fixture setup entry
     */
    protected function newPersonAgent(string $userId): ThemeAccountSessionTestPerson
    {
        return new ThemeAccountSessionTestPerson($userId);
    }

    /**
     * @param string $acceptKey Initiating tab
     * @param string $pick Proposed browser choice
     * @param string $requestId Action correlation
     * @param array<string, mixed> $extra Extra untrusted fields
     */
    private function submitAdoption(string $acceptKey, string $pick, string $requestId, array $extra = []): void
    {
        $action = new WebSocketActionSignalDTO($acceptKey, HilosSignalConstants::HILOS_THEME_ADOPT,
            [ThemeAdoptActionDTO::themePick => $pick, ...$extra], $requestId);
        $signal = new SignalDTO(new SignalSource(SignalSource::WEBSOCKET), new SignalType(SignalTypeConstants::ACTION),
            new SignalName($action->action), $action);
        $destination = $this->agentDestination($signal);
        self::assertSame($this->users::AGENT_TYPE, $destination->agentType);
        $dto = Hilos::$sr->createAgentActionPayloadDTO($action->action, $action->data, $destination->agentType);
        self::assertInstanceOf(ThemeAdoptActionDTO::class, $dto);
        self::assertSame([ThemeAdoptActionDTO::themePick => $pick], $dto->toArray());
        ExecutionContext::run(new ExecutionFrame(agentId: $this->users->getId(), acceptKey: $acceptKey), function () use ($action, $dto): void {
            $this->users->beginActionDispatch($action->requestId);
            try {
                self::assertNull($this->users->onAgentAction($action->acceptKey, $action->action, $dto));
                self::assertTrue($this->users->actionReplyDeferred());
            } finally {
                $this->users->endActionDispatch();
            }
        });
    }

    /**
     * @param string $acceptKey Tab arriving or reconnecting
     * @param string $token Browser cookie token
     */
    private function handshake(string $acceptKey, string $token): void
    {
        ExecutionContext::run(new ExecutionFrame(agentId: $this->sessions->getId()), fn () => $this->sessions->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(headers: [], acceptKey: $acceptKey, cookies: [], clientIp: null, sessionToken: $token),
            'test', 'handshake',
        ));
    }

    /** @return list<SignalDTO> All frames observed, including the real intermediate hops */
    private function carryFrames(): array
    {
        $frames = [];
        while (($queued = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $signal = SignalDTO::fromArray($queued->toArray());
            $frames[] = $signal;
            if ($signal->signalType->getType() !== SignalTypeConstants::AGENT_SIGNAL) {
                continue;
            }
            $destination = $this->agentDestination($signal);
            self::assertInstanceOf(AgentSignalData::class, $signal->data);
            if ($destination->agentType === ThemeAccountSessionTestPerson::AGENT_TYPE) {
                self::assertInstanceOf(UserThemePickWriteSignalData::class, $signal->data->data);
                self::assertSame((string)$signal->data->data->userId, $destination->agentIndex);
                $this->deliverToPerson($signal);
                continue;
            }
            $agent = match ($destination->agentType) {
                ThemeAccountSessionTestUsersLibrary::AGENT_TYPE => $this->users,
                ThemeAccountSessionTestSessionsLibrary::AGENT_TYPE => $this->sessions,
                ThemeAccountSessionTestHost::AGENT_TYPE => $this->host,
                default => self::fail('Unexpected destination: ' . $destination->agentType),
            };
            ExecutionContext::run(new ExecutionFrame(agentId: $agent->getId()), static fn () => $agent->onSignalAgent(
                $signal->data, SignalSource::describe($signal->signalSource), $signal->signalName->getName(),
            ));
        }

        return $frames;
    }

    /**
     * @param SignalDTO $signal Real queued frame
     * @return AgentDestination Single declaratively resolved local agent
     */
    private function agentDestination(SignalDTO $signal): AgentDestination
    {
        $destinations = Hilos::$sr->getDestinations($signal);
        self::assertCount(1, $destinations);
        self::assertInstanceOf(AgentDestination::class, $destinations[0]);

        return $destinations[0];
    }

    /**
     * @param list<SignalDTO> $frames Observed queue
     * @param string $name Frame name
     * @return list<SignalDTO> Matching frames, in delivery order
     */
    private function framesNamed(array $frames, string $name): array
    {
        return array_values(array_filter($frames, static fn (SignalDTO $signal): bool => $signal->signalName->getName() === $name));
    }

    /**
     * @param list<SignalDTO> $frames Observed queue
     * @return list<WebSocketSignalData> Actual stamped handshake replies
     */
    private function handshakeFrames(array $frames): array
    {
        $handshakes = [];
        foreach ($this->framesNamed($frames, ThemeAccountSessionTestHost::HANDSHAKE_RESPONSE) as $signal) {
            self::assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertInstanceOf(HandshakeResponseSignalData::class, $signal->data->data);
            $handshakes[] = $signal->data;
        }

        return $handshakes;
    }

    /**
     * @param list<SignalDTO> $frames Observed queue
     * @param ?string $pick Authoritative position, including not chosen
     * @param list<string> $keys Expected recipient sockets
     * @param int $copies Number of restates
     */
    private function assertHandshakePicks(array $frames, ?string $pick,
        array $keys = [self::TAB_A, self::TAB_B, self::TAB_C], int $copies = 1,
    ): void {
        $handshakes = $this->handshakeFrames($frames);
        self::assertCount(count($keys) * $copies, $handshakes);
        $delivered = [];
        foreach ($handshakes as $frame) {
            $payload = $frame->data->toArray();
            self::assertSame(self::PERSON, $payload['entities']['currentUser']['id']);
            self::assertArrayHasKey(HandshakeResponseSignalData::themePick, $payload['entities']['currentUser']);
            self::assertSame($pick, $payload['entities']['currentUser'][HandshakeResponseSignalData::themePick]);
            self::assertArrayHasKey(HandshakeResponseSignalData::themeSettings, $payload['data']);
            $delivered[] = $frame->targetAcceptKey;
        }
        sort($delivered);
        $expected = array_merge(...array_fill(0, $copies, $keys));
        sort($expected);
        self::assertSame($expected, $delivered, 'Only the named person\'s local sockets receive their account pick');
    }

    /**
     * @param list<SignalDTO> $frames Observed queue
     * @param string $requestId Request that must complete
     * @param string $acceptKey Initiating socket
     */
    private function assertSilentSuccess(array $frames, string $requestId, string $acceptKey): void
    {
        $answers = [];
        foreach ($frames as $signal) {
            if ($signal->data instanceof WebSocketSignalData && $signal->data->data instanceof PageActionSuccessSignalData
                && $signal->data->data->requestId === $requestId
            ) {
                $answers[] = $signal->data;
            }
        }
        self::assertCount(1, $answers);
        self::assertSame($acceptKey, $answers[0]->targetAcceptKey);
        self::assertSame(HilosSignalConstants::HILOS_THEME_ADOPT, $answers[0]->data->action);
        self::assertNull($answers[0]->data->message);
        self::assertNull($answers[0]->data->reply);
        self::assertSame([], $this->framesNamed($frames, HilosSignalConstants::HILOS_SESSION_TOASTS));
        foreach ($this->framesNamed($frames, HilosSignalConstants::HILOS_USER_THEME_PICK_ADOPT_DONE) as $signal) {
            self::assertInstanceOf(UserThemePickWriteDoneSignalData::class, $signal->data->data);
            self::assertNull($signal->data->data->error);
        }
    }
}

/** The users library's production adoption behavior, without overrides. */
final class ThemeAccountSessionTestUsersLibrary extends AbstractUsersLibraryAgent
{
    public const string AGENT_TYPE = 'theme_test_users';
}

/** The sessions library's production restate and handshake behavior, without overrides. */
final class ThemeAccountSessionTestSessionsLibrary extends AbstractSessionsLibraryAgent
{
    public const string AGENT_TYPE = 'theme_test_sessions';
}

/** The real owner; this seam seeds a chosen account through its existing guarded write. */
final class ThemeAccountSessionTestPerson extends AbstractUserAgent
{
    /**
     * @param string $pick Fixture account position
     * @throws HilosException When the guarded theme write fails
     * @throws LogicException When a collection's class constants are not configured
     */
    public function writeThemeForTest(string $pick): void
    {
        $this->writeThemePick($pick);
    }
}

/** Minimal project session receiver, using the framework's identity builder and stamped send. */
final class ThemeAccountSessionTestHost extends AbstractAgent
{
    public const string AGENT_TYPE = 'theme_test_host';
    public const string HANDSHAKE_RESPONSE = 'handshake_response';
    public const array AGENT_SIGNALS = [HilosSignalConstants::HILOS_SESSION_STATE => SessionStateSignalData::class];
    public const array OWNS_RT = [ThemeAccountSessionTestRuntime::themeConnections => TruthSourceOperation::ALL];

    /** No lifecycle cleanup is needed in this in-process fixture. */
    public function onStop(): void
    {
    }

    /**
     * @param AgentSignalData $data Serialized session-state wrapper
     * @param string $sender Sender identity, unused by the fixture
     * @param string $name Session-state signal name
     * @throws HilosException When identity construction or runtime settlement fails
     * @throws LogicException When a collection's class constants are not configured
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case HilosSignalConstants::HILOS_SESSION_STATE:
                if (!$data->data instanceof SessionStateSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, SessionStateSignalData::class, $data->data);
                }
                $state = $data->data;
                $identity = $this->handshakeIdentity($state);
                foreach (Hilos::$rt->themeConnections->acceptKeysForSessionFrame($state->acceptKeys, $state->sessionToken) as $acceptKey) {
                    if (!isset(Hilos::$rt->themeConnections[$acceptKey])) {
                        Hilos::$rt->themeConnections->actions->register($acceptKey, $state->userId, $state->sessionToken, $state->sessionId);
                    } else {
                        Hilos::$rt->themeConnections[$acceptKey]->actions->bindUser($state->userId);
                    }
                    $this->sendHandshakeResponse(self::HANDSHAKE_RESPONSE, $acceptKey, $identity, $state);
                }
                return;
            default:
                throw new AgentUnknownSignalException($name);
        }
    }
}

/** Fixture registry retains the inherited production action and indexed agent declarations. */
abstract class ThemeAccountSessionTestHilos extends Hilos
{
    public const array AGENTS = [
        ThemeAccountSessionTestUsersLibrary::AGENT_TYPE => [AgentRegistryKey::WORKER => ThemeAccountSessionTestUsersLibrary::class],
        ThemeAccountSessionTestSessionsLibrary::AGENT_TYPE => [AgentRegistryKey::WORKER => ThemeAccountSessionTestSessionsLibrary::class],
        ThemeAccountSessionTestPerson::AGENT_TYPE => [
            AgentRegistryKey::WORKER => ThemeAccountSessionTestPerson::class,
            AgentRegistryKey::INDEXED => true,
        ],
        ThemeAccountSessionTestHost::AGENT_TYPE => [AgentRegistryKey::WORKER => ThemeAccountSessionTestHost::class],
    ];
}

/** Uses ordinary topology resolution against the fixture project registry. */
final class ThemeAccountSessionTestRouter extends SignalRouter
{
    /** @return class-string<Hilos> Fixture topology owner */
    protected function hilosClass(): string
    {
        return ThemeAccountSessionTestHilos::class;
    }
}

/** @property-read ThemeAccountSessionTestConnections $themeConnections Fixture sockets */
final class ThemeAccountSessionTestRuntime extends RtContext
{
    public const string themeConnections = 'themeConnections';

    /**
     * Mounts a fully represented session-stage collection; every mutation uses actions.
     *
     * @throws HilosException When the fixture collection cannot be represented
     */
    public function configure(): void
    {
        $this->_stateCollections[self::themeConnections] = ThemeAccountSessionTestConnectionStates::init();
        $this->setRepresent(self::themeConnections, ThemeAccountSessionTestConnections::class,
            ThemeAccountSessionTestConnectionsActions::class, ThemeAccountSessionTestConnectionActions::class);
    }
}

/** Session-stage rows, with no extra fields. */
final class ThemeAccountSessionTestConnectionStates extends StateSessionConnections
{
    public const string STATE_CLASS = ThemeAccountSessionTestConnectionState::class;
}

/** Session-stage row with an empty project half. */
final class ThemeAccountSessionTestConnectionState extends StateSessionConnection
{
    /** The fixture adds no project defaults. */
    protected function initOwn(): void
    {
    }

    /** @param array<string, mixed> $row Incoming row, with no project fields */
    protected function hydrateOwn(array $row): void
    {
    }

    /** @return array<string, mixed> Empty project field set */
    protected function ownToArray(): array
    {
        return [];
    }

    /** @param array<string, mixed> $diff Incoming diff, with no project fields */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

/**
 * Fully represented fixture sockets, inheriting the production session lookups.
 *
 * @extends HilosSessionConnections<ThemeAccountSessionTestConnection, ThemeAccountSessionTestConnectionsActions>
 */
final class ThemeAccountSessionTestConnections extends HilosSessionConnections
{
    /**
     * @param RtState $state Session-stage state row
     * @return ThemeAccountSessionTestConnection Read-only fixture connection
     */
    protected function createRtItem(RtState $state): ThemeAccountSessionTestConnection
    {
        return new ThemeAccountSessionTestConnection($state);
    }
}

/** @extends HilosSessionConnection<ThemeAccountSessionTestConnectionState> */
final class ThemeAccountSessionTestConnection extends HilosSessionConnection
{
}

/**
 * Session registration uses the inherited guarded collection actions.
 *
 * @property-read ThemeAccountSessionTestConnectionStates $stateCollection Backing fixture sockets
 */
final class ThemeAccountSessionTestConnectionsActions extends HilosSessionConnectionsActions
{
}

/** Connection changes and removal use the inherited guarded item actions. */
final class ThemeAccountSessionTestConnectionActions extends HilosConnectionActions
{
}
