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
use Hilos\Hilos;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
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

    protected function setUp(): void
    {
        $this->boundAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, BorrowedClaimReadinessTestHilos::class);
    }

    protected function tearDown(): void
    {
        foreach ([BorrowedClaimReadinessTestBorrower::AGENT_TYPE, BorrowedClaimReadinessTestOwner::AGENT_TYPE] as $agentId) {
            TruthSourceRegistry::unregisterAgent($agentId);
            RtTruthSourceRegistry::unregisterAgent($agentId);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
        }
        SourceInterestRegistry::readsWhatItMounts();
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

/**
 * Project facade standing in for a real one: it registers the borrower and nothing else, which is
 * what lets the start read the declaration off its class.
 */
abstract class BorrowedClaimReadinessTestHilos extends Hilos
{
    public const array AGENTS = [
        BorrowedClaimReadinessTestBorrower::AGENT_TYPE => [AgentRegistryKey::WORKER => BorrowedClaimReadinessTestBorrower::class],
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
     */
    public function __construct(private readonly BorrowedClaimReadinessTestAgentManager $testAgentManager)
    {
        parent::__construct(1);
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
     * @return AgentInterface A fresh borrower
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        $this->askedToCreate = true;

        return new BorrowedClaimReadinessTestBorrower();
    }
}
