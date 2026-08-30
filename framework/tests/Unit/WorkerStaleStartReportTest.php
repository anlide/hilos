<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A start report the roster cannot place costs one agent, not the whole worker.
 *
 * The report and the roster can disagree honestly: a freeze deregisters an agent while its
 * start is already travelling up the link, so the master is told about something it just
 * decided to stop. The master used to answer that by discarding the client - and the client
 * is the entire worker, with every other agent on it. That is how a freeze came to kill
 * healthy workers, and how the mail pool that shared one went missing for a whole run.
 *
 * What must be true instead: the report is answered where the link is held, the worker keeps
 * serving, and the agent nobody has a record of is stopped rather than left running unseen.
 */
final class WorkerStaleStartReportTest extends TestCase
{
    private const string AGENT_TYPE = 'stale_start_agent';

    private const string AGENT_INDEX = '4';

    private const string AGENT_ID = self::AGENT_TYPE . ':' . self::AGENT_INDEX;

    /** @var class-string<Hilos> App class bound before this test touched it */
    private string $boundAppClass;

    private ?EnvAccessor $boundEnv = null;

    protected function setUp(): void
    {
        $this->boundAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, StaleStartTestHilos::class);

        // The client reads one number out of the environment at construction; the catalog stub
        // the accessor defaults to answers it, so no .env is involved.
        $this->boundEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->boundAppClass);
        Hilos::$env = $this->boundEnv;

        parent::tearDown();
    }

    public function testAStartReportForAnAgentTheRosterDoesNotHaveKeepsTheWorkerServing(): void
    {
        $client = $this->buildClient();

        $client->receive($this->startReport());

        $this->assertFalse(
            $client->shouldClose(),
            'One stale line about one agent must not cost the link every other agent shares.',
        );
    }

    public function testTheUnplacedAgentIsStoppedOnTheWorkerThatReportedIt(): void
    {
        $client = $this->buildClient();

        $client->receive($this->startReport());

        $this->assertCount(1, $client->frames);
        $frame = json_decode($client->frames[0], true);
        $this->assertIsArray($frame);
        $this->assertSame(AgentStopDTO::MESSAGE_TYPE, $frame['type'] ?? null);
        $this->assertSame(self::AGENT_ID, $frame['agentId'] ?? null);
    }

    public function testAStartReportTheRosterDoesHaveIsAnsweredWithNothing(): void
    {
        // The other side of the rule: an ordinary start must not be stopped by it.
        $client = $this->buildClient();
        $client->manager()->addAgent(
            self::AGENT_ID,
            new StaleStartTestAgentDaemon(self::AGENT_INDEX),
            2,
            false,
        );

        $client->receive($this->startReport());

        $this->assertSame([], $client->frames);
        $this->assertFalse($client->shouldClose());
    }

    /**
     * @return string One agent_started frame for the agent this test names
     */
    private function startReport(): string
    {
        return new WorkerAgentStartedDTO(
            agentId: self::AGENT_ID,
            agentType: self::AGENT_TYPE,
            agentIndex: self::AGENT_INDEX,
        )->toJson();
    }

    /**
     * @return StaleStartTestWorkerClient Client whose link is a memory stream nothing reads
     */
    private function buildClient(): StaleStartTestWorkerClient
    {
        $socket = fopen('php://memory', 'r+');
        self::assertIsResource($socket);

        return new StaleStartTestWorkerClient($socket, new StaleStartTestAgentManagerDaemon());
    }
}

/**
 * Worker client whose link is captured rather than written, and which can be fed a frame.
 *
 * Fed through {@see WorkerClient::processReadBuffer()} rather than by calling the handler, so
 * what the test drives is the real path a frame takes: buffer, decode, dispatch.
 */
final class StaleStartTestWorkerClient extends WorkerClient
{
    /** @var list<string> Raw frames the master wrote to this link, in order */
    public array $frames = [];

    /** The manager handed in, kept reachable so a test can seed the roster. */
    private StaleStartTestAgentManagerDaemon $managerDouble;

    /**
     * @param resource $socket Link the client would write to
     * @param StaleStartTestAgentManagerDaemon $manager Roster the client reports into
     */
    public function __construct($socket, StaleStartTestAgentManagerDaemon $manager)
    {
        parent::__construct($socket, $manager);
        $this->managerDouble = $manager;
        $this->setWorkerIndex(2);
    }

    /**
     * @return StaleStartTestAgentManagerDaemon Roster this client reports into
     */
    public function manager(): StaleStartTestAgentManagerDaemon
    {
        return $this->managerDouble;
    }

    /**
     * @param string $message Frame the master wants written to this worker
     */
    public function send(string $message): void
    {
        $this->frames[] = $message;
    }

    /**
     * Hands the client one inbound frame, the way a read off the socket would.
     *
     * @param string $frame Complete JSON frame from the worker
     */
    public function receive(string $frame): void
    {
        // The newline is the wire format, not decoration: a frame without one is read as still
        // arriving, and the buffer would sit there complete and unhandled.
        $this->readBuffer = $frame . "\n";
        $this->processReadBuffer();
    }
}

/**
 * Agent manager whose factory answers the one type this test declares.
 */
final class StaleStartTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentDaemonInterface Daemon carrying nothing but its index
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        return new StaleStartTestAgentDaemon($agentIndex);
    }
}

/**
 * Agent daemon that declares nothing: only the roster decides its fate here.
 */
final class StaleStartTestAgentDaemon extends TopologyTestAgentDaemon
{
    public function __construct(?string $agentIndex)
    {
        $this->agentIndex = $agentIndex;
    }

    /**
     * @param AgentMessageDTOInterface $message Message that would go to a user; unused here
     */
    public function sendToUser(AgentMessageDTOInterface $message): void
    {
        // Not used in this test
    }
}

/**
 * Project facade whose registry declares the one instance agent these tests name.
 *
 * Abstract because only its registry constant is read.
 */
abstract class StaleStartTestHilos extends Hilos
{
    public const array AGENTS = [
        'stale_start_agent' => [
            AgentRegistryKey::INDEXED => true,
        ],
    ];
}
