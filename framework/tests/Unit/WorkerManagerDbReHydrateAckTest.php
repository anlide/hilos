<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Exception\AgentCreationFailedException;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Context\DbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\DTO\DbReHydrateOutcome;
use Hilos\Hilos;
use Hilos\Socket\Worker\DTO\DbReHydrateCompleteDTO;
use Hilos\Socket\Worker\DTO\WorkerDbReHydratedDTO;
use Hilos\Socket\Worker\DTO\WorkerDbReHydrateMessageDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The worker's half of the re-hydrate barrier (HIL-436).
 *
 * A worker told that the database underneath it was replaced re-reads its collections and now
 * answers - on both outcomes. The failure branch is the whole point of the frame: it used to end
 * in a lone log line on the worker, where nobody restoring a database would ever look, so a node
 * holding one worker full of caches of a database that no longer exists still counted as ready.
 * Now that worker's "no" travels back and keeps the node closed.
 *
 * The other direction is the return leg: the aggregated verdict arrives addressed to the agent
 * that announced the swap, and this worker hands it to that agent and to nobody else.
 */
final class WorkerManagerDbReHydrateAckTest extends TestCase
{
    /** @var ?DbContext Database context to restore after the test */
    private ?DbContext $previousDb = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousDb = Hilos::$db;
    }

    protected function tearDown(): void
    {
        Hilos::$db = $this->previousDb;
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testAWorkerThatReReadItsCollectionsAnswersThatItDid(): void
    {
        Hilos::$db = new WorkerManagerDbReHydrateAckTestDbContext();
        $manager = new WorkerManagerDbReHydrateAckTestManager();

        $manager->handleDaemonMessage(new WorkerDbReHydrateMessageDTO('backup'));

        $answer = $manager->singleAnswer();
        $this->assertTrue($answer->ok);
        $this->assertNull($answer->error, 'A worker that came back whole has nothing to report');
    }

    public function testAWorkerThatCouldNotReReadAnswersWithTheReason(): void
    {
        // The case the barrier exists for: without an answer here the daemon would wait out its
        // whole deadline and then report a timeout, naming the wrong fault.
        Hilos::$db = new WorkerManagerDbReHydrateAckTestDbContext(
            new DatabaseException('table hilos_users is missing'),
        );
        $manager = new WorkerManagerDbReHydrateAckTestManager();

        $manager->handleDaemonMessage(new WorkerDbReHydrateMessageDTO('backup'));

        $answer = $manager->singleAnswer();
        $this->assertFalse($answer->ok);
        $this->assertSame(
            'table hilos_users is missing',
            $answer->error,
            'The operator reads this line to learn which process could not come back, and why',
        );
    }

    public function testAFailedReReadStillLeavesTheWorkerRunning(): void
    {
        // Contained on purpose: a worker that died here would take live browser connections with
        // it while the node is still finishing a restore.
        Hilos::$db = new WorkerManagerDbReHydrateAckTestDbContext(new DatabaseException('gone'));
        $manager = new WorkerManagerDbReHydrateAckTestManager();

        $manager->handleDaemonMessage(new WorkerDbReHydrateMessageDTO('backup'));

        $this->assertFalse($manager->singleAnswer()->ok);
    }

    public function testTheVerdictReachesTheAgentThatAnnouncedTheSwap(): void
    {
        $manager = new WorkerManagerDbReHydrateAckTestManager();
        $agent = $manager->hostAgent();

        $manager->handleDaemonMessage(new DbReHydrateCompleteDTO(
            WorkerManagerDbReHydrateAckTestAgent::AGENT_TYPE,
            false,
            ['worker #2: timeout'],
        ));

        $this->assertNotNull($agent->outcome);
        $this->assertFalse($agent->outcome->complete);
        $this->assertSame(['worker #2: timeout'], $agent->outcome->problems);
    }

    public function testAVerdictForAnAgentThisWorkerDoesNotHostIsDropped(): void
    {
        // Agents move between workers, and a verdict that arrives after its initiator has gone
        // must not be handed to whoever happens to answer to a similar id here.
        $manager = new WorkerManagerDbReHydrateAckTestManager();
        $agent = $manager->hostAgent();

        $manager->handleDaemonMessage(new DbReHydrateCompleteDTO('somebody-else', true, []));

        $this->assertNull($agent->outcome);
    }

    public function testAnUnaddressedVerdictIsDropped(): void
    {
        $manager = new WorkerManagerDbReHydrateAckTestManager();
        $agent = $manager->hostAgent();

        $manager->handleDaemonMessage(new DbReHydrateCompleteDTO(null, true, []));

        $this->assertNull($agent->outcome);
    }
}

/**
 * Worker manager with the daemon link replaced by a recorder, so the answers it sends are
 * readable without a socket.
 */
final class WorkerManagerDbReHydrateAckTestManager extends WorkerManager
{
    /** Everything this worker handed to the daemon */
    private readonly WorkerManagerDbReHydrateAckTestDaemonClient $recorder;

    public function __construct()
    {
        parent::__construct(1);

        $this->recorder = new WorkerManagerDbReHydrateAckTestDaemonClient();
        $this->daemonClient = $this->recorder;
    }

    /**
     * Puts the test agent on this worker, the way a daemon start message would.
     *
     * @return WorkerManagerDbReHydrateAckTestAgent The hosted agent
     * @throws AgentCreationFailedException If the agent cannot be created
     */
    public function hostAgent(): WorkerManagerDbReHydrateAckTestAgent
    {
        $agent = $this->agentManager->createAndAddAgent(
            WorkerManagerDbReHydrateAckTestAgent::AGENT_TYPE,
            null,
        );

        return $agent instanceof WorkerManagerDbReHydrateAckTestAgent
            ? $agent
            : throw new RuntimeException('The test agent manager handed out a foreign agent.');
    }

    /**
     * @return WorkerDbReHydratedDTO The one re-hydrate answer this worker sent
     */
    public function singleAnswer(): WorkerDbReHydratedDTO
    {
        $answers = array_values(array_filter(
            $this->recorder->sent,
            static fn($sent) => $sent instanceof WorkerDbReHydratedDTO,
        ));
        if (count($answers) !== 1) {
            throw new RuntimeException('A re-hydrate announcement is answered exactly once.');
        }

        return $answers[0];
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManager(): AgentManager
    {
        return new WorkerManagerDbReHydrateAckTestAgentManager();
    }
}

final class WorkerManagerDbReHydrateAckTestAgentManager extends AgentManager
{
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return new WorkerManagerDbReHydrateAckTestAgent();
    }
}

/**
 * An agent that keeps the verdict it was handed, instead of acting on it.
 */
final class WorkerManagerDbReHydrateAckTestAgent extends AbstractAgent
{
    /** @var string Agent type identifier */
    public const string AGENT_TYPE = 'unit_db_rehydrate_ack';

    /** The verdict this agent received, or null while it has received none */
    public ?DbReHydrateOutcome $outcome = null;

    public function onStop(): void
    {
    }

    /**
     * @param DbReHydrateOutcome $outcome Whether every process re-read, and who did not
     */
    public function onDbReHydrateComplete(DbReHydrateOutcome $outcome): void
    {
        $this->outcome = $outcome;
    }
}

/**
 * Daemon link that records what the worker sent instead of writing it to a socket.
 */
final class WorkerManagerDbReHydrateAckTestDaemonClient extends WorkerDaemonClient
{
    /** @var list<WorkerDTO|array<string, mixed>> Everything the worker handed to the daemon */
    public array $sent = [];

    public function __construct()
    {
    }

    public function isConnected(): bool
    {
        return true;
    }

    /**
     * @param WorkerDTO|array<string, mixed> $data Message the worker wants delivered
     */
    public function send(WorkerDTO|array $data): void
    {
        $this->sent[] = $data;
    }
}

/**
 * Database context whose re-read either returns or fails, without a database behind it.
 */
final class WorkerManagerDbReHydrateAckTestDbContext extends DbContext
{
    /**
     * @param ?DatabaseException $failure Failure the re-read raises, or null when it succeeds
     */
    public function __construct(private readonly ?DatabaseException $failure = null)
    {
        parent::__construct();
    }

    public function configure(): void
    {
    }

    /**
     * @throws DatabaseException When the case under test is a worker that could not re-read
     */
    public function reHydrateDbBackedCollections(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
