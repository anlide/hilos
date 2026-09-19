<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Tests\Integration\AuthThrottleIntegrationTest;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the operations an agent's own claims carry.
 */
final class AgentTruthSourceOperationsTest extends TestCase
{
    public function tearDown(): void
    {
        ExecutionContext::setCurrentAgentId(null);
        RtTruthSourceRegistry::unregisterAgent(AgentTruthSourceOperationsTestAgent::AGENT_TYPE);
        TruthSourceRegistry::unregisterAgent(AgentTruthSourceOperationsTestAgent::AGENT_TYPE);
        RtTruthSourceRegistry::unregisterAgent(AgentTruthSourceOperationsTestLibrary::AGENT_TYPE);
        // A claim is a reader interest too, so it is given back here beside the claim itself.
        SourceInterestRegistry::readsWhatItMounts();
        SourceInterestRegistry::releaseConsumer(
            SourceConsumer::agent(AgentTruthSourceOperationsTestAgent::AGENT_TYPE),
        );
        SourceInterestRegistry::releaseConsumer(
            SourceConsumer::agent(AgentTruthSourceOperationsTestLibrary::AGENT_TYPE),
        );

        parent::tearDown();
    }

    /**
     * A writer may read what it writes the moment it has claimed it, with nothing to wait for.
     *
     * The case a live run found (HIL-717): an agent publishing its first row inside onStart()
     * reads the collection before the worker has told the master anything, so an interest raised
     * at that report would come too late and the agent would be refused its own collection. What
     * makes the claim enough is that the writer IS the state - there is no copy travelling to it.
     */
    public function testAClaimedCollectionIsReadableAtOnce(): void
    {
        SourceInterestRegistry::readsWhatIsDelivered();
        $agent = new AgentTruthSourceOperationsTestAgent();

        $this->startAgent($agent);

        $this->assertTrue(SourceInterestRegistry::isReady(
            SourceChange::KIND_RT,
            AgentTruthSourceOperationsTestAgent::RT_COLLECTION,
        ));
    }

    /**
     * The same for a claim over a database collection (HIL-750), and by a different argument: the
     * rows are not travelling to this process, they are in the shared database - but the copy it
     * caches lives only under an interest, so the claim is what makes the cache real and there is
     * nothing after it to wait for.
     */
    public function testAClaimedDatabaseCollectionIsReadableAtOnce(): void
    {
        SourceInterestRegistry::readsWhatIsDelivered();
        $agent = new AgentTruthSourceOperationsTestAgent();

        $this->startAgent($agent);

        $this->assertTrue(SourceInterestRegistry::isReady(
            SourceChange::KIND_DB,
            AgentTruthSourceOperationsTestAgent::DB_COLLECTION,
        ));
    }

    public function testOrdinaryAgentClaimsEveryOperation(): void
    {
        $agent = new AgentTruthSourceOperationsTestAgent();
        $this->startAgent($agent);
        ExecutionContext::setCurrentAgentId($agent->getId());

        foreach (TruthSourceOperation::ALL as $operation) {
            RtTruthSourceRegistry::checkCanWriteState(
                AgentTruthSourceOperationsTestAgent::RT_COLLECTION,
                '1',
                $operation,
            );
        }

        $this->assertTrue(true);
    }

    public function testLibraryAgentMayBringARowAndTakeItAway(): void
    {
        $agent = new AgentTruthSourceOperationsTestLibrary();
        $this->startLibrary($agent);
        ExecutionContext::setCurrentAgentId($agent->getId());

        RtTruthSourceRegistry::checkCanWriteState(
            AgentTruthSourceOperationsTestLibrary::RT_COLLECTION,
            '1',
            TruthSourceOperation::Add,
        );
        RtTruthSourceRegistry::checkCanWriteState(
            AgentTruthSourceOperationsTestLibrary::RT_COLLECTION,
            '1',
            TruthSourceOperation::Remove,
        );

        $this->assertTrue(true);
    }

    public function testLibraryAgentMayNotEditWhatIsAlreadyWritten(): void
    {
        $agent = new AgentTruthSourceOperationsTestLibrary();
        $this->startLibrary($agent);
        ExecutionContext::setCurrentAgentId($agent->getId());

        $this->expectExceptionMessage(
            "with operations [add, remove] and may not update state '1'."
        );
        RtTruthSourceRegistry::checkCanWriteState(
            AgentTruthSourceOperationsTestLibrary::RT_COLLECTION,
            '1',
            TruthSourceOperation::Update,
        );
    }

    /**
     * Lays the claims of an ordinary agent down and starts it, the way a worker would.
     *
     * An agent built here is never started through {@see WorkerManager}, and a declaration on its
     * own reaches nothing: the resolver runs on the worker's start path, not in the constructor.
     * So the claims are laid by hand first, exactly as every harness that builds an agent itself
     * does ({@see AuthThrottleIntegrationTest}).
     *
     * @param AgentTruthSourceOperationsTestAgent $agent Agent to claim for and start
     */
    private function startAgent(AgentTruthSourceOperationsTestAgent $agent): void
    {
        OwnershipDeclaration::claimDb($agent::class, $agent->getId());
        OwnershipDeclaration::claimRt($agent::class, $agent->getId());
        $agent->onStart();
    }

    /**
     * The same for the library double, whose runtime claim alone is what these cases ask about.
     *
     * Its database half is deliberately not laid down: the declaration merges upwards, so
     * {@see AbstractUsersLibraryAgent}'s own OWNS_DB - identities, verifications, sessions - would
     * be claimed here under a test agent id, over collections no case in this file touches.
     *
     * @param AgentTruthSourceOperationsTestLibrary $agent Library agent to claim for and start
     */
    private function startLibrary(AgentTruthSourceOperationsTestLibrary $agent): void
    {
        OwnershipDeclaration::claimRt($agent::class, $agent->getId());
        $agent->onStart();
    }
}

/**
 * An agent that says nothing about operations, so it holds all of them.
 */
final class AgentTruthSourceOperationsTestAgent extends AbstractAgent
{
    /** @var array<string, list<TruthSourceOperation>> The one database collection this test asks about */
    public const array OWNS_DB = [self::DB_COLLECTION => TruthSourceOperation::BY_KIND];

    /** @var array<string, list<TruthSourceOperation>> The one runtime collection this test asks about */
    public const array OWNS_RT = [self::RT_COLLECTION => TruthSourceOperation::BY_KIND];

    public const string AGENT_TYPE = 'unit_truth_source_operations';
    public const string RT_COLLECTION = 'unit_truth_source_operations_rt';
    public const string DB_COLLECTION = 'unit_truth_source_operations_db';

    /**
     * Holds nothing across a stop.
     */
    public function onStop(): void
    {
    }
}

/**
 * The framework's library base class, made concrete with the seams a project fills in.
 *
 * Subclassed rather than imitated on purpose: what is under test is the default the base
 * class declares, and a copy of that default in a test agent would prove only the copy.
 */
final class AgentTruthSourceOperationsTestLibrary extends AbstractUsersLibraryAgent
{
    /**
     * @var array<string, list<TruthSourceOperation>> The one runtime collection this test asks about
     *
     * {@see TruthSourceOperation::BY_KIND} and not a set written out here, so that the claim
     * carries the base class's own default: the resolver asks
     * {@see AbstractUsersLibraryAgent::defaultTruthSourceOperations()} of the class that is
     * starting, which is the answer under test.
     */
    public const array OWNS_RT = [self::RT_COLLECTION => TruthSourceOperation::BY_KIND];

    public const string AGENT_TYPE = 'unit_truth_source_operations_library';
    public const string RT_COLLECTION = 'unit_truth_source_operations_library_rt';

    /**
     * @param string $displayName Name the account would be minted under
     * @return int Never returned: no test drives account creation
     */
    public function createUser(string $displayName): int
    {
        return 0;
    }

    /**
     * @param int $userId Account to name
     * @return ?string Always null: no test drives account lookup
     */
    public function displayNameOf(int $userId): ?string
    {
        return null;
    }
}
