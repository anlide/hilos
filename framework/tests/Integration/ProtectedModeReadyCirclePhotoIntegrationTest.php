<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Exception\AgentCreationFailedException;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Log\AgentLogStream;
use Hilos\ProtectedMode\DTO\ProtectedModeCircleSignalData;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Worker\DTO\ProtectedModeReadyDTO;
use Hilos\Utils\Logger;
use RuntimeException;

/**
 * The verifier circle is photographed by the worker's ready relay, for whichever agent asked for
 * the freeze (HIL-1118).
 *
 * The initiator here is a plain agent - neither the backup agent nor the test drive - whose ready
 * hook does nothing but answer, the way the test drive answers its enter. That is the point of
 * the case: no agent sends the circle any more, so an agent that knows nothing about it has to
 * get it all the same, and it has to leave before whatever the hook queues next.
 *
 * The worker's read guard is on, and the reads are confirmed the way a master would confirm
 * them: the framework's process-wide list is declared and its answers marked ready, and no agent
 * declares anything. That is the refusal HIL-1096 found - the circle read in a worker its one
 * declaring agent did not share - and the case the guard does not confirm is the one that has to
 * be named as a refusal rather than read as an empty circle.
 */
final class ProtectedModeReadyCirclePhotoIntegrationTest extends HilosSessionIntegrationTestCase
{
    private const string TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private const string CREATED_AT = '2026-08-01 09:15:00';

    /** Well past any run of this suite: these cases are about live sessions, not about expiry. */
    private const string EXPIRES_AT = '2036-09-01 09:15:00';

    private const int MEMBER_USER_ID = 41;

    private const string EMAIL_TYPE = 'password';

    private const string MEMBER_EMAIL = 'ann@example.test';

    /** Index the initiator is hosted under, so the frame is seen to carry it as a number. */
    private const string INITIATOR_INDEX = '3';

    /** @var ?RtContext Runtime context to restore after the test */
    private ?RtContext $previousRt = null;

    /** Directory the worker's main log and the agents' streams are written to */
    private string $logDirectory = '';

    /**
     * @throws HilosException When the schema reset or the context build fails
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::runCircleStub(down: true);
        self::runCircleStub(down: false);

        $this->previousRt = Hilos::$rt;
        $rt = new ReadyCirclePhotoRtContext();
        $rt->configure();
        Hilos::$rt = $rt;

        $this->logDirectory = sys_get_temp_dir() . '/hilos-ready-circle-photo-' . getmypid();
        if (!is_dir($this->logDirectory)) {
            mkdir($this->logDirectory, 0777, true);
        }
        Logger::setLogFile($this->logDirectory . '/main.log');

        // A worker, as the relay runs in one: nothing is read that was not declared and confirmed.
        // The two collections the photograph resolves people and sessions through are confirmed
        // for every case; the circle is left to the case, because its readiness is what one of
        // them is about.
        SourceInterestRegistry::readsWhatIsDelivered();
        Hilos::$db->declareProcessWideReads();
        SourceInterestRegistry::markReady(SourceChange::KIND_DB, HilosDbContext::identities);
        SourceInterestRegistry::markReady(SourceChange::KIND_DB, HilosDbContext::sessions);
    }

    /**
     * @throws HilosException When dropping the stub table fails
     */
    protected function tearDown(): void
    {
        // The relay leaves the agent it addressed as the current one, as the worker loop would
        // until its next frame; left standing, every later case would write as that agent.
        ExecutionContext::setCurrentAgentId(null);
        SourceInterestRegistry::readsWhatItMounts();
        foreach (Hilos::$db->processWideReadKeys() as $collectionKey) {
            SourceInterestRegistry::releaseConsumer(SourceConsumer::feature($collectionKey));
        }

        Logger::resetLogFile();
        foreach (glob($this->logDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->logDirectory);

        Hilos::$rt = $this->previousRt;
        Hilos::$sr = null;
        self::runCircleStub(down: true);

        parent::tearDown();
    }

    /**
     * @throws HilosException When a step against the database fails
     * @throws AgentCreationFailedException When the initiator cannot be hosted
     */
    public function testANamedMemberOnlineIsPhotographedForTheInitiatorBeforeItsHook(): void
    {
        $this->seedMemberOnline();
        SourceInterestRegistry::markReady(SourceChange::KIND_DB, HilosDbContext::verifierCircle);
        $manager = new ReadyCirclePhotoTestManager();
        $initiator = $manager->hostAgent(self::INITIATOR_INDEX);

        $manager->handleDaemonMessage(new ProtectedModeReadyDTO($initiator->getId()));

        $signals = self::drainQueuedSignals();
        $this->assertSame(
            [SignalTypeConstants::PROTECTED_MODE_CIRCLE, SignalTypeConstants::COMMAND_REPLY],
            self::typesOf($signals),
            'The circle leaves first: a caller told the node is frozen reads it already admitted',
        );
        $circle = $signals[0];
        $this->assertSame(SignalSource::AGENT, $circle->signalSource->getSource());
        $this->assertSame(ReadyCirclePhotoTestAgent::AGENT_TYPE, $circle->signalSource->getType());
        $this->assertSame(self::INITIATOR_INDEX, $circle->signalSource->getIndex());
        $this->assertInstanceOf(ProtectedModeCircleSignalData::class, $circle->data);
        $this->assertSame(ReadyCirclePhotoTestAgent::AGENT_TYPE, $circle->data->initiatorAgentType);
        $this->assertSame((int)self::INITIATOR_INDEX, $circle->data->initiatorAgentIndex);
        $this->assertSame(1, $circle->data->namedCount);
        $this->assertSame([ProtectedModeRuntime::hashSessionToken(self::TOKEN)], $circle->data->sessionTokenHashes);
        $this->assertSame(1, $initiator->readyCalls);
    }

    /**
     * @throws HilosException When a step against the database fails
     * @throws AgentCreationFailedException When the initiator cannot be hosted
     */
    public function testANamedMemberNobodyCanSeeOnlineTravelsAsACountAndIsWrittenDown(): void
    {
        // Named, proved, but no tab open: the count still travels, because afterwards it is the
        // only thing that tells "nobody named was online" from "nobody was named".
        self::seedCircle(self::EMAIL_TYPE, self::MEMBER_EMAIL);
        self::seedIdentity(self::MEMBER_USER_ID, self::EMAIL_TYPE, self::MEMBER_EMAIL);
        SourceInterestRegistry::markReady(SourceChange::KIND_DB, HilosDbContext::verifierCircle);
        $manager = new ReadyCirclePhotoTestManager();
        $initiator = $manager->hostAgent(self::INITIATOR_INDEX);

        $manager->handleDaemonMessage(new ProtectedModeReadyDTO($initiator->getId()));

        $signals = self::drainQueuedSignals();
        $this->assertSame(
            [SignalTypeConstants::PROTECTED_MODE_CIRCLE, SignalTypeConstants::COMMAND_REPLY],
            self::typesOf($signals),
        );
        $this->assertInstanceOf(ProtectedModeCircleSignalData::class, $signals[0]->data);
        $this->assertSame(1, $signals[0]->data->namedCount);
        $this->assertSame([], $signals[0]->data->sessionTokenHashes);
        $this->assertStringContainsString(
            'Verifier circle admitted 0 of 1 named member(s)',
            $this->agentLog($initiator, errorStream: false),
        );
    }

    /**
     * @throws HilosException When a step against the database fails
     * @throws AgentCreationFailedException When the initiator cannot be hosted
     */
    public function testACircleThatNamesNobodySendsNothingAndTheHookStillRuns(): void
    {
        SourceInterestRegistry::markReady(SourceChange::KIND_DB, HilosDbContext::verifierCircle);
        $manager = new ReadyCirclePhotoTestManager();
        $initiator = $manager->hostAgent(self::INITIATOR_INDEX);

        $manager->handleDaemonMessage(new ProtectedModeReadyDTO($initiator->getId()));

        $this->assertSame([SignalTypeConstants::COMMAND_REPLY], self::typesOf(self::drainQueuedSignals()));
        $this->assertSame(1, $initiator->readyCalls);
    }

    /**
     * @throws HilosException When a step against the database fails
     * @throws AgentCreationFailedException When the initiator cannot be hosted
     */
    public function testACircleWhoseReadinessHasNotArrivedIsNamedAsARefusalAndStopsNothing(): void
    {
        // Somebody is named and online, so a read that went through would have sent a frame. It
        // does not go through - the circle is declared but not confirmed - and that is written as
        // the refusal it is, not as a circle nobody filled; the freeze goes on regardless.
        $this->seedMemberOnline();
        $manager = new ReadyCirclePhotoTestManager();
        $initiator = $manager->hostAgent(self::INITIATOR_INDEX);

        $manager->handleDaemonMessage(new ProtectedModeReadyDTO($initiator->getId()));

        $this->assertSame([SignalTypeConstants::COMMAND_REPLY], self::typesOf(self::drainQueuedSignals()));
        $this->assertSame(1, $initiator->readyCalls);
        $this->assertStringContainsString(
            'Protected mode cannot photograph the verifier circle here: ',
            $this->agentLog($initiator, errorStream: true),
        );
    }

    /**
     * @throws HilosException When a step against the database fails
     * @throws AgentCreationFailedException When the initiator cannot be hosted
     */
    public function testAReadyForAnAgentThisWorkerDoesNotHostPhotographsNothing(): void
    {
        $this->seedMemberOnline();
        SourceInterestRegistry::markReady(SourceChange::KIND_DB, HilosDbContext::verifierCircle);
        $manager = new ReadyCirclePhotoTestManager();
        $hosted = $manager->hostAgent(self::INITIATOR_INDEX);

        $manager->handleDaemonMessage(new ProtectedModeReadyDTO('somebody-else'));

        $this->assertSame([], self::drainQueuedSignals(), 'No photograph is taken for an initiator not here');
        $this->assertSame(0, $hosted->readyCalls);
    }

    /**
     * Names one member of the circle, proves the address, and opens a tab for them.
     *
     * @throws HilosException When a seed insert fails
     */
    private function seedMemberOnline(): void
    {
        self::seedCircle(self::EMAIL_TYPE, self::MEMBER_EMAIL);
        self::seedIdentity(self::MEMBER_USER_ID, self::EMAIL_TYPE, self::MEMBER_EMAIL);
        self::seedSession(self::TOKEN, self::MEMBER_USER_ID, self::CREATED_AT, self::EXPIRES_AT);
        /** @var ReadyCirclePhotoRtContext $rt */
        $rt = Hilos::$rt;
        $rt->connections()->add(ReadyCirclePhotoConnection::create('accept-1', self::MEMBER_USER_ID, self::TOKEN));
    }

    /**
     * Reads back one of the initiator's log streams, which the case expects to have been written.
     *
     * @param AgentInterface $initiator Agent whose stream is read
     * @param bool $errorStream Whether to read the error twin rather than the main stream
     * @return string Stream contents
     */
    private function agentLog(AgentInterface $initiator, bool $errorStream): string
    {
        $path = AgentLogStream::pathFor($this->logDirectory, $initiator->getId(), $errorStream);
        $this->assertFileExists($path, 'The initiator\'s stream was never written');

        return (string)file_get_contents($path);
    }

    /**
     * Inserts one named member of the circle, the way the admin surface would have.
     *
     * @param string $type Identity type of the named pair
     * @param string $identifier Normalized identifier of the named pair
     * @throws HilosException When the insert fails
     */
    private static function seedCircle(string $type, string $identifier): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_verifier_circle` (`identity_type`, `identifier`) VALUES (?, ?)',
            [$type, $identifier],
        );
    }

    /**
     * Runs one direction of the circle table's stub file.
     *
     * @param bool $down Run the down (drop) stub when true, the create stub when false
     * @throws HilosException When the stub statement fails
     */
    private static function runCircleStub(bool $down): void
    {
        // external-boundary: the neutral element of the name being built - the up file carries no suffix
        $suffix = $down ? '_down' : '';
        $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_hilos_verifier_circle{$suffix}.sql";
        Database::sqlRun((string)file_get_contents($stub));
    }

    /**
     * Drains and returns all queued signals from the signal router.
     *
     * @return list<SignalDTO> Drained signals in queue order
     */
    private static function drainQueuedSignals(): array
    {
        $signals = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $signals[] = $signal;
        }

        return $signals;
    }

    /**
     * @param list<SignalDTO> $signals Drained signals
     * @return list<string> Their signal types, in queue order
     */
    private static function typesOf(array $signals): array
    {
        return array_map(static fn(SignalDTO $signal): string => $signal->signalType->getType(), $signals);
    }
}

/**
 * Worker manager hosting the one test initiator; nothing it does here needs the daemon link.
 */
final class ReadyCirclePhotoTestManager extends WorkerManager
{
    public function __construct()
    {
        parent::__construct(1);
    }

    /**
     * Puts the test initiator on this worker, the way a daemon start message would.
     *
     * @param string $agentIndex Index to host the initiator under
     * @return ReadyCirclePhotoTestAgent The hosted agent
     * @throws AgentCreationFailedException If the agent cannot be created
     */
    public function hostAgent(string $agentIndex): ReadyCirclePhotoTestAgent
    {
        $agent = $this->agentManager->createAndAddAgent(ReadyCirclePhotoTestAgent::AGENT_TYPE, $agentIndex);

        return $agent instanceof ReadyCirclePhotoTestAgent
            ? $agent
            : throw new RuntimeException('The test agent manager handed out a foreign agent.');
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManager(): AgentManager
    {
        return new ReadyCirclePhotoTestAgentManager();
    }
}

/**
 * Agent manager building the test initiator under the index it is asked for.
 */
final class ReadyCirclePhotoTestAgentManager extends AgentManager
{
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return new ReadyCirclePhotoTestAgent($agentIndex);
    }
}

/**
 * An initiator that knows nothing about the circle: its ready hook only answers, as a test drive
 * answers its enter.
 */
final class ReadyCirclePhotoTestAgent extends AbstractAgent
{
    /** @var string Agent type identifier */
    public const string AGENT_TYPE = 'unit_ready_circle_photo';

    /** How many times the ready hook ran */
    public int $readyCalls = 0;

    /**
     * @param ?string $agentIndex Index this agent is hosted under
     */
    public function __construct(?string $agentIndex)
    {
        $this->agentIndex = $agentIndex;
    }

    public function onStop(): void
    {
    }

    /**
     * Counts the call and queues an answer, so what the hook queues is seen to follow the circle.
     *
     * @throws InvalidArgumentException When the answer cannot be named
     */
    public function onProtectedModeReady(): void
    {
        $this->readyCalls++;
        $this->replyToCommand(CommandReplyDTO::ok('corr-ready-circle-photo'));
    }
}

/**
 * The smallest concrete connection row: the framework session triple and nothing else.
 */
final class ReadyCirclePhotoConnection extends HilosSessionConnection
{
    /**
     * @return string Runtime collection key
     */
    public static function getRtCollectionKey(): string
    {
        return ReadyCirclePhotoRtContext::connections;
    }

    protected function initOwn(): void
    {
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row (nothing of its own to read)
     */
    protected function hydrateOwn(array $row): void
    {
    }

    /**
     * @return array<string, mixed> Always empty: the row is the framework base
     */
    protected function ownToArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $diff Partial update (nothing of its own to apply)
     */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

/**
 * A project connections collection, as every project that has sessions declares one.
 *
 * @extends HilosSessionConnections<ReadyCirclePhotoConnection>
 */
final class ReadyCirclePhotoConnections extends HilosSessionConnections
{
    public const string STATE_CLASS = ReadyCirclePhotoConnection::class;
}

/**
 * A runtime context whose connections extend the framework base, as demo/chat does.
 */
final class ReadyCirclePhotoRtContext extends RtContext
{
    public const string connections = 'connections';

    /**
     * Mounts the one collection these cases need: the project's live connections.
     */
    public function configure(): void
    {
        $this->_stateCollections[self::connections] = ReadyCirclePhotoConnections::init();
    }

    /**
     * @return ReadyCirclePhotoConnections Live connections of this context
     */
    public function connections(): ReadyCirclePhotoConnections
    {
        /** @var ReadyCirclePhotoConnections $connections */
        $connections = $this->_stateCollections[self::connections];

        return $connections;
    }
}
