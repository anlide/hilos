<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
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
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\DTO\UserTimeZoneWriteDoneSignalData;
use Hilos\Users\DTO\UserTimeZoneWriteSignalData;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Personal time zones cross the real owner, sessions library and stamped handshake (HIL-1580).
 *
 * Internal handover asks use the production declarative router and serialize at every agent hop.
 * Two tokens of one person have three sockets beside a stranger. The fixture host builds identity
 * from the database and uses the real handshake stamps; peer delivery belongs to HIL-1597.
 */
final class TimeZoneAccountSessionIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    private const int PERSON = 7;
    private const int STRANGER = 9;
    private const string TOKEN_A = '0123456789abcdef0123456789abcdef';
    private const string TOKEN_B = 'fedcba9876543210fedcba9876543210';
    private const string TOKEN_STRANGER = 'aaaabbbbccccddddaaaabbbbccccdddd';
    private const string TAB_A = 'time-zone-tab-a';
    private const string TAB_B = 'time-zone-tab-b';
    private const string TAB_C = 'time-zone-tab-c';
    private const string TAB_STRANGER = 'time-zone-tab-stranger';
    private const string HANDOVER_ACTION = 'fixture_time_zone_handover';

    private TimeZoneAccountSessionTestUsersLibrary $users;
    private TimeZoneAccountSessionTestSessionsLibrary $sessions;
    private TimeZoneAccountSessionTestHost $host;
    private ?SignalRouter $previousRouter = null;
    private ?RtContext $previousRuntime = null;

    /** @return iterable<string, array{?string}> Stored personal choices */
    public static function storedTimeZones(): iterable
    {
        yield 'chosen' => ['Europe/Warsaw'];
        yield 'historical alias' => ['Europe/Kiev'];
        yield 'automatic' => [null];
    }

    /** @param ?string $timeZone Persisted choice or automatic selection */
    #[DataProvider('storedTimeZones')]
    public function testInitialNewAndResumedConnectionsReadTheStoredChoice(?string $timeZone): void
    {
        $this->asPerson(self::PERSON, static fn () => Hilos::$db->users[self::PERSON]->actions->setTimeZone($timeZone));

        $this->handshake(self::TAB_A, self::TOKEN_A);
        $this->handshake(self::TAB_C, self::TOKEN_B);
        $this->assertHandshakeTimeZones($this->carryFrames(), $timeZone);

        ExecutionContext::run(
            new ExecutionFrame(agentId: $this->host->getId()),
            static fn () => Hilos::$rt->timeZoneConnections[self::TAB_A]->actions->unregister(),
        );
        $this->carryFrames();
        $this->handshake(self::TAB_A, self::TOKEN_A);
        $this->assertHandshakeTimeZones($this->carryFrames(), $timeZone, [self::TAB_A, self::TAB_B]);

        $this->handshake('time-zone-new-tab', self::TOKEN_B);
        $this->assertHandshakeTimeZones($this->carryFrames(), $timeZone, [self::TAB_C, 'time-zone-new-tab']);
    }

    /** @return iterable<string, array{?string, ?string}> Choices, resets and repeats */
    public static function timeZoneWrites(): iterable
    {
        yield 'first choice' => [null, 'Europe/Warsaw'];
        yield 'replacement' => ['Europe/Warsaw', 'Asia/Tokyo'];
        yield 'historical alias' => ['Europe/Warsaw', 'Europe/Kiev'];
        yield 'reset' => ['Europe/Warsaw', null];
        yield 'repeat choice' => ['Europe/Warsaw', 'Europe/Warsaw'];
        yield 'repeat automatic' => [null, null];
    }

    /**
     * @param ?string $previous Previous personal choice
     * @param ?string $timeZone New personal choice or reset
     */
    #[DataProvider('timeZoneWrites')]
    public function testSuccessfulWritesRestateBothSessionsAndAllTabs(?string $previous, ?string $timeZone): void
    {
        $this->asPerson(self::PERSON, static fn () => Hilos::$db->users[self::PERSON]->actions->setTimeZone($previous));
        $this->asPerson(self::STRANGER, static fn () => Hilos::$db->users[self::STRANGER]->actions->setTimeZone('Pacific/Auckland'));
        $this->carryFrames();
        $this->submitWrite($timeZone);
        $this->assertStoredTimeZone($previous);

        $frames = $this->carryFrames();

        $asks = $this->framesNamed($frames, HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE);
        self::assertCount(1, $asks);
        self::assertInstanceOf(UserTimeZoneWriteSignalData::class, $asks[0]->data->data);
        self::assertSame(self::PERSON, $asks[0]->data->data->userId);
        self::assertSame($timeZone, $asks[0]->data->data->timeZone);
        self::assertCount(1, $this->framesNamed($frames, HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE));
        $states = $this->framesNamed($frames, HilosSignalConstants::HILOS_SESSION_STATE);
        self::assertCount(2, $states);
        $keysByToken = [];
        foreach ($states as $signal) {
            self::assertInstanceOf(SessionStateSignalData::class, $signal->data->data);
            self::assertSame(self::PERSON, $signal->data->data->userId);
            self::assertArrayNotHasKey('timeZone', $signal->data->data->toArray());
            $keysByToken[$signal->data->data->sessionToken] = $signal->data->data->acceptKeys;
        }
        self::assertSame([
            self::TOKEN_A => [self::TAB_A, self::TAB_B],
            self::TOKEN_B => [self::TAB_C],
        ], $keysByToken);
        $this->assertHandshakeTimeZones($frames, $timeZone);
        $this->assertWriteDone($frames, $timeZone);
        $this->assertStoredTimeZone($timeZone);
        Database::sql('SELECT `time_zone` FROM `hilos_user` WHERE `id` = ?', [self::STRANGER]);
        self::assertSame('Pacific/Auckland', Database::row()['time_zone']);
        foreach ($frames as $signal) {
            if ($signal->data instanceof WebSocketSignalData) {
                self::assertNotSame(self::TAB_STRANGER, $signal->data->targetAcceptKey);
            }
        }

        $this->handshake('time-zone-after-write', self::TOKEN_B);
        $this->assertHandshakeTimeZones($this->carryFrames(), $timeZone, [self::TAB_C, 'time-zone-after-write']);
    }

    public function testQueuedWritesLeaveTheLastProcessedChoiceInEveryRestatement(): void
    {
        $this->submitWrite('Europe/Warsaw');
        $this->submitWrite('Asia/Tokyo');
        $this->assertStoredTimeZone(null);

        $frames = $this->carryFrames();

        self::assertCount(2, $this->framesNamed($frames, HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE));
        $this->assertHandshakeTimeZones($frames, 'Asia/Tokyo', copies: 2);
        $this->assertWriteDone($frames, 'Europe/Warsaw');
        $this->assertWriteDone($frames, 'Asia/Tokyo');
        $this->assertStoredTimeZone('Asia/Tokyo');
    }

    public function testInvalidChoiceIsRefusedByTheOwnerWithoutRestatement(): void
    {
        $this->asPerson(self::PERSON, static fn () => Hilos::$db->users[self::PERSON]->actions->setTimeZone('Europe/Warsaw'));
        $this->submitWrite(' Europe/Warsaw');

        $frames = $this->carryFrames();

        $answers = $this->framesNamed($frames, HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE_DONE);
        self::assertCount(1, $answers);
        self::assertInstanceOf(UserTimeZoneWriteDoneSignalData::class, $answers[0]->data->data);
        self::assertSame('Choose a valid IANA time zone or Automatic.', $answers[0]->data->data->error);
        $failures = $this->framesNamed($frames, SignalConstants::ACTION_ERROR);
        self::assertCount(1, $failures);
        self::assertSame(self::TAB_A, $failures[0]->data->targetAcceptKey);
        self::assertInstanceOf(PageActionErrorSignalData::class, $failures[0]->data->data);
        self::assertSame(self::HANDOVER_ACTION, $failures[0]->data->data->action);
        self::assertSame('Choose a valid IANA time zone or Automatic.', $failures[0]->data->data->reason);
        self::assertSame([], $this->framesNamed($frames, HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE));
        self::assertSame([], $this->handshakeFrames($frames));
        $this->assertStoredTimeZone('Europe/Warsaw');
    }

    public function testGuestHasNoCurrentUserOrPersonalTimeZone(): void
    {
        Hilos::$db->sessions->findByToken(self::TOKEN_A)->actions->unbindUser();
        $this->handshake(self::TAB_A, self::TOKEN_A);

        $frames = $this->handshakeFrames($this->carryFrames());

        self::assertCount(2, $frames);
        $keys = [];
        foreach ($frames as $frame) {
            $payload = $frame->data->toArray();
            self::assertNull($payload['entities']['currentUser']);
            self::assertArrayNotHasKey('timeZone', $payload['data']);
            $keys[] = $frame->targetAcceptKey;
        }
        sort($keys);
        self::assertSame([self::TAB_A, self::TAB_B], $keys);
    }

    /** @throws HilosException When the schema or fixture setup fails */
    protected function setUp(): void
    {
        parent::setUp();

        Database::sqlRun('INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, ?), (?, ?)', [
            self::PERSON, 'Time zone person', self::STRANGER, 'Stranger',
        ]);
        foreach ([self::TOKEN_A => self::PERSON, self::TOKEN_B => self::PERSON, self::TOKEN_STRANGER => self::STRANGER] as $token => $userId) {
            Hilos::$db->sessions->actions->createAnonymous($token)->actions->bindUser($userId);
        }
        $this->previousRouter = Hilos::$sr;
        $this->previousRuntime = Hilos::$rt;
        Hilos::$sr = new TimeZoneAccountSessionTestRouter();
        Hilos::$rt = new TimeZoneAccountSessionTestRuntime();
        Hilos::$rt->mountFeatureRuntime([]);
        Hilos::$rt->configure();
        Hilos::$rt->bindStateCollectionNames();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        $this->users = new TimeZoneAccountSessionTestUsersLibrary();
        $this->sessions = new TimeZoneAccountSessionTestSessionsLibrary();
        $this->host = new TimeZoneAccountSessionTestHost();
        foreach ([$this->users, $this->sessions, $this->host] as $agent) {
            OwnershipDeclaration::claimAll($agent);
        }
        ExecutionContext::run(new ExecutionFrame(agentId: $this->host->getId()), static function (): void {
            foreach ([self::TAB_A => self::TOKEN_A, self::TAB_B => self::TOKEN_A, self::TAB_C => self::TOKEN_B,
                self::TAB_STRANGER => self::TOKEN_STRANGER] as $acceptKey => $token
            ) {
                $session = Hilos::$db->sessions->findByToken($token);
                Hilos::$rt->timeZoneConnections->actions->register($acceptKey, $session->userId, $token, (int)$session->id);
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
            if ($destination->agentType === IntegrationUserAgent::AGENT_TYPE) {
                self::assertInstanceOf(UserTimeZoneWriteSignalData::class, $signal->data->data);
                self::assertSame((string)$signal->data->data->userId, $destination->agentIndex);
                $this->deliverToPerson($signal);
                continue;
            }
            $agent = match ($destination->agentType) {
                TimeZoneAccountSessionTestUsersLibrary::AGENT_TYPE => $this->users,
                TimeZoneAccountSessionTestSessionsLibrary::AGENT_TYPE => $this->sessions,
                TimeZoneAccountSessionTestHost::AGENT_TYPE => $this->host,
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
        foreach ($this->framesNamed($frames, TimeZoneAccountSessionTestHost::HANDSHAKE_RESPONSE) as $signal) {
            self::assertSame(SignalTypeConstants::WS_USER, $signal->signalType->getType());
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertInstanceOf(HandshakeResponseSignalData::class, $signal->data->data);
            $handshakes[] = $signal->data;
        }

        return $handshakes;
    }

    /** @param ?string $timeZone Internal handover choice, without a browser action */
    private function submitWrite(?string $timeZone): void
    {
        ExecutionContext::run(new ExecutionFrame(agentId: $this->users->getId()), fn () => $this->users->sendToAgent(
            HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE,
            new UserTimeZoneWriteSignalData(
                userId: self::PERSON,
                timeZone: $timeZone,
                replySignal: HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE_DONE,
                acceptKey: self::TAB_A,
                requestId: null,
                action: self::HANDOVER_ACTION,
                successMessage: null,
            ),
        ));
    }

    /**
     * @param list<SignalDTO> $frames Observed queue
     * @param ?string $timeZone Personal choice expected at every addressed tab
     * @param list<string> $keys Expected recipient sockets
     * @param int $copies Number of restatements
     */
    private function assertHandshakeTimeZones(array $frames, ?string $timeZone,
        array $keys = [self::TAB_A, self::TAB_B, self::TAB_C], int $copies = 1,
    ): void {
        $handshakes = $this->handshakeFrames($frames);
        self::assertCount(count($keys) * $copies, $handshakes);
        $delivered = [];
        foreach ($handshakes as $frame) {
            $payload = $frame->data->toArray();
            self::assertSame(self::PERSON, $payload['entities']['currentUser']['id']);
            self::assertArrayHasKey('timeZone', $payload['entities']['currentUser']);
            self::assertSame($timeZone, $payload['entities']['currentUser']['timeZone']);
            self::assertSame($timeZone, $frame->data->selfTimeZone);
            self::assertArrayNotHasKey('timeZone', $payload['data']);
            self::assertNull($payload['entities']['impersonatedBy']);
            self::assertIsInt($payload['data']['serverTimeMs']);
            self::assertNotNull($payload['data']['codeDelivery']);
            self::assertNotNull($payload['data']['themeSettings']);
            $delivered[] = $frame->targetAcceptKey;
        }
        sort($delivered);
        $expected = array_merge(...array_fill(0, $copies, $keys));
        sort($expected);
        self::assertSame($expected, $delivered);
    }

    /**
     * @param list<SignalDTO> $frames Observed queue
     * @param ?string $timeZone Handover choice that must complete successfully
     */
    private function assertWriteDone(array $frames, ?string $timeZone): void
    {
        $answers = $this->framesNamed($frames, HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE_DONE);
        $matching = [];
        foreach ($answers as $signal) {
            self::assertInstanceOf(UserTimeZoneWriteDoneSignalData::class, $signal->data->data);
            if ($signal->data->data->ask->timeZone === $timeZone) {
                $matching[] = $signal->data->data;
            }
        }
        self::assertCount(1, $matching);
        self::assertNull($matching[0]->error);
        self::assertSame(self::HANDOVER_ACTION, $matching[0]->ask->action);
        self::assertNull($matching[0]->ask->requestId);
        self::assertSame([], $this->framesNamed($frames, SignalConstants::ACTION_ERROR));
    }

    /** @param ?string $timeZone Expected independent database value */
    private function assertStoredTimeZone(?string $timeZone): void
    {
        Database::sql('SELECT `time_zone` FROM `hilos_user` WHERE `id` = ?', [self::PERSON]);
        self::assertSame($timeZone, Database::row()['time_zone']);
    }
}

/** Production users-library completion handler. */
final class TimeZoneAccountSessionTestUsersLibrary extends AbstractUsersLibraryAgent
{
    public const string AGENT_TYPE = 'time_zone_test_users';
}

/** Production sessions-library handshake and restatement handlers. */
final class TimeZoneAccountSessionTestSessionsLibrary extends AbstractSessionsLibraryAgent
{
    public const string AGENT_TYPE = 'time_zone_test_sessions';
}

/** Minimal project session receiver, using the framework's identity builder and stamped send. */
final class TimeZoneAccountSessionTestHost extends AbstractAgent
{
    public const string AGENT_TYPE = 'time_zone_test_host';
    public const string HANDSHAKE_RESPONSE = 'handshake_response';
    public const array AGENT_SIGNALS = [HilosSignalConstants::HILOS_SESSION_STATE => SessionStateSignalData::class];
    public const array OWNS_RT = [TimeZoneAccountSessionTestRuntime::timeZoneConnections => TruthSourceOperation::ALL];

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
                foreach (Hilos::$rt->timeZoneConnections->acceptKeysForSessionFrame($state->acceptKeys, $state->sessionToken) as $acceptKey) {
                    if (!isset(Hilos::$rt->timeZoneConnections[$acceptKey])) {
                        Hilos::$rt->timeZoneConnections->actions->register($acceptKey, $state->userId, $state->sessionToken, $state->sessionId);
                    } else {
                        Hilos::$rt->timeZoneConnections[$acceptKey]->actions->bindUser($state->userId);
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
abstract class TimeZoneAccountSessionTestHilos extends Hilos
{
    public const array AGENTS = [
        TimeZoneAccountSessionTestUsersLibrary::AGENT_TYPE => [AgentRegistryKey::WORKER => TimeZoneAccountSessionTestUsersLibrary::class],
        TimeZoneAccountSessionTestSessionsLibrary::AGENT_TYPE => [AgentRegistryKey::WORKER => TimeZoneAccountSessionTestSessionsLibrary::class],
        IntegrationUserAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => IntegrationUserAgent::class,
            AgentRegistryKey::INDEXED => true,
        ],
        TimeZoneAccountSessionTestHost::AGENT_TYPE => [AgentRegistryKey::WORKER => TimeZoneAccountSessionTestHost::class],
    ];
}

/** Uses ordinary topology resolution against the fixture project registry. */
final class TimeZoneAccountSessionTestRouter extends SignalRouter
{
    /** @return class-string<Hilos> Fixture topology owner */
    protected function hilosClass(): string
    {
        return TimeZoneAccountSessionTestHilos::class;
    }
}

/** @property-read TimeZoneAccountSessionTestConnections $timeZoneConnections Fixture sockets */
final class TimeZoneAccountSessionTestRuntime extends RtContext
{
    public const string timeZoneConnections = 'timeZoneConnections';

    /**
     * Mounts a fully represented session-stage collection; every mutation uses actions.
     *
     * @throws HilosException When the fixture collection cannot be represented
     */
    public function configure(): void
    {
        $this->_stateCollections[self::timeZoneConnections] = TimeZoneAccountSessionTestConnectionStates::init();
        $this->setRepresent(self::timeZoneConnections, TimeZoneAccountSessionTestConnections::class,
            TimeZoneAccountSessionTestConnectionsActions::class, TimeZoneAccountSessionTestConnectionActions::class);
    }
}

/** Session-stage rows, with no extra fields. */
final class TimeZoneAccountSessionTestConnectionStates extends StateSessionConnections
{
    public const string STATE_CLASS = TimeZoneAccountSessionTestConnectionState::class;
}

/** Session-stage row with an empty project half. */
final class TimeZoneAccountSessionTestConnectionState extends StateSessionConnection
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
 * @extends HilosSessionConnections<TimeZoneAccountSessionTestConnection, TimeZoneAccountSessionTestConnectionsActions>
 */
final class TimeZoneAccountSessionTestConnections extends HilosSessionConnections
{
    /**
     * @param RtState $state Session-stage state row
     * @return TimeZoneAccountSessionTestConnection Read-only fixture connection
     */
    protected function createRtItem(RtState $state): TimeZoneAccountSessionTestConnection
    {
        return new TimeZoneAccountSessionTestConnection($state);
    }
}

/** @extends HilosSessionConnection<TimeZoneAccountSessionTestConnectionState> */
final class TimeZoneAccountSessionTestConnection extends HilosSessionConnection
{
}

/**
 * Session registration uses the inherited guarded collection actions.
 *
 * @property-read TimeZoneAccountSessionTestConnectionStates $stateCollection Backing fixture sockets
 */
final class TimeZoneAccountSessionTestConnectionsActions extends HilosSessionConnectionsActions
{
}

/** Connection changes and removal use the inherited guarded item actions. */
final class TimeZoneAccountSessionTestConnectionActions extends HilosConnectionActions
{
}
