<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Auth\OAuth\Agent\AbstractOAuthAgent;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Hilos;
use Hilos\Users\Agent\AbstractUserAgent;
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
                [],
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
            [],
            TruthSourceOperation::Add,
        );
        RtTruthSourceRegistry::checkCanWriteState(
            AgentTruthSourceOperationsTestLibrary::RT_COLLECTION,
            '1',
            [],
            TruthSourceOperation::Remove,
        );

        $this->assertTrue(true);
    }

    /**
     * The library only creates people: an edit of one person is that person's agent's (HIL-1404).
     *
     * The claim is read off the class the way a start reads it, so no project subclass stands
     * between the base's declaration and the answer (HIL-1194). The rename journal is only read here;
     * the person's agent adds its row and writes its feed link.
     */
    public function testLibraryAgentOnlyCreatesThePersonRow(): void
    {
        $claims = OwnershipDeclaration::dbCollectionsOf(AgentTruthSourceOperationsTestLibrary::class);

        $this->assertArrayHasKey(HilosDbContext::users, $claims);
        $this->assertTrue($claims[HilosDbContext::users]->allows(TruthSourceOperation::Add));
        $this->assertFalse($claims[HilosDbContext::users]->allows(TruthSourceOperation::Update));
        $this->assertFalse($claims[HilosDbContext::users]->allows(TruthSourceOperation::Remove));
        $this->assertArrayNotHasKey(HilosDbContext::userRenames, $claims);
        $this->assertContains(HilosDbContext::userRenames, AgentTruthSourceOperationsTestLibrary::readDbCollections());
    }

    /**
     * The library only creates a way in or a passkey: every later edit is the person's agent's (HIL-1405).
     *
     * Read off the class the way a start reads it. The codes and the holds of a registration stay
     * the library's whole; since HIL-1411 only the sessions holder's borrowed Remove for account
     * erasure remains beside that full claim.
     */
    public function testLibraryAgentOnlyCreatesWaysInAndPasskeys(): void
    {
        $claims = OwnershipDeclaration::dbCollectionsOf(AgentTruthSourceOperationsTestLibrary::class);

        foreach ([HilosDbContext::identities, HilosDbContext::passkeyCredentials] as $collection) {
            $this->assertTrue($claims[$collection]->allows(TruthSourceOperation::Add), $collection);
            $this->assertFalse($claims[$collection]->allows(TruthSourceOperation::Update), $collection);
            $this->assertFalse($claims[$collection]->allows(TruthSourceOperation::Remove), $collection);
        }
        $this->assertTrue($claims[HilosDbContext::verifications]->allows(TruthSourceOperation::Update));
    }

    /**
     * The library adds the results of its own erasure steps and never changes one; their set is
     * keyed by its full id (HIL-1944).
     *
     * The step reads its assignment and that assignment's operation. The results are not among the
     * reads: the set claim is already their reader interest.
     */
    public function testLibraryAgentOnlyAddsItsErasureResults(): void
    {
        $claims = OwnershipDeclaration::dbSetCollectionsOf(AgentTruthSourceOperationsTestLibrary::class);

        $this->assertSame([TruthSourceOperation::Add], AgentTruthSourceOperationsTestLibrary::OWNS_DB_SET[HilosDbContext::accountOperationResults]);
        $this->assertTrue($claims[HilosDbContext::accountOperationResults]->allows(TruthSourceOperation::Add));
        $this->assertFalse($claims[HilosDbContext::accountOperationResults]->allows(TruthSourceOperation::Update));
        $this->assertFalse($claims[HilosDbContext::accountOperationResults]->allows(TruthSourceOperation::Remove));
        $this->assertSame(
            'unit_truth_source_operations_library',
            (new AgentTruthSourceOperationsTestLibrary())->ownedDbSetKey(HilosDbContext::accountOperationResults),
        );
        $reads = AgentTruthSourceOperationsTestLibrary::readDbCollections();
        $this->assertContains(HilosDbContext::accountOperationAssignments, $reads);
        $this->assertContains(HilosDbContext::accountOperations, $reads);
        $this->assertNotContains(HilosDbContext::accountOperationResults, $reads);
    }

    /** The personal owner creates and changes the factor; the library reads only. */
    public function testLibraryAgentReadsTheSecondFactorWithoutWriting(): void
    {
        $claims = OwnershipDeclaration::dbCollectionsOf(AgentTruthSourceOperationsTestLibrary::class);

        $this->assertArrayNotHasKey(HilosDbContext::secondFactors, $claims);
        $this->assertContains(HilosDbContext::secondFactors, AgentTruthSourceOperationsTestLibrary::READS_DB);
        $this->assertArrayNotHasKey(HilosDbContext::secondFactorBackupCodes, $claims);
        $this->assertContains(HilosDbContext::secondFactorBackupCodes, AgentTruthSourceOperationsTestLibrary::READS_DB);
        $this->assertArrayNotHasKey(HilosDbContext::secondFactorResets, $claims);
        $this->assertContains(HilosDbContext::secondFactorResets, AgentTruthSourceOperationsTestLibrary::READS_DB);
        $this->assertArrayNotHasKey(HilosDbContext::secondFactorSettings, $claims);
        $this->assertContains(HilosDbContext::secondFactorSettings, AgentTruthSourceOperationsTestLibrary::READS_DB);
    }

    /** OAuth reads the live session and reservation; its final Add belongs to the personal owner. */
    public function testOAuthAgentReadsWithoutAnIdentityWriteClaim(): void
    {
        $this->assertArrayNotHasKey(HilosDbContext::identities, OwnershipDeclaration::dbCollectionsOf(AbstractOAuthAgent::class));
        $this->assertSame([
            HilosDbContext::sessions,
            HilosDbContext::accountOperations,
            HilosDbContext::accountOperationAssignments,
            HilosDbContext::accountOperationResults,
        ], AbstractOAuthAgent::READS_DB);
    }

    /** The empty project subclass keeps only the holder's own whole-collection writes. */
    public function testSessionsLibraryKeepsOnlyItsOwnOperationWrites(): void
    {
        $claims = OwnershipDeclaration::dbCollectionsOf(AgentTruthSourceOperationsTestSessionsLibrary::class);

        $this->assertSame([
            HilosDbContext::sessions,
            HilosDbContext::accountOperations,
            HilosDbContext::accountOperationAssignments,
            HilosDbContext::accessLogEntries,
        ], array_keys($claims));
        $this->assertArrayNotHasKey(HilosDbContext::users, $claims);
        $this->assertArrayNotHasKey(HilosDbContext::userRenames, $claims);
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
            [],
            TruthSourceOperation::Update,
        );
    }

    /** The private copy grants its source Add and Remove under the full agent address, never Update. */
    public function testPersonalCopyClaimAddsAndRemovesUsingTheFullAgentAddress(): void
    {
        $claims = OwnershipDeclaration::dbSetCollectionsOf(CopyOperationsTestPerson::class);
        $person = new CopyOperationsTestPerson('7');
        self::assertSame([TruthSourceOperation::Add, TruthSourceOperation::Remove],
            CopyOperationsTestPerson::OWNS_DB_SET[HilosDbContext::accountMergeCopies]);
        self::assertTrue($claims[HilosDbContext::accountMergeCopies]->allows(TruthSourceOperation::Add));
        self::assertFalse($claims[HilosDbContext::accountMergeCopies]->allows(TruthSourceOperation::Update));
        self::assertTrue($claims[HilosDbContext::accountMergeCopies]->allows(TruthSourceOperation::Remove));
        self::assertSame($person->getId(), $person->ownedDbSetKey(HilosDbContext::accountMergeCopies));
        self::assertSame([TruthSourceOperation::Add, TruthSourceOperation::Update],
            CopyOperationsTestPerson::OWNS_DB_SET[HilosDbContext::accountMergeFences]);
        self::assertFalse($claims[HilosDbContext::accountMergeFences]->allows(TruthSourceOperation::Remove));
        self::assertSame('7', $person->ownedDbSetKey(HilosDbContext::accountMergeFences));
        self::assertSame([TruthSourceOperation::Add, TruthSourceOperation::Remove],
            CopyOperationsTestPerson::OWNS_DB_SET[HilosDbContext::userMerges]);
        foreach ([HilosDbContext::identities, HilosDbContext::passkeyCredentials, HilosDbContext::secondFactors,
            HilosDbContext::secondFactorBackupCodes] as $collection) {
            self::assertSame(TruthSourceOperation::ALL, CopyOperationsTestPerson::OWNS_DB_SET[$collection]);
        }
    }

    public function testPersonalMergeClaimAddsAndRemovesWithoutEditingExistingRows(): void
    {
        $claims = OwnershipDeclaration::dbSetCollectionsOf(CopyOperationsTestPerson::class);
        self::assertSame([TruthSourceOperation::Add, TruthSourceOperation::Remove],
            CopyOperationsTestPerson::OWNS_DB_SET[HilosDbContext::userMerges]);
        self::assertTrue($claims[HilosDbContext::userMerges]->allows(TruthSourceOperation::Add));
        self::assertTrue($claims[HilosDbContext::userMerges]->allows(TruthSourceOperation::Remove));
        self::assertFalse($claims[HilosDbContext::userMerges]->allows(TruthSourceOperation::Update));
        self::assertSame('7', new CopyOperationsTestPerson('7')->ownedDbSetKey(HilosDbContext::userMerges));
    }

    /** The source may remove its own copy; own Update and all foreign mutations remain refused. */
    public function testCopySetGrantRefusesForeignSetsAndOwnUpdates(): void
    {
        $previousDb = Hilos::$db;
        Hilos::$db = new CopyOperationsTestDbContext();
        Hilos::$db->configure();
        $person = new CopyOperationsTestPerson('7');
        try {
            OwnershipDeclaration::claimDbSet($person);
            ExecutionContext::setCurrentAgentId($person->getId());
            TruthSourceRegistry::checkCanCreate(HilosDbContext::accountMergeCopies, static fn (): array => [$person->getId()]);
            TruthSourceRegistry::checkCanWriteItem(
                HilosDbContext::accountMergeCopies, '19', static fn (): array => [$person->getId()], TruthSourceOperation::Remove,
            );
            try {
                TruthSourceRegistry::checkCanWriteItem(
                    HilosDbContext::accountMergeCopies, '19', static fn (): array => [$person->getId()], TruthSourceOperation::Update,
                );
                self::fail('A personal copy claim updated an existing row');
            } catch (WriteNotAllowedException) {
                self::assertTrue(true);
            }
            foreach ([$person->getIndex(), new CopyOperationsTestPerson('8')->getId()] as $foreign) {
                foreach ([TruthSourceOperation::Update, TruthSourceOperation::Remove] as $operation) {
                    try {
                        TruthSourceRegistry::checkCanWriteItem(
                            HilosDbContext::accountMergeCopies, '19', static fn (): array => [$foreign], $operation,
                        );
                        self::fail('A copy claim changed a foreign or person-only set');
                    } catch (WriteNotAllowedException) {
                        self::assertTrue(true);
                    }
                }
                try {
                    TruthSourceRegistry::checkCanCreate(HilosDbContext::accountMergeCopies, static fn (): array => [$foreign]);
                    self::fail('A foreign or person-only copy set was accepted');
                } catch (CreateNotAllowedException) {
                    self::assertTrue(true);
                }
            }
            ExecutionContext::setCurrentAgentId('foreign-copy-writer');
            $this->expectException(CreateNotAllowedException::class);
            TruthSourceRegistry::checkCanCreate(HilosDbContext::accountMergeCopies, static fn (): array => [$person->getId()]);
        } finally {
            TruthSourceRegistry::unregisterAgent($person->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($person->getId()));
            Hilos::$db = $previousDb;
        }
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
 * The framework's library base class under a test name, and nothing else.
 *
 * Subclassed rather than imitated on purpose: what is under test is the default the base
 * class declares, and a copy of that default in a test agent would prove only the copy. It
 * overrides no method either, which proves the base has no seam left a project must fill in.
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
}

/**
 * The framework's sessions library base class under a test name, and nothing else - so its
 * claims are the base's declaration and no project's.
 */
final class AgentTruthSourceOperationsTestSessionsLibrary extends AbstractSessionsLibraryAgent
{
    public const string AGENT_TYPE = 'unit_truth_source_operations_sessions_library';
}

/** Uses the production personal claims without lifecycle work. */
final class CopyOperationsTestPerson extends AbstractUserAgent
{
}

final class CopyOperationsTestDbContext extends HilosDbContext
{
}
