<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\AgentConstants;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\DTO\AgentMessageDTOInterface;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Socket\Worker\DTO\WorkerRtSourceRegisteredDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSourceReleasedDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\TruthSource\RtNodeSourceMap;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * How the daemon master learns what its own node owns (HIL-586).
 *
 * The truth-source registry lives in the worker beside the agent that registered in it, and
 * the master is where a frame from another node is judged — two processes, so the answer has
 * to travel rather than be read. These cases pin the three parts of that trip: what the worker
 * reads out of its registry, what the message carries, and what the master remembers.
 *
 * The map is what turns a silent split into a line in the log, so an entry that outlives its
 * agent is not a leak but a wrong answer: the node would refuse replicas for a collection
 * nobody here owns any more.
 */
final class RtNodeSourceMapTest extends TestCase
{
    /** @var string Agent the cases register under */
    private const string AGENT = 'unit_rt_node_source:1';

    /** @var string Second agent, for the cases about two owners on one node */
    private const string OTHER_AGENT = 'unit_rt_node_source:2';

    /** @var string Collection the agent above owns */
    private const string COLLECTION = 'unitRtNodeSourceRows';

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterAgent(self::AGENT);
        RtTruthSourceRegistry::unregisterAgent(self::OTHER_AGENT);

        parent::tearDown();
    }

    public function testTheRegistryNamesTheCollectionsOneAgentTook(): void
    {
        RtTruthSourceRegistry::register(self::COLLECTION, true, self::AGENT);
        RtTruthSourceRegistry::register('unitRtNodeSourceOther', ['1'], self::AGENT);
        RtTruthSourceRegistry::register('unitRtNodeSourceThird', true, self::OTHER_AGENT);

        $this->assertSame(
            [self::COLLECTION, 'unitRtNodeSourceOther'],
            RtTruthSourceRegistry::collectionsOf(self::AGENT),
        );
    }

    /**
     * Key-scoped ownership counts as owning the collection: the question the map asks is whether
     * anything on this node writes it, and three keys of it are enough for the answer to be yes.
     */
    public function testAKeyScopedRegistrationStillNamesItsCollection(): void
    {
        RtTruthSourceRegistry::register(self::COLLECTION, ['7'], self::AGENT);

        $this->assertSame([self::COLLECTION], RtTruthSourceRegistry::collectionsOf(self::AGENT));
    }

    public function testTheRegistryNamesNothingOnceTheAgentIsUnregistered(): void
    {
        RtTruthSourceRegistry::register(self::COLLECTION, true, self::AGENT);
        RtTruthSourceRegistry::unregisterAgent(self::AGENT);

        $this->assertSame([], RtTruthSourceRegistry::collectionsOf(self::AGENT));
    }

    public function testTheMapAnswersForWhatAnAgentReported(): void
    {
        $map = new RtNodeSourceMap();

        $map->note(self::AGENT, [self::COLLECTION]);

        $this->assertTrue($map->owns(self::COLLECTION));
        $this->assertFalse($map->owns('unitRtNodeSourceOther'));
        $this->assertSame([self::COLLECTION], $map->collections());
    }

    public function testTheMapForgetsAnAgentThatStopped(): void
    {
        $map = new RtNodeSourceMap();
        $map->note(self::AGENT, [self::COLLECTION]);

        $map->release(self::AGENT);

        $this->assertFalse($map->owns(self::COLLECTION), 'A stopped agent leaves nothing behind to refuse replicas');
        $this->assertSame([], $map->collections());
    }

    /**
     * Two agents of one node may own the same collection between them — keys of it, say — and
     * one of them stopping does not make the node stop owning it.
     */
    public function testACollectionStaysOwnedWhileAnotherAgentHereTooOwnsIt(): void
    {
        $map = new RtNodeSourceMap();
        $map->note(self::AGENT, [self::COLLECTION]);
        $map->note(self::OTHER_AGENT, [self::COLLECTION]);

        $map->release(self::AGENT);

        $this->assertTrue($map->owns(self::COLLECTION));
        $this->assertSame([self::COLLECTION], $map->collections(), 'The collection is named once, not per owner');
    }

    /**
     * A re-report replaces rather than adds: the worker sends what the agent owns now, so a
     * collection missing from the new list is one it does not own any more.
     */
    public function testAFreshReportReplacesTheOneBeforeIt(): void
    {
        $map = new RtNodeSourceMap();
        $map->note(self::AGENT, [self::COLLECTION]);

        $map->note(self::AGENT, ['unitRtNodeSourceOther']);

        $this->assertFalse($map->owns(self::COLLECTION));
        $this->assertSame(['unitRtNodeSourceOther'], $map->collections());
    }

    /**
     * @throws InvalidFormatException When the frame is not the object its DTO needs
     */
    public function testTheReportRoundTripsToTheMaster(): void
    {
        $dto = new WorkerRtSourceRegisteredDTO(self::AGENT, [self::COLLECTION]);

        $parsed = WorkerDTO::factoryWorkerDTO($dto->toJson());

        $this->assertInstanceOf(WorkerRtSourceRegisteredDTO::class, $parsed);
        $this->assertSame(self::AGENT, $parsed->agentId);
        $this->assertSame([self::COLLECTION], $parsed->collectionKeys);
    }

    /**
     * @throws InvalidFormatException When the frame is not the object its DTO needs
     */
    public function testTheReleaseRoundTripsToTheMaster(): void
    {
        $dto = new WorkerRtSourceReleasedDTO(self::AGENT);

        $parsed = WorkerDTO::factoryWorkerDTO($dto->toJson());

        $this->assertInstanceOf(WorkerRtSourceReleasedDTO::class, $parsed);
        $this->assertSame(self::AGENT, $parsed->agentId);
    }

    /**
     * A worker that dies never sends its releases, so the daemon gives them back on its behalf.
     * An ownership claim outliving the process that held it is worse than none: this node would
     * go on refusing replicas for a collection nobody here writes any more.
     */
    public function testTheAgentsOfADeadWorkerStopOwningAnything(): void
    {
        $agentManager = new RtNodeSourceMapTestAgentManagerDaemon();
        $agentManager->addAgent(self::AGENT, new RtNodeSourceMapTestAgentDaemon(), 3, false);
        $agentManager->addAgent(self::OTHER_AGENT, new RtNodeSourceMapTestAgentDaemon(), 4, false);
        $agentManager->handleRtSourceRegistered(new WorkerRtSourceRegisteredDTO(self::AGENT, [self::COLLECTION]));
        $agentManager->handleRtSourceRegistered(
            new WorkerRtSourceRegisteredDTO(self::OTHER_AGENT, ['unitRtNodeSourceOther']),
        );

        $agentManager->releaseRtSourcesOfWorker(3, isMonopolistic: false);

        $this->assertFalse($agentManager->rtNodeSourceMap()->owns(self::COLLECTION));
        $this->assertTrue(
            $agentManager->rtNodeSourceMap()->owns('unitRtNodeSourceOther'),
            'Only the dead worker gives its collections back',
        );
    }

    public function testAReportWithoutACollectionListIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        WorkerRtSourceRegisteredDTO::fromArray([AgentConstants::FIELD_AGENT_ID => self::AGENT]);
    }

    /**
     * The registry names a claim partial when it is short of any operation, and that is the
     * whole of what the master needs: which of this node's claims another node may hold the
     * rest of.
     */
    public function testTheRegistryNamesTheClaimsThatAreShortOfAnOperation(): void
    {
        RtTruthSourceRegistry::register(
            self::COLLECTION,
            true,
            self::AGENT,
            [TruthSourceOperation::Add, TruthSourceOperation::Remove],
        );
        RtTruthSourceRegistry::register('unitRtNodeSourceOther', true, self::AGENT);

        $this->assertSame([self::COLLECTION], RtTruthSourceRegistry::partialCollectionsOf(self::AGENT));
    }

    /**
     * Owning and owning wholly are two questions, and only the second one decides whether a
     * replica from another node is a split: a node holding part of a right has no ground to
     * refuse the holder of the rest.
     */
    public function testTheMapTellsOwningApartFromOwningWholly(): void
    {
        $map = new RtNodeSourceMap();

        $map->note(self::AGENT, [self::COLLECTION, 'unitRtNodeSourceOther'], [self::COLLECTION]);

        $this->assertTrue($map->owns(self::COLLECTION));
        $this->assertFalse($map->ownsFully(self::COLLECTION));
        $this->assertTrue($map->ownsFully('unitRtNodeSourceOther'));
        $this->assertSame(['unitRtNodeSourceOther'], $map->fullyOwnedCollections());
    }

    /**
     * Two agents here, one holding part of a collection and one holding all of it: the node owns
     * it wholly, because one of its own agents does.
     */
    public function testANodeOwnsWhollyWhenAnyOfItsAgentsDoes(): void
    {
        $map = new RtNodeSourceMap();

        $map->note(self::AGENT, [self::COLLECTION], [self::COLLECTION]);
        $map->note(self::OTHER_AGENT, [self::COLLECTION]);

        $this->assertTrue($map->ownsFully(self::COLLECTION));
        $this->assertSame([self::COLLECTION], $map->fullyOwnedCollections());
    }

    /**
     * The width axis of one registration, as the worker reads it out for the master: a claim
     * naming keys is ownership of those entities, so the keys have to travel. A claim on the
     * whole collection names none and is absent, the way silence means "all of it" everywhere
     * else in the registry.
     */
    public function testTheRegistryNamesTheRowsAClaimCovers(): void
    {
        RtTruthSourceRegistry::register(self::COLLECTION, ['7', '9'], self::AGENT);
        RtTruthSourceRegistry::register('unitRtNodeSourceOther', true, self::AGENT);

        $this->assertSame([self::COLLECTION => ['7', '9']], RtTruthSourceRegistry::keysByCollectionOf(self::AGENT));
    }

    /**
     * The map judges the ROW once the report carries keys: the owner of one entity refuses a
     * frame about that entity and lets every other row of the same collection through. This is
     * the fleet arrangement working — each node writes its own rows of one shared collection —
     * and before HIL-589 every one of those frames read as a split.
     */
    public function testTheMapJudgesTheRowAClaimNamesRatherThanTheCollection(): void
    {
        $map = new RtNodeSourceMap();

        $map->note(self::AGENT, [self::COLLECTION], [], [self::COLLECTION => ['7']]);

        $this->assertTrue($map->owns(self::COLLECTION), 'Something here does write the collection');
        $this->assertTrue($map->owns(self::COLLECTION, '7'));
        $this->assertFalse($map->owns(self::COLLECTION, '9'), 'The rows it did not claim are somebody else\'s');
        $this->assertTrue($map->ownsFully(self::COLLECTION, '7'));
        $this->assertFalse($map->ownsFully(self::COLLECTION, '9'));
    }

    /**
     * Asked about the collection around its keys, a key-scoped claim answers no: what it holds
     * wholly is three entities, and the collection is not those three entities. That is what
     * keeps such a node from handing the collection over and from refusing its neighbours.
     */
    public function testAKeyScopedClaimNeverOwnsTheCollectionAroundItsRows(): void
    {
        $map = new RtNodeSourceMap();

        $map->note(self::AGENT, [self::COLLECTION], [], [self::COLLECTION => ['7']]);

        $this->assertFalse($map->ownsFully(self::COLLECTION));
        $this->assertSame([], $map->fullyOwnedCollections());
        $this->assertSame([self::COLLECTION => ['7']], $map->keyScopedCollections());
    }

    /**
     * Two axes, and each of them alone is enough to make a claim less than whole: the rows are
     * named AND an operation is missing, so even about those rows this node's copy is not the
     * whole truth and it hands nothing over.
     */
    public function testAClaimShortOfAnOperationHandsOverNothingEvenAboutItsOwnRows(): void
    {
        $map = new RtNodeSourceMap();

        $map->note(self::AGENT, [self::COLLECTION], [self::COLLECTION], [self::COLLECTION => ['7']]);

        $this->assertFalse($map->ownsFully(self::COLLECTION, '7'));
        $this->assertSame([], $map->keyScopedCollections());
    }

    /**
     * One node, two agents, the rows of one collection split between them: the node hands over
     * the union of what they claimed, once, and still does not claim the collection itself.
     */
    public function testTheRowsOfTwoAgentsHereMakeOneScope(): void
    {
        $map = new RtNodeSourceMap();

        $map->note(self::AGENT, [self::COLLECTION], [], [self::COLLECTION => ['7']]);
        $map->note(self::OTHER_AGENT, [self::COLLECTION], [], [self::COLLECTION => ['9']]);

        $this->assertSame([self::COLLECTION => ['7', '9']], $map->keyScopedCollections());
        $this->assertFalse($map->ownsFully(self::COLLECTION));
    }

    /**
     * A whole claim beside a key-scoped one leaves the collection handed over whole, and the
     * scope list silent about it: offering the same rows twice under two scopes would have the
     * receiver apply the narrow snapshot over the wide one.
     */
    public function testAWholeClaimHereKeepsTheCollectionOffTheScopeList(): void
    {
        $map = new RtNodeSourceMap();

        $map->note(self::AGENT, [self::COLLECTION], [], [self::COLLECTION => ['7']]);
        $map->note(self::OTHER_AGENT, [self::COLLECTION]);

        $this->assertSame([self::COLLECTION], $map->fullyOwnedCollections());
        $this->assertSame([], $map->keyScopedCollections());
    }

    /**
     * A stopped agent takes its rows with it, exactly as it takes its collections: a claim that
     * outlives the process holding it makes this node refuse frames about rows nobody here
     * writes any more.
     */
    public function testAStoppedAgentTakesItsRowsWithIt(): void
    {
        $map = new RtNodeSourceMap();
        $map->note(self::AGENT, [self::COLLECTION], [], [self::COLLECTION => ['7']]);

        $map->release(self::AGENT);

        $this->assertFalse($map->owns(self::COLLECTION, '7'));
        $this->assertSame([], $map->keyScopedCollections());
    }

    /**
     * @throws InvalidFormatException When the frame is not the object its DTO needs
     */
    public function testThePartialClaimsRoundTripToTheMaster(): void
    {
        $dto = new WorkerRtSourceRegisteredDTO(self::AGENT, [self::COLLECTION], [self::COLLECTION]);

        $parsed = WorkerDTO::factoryWorkerDTO($dto->toJson());

        $this->assertInstanceOf(WorkerRtSourceRegisteredDTO::class, $parsed);
        $this->assertSame([self::COLLECTION], $parsed->partialCollectionKeys);
    }

    /**
     * A report naming no partial claim is read as claiming the whole right, so a worker of an
     * older build keeps the two-owner refusal working rather than silently switching it off.
     *
     * @throws InvalidFormatException When the frame is not the object its DTO needs
     */
    public function testAReportSilentAboutPartialClaimsNamesNone(): void
    {
        $parsed = WorkerRtSourceRegisteredDTO::fromArray([
            AgentConstants::FIELD_AGENT_ID => self::AGENT,
            WorkerRtSourceRegisteredDTO::FIELD_COLLECTION_KEYS => [self::COLLECTION],
        ]);

        $this->assertSame([], $parsed->partialCollectionKeys);
        $this->assertSame([], $parsed->keysByCollection, 'A build that names no rows claims none by name');
    }

    /**
     * @throws InvalidFormatException When the frame is not the object its DTO needs
     */
    public function testTheClaimedRowsRoundTripToTheMaster(): void
    {
        $dto = new WorkerRtSourceRegisteredDTO(self::AGENT, [self::COLLECTION], [], [self::COLLECTION => ['7', '9']]);

        $parsed = WorkerDTO::factoryWorkerDTO($dto->toJson());

        $this->assertInstanceOf(WorkerRtSourceRegisteredDTO::class, $parsed);
        $this->assertSame([self::COLLECTION => ['7', '9']], $parsed->keysByCollection);
    }

    /**
     * The whole trip in one case: what the agent registered, what its worker read out of the
     * registry, and what the master remembers - the three parts that have to agree for a fleet
     * of one-row owners to replicate at all.
     *
     * @throws InvalidFormatException When the frame is not the object its DTO needs
     */
    public function testTheRowsAnAgentClaimedReachTheMastersMap(): void
    {
        RtTruthSourceRegistry::register(self::COLLECTION, ['7'], self::AGENT);
        $map = new RtNodeSourceMap();

        $parsed = WorkerDTO::factoryWorkerDTO(new WorkerRtSourceRegisteredDTO(
            self::AGENT,
            RtTruthSourceRegistry::collectionsOf(self::AGENT),
            RtTruthSourceRegistry::partialCollectionsOf(self::AGENT),
            RtTruthSourceRegistry::keysByCollectionOf(self::AGENT),
        )->toJson());
        $this->assertInstanceOf(WorkerRtSourceRegisteredDTO::class, $parsed);
        $map->note($parsed->agentId, $parsed->collectionKeys, $parsed->partialCollectionKeys, $parsed->keysByCollection);

        $this->assertTrue($map->ownsFully(self::COLLECTION, '7'));
        $this->assertFalse($map->ownsFully(self::COLLECTION, '9'));
        $this->assertFalse($map->ownsFully(self::COLLECTION));
    }
}

/**
 * Agent manager whose only job here is to hold the map and the agent-to-worker mapping.
 */
final class RtNodeSourceMapTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentDaemonInterface Never returned; these cases start no agent
     * @throws AgentDaemonCreationFailedException Always
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}

/**
 * Agent daemon that is only ever a value in the mapping above.
 */
final class RtNodeSourceMapTestAgentDaemon extends AbstractAgentDaemon
{
    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }

    /**
     * @param AgentMessageDTOInterface $message Message that would go to a user; unused here
     */
    public function sendToUser(AgentMessageDTOInterface $message): void
    {
        // Not used in this test
    }
}
