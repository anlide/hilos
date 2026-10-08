<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\Worker\WorkerTickUnit;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\Transaction\AnnouncementFailedException;
use Hilos\Database\Exception\Transaction\TransactionLeftOpenException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\Worker\DaemonConnectionState;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Utils\Logger;
use Hilos\Utils\WorkerTickFailureLog;
use RuntimeException;

/**
 * A transaction does not outlive the unit of the worker's tick that opened it (HIL-1164), and a
 * failed announcement of a commit is charged to that unit rather than to the commit (HIL-1299).
 *
 * The worker's loop contains a failure per unit; this pins the other things every unit owes at
 * its end - a transaction it left open is rolled back, what it held is dropped, and the failure
 * is written as the unit's own, so the next unit starts on a clean connection and the journal
 * says which handler kept it; an announcement a commit released that failed leaves the commit
 * standing and the unit going on, and is written as the unit's own the same way. Driven the way
 * the tick guard's cases drive the manager, one loop iteration with no daemon socket, but over a
 * real connection: the rollback and the clean stack cannot be seen anywhere else.
 */
final class WorkerTransactionLeftOpenIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Table the units write into and leave inside their transaction. */
    public const string FIXTURE_TABLE = 'hilos_test_transaction_left_open';

    private const string LEAVING_MESSAGE_TYPE = 'agent_start';

    private const string FOLLOWING_MESSAGE_TYPE = 'cron';

    /** Temporary main log file the assertions read the written lines back from */
    private string $logFile = '';

    private ?SignalRouter $previousSignalRouter = null;

    /**
     * @throws DatabaseException When the fixture table cannot be created
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previousSignalRouter = Hilos::$sr;
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-worker-transaction-left-open');
        Logger::setLogFile($this->logFile);
        WorkerTickFailureLog::reset();
        ExecutionContext::clear();
        Database::sqlRun('DROP TABLE IF EXISTS `' . self::FIXTURE_TABLE . '`');
        Database::sqlRun(
            'CREATE TABLE `' . self::FIXTURE_TABLE . '` ('
            . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . '`mark` VARCHAR(255) NOT NULL'
            . ') ' . DatabaseConnectionDefaults::DDL_TABLE_SUFFIX,
        );
    }

    /**
     * @throws DatabaseException When the fixture table cannot be dropped
     */
    protected function tearDown(): void
    {
        // The manager's constructor registers its signal router globally, and run() states
        // process-wide what kind of process this is: both would outlive this file.
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$browser = null;
        SourceInterestRegistry::readsWhatItMounts();
        Logger::resetLogFile();
        WorkerTickFailureLog::reset();
        ExecutionContext::clear();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        if (Database::isConnected()) {
            Database::sqlRun('DROP TABLE IF EXISTS `' . self::FIXTURE_TABLE . '`');
        }

        parent::tearDown();
    }

    /**
     * An agent that opens a transaction in its tick and returns without closing it: the row it
     * wrote is gone, the announcement it held was never made, the card names the agent, and the
     * agent after it ticks on a connection with nothing open.
     *
     * @throws HilosException When the probe of the connection fails
     */
    public function testATransactionAnAgentLeavesOpenIsRolledBackAndChargedToThatAgent(): void
    {
        $manager = new WorkerTransactionLeftOpenTestManager();
        $leaving = new WorkerTransactionLeftOpenTestAgent('1');
        $leaving->leavesTransactionOpen = true;
        $neighbour = new WorkerTransactionLeftOpenTestAgent('2');
        $manager->addTestAgent($leaving);
        $manager->addTestAgent($neighbour);

        $manager->run();

        self::assertSame(1, $neighbour->ticks, 'The next agent ticks');
        self::assertSame(0, self::fixtureRows(), 'The row the agent wrote went with the rollback');
        self::assertFalse($leaving->announced, 'The announcement the agent held was dropped');
        self::assertSame([], Database::handlerEnd(), 'The stack is empty once the unit ended');
        self::assertCount(1, $manager->containedFailures);
        self::assertSame(WorkerTickUnit::AGENT, $manager->containedFailures[0]->unit);
        self::assertSame($leaving->getId(), $manager->containedFailures[0]->address);
        self::assertInstanceOf(TransactionLeftOpenException::class, $manager->containedFailures[0]->failure);
        self::assertStringContainsString(
            'contained a failure in agent (' . $leaving->getId() . ')',
            $this->logged(),
        );
        self::assertStringContainsString('A transaction was left open on connection 0 at depth 1', $this->logged());

        // The connection is clean: a start is not refused as nested.
        Database::transactionStart();
        Database::transactionRollback();
    }

    /**
     * An agent that raises out of its transaction: the rollback comes before the project is told,
     * so what the project's hook writes about the failure stands outside the leaked transaction,
     * and the agent's own failure is charged before the transaction it left.
     */
    public function testTheRollbackComesBeforeTheProjectIsToldOfTheFailure(): void
    {
        $manager = new WorkerTransactionLeftOpenTestManager();
        $manager->hookWritesARow = true;
        $raising = new WorkerTransactionLeftOpenTestAgent('1');
        $raising->leavesTransactionOpen = true;
        $raising->raisesAfterTheWrite = true;
        $manager->addTestAgent($raising);

        $manager->run();

        self::assertSame(
            ['hook:' . RuntimeException::class, 'hook:' . TransactionLeftOpenException::class],
            self::fixtureMarks(),
            'The agent\'s own row is gone; the hook\'s rows, written after the rollback, stand',
        );
        self::assertCount(2, $manager->containedFailures);
        self::assertInstanceOf(RuntimeException::class, $manager->containedFailures[0]->failure);
        self::assertInstanceOf(TransactionLeftOpenException::class, $manager->containedFailures[1]->failure);
        self::assertSame($raising->getId(), $manager->containedFailures[1]->address);
    }

    /**
     * A daemon message handled to the end with its transaction still open is charged like a
     * message that raised, and the message behind it is still handled.
     */
    public function testATransactionADaemonMessageLeavesOpenIsChargedToThatMessage(): void
    {
        $manager = new WorkerTransactionLeftOpenTestManager();
        $manager->leavingMessageType = self::LEAVING_MESSAGE_TYPE;
        $manager->queueMessage(new WorkerTransactionLeftOpenTestMessage(self::LEAVING_MESSAGE_TYPE));
        $manager->queueMessage(new WorkerTransactionLeftOpenTestMessage(self::FOLLOWING_MESSAGE_TYPE));

        $manager->run();

        self::assertSame(
            [self::LEAVING_MESSAGE_TYPE, self::FOLLOWING_MESSAGE_TYPE],
            $manager->handledMessageTypes,
            'The message itself returned normally, and the one behind it was handled',
        );
        self::assertSame(0, self::fixtureRows());
        self::assertCount(1, $manager->containedFailures);
        self::assertSame(WorkerTickUnit::DAEMON_MESSAGE, $manager->containedFailures[0]->unit);
        self::assertSame(self::LEAVING_MESSAGE_TYPE, $manager->containedFailures[0]->address);
        self::assertInstanceOf(TransactionLeftOpenException::class, $manager->containedFailures[0]->failure);
        self::assertStringContainsString(
            'contained a failure in daemon message (' . self::LEAVING_MESSAGE_TYPE . ')',
            $this->logged(),
        );
    }

    /**
     * The project's own tick hook is a unit like the others.
     */
    public function testATransactionTheProjectTickLeavesOpenIsChargedToTheWorkerTick(): void
    {
        $manager = new WorkerTransactionLeftOpenTestManager();
        $manager->workerTickLeavesTransactionOpen = true;
        $neighbour = new WorkerTransactionLeftOpenTestAgent('2');
        $manager->addTestAgent($neighbour);

        $manager->run();

        self::assertSame(1, $neighbour->ticks);
        self::assertSame(0, self::fixtureRows());
        self::assertCount(1, $manager->containedFailures);
        self::assertSame(WorkerTickUnit::WORKER_TICK, $manager->containedFailures[0]->unit);
        self::assertSame('onTick', $manager->containedFailures[0]->address);
        self::assertInstanceOf(TransactionLeftOpenException::class, $manager->containedFailures[0]->failure);
    }

    /**
     * A unit that closes its transaction owes nothing: no card, and the row stands.
     */
    public function testAUnitThatCommitsItsTransactionIsNotCharged(): void
    {
        $manager = new WorkerTransactionLeftOpenTestManager();
        $committing = new WorkerTransactionLeftOpenTestAgent('1');
        $committing->commitsTransaction = true;
        $manager->addTestAgent($committing);

        $manager->run();

        self::assertSame(1, self::fixtureRows());
        self::assertTrue($committing->announced, 'The held announcement was made at the commit');
        self::assertSame([], $manager->containedFailures);
    }

    /**
     * An agent whose commit released an announcement that failed: the commit returns and the
     * tick goes on past it, the row stands, and the failure is one card of that agent's unit -
     * the agent after it ticks with no card of its own.
     */
    public function testAFailedAnnouncementOfACommitIsChargedToTheAgentWhoseTickGoesOn(): void
    {
        $manager = new WorkerTransactionLeftOpenTestManager();
        $committing = new WorkerTransactionLeftOpenTestAgent('1');
        $committing->commitsTransaction = true;
        $committing->announcementFails = true;
        $neighbour = new WorkerTransactionLeftOpenTestAgent('2');
        $manager->addTestAgent($committing);
        $manager->addTestAgent($neighbour);

        $manager->run();

        self::assertTrue($committing->reachedTheEndOfItsTick, 'The commit returned, and the tick went on past it');
        self::assertSame(1, self::fixtureRows(), 'The commit stood');
        self::assertSame(1, $neighbour->ticks, 'The next agent ticks');
        self::assertCount(1, $manager->containedFailures);
        self::assertSame(WorkerTickUnit::AGENT, $manager->containedFailures[0]->unit);
        self::assertSame($committing->getId(), $manager->containedFailures[0]->address);
        self::assertInstanceOf(AnnouncementFailedException::class, $manager->containedFailures[0]->failure);
        self::assertSame(
            WorkerTransactionLeftOpenTestAgent::ANNOUNCEMENT_FAILURE,
            $manager->containedFailures[0]->failure->getPrevious()?->getMessage(),
        );
        self::assertStringContainsString(
            'contained a failure in agent (' . $committing->getId() . ')',
            $this->logged(),
        );
    }

    /**
     * Opens a transaction on the current connection and writes one row into the fixture table,
     * holding an announcement, the way a unit under test does.
     *
     * @param string $mark Value telling this row apart
     * @param bool $announcedFlag Flag the held announcement raises when it is made
     * @param bool $announcementFails Whether the held announcement raises instead, when it is made
     * @throws HilosException When the start or the write fails
     */
    public static function openAndWrite(string $mark, bool &$announcedFlag, bool $announcementFails = false): void
    {
        Database::transactionStart();
        Database::sqlRun('INSERT INTO `' . self::FIXTURE_TABLE . '` (`mark`) VALUES (?)', [$mark]);
        Database::afterCommit(static function () use (&$announcedFlag, $announcementFails): void {
            if ($announcementFails) {
                throw new RuntimeException(WorkerTransactionLeftOpenTestAgent::ANNOUNCEMENT_FAILURE);
            }
            $announcedFlag = true;
        });
    }

    /**
     * @return int Rows the fixture table holds, read on the worker's own connection
     * @throws DatabaseException When the count fails
     */
    private static function fixtureRows(): int
    {
        Database::sql('SELECT COUNT(*) AS total FROM `' . self::FIXTURE_TABLE . '`');

        return (int)Database::row()['total'];
    }

    /**
     * @return list<string> Marks of the rows the fixture table holds, in the order they were written
     * @throws DatabaseException When the read fails
     */
    private static function fixtureMarks(): array
    {
        Database::sql('SELECT `mark` FROM `' . self::FIXTURE_TABLE . '` ORDER BY `id`');

        return array_map(static fn(array $row): string => (string)$row['mark'], Database::rows());
    }

    /**
     * @return string Everything written to the main log so far
     */
    private function logged(): string
    {
        return (string)file_get_contents($this->logFile);
    }
}

/**
 * Worker manager driven through exactly one loop iteration, without a daemon socket.
 */
final class WorkerTransactionLeftOpenTestManager extends WorkerManager
{
    /** Type of the daemon message whose handler leaves its transaction open, or null when none does. */
    public ?string $leavingMessageType = null;

    /** Whether the project's own tick hook leaves its transaction open. */
    public bool $workerTickLeavesTransactionOpen = false;

    /** Whether the tick hook's held announcement was made; never, in these cases. */
    public bool $workerTickAnnounced = false;

    /** Whether the failure hook writes a row of its own into the fixture table. */
    public bool $hookWritesARow = false;

    /** @var list<string> Types of the messages the handler saw through to the end. */
    public array $handledMessageTypes = [];

    /** @var list<ContainedFailure> What the guard handed to the project, in order. */
    public array $containedFailures = [];

    /** @var list<WorkerDTO> Messages the scripted client hands out, in order. */
    private array $messages = [];

    public function __construct()
    {
        parent::__construct(1);
    }

    /**
     * Puts one message in the queue the loop drains.
     *
     * @param WorkerDTO $message Message the scripted client hands out
     */
    public function queueMessage(WorkerDTO $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * Puts an agent in the manager the loop ticks.
     *
     * @param AgentInterface $agent Agent to tick
     */
    public function addTestAgent(AgentInterface $agent): void
    {
        $this->agentManager->addAgent($agent->getId(), $agent);
    }

    /**
     * Handles a message to the end; the named one opens a transaction it never closes.
     *
     * @param WorkerDTO $data Message from the daemon
     * @throws HilosException When the transaction or the write fails
     */
    public function handleDaemonMessage(WorkerDTO $data): void
    {
        if ($data->getType() === $this->leavingMessageType) {
            $ignored = false;
            WorkerTransactionLeftOpenIntegrationTest::openAndWrite($data->getType(), $ignored);
        }

        $this->handledMessageTypes[] = $data->getType();
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManager(): AgentManager
    {
        return new WorkerTransactionLeftOpenTestAgentManager();
    }

    protected function connectToDaemon(): void
    {
        $this->daemonClient = new WorkerTransactionLeftOpenTestClient($this->messages);
    }

    /**
     * @throws HilosException When the transaction or the write fails
     */
    protected function onTick(): void
    {
        if ($this->workerTickLeavesTransactionOpen) {
            WorkerTransactionLeftOpenIntegrationTest::openAndWrite('worker tick', $this->workerTickAnnounced);
        }
    }

    /**
     * @param ContainedFailure $failure Failure the tick contained
     * @throws HilosException When the row the hook writes cannot be written
     */
    protected function onTickFailure(ContainedFailure $failure): void
    {
        $this->containedFailures[] = $failure;
        if ($this->hookWritesARow) {
            Database::sqlRun(
                'INSERT INTO `' . WorkerTransactionLeftOpenIntegrationTest::FIXTURE_TABLE . '` (`mark`) VALUES (?)',
                ['hook:' . $failure->failure::class],
            );
        }
    }

    protected function setupErrorHandling(): void
    {
    }

    protected function setupSignalHandlers(): void
    {
    }

    protected function checkDaemonLiveness(float $loopStartTime): void
    {
    }

    protected function sleepWithPreciseTiming(float $loopStartTime, int $targetLoopTimeMicroseconds = 10000): void
    {
        $this->shouldExit = true;
    }

    protected function cleanup(): void
    {
    }
}

/**
 * Agent manager stub: these cases never start an agent, they are handed them.
 */
final class WorkerTransactionLeftOpenTestAgentManager extends AgentManager
{
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        throw new RuntimeException('The left-open cases never start an agent.');
    }
}

/**
 * Agent that can be told to open a transaction in its tick and either commit it or walk away.
 */
final class WorkerTransactionLeftOpenTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'integration_transaction_left_open';

    /** What the announcement of a commit raises when the case asks it to fail. */
    public const string ANNOUNCEMENT_FAILURE = 'the announcement could not be made';

    /** Whether this agent's tick opens a transaction, writes, and returns without closing it. */
    public bool $leavesTransactionOpen = false;

    /** Whether this agent's tick opens a transaction, writes, and commits it. */
    public bool $commitsTransaction = false;

    /** Whether this agent's tick raises after its write, with the transaction still open. */
    public bool $raisesAfterTheWrite = false;

    /** Whether the announcement the tick holds raises when the commit makes it. */
    public bool $announcementFails = false;

    /** Whether the tick ran to its last line. */
    public bool $reachedTheEndOfItsTick = false;

    /** Whether the announcement the tick held was made. */
    public bool $announced = false;

    /** How many times the loop ticked this agent. */
    public int $ticks = 0;

    /**
     * @param string $agentIndex Index telling this agent from its neighbour
     */
    public function __construct(string $agentIndex)
    {
        $this->agentIndex = $agentIndex;
    }

    /**
     * @throws HilosException When the transaction or the write fails
     * @throws RuntimeException When the case asks the tick to raise after its write
     */
    public function onTick(): void
    {
        $this->ticks++;

        if ($this->leavesTransactionOpen || $this->commitsTransaction) {
            WorkerTransactionLeftOpenIntegrationTest::openAndWrite($this->getId(), $this->announced, $this->announcementFails);
        }
        if ($this->raisesAfterTheWrite) {
            throw new RuntimeException('agent tick refused after its write');
        }
        if ($this->commitsTransaction) {
            Database::transactionCommit();
        }
        $this->reachedTheEndOfItsTick = true;
    }

    public function onStop(): void
    {
    }
}

/**
 * Daemon client stub: connected, silent, handing out scripted messages once.
 */
final class WorkerTransactionLeftOpenTestClient extends WorkerDaemonClient
{
    /**
     * @param list<WorkerDTO> $messages Messages to hand out, in order
     */
    public function __construct(private array $messages)
    {
        $this->state = DaemonConnectionState::CONNECTED;
    }

    public function isConnected(): bool
    {
        return true;
    }

    public function read(): void
    {
    }

    public function write(): void
    {
    }

    public function getNextMessage(): ?WorkerDTO
    {
        return array_shift($this->messages);
    }
}

/**
 * Daemon message that is nothing but its type.
 */
final class WorkerTransactionLeftOpenTestMessage extends WorkerDTO
{
    /**
     * @param string $type Type this message reports
     */
    public function __construct(private string $type)
    {
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return array{type: string} This message as the wire carries it
     */
    public function toArray(): array
    {
        return [self::TYPE => $this->type];
    }

    /**
     * @param array{type: string} $data Wire payload
     * @return static Message carrying the payload's type
     */
    public static function fromArray(array $data): static
    {
        return new static($data[self::TYPE]);
    }
}
