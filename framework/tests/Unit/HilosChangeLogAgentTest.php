<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Collection\ChangeLogReceipts as DbChangeLogReceipts;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Hilos\AbstractHilosChangeLogAgent;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Collection\ChangeLogReceipts as ObjectChangeLogReceipts;
use Hilos\Database\Object\Collection\Settings as ObjectSettings;
use Hilos\Database\Object\Collection\Users as ObjectUsers;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/** The journal reader's interest follows mounted metadata without reading persisted rows. */
final class HilosChangeLogAgentTest extends TestCase
{
    private const array JOURNAL_READS = [
        HilosDbContext::changeLogTables,
        HilosDbContext::changeLogFields,
        HilosDbContext::changeLogEntries,
        HilosDbContext::changeLogChanges,
        HilosDbContext::changeLogValues,
    ];

    private ?HilosDbContext $previousDb;

    /** Saves the facade without initializing it. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        Hilos::$db = null;
    }

    /** Restores the process's original context. */
    protected function tearDown(): void
    {
        TruthSourceRegistry::unregisterAgent(ChangeLogReceiverAgent::AGENT_TYPE);
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(ChangeLogReceiverAgent::AGENT_TYPE));
        Hilos::$db = $this->previousDb;
        parent::tearDown();
    }

    public function testTheDefaultHookKeepsConstantReplacementAndExplicitParentReads(): void
    {
        $this->assertSame([], AbstractAgent::readDbCollections());
        $this->assertSame(['parent_read'], ChangeLogReadParentAgent::readDbCollections());
        $this->assertSame(['replacement_read'], ChangeLogReadReplacementAgent::readDbCollections());
        $this->assertSame(['parent_read', 'extra_read'], ChangeLogReadExtendedAgent::readDbCollections());
    }

    public function testTheHookNeedsNeitherAnInstanceNorADatabaseContext(): void
    {
        $this->assertSame(self::JOURNAL_READS, AbstractHilosChangeLogAgent::readDbCollections());
        $this->assertNull(Hilos::$db);

        Hilos::$db = new ChangeLogReadMetadataContext();
        $this->assertSame(self::JOURNAL_READS, AbstractHilosChangeLogAgent::readDbCollections());
    }

    public function testMountedFrameworkAndProjectJournaledKeysBecomeReadsWithoutRows(): void
    {
        $context = new ChangeLogReadMetadataContext();
        $context->mount(HilosDbContext::users, ObjectUsers::initEmpty());
        $context->mount('project_journaled', ChangeLogReadProjectObjects::initEmpty());
        $context->mount(HilosDbContext::changeLogReceipts, ObjectChangeLogReceipts::initEmpty());
        $context->mount(HilosDbContext::settings, ObjectSettings::initEmpty());
        Hilos::$db = $context;

        $this->assertSame([
            ...self::JOURNAL_READS,
            HilosDbContext::users,
            'project_journaled',
            HilosDbContext::settings,
        ], AbstractHilosChangeLogAgent::readDbCollections());
        $this->assertNotContains(HilosDbContext::changeLogReceipts, AbstractHilosChangeLogAgent::readDbCollections());

        // Metadata follows the active facade rather than a cached result from the first call.
        Hilos::$db = new ChangeLogReadMetadataContext();
        $this->assertSame(self::JOURNAL_READS, AbstractHilosChangeLogAgent::readDbCollections());
    }

    public function testAllThreeCallbacksUseThePayloadWriterAndEachDeliveredFact(): void
    {
        [$agent, $objects] = $this->receiver();
        $facts = [
            new DbSyncCreatedSignalData(HilosDbContext::users, '7', [], emitter: 'foreign-process', receiptId: 41, writerAgentId: 'business-writer'),
            new DbSyncUpdatedSignalData('project_journaled', '7', [], receiptId: 41, writerAgentId: 'next-writer'),
            new DbSyncDeletedSignalData(HilosDbContext::users, '7', [], receiptId: 41, writerAgentId: 'last-writer'),
        ];
        ExecutionContext::run(new ExecutionFrame($agent->getId()), static function () use ($agent, $facts): void {
            $agent->onSignalDbSyncCreated($facts[0], 'transport-source', SignalConstants::DB_SYNC_CREATED);
            $agent->onSignalDbSyncUpdated($facts[1], 'transport-source', SignalConstants::DB_SYNC_UPDATED);
            $agent->onSignalDbSyncDeleted($facts[2], 'transport-source', SignalConstants::DB_SYNC_DELETED);
            $agent->onSignalDbSyncDeleted($facts[2], 'transport-source', SignalConstants::DB_SYNC_DELETED);
        });
        $this->assertSame([[41, 'business-writer'], [41, 'next-writer'], [41, 'last-writer'], [41, 'last-writer']], $objects->writers);
        $this->assertSame([], $agent->errors);
    }

    public function testInvalidMetadataAndNonjournaledCollectionsNeverReachTheAction(): void
    {
        [$agent, $objects] = $this->receiver();
        foreach ([
            [null, 'writer'], [0, 'writer'], [-1, 'writer'], [41, null], [41, ''],
        ] as [$receiptId, $writerAgentId]) {
            $agent->onSignalDbSyncCreated(
                new DbSyncCreatedSignalData(HilosDbContext::users, '7', [], receiptId: $receiptId, writerAgentId: $writerAgentId),
                'source', SignalConstants::DB_SYNC_CREATED,
            );
        }
        foreach (['unmounted', ...self::JOURNAL_READS, HilosDbContext::changeLogReceipts] as $key) {
            $agent->onSignalDbSyncUpdated(
                new DbSyncUpdatedSignalData($key, '7', [], receiptId: 41, writerAgentId: 'writer'),
                'source', SignalConstants::DB_SYNC_UPDATED,
            );
        }
        $this->assertSame([], $objects->writers);
        $this->assertSame([], $agent->errors);
    }

    public function testAttributionFailureIsLoggedOnceWithoutRetryOrPropagation(): void
    {
        [$agent, $objects] = $this->receiver();
        $objects->failure = new DatabaseException('writer update failed');
        ExecutionContext::run(new ExecutionFrame($agent->getId()), static fn () => $agent->onSignalDbSyncUpdated(
            new DbSyncUpdatedSignalData(HilosDbContext::users, '7', [], receiptId: 41, writerAgentId: 'writer'),
            'source', SignalConstants::DB_SYNC_UPDATED,
        ));
        $this->assertSame([[41, 'writer']], $objects->writers);
        $this->assertSame(['Cannot attribute journal receipt 41: writer update failed'], $agent->errors);
    }

    public function testMetadataReadsPreserveSubclassDeclarationsAndReturnADenseUniqueList(): void
    {
        $context = new ChangeLogReadMetadataContext();
        $context->mount(HilosDbContext::users, ObjectUsers::initEmpty());
        $context->mount('project_journaled', ChangeLogReadProjectObjects::initEmpty());
        Hilos::$db = $context;

        $this->assertSame([
            ...self::JOURNAL_READS,
            'extra_read',
            HilosDbContext::users,
            'project_journaled',
        ], ChangeLogReadExtendedJournalAgent::readDbCollections());
    }
    /** @return array{ChangeLogReceiverAgent, ChangeLogReceiverObjects} Real agent and recording ORM door */
    private function receiver(): array
    {
        $context = new ChangeLogReadMetadataContext();
        $context->mount(HilosDbContext::users, ObjectUsers::initEmpty());
        $context->mount('project_journaled', ChangeLogReadProjectObjects::initEmpty());
        $objects = ChangeLogReceiverObjects::initEmpty();
        $receipts = DbChangeLogReceipts::forMigration();
        $receipts->setObjectCollection($objects);
        $context->mount(HilosDbContext::changeLogReceipts, $objects);
        $context->mountReceipts($receipts);
        Hilos::$db = $context;
        $agent = new ChangeLogReceiverAgent();
        OwnershipDeclaration::claimDb($agent::class, $agent->getId());

        return [$agent, $objects];
    }

}

abstract class ChangeLogReadParentAgent extends AbstractAgent
{
    public const array READS_DB = ['parent_read'];
}

abstract class ChangeLogReadReplacementAgent extends ChangeLogReadParentAgent
{
    public const array READS_DB = ['replacement_read'];
}

abstract class ChangeLogReadExtendedAgent extends ChangeLogReadParentAgent
{
    public const array READS_DB = [...parent::READS_DB, 'extra_read'];
}

abstract class ChangeLogReadExtendedJournalAgent extends AbstractHilosChangeLogAgent
{
    public const array READS_DB = [...parent::READS_DB, 'extra_read', HilosDbContext::users, HilosDbContext::users];
}

/** Empty object collections provide only the mounted Entity metadata. */
final class ChangeLogReadMetadataContext extends HilosDbContext
{
    /** Mounts no production data or connections. */
    public function configure(): void
    {
    }

    /**
     * @param string $key Actual project mount key
     * @param Objects $objects Empty object collection
     */
    public function mount(string $key, Objects $objects): void
    {
        $this->_objectCollections[$key] = $objects;
    }

    /** @param DbChangeLogReceipts $receipts View over the recording ORM collection */
    public function mountReceipts(DbChangeLogReceipts $receipts): void
    {
        $this->_dbItemCollections[self::changeLogReceipts] = $receipts;
    }
}

final class ChangeLogReadProjectEntity extends Entity
{
    public const string _table = 'unit_project_journaled';
    public const bool _journaled = true;
}

final class ChangeLogReadProjectObject extends Object_
{
    public const string ENTITY_CLASS = ChangeLogReadProjectEntity::class;
}

/** @extends Objects<ChangeLogReadProjectObject> */
final class ChangeLogReadProjectObjects extends Objects
{
    public const string OBJECT_CLASS = ChangeLogReadProjectObject::class;
}

/** Keeps the production callbacks and records only their logger output. */
final class ChangeLogReceiverAgent extends AbstractHilosChangeLogAgent
{
    /** @var list<string> Failures contained by the attribution boundary */
    public array $errors = [];

    /** @param string $message Attribution diagnostic */
    protected function logAgentError(string $message): void
    {
        $this->errors[] = $message;
    }
}

/** The normal collection action reaches this recording object door without SQL. */
final class ChangeLogReceiverObjects extends ObjectChangeLogReceipts
{
    /** @var list<array{int, string}> Delivered receipt and original writer */
    public array $writers = [];

    public ?DatabaseException $failure = null;

    /**
     * @param int $receiptId Receipt carried by the fact
     * @param string $writerAgentId Writer carried by the fact
     */
    public function recordWriter(int $receiptId, string $writerAgentId): void
    {
        $this->writers[] = [$receiptId, $writerAgentId];
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
