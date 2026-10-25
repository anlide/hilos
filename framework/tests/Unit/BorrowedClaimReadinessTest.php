<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Exception\AgentCreationFailedException;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Socket\Worker\DTO\WorkerDbInterestReadyMessageDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A claim whose holder may not add is not a held copy (HIL-989).
 *
 * A writer that brings rows into being holds the copy of what it writes, so its claim is ready the
 * moment it is laid down. A holder that only edits rows somebody else wrote holds nothing of them:
 * its readiness has to arrive the way a reader's does, and the start waits for it beside the reads
 * the class declares. These cases pin both halves of that, the refusal when the state never comes,
 * and the one place the rule must stay silent - a process that is the origin of its own state.
 */
final class BorrowedClaimReadinessTest extends TestCase
{
    /** @var class-string<Hilos> App class bound before this test touched it */
    private string $boundAppClass;

    private ?SignalRouter $previousRouter;
    private ?HilosDbContext $previousDb;

    protected function setUp(): void
    {
        $this->boundAppClass = Hilos::appClass();
        $this->previousRouter = Hilos::$sr;
        $this->previousDb = Hilos::$db;
        Hilos::$db = null;
        Hilos::$sr = new SignalRouter();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, BorrowedClaimReadinessTestHilos::class);
    }

    protected function tearDown(): void
    {
        foreach ([
            BorrowedClaimReadinessTestBorrower::AGENT_TYPE,
            BorrowedClaimReadinessTestOwner::AGENT_TYPE,
            BorrowedClaimReadinessTestReader::AGENT_TYPE,
            BorrowedClaimReadinessTestComputedReader::AGENT_TYPE,
        ] as $agentId) {
            TruthSourceRegistry::unregisterAgent($agentId);
            RtTruthSourceRegistry::unregisterAgent($agentId);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
        }
        SourceInterestRegistry::readsWhatItMounts();
        Hilos::$sr = $this->previousRouter;
        Hilos::$db = $this->previousDb;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->boundAppClass);

        parent::tearDown();
    }

    /**
     * The right to write stands at once - only the readiness is withheld, because the rows the
     * holder edits are on their way here rather than in its hands.
     */
    public function testAClaimWithoutAddRaisesAnInterestThatIsNotReady(): void
    {
        SourceInterestRegistry::readsWhatIsDelivered();

        OwnershipDeclaration::claimDb(BorrowedClaimReadinessTestBorrower::class, BorrowedClaimReadinessTestBorrower::AGENT_TYPE);
        OwnershipDeclaration::claimRt(BorrowedClaimReadinessTestBorrower::class, BorrowedClaimReadinessTestBorrower::AGENT_TYPE);

        $this->assertTrue(TruthSourceRegistry::hasTruthSource(BorrowedClaimReadinessTestBorrower::DB_COLLECTION));
        $this->assertTrue(RtTruthSourceRegistry::hasTruthSource(BorrowedClaimReadinessTestBorrower::RT_COLLECTION));
        $this->assertTrue(SourceInterestRegistry::isDeclared(
            SourceChange::KIND_DB,
            BorrowedClaimReadinessTestBorrower::DB_COLLECTION,
        ));
        $this->assertFalse(SourceInterestRegistry::isReady(
            SourceChange::KIND_DB,
            BorrowedClaimReadinessTestBorrower::DB_COLLECTION,
        ));
        $this->assertTrue(SourceInterestRegistry::isDeclared(
            SourceChange::KIND_RT,
            BorrowedClaimReadinessTestBorrower::RT_COLLECTION,
        ));
        $this->assertFalse(SourceInterestRegistry::isReady(
            SourceChange::KIND_RT,
            BorrowedClaimReadinessTestBorrower::RT_COLLECTION,
        ));
    }

    /**
     * The owner that may add is ready at once, as it always was - and it is judged on the FOLDED
     * operations: the heir below gives its parent's borrowed record the right to add, and that
     * makes it an owner of what the parent only borrowed.
     */
    public function testAClaimThatMayAddIsReadyAtOnce(): void
    {
        SourceInterestRegistry::readsWhatIsDelivered();

        OwnershipDeclaration::claimDb(BorrowedClaimReadinessTestOwner::class, BorrowedClaimReadinessTestOwner::AGENT_TYPE);
        OwnershipDeclaration::claimRt(BorrowedClaimReadinessTestOwner::class, BorrowedClaimReadinessTestOwner::AGENT_TYPE);

        $this->assertSame([], OwnershipDeclaration::borrowedDbCollectionsOf(BorrowedClaimReadinessTestOwner::class));
        $this->assertSame([], OwnershipDeclaration::borrowedRtCollectionsOf(BorrowedClaimReadinessTestOwner::class));
        $this->assertTrue(SourceInterestRegistry::isReady(
            SourceChange::KIND_DB,
            BorrowedClaimReadinessTestOwner::DB_COLLECTION,
        ));
        $this->assertTrue(SourceInterestRegistry::isReady(
            SourceChange::KIND_RT,
            BorrowedClaimReadinessTestOwner::RT_COLLECTION,
        ));
    }

    /**
     * The class reads nothing, so the only thing this start can be waiting for is its borrowed
     * claims - and with no master to answer, the wait ends on a refusal that leaves nothing
     * behind: no instance, and no interest asking for frames on behalf of an agent that never ran.
     */
    public function testAStartWaitsForABorrowedClaimAndRefusesWhenItDoesNotArrive(): void
    {
        SourceInterestRegistry::readsWhatIsDelivered();
        $agentManager = new BorrowedClaimReadinessTestAgentManager();
        $manager = new BorrowedClaimReadinessTestManager($agentManager);

        try {
            $manager->handleDaemonMessage(new AgentStartDTO(BorrowedClaimReadinessTestBorrower::AGENT_TYPE));
            $this->fail('Expected a start whose borrowed state never arrived to be refused.');
        } catch (AgentCreationFailedException) {
        }

        $this->assertFalse($agentManager->askedToCreate);
        $this->assertFalse(SourceInterestRegistry::isDeclared(
            SourceChange::KIND_DB,
            BorrowedClaimReadinessTestBorrower::DB_COLLECTION,
        ));
        $this->assertFalse(SourceInterestRegistry::isDeclared(
            SourceChange::KIND_RT,
            BorrowedClaimReadinessTestBorrower::RT_COLLECTION,
        ));
    }

    /**
     * @param string $agentType Reader to start
     * @param list<string> $expectedReads Full DB interest before creation and until stop
     */
    #[DataProvider('dbReaders')]
    public function testDbReadsWaitBeforeCreationAndAreReleasedOnStop(string $agentType, array $expectedReads): void
    {
        SourceInterestRegistry::readsWhatIsDelivered();
        $agentManager = new BorrowedClaimReadinessTestAgentManager();
        $manager = new BorrowedClaimReadinessTestManager($agentManager, withDaemonLink: true);
        $consumer = SourceConsumer::agent($agentType);

        $manager->handleDaemonMessage(new AgentStartDTO($agentType));

        $this->assertFalse($agentManager->askedToCreate);
        $this->assertSame($expectedReads, SourceInterestRegistry::collectionsOfConsumer($consumer, SourceChange::KIND_DB));
        foreach ($expectedReads as $collection) {
            $this->assertFalse(SourceInterestRegistry::isReady(SourceChange::KIND_DB, $collection));
            $manager->pass();
            $this->assertFalse($agentManager->askedToCreate, 'Every declared read must be ready before creation.');
            $manager->handleDaemonMessage(new WorkerDbInterestReadyMessageDTO($collection));
        }

        $manager->pass();

        $this->assertTrue($agentManager->askedToCreate);
        $this->assertTrue($agentManager->hasAgent($agentType));
        $agent = $agentManager->getAgent($agentType);
        $this->assertInstanceOf(BorrowedClaimReadinessTestReader::class, $agent);
        $this->assertTrue($agent->started);
        $this->assertSame($expectedReads, SourceInterestRegistry::collectionsOfConsumer($consumer, SourceChange::KIND_DB));

        $manager->handleDaemonMessage(new AgentStopDTO($agentType));

        $this->assertFalse($agentManager->hasAgent($agentType));
        $this->assertSame([], SourceInterestRegistry::collectionsOfConsumer($consumer, SourceChange::KIND_DB));
        foreach ($expectedReads as $collection) {
            $this->assertFalse(SourceInterestRegistry::isDeclared(SourceChange::KIND_DB, $collection));
        }
    }

    /**
     * @param string $agentType Reader whose state cannot arrive
     * @param list<string> $expectedReads DB interests the refused start must release
     */
    #[DataProvider('dbReaders')]
    public function testARefusedStartReleasesComputedAndConstantReads(string $agentType, array $expectedReads): void
    {
        SourceInterestRegistry::readsWhatIsDelivered();
        $agentManager = new BorrowedClaimReadinessTestAgentManager();
        $manager = new BorrowedClaimReadinessTestManager($agentManager);

        try {
            $manager->handleDaemonMessage(new AgentStartDTO($agentType));
            $this->fail('Expected a start whose DB reads never arrived to be refused.');
        } catch (AgentCreationFailedException) {
        }

        $this->assertFalse($agentManager->askedToCreate);
        $this->assertSame([], SourceInterestRegistry::collectionsOfConsumer(SourceConsumer::agent($agentType), SourceChange::KIND_DB));
        foreach ($expectedReads as $collection) {
            $this->assertFalse(SourceInterestRegistry::isDeclared(SourceChange::KIND_DB, $collection));
        }
    }

    /** @return array<string, array{string, list<string>}> Constant-only and computed DB declarations */
    public static function dbReaders(): array
    {
        return [
            'constant' => [BorrowedClaimReadinessTestReader::AGENT_TYPE, ['unit_constant_read_db']],
            'computed' => [
                BorrowedClaimReadinessTestComputedReader::AGENT_TYPE,
                ['unit_constant_read_db', 'unit_computed_read_db'],
            ],
        ];
    }

    /**
     * Where nothing is delivered - a CLI command, an integration harness claiming by hand - the
     * process is the origin of what it reads, so the claim that is no longer marked ready is read
     * all the same.
     */
    public function testOutsideAWorkerABorrowedClaimReadsAtOnce(): void
    {
        OwnershipDeclaration::claimDb(BorrowedClaimReadinessTestBorrower::class, BorrowedClaimReadinessTestBorrower::AGENT_TYPE);
        OwnershipDeclaration::claimRt(BorrowedClaimReadinessTestBorrower::class, BorrowedClaimReadinessTestBorrower::AGENT_TYPE);

        $this->assertTrue(SourceInterestRegistry::isReady(
            SourceChange::KIND_DB,
            BorrowedClaimReadinessTestBorrower::DB_COLLECTION,
        ));
        $this->assertTrue(SourceInterestRegistry::isReady(
            SourceChange::KIND_RT,
            BorrowedClaimReadinessTestBorrower::RT_COLLECTION,
        ));
    }
}

/**
 * An agent that edits the rows of one collection of each half and brings none of them into being.
 */
class BorrowedClaimReadinessTestBorrower extends AbstractAgent
{
    /** @var array<string, list<TruthSourceOperation>> The database collection it edits and never adds to */
    public const array OWNS_DB = [self::DB_COLLECTION => [TruthSourceOperation::Update]];

    /** @var array<string, list<TruthSourceOperation>> The runtime collection it edits and never adds to */
    public const array OWNS_RT = [self::RT_COLLECTION => [TruthSourceOperation::Update]];

    public const string AGENT_TYPE = 'unit_borrowed_claim';
    public const string DB_COLLECTION = 'unit_borrowed_claim_db';
    public const string RT_COLLECTION = 'unit_borrowed_claim_rt';

    /**
     * Claims nothing here: the declarations above are the claims.
     */
    public function onStart(): void
    {
    }

    /**
     * Holds nothing across a stop.
     */
    public function onStop(): void
    {
    }
}

/**
 * The heir of the borrower that may add to both collections, and so owns them.
 */
final class BorrowedClaimReadinessTestOwner extends BorrowedClaimReadinessTestBorrower
{
    /** @var array<string, list<TruthSourceOperation>> The right to add, folded into the parent's record */
    public const array OWNS_DB = [self::DB_COLLECTION => [TruthSourceOperation::Add]];

    /** @var array<string, list<TruthSourceOperation>> The right to add, folded into the parent's record */
    public const array OWNS_RT = [self::RT_COLLECTION => [TruthSourceOperation::Add]];

    public const string AGENT_TYPE = 'unit_borrowed_claim_owner';
}

/** Constant-only readers keep their original pre-start readiness contract. */
class BorrowedClaimReadinessTestReader extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_constant_reader';
    public const array READS_DB = ['unit_constant_read_db'];

    public bool $started = false;

    /** Records that the start hook ran after readiness. */
    public function onStart(): void
    {
        $this->started = true;
    }

    /** Holds no resources after a stop. */
    public function onStop(): void
    {
    }
}

final class BorrowedClaimReadinessTestComputedReader extends BorrowedClaimReadinessTestReader
{
    public const string AGENT_TYPE = 'unit_computed_reader';

    /** @return list<string> Parent reads plus a key absent from READS_DB */
    public static function readDbCollections(): array
    {
        return [...parent::readDbCollections(), 'unit_computed_read_db'];
    }
}

/**
 * Registers the borrower and both DB readers so starts resolve their declarations off the class.
 */
abstract class BorrowedClaimReadinessTestHilos extends Hilos
{
    public const array AGENTS = [
        BorrowedClaimReadinessTestBorrower::AGENT_TYPE => [AgentRegistryKey::WORKER => BorrowedClaimReadinessTestBorrower::class],
        BorrowedClaimReadinessTestReader::AGENT_TYPE => [AgentRegistryKey::WORKER => BorrowedClaimReadinessTestReader::class],
        BorrowedClaimReadinessTestComputedReader::AGENT_TYPE => [AgentRegistryKey::WORKER => BorrowedClaimReadinessTestComputedReader::class],
    ];
}

/**
 * Worker manager standing in for a real one: it opens no connection, so nothing ever answers the
 * interest a start raises.
 */
final class BorrowedClaimReadinessTestManager extends WorkerManager
{
    /**
     * @param BorrowedClaimReadinessTestAgentManager $testAgentManager Agent manager recording whether it was asked to create
     * @param bool $withDaemonLink Whether the source wait can park the start
     */
    public function __construct(
        private readonly BorrowedClaimReadinessTestAgentManager $testAgentManager,
        bool $withDaemonLink = false,
    ) {
        parent::__construct(1);
        if ($withDaemonLink) {
            $this->daemonClient = new WorkerDaemonClient();
        }
    }

    /** Releases starts whose DB interests are now ready. */
    public function pass(): void
    {
        $this->releaseParkedFrames(microtime(true));
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManager(): AgentManager
    {
        return $this->testAgentManager;
    }
}

/**
 * Agent manager standing in for a real one: it builds the borrower when asked, and remembers that it was.
 */
final class BorrowedClaimReadinessTestAgentManager extends AgentManager
{
    /** Whether a start got as far as building the instance. */
    public bool $askedToCreate = false;

    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentInterface A fresh borrower or reader
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        $this->askedToCreate = true;

        return match ($agentType) {
            BorrowedClaimReadinessTestReader::AGENT_TYPE => new BorrowedClaimReadinessTestReader(),
            BorrowedClaimReadinessTestComputedReader::AGENT_TYPE => new BorrowedClaimReadinessTestComputedReader(),
            default => new BorrowedClaimReadinessTestBorrower(),
        };
    }
}
