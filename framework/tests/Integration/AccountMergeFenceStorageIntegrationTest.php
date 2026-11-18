<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationKind;
use Hilos\Constants\HilosAgentType;
use Hilos\Backup\BackupCreator;
use Hilos\Backup\BackupScope;
use Hilos\Backup\Anonymization\AnonymizationCoverageValidator;
use Hilos\Backup\Anonymization\AnonymizationSqlBuilder;
use Hilos\Backup\Anonymization\CatalogRestoreAnonymizer;
use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\DbSyncClearedSignalData;
use Hilos\Database\DbSyncApplicator;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Identity\IdentityType;
use Hilos\Database\Entity\Item\AccountMergeFence as EntityAccountMergeFence;
use Hilos\Database\Schema\EntitySchemaAudit;
use Hilos\Database\Object\Item\Object_;
use ReflectionProperty;
use Hilos\Hilos;
use RuntimeException;

/** Real commits, cold reconnects and physical schema/PURGE checks cannot use an always-rollback root. */
final class AccountMergeFenceStorageIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    private const array TABLES = [
        'hilos_account_operation_assignment',
        'hilos_account_operation_result',
        'hilos_account_merge_copy',
        'hilos_account_merge_fence',
    ];

    private ?SignalRouter $previousRouter;

    /** Mounts the real metadata table beside the session fixture's business tables. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        self::stub(true);
        self::stub(false);
    }

    /** Restores the handler, schema, claims and source bus even after a failed assertion. */
    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            self::stub(true);
        } finally {
            $this->releasePersonAgents();
            SourceChangeBus::reset();
            Hilos::$sr = $this->previousRouter;
            parent::tearDown();
        }
    }

    public function testRealSchemaCompositeColdOffsetsAndNoNegativeOrStaleFreshMirror(): void
    {
        $columns = array_column(Database::sql('SHOW FULL COLUMNS FROM `hilos_account_merge_fence`')->rows(), null, 'Field');
        self::assertSame(EntityAccountMergeFence::_columns, array_keys($columns));
        self::assertSame([], EntitySchemaAudit::audit([EntityAccountMergeFence::class]));
        self::assertSame('utf8mb4_bin', $columns['collection_key']['Collation']);
        self::assertSame('0', $columns['deleted']['Default']);
        $ddl = Database::sql('SHOW CREATE TABLE `hilos_account_merge_fence`')->firstRow()['Create Table'];
        self::assertStringNotContainsString('FOREIGN KEY', $ddl);
        self::assertStringContainsString('PRIMARY KEY (`collection_key`,`row_id`)', $ddl);
        self::assertStringContainsString('KEY `idx_account_merge_fence_owner` (`owner_user_id`,`collection_key`,`row_id`)', $ddl);
        self::assertNull(Hilos::$db->accountMergeFences['identities:31'] ?? null);
        $this->seed('identities', 31);
        $this->seed('secondFactors', 31);
        $fresh = Hilos::$db->accountMergeFences->fresh('identities:31');
        self::assertSame(1, $fresh->revision);
        self::assertSame(20, $fresh->ownerUserId);
        $row = Hilos::$db->accountMergeFences['identities:31'];
        self::assertSame('identities:31', $row->getObject()->getIdString());
        self::assertSame('secondFactors', Hilos::$db->accountMergeFences['secondFactors:31']->collectionKey);
        Database::sql('UPDATE `hilos_account_merge_fence` SET `revision` = 3 WHERE `collection_key` = ? AND `row_id` = ?', ['identities', 31]);
        self::assertSame(3, Hilos::$db->accountMergeFences->fresh('identities:31')->revision);
        self::assertSame(1, $row->revision);
        self::assertSame($row->getObject(), Hilos::$db->accountMergeFences['identities:31']->getObject());
        Database::close();
        Database::connect();
        Hilos::$db = new HilosSessionTestDbContext();
        Hilos::$db->configure();
        self::assertSame(3, Hilos::$db->accountMergeFences['identities:31']->revision);
        self::assertNull(Hilos::$db->accountMergeFences->fresh('identities:99'));
    }

    public function testFreshReadsCurrentMetadataBeyondTheTransactionsConsistentSnapshot(): void
    {
        $this->seed('identities', 31);
        $primary = Database::getConnectionConfig(0);
        Database::configure(2, $primary->host, $primary->user, $primary->password,
            $primary->database, $primary->port, $primary->charset, $primary->socket);
        Database::connect(2);
        Database::transactionStart();
        try {
            self::assertSame(1, (int)Database::sql('SELECT `revision` FROM `hilos_account_merge_fence`')->firstRow()['revision']);
            Database::sql('UPDATE `hilos_account_merge_fence` SET `revision` = 3', index: 2);
            self::assertSame(1, (int)Database::sql('SELECT `revision` FROM `hilos_account_merge_fence`')->firstRow()['revision']);
            self::assertSame(3, Hilos::$db->accountMergeFences->fresh('identities:31')->revision);
        } finally {
            Database::transactionRollback();
            Database::close(2);
        }
    }

    public function testAdvanceUsesTheCurrentOwnerAndCasAndRollsBackItsExistingWrapper(): void
    {
        $this->seed('identities', 31);
        $row = Hilos::$db->accountMergeFences['identities:31'];
        $this->asPerson(20, static function () use ($row): void {
            try {
                $row->actions->advanceForWrite();
                self::fail('A fence advanced outside its business transaction');
            } catch (DatabaseException) {
                self::assertSame(1, $row->revision);
            }
            try {
                Database::transaction(DatabaseNames::PRIMARY, static function () use ($row): void {
                    $snapshot = $row->actions->advanceForWrite();
                    self::assertSame(2, $snapshot->revision);
                    self::assertSame(20, $snapshot->ownerUserId);
                    self::assertSame(71, $snapshot->operationId);
                    self::assertSame(81, $snapshot->assignmentId);
                    throw new RuntimeException('rollback metadata');
                });
            } catch (RuntimeException $failure) {
                self::assertSame('rollback metadata', $failure->getMessage());
            }
            self::assertSame(1, $row->revision);
            self::assertSame(1, (int)Database::sql('SELECT `revision` FROM `hilos_account_merge_fence`')->firstRow()['revision']);
            Database::transaction(DatabaseNames::PRIMARY, static fn () => $row->actions->advanceForWrite());
            self::assertSame(2, $row->revision);
            Database::sql('UPDATE `hilos_account_merge_fence` SET `revision` = 4');
            try {
                Database::transaction(DatabaseNames::PRIMARY, static fn () => $row->actions->advanceForWrite());
                self::fail('A stale revision overwrote current metadata');
            } catch (DatabaseException) {
                self::assertSame(2, $row->revision);
            }
        });
        $this->asPerson(10, static function () use ($row): void {
            try {
                Database::transaction(DatabaseNames::PRIMARY, static fn () => $row->actions->advanceForWrite());
                self::fail('A foreign personal set advanced metadata');
            } catch (WriteNotAllowedException) {
                self::assertSame(2, $row->revision);
            }
        });
        self::assertSame(4, Hilos::$db->accountMergeFences->fresh('identities:31')->revision);
    }

    public function testOrdinaryUpdateCannotMoveTheFenceOwnerAndPersonalRemoveIsAbsent(): void
    {
        $this->seed('identities', 31);
        $row = Hilos::$db->accountMergeFences['identities:31'];
        $this->asPerson(20, static function () use ($row): void {
            $object = $row->getObject();
            $entity = new ReflectionProperty(Object_::class, 'entity')->getValue($object);
            $entity->owner_user_id = 10;
            try {
                Database::transaction(DatabaseNames::PRIMARY, static fn () => $object->sync());
                self::fail('Ordinary Update moved a fence into another personal set');
            } catch (WriteNotAllowedException) {
                self::assertSame(20, $row->ownerUserId);
            }
            try {
                Database::transaction(DatabaseNames::PRIMARY, static fn () => $object->delete());
                self::fail('Personal metadata rights included Remove');
            } catch (WriteNotAllowedException) {
                self::assertTrue($object->isRelated());
            }
        });
        self::assertSame(20, Hilos::$db->accountMergeFences->fresh('identities:31')->ownerUserId);
    }

    public function testAppliedProgressSurvivesFreshAndMetadataArrivalButRollsBackAndResetsWithTheContext(): void
    {
        $this->seed('identities', 31);
        $collection = Hilos::$db->accountMergeFences->getObjectCollection();
        $object = $collection->rememberSnapshot($collection->fresh('identities:31'));
        $object->markApplied(1);
        DbSyncApplicator::applyCleared(new DbSyncClearedSignalData(HilosDbContext::accountMergeFences), false);
        self::assertSame($object, Hilos::$db->accountMergeFences['identities:31']->getObject());
        self::assertSame(1, $object->appliedRevision());
        Database::sql('UPDATE `hilos_account_merge_fence` SET `revision` = 3');
        self::assertSame($object, $collection->rememberSnapshot($collection->fresh('identities:31')));
        self::assertSame(1, $object->appliedRevision());
        $object->applyDbSyncEntityUpdate(['revision' => 2]);
        self::assertSame(1, $object->appliedRevision());
        Database::transactionStart();
        $object->markApplied(3);
        self::assertSame(3, $object->appliedRevision());
        Database::transactionRollback();
        self::assertSame(1, $object->appliedRevision());
        self::assertSame(3, $collection->fresh('identities:31')->revision);
        Hilos::$db = new HilosSessionTestDbContext();
        Hilos::$db->configure();
        self::assertSame(0, Hilos::$db->accountMergeFences['identities:31']->getObject()->appliedRevision());
    }

    public function testTombstonesOutliveBusinessAndOperationCleanupAndCollectedPurgeRemovesThem(): void
    {
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (20, 'Fence owner')");
        Database::sqlRun("INSERT INTO `hilos_identity` (`id`, `user_id`, `type`, `identifier`, `created_at`, `updated_at`)"
            . " VALUES (31, 20, ?, 'fence@example.test', 0, 0)", [IdentityType::MAGIC_LINK]);
        Database::sqlRun('INSERT INTO `hilos_account_operation`'
            . ' (`id`, `kind`, `root_user_id`, `circle_user_ids`, `stage`, `created_at`, `updated_at`)'
            . " VALUES (71, ?, 20, '[20,10]', ?, 0, 0)", [AccountOperationKind::Merge->value, AccountMergeStage::Go->value]);
        Database::sqlRun('INSERT INTO `hilos_account_operation_assignment`'
            . ' (`id`, `operation_id`, `stage`, `assignment_key`, `owner_type`, `owner_index`, `user_id`, `payload_json`, `created_at`)'
            . " VALUES (81, 71, ?, ?, ?, '10', 10, '{}', 0)",
            [AccountMergeStage::Go->value, AccountMergeDuty::Give->value, HilosAgentType::HILOS_USER]);
        Database::sqlRun('INSERT INTO `hilos_account_merge_copy` (`assignment_id`, `owner_agent_id`, `payload_json`, `created_at`)'
            . " VALUES (81, ?, '{}', 0)", [HilosAgentType::HILOS_USER . ':10']);
        $this->seed('identities', 31, true);
        Database::sqlRun('DELETE FROM `hilos_account_merge_copy` WHERE `assignment_id` = 81');
        self::assertSame(1, Hilos::$db->accountMergeFences->fresh('identities:31')->revision);
        Database::sqlRun('DELETE FROM `hilos_account_operation_assignment` WHERE `id` = 81');
        self::assertTrue(Hilos::$db->accountMergeFences->fresh('identities:31')->deleted);
        Database::sqlRun('DELETE FROM `hilos_identity` WHERE `id` = 31');
        Database::sqlRun('DELETE FROM `hilos_account_operation`');
        Database::sqlRun('DELETE FROM `hilos_user` WHERE `id` = 20');
        $snapshot = Hilos::$db->accountMergeFences->fresh('identities:31');
        self::assertTrue($snapshot->deleted);
        self::assertSame(1, $snapshot->revision);
        self::assertSame(
            [['flags' => [], 'tables' => [], 'append' => false]],
            BackupCreator::scopeDumpPasses(BackupScope::FULL, ['hilos_user']),
            'FULL keeps current metadata and tombstones independently of the reference-table selection.',
        );
        $registry = PiiRegistry::collect();
        $schema = LiveSchemaReader::read(0);
        AnonymizationCoverageValidator::validateLiveSchema($registry, [0 => $schema]);
        $anonymizer = new CatalogRestoreAnonymizer($registry, new AnonymizationSqlBuilder(str_repeat('b', 64)));
        $anonymizer->validateArchive([0 => array_keys($schema)]);
        $database = Database::getConnectionConfig(0)->database;
        $anonymizer->validateTargetSchema(0, $database);
        $anonymizer->anonymizeConnection(0, $database);
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_merge_fence`')->rows());
    }

    /**
     * @param string $collection Stored business collection
     * @param int $id Stored row id
     * @param bool $deleted Whether the fixture represents a durable tombstone
     */
    private function seed(string $collection, int $id, bool $deleted = false): void
    {
        Database::sql('INSERT INTO `hilos_account_merge_fence`'
            . ' (`collection_key`, `row_id`, `operation_id`, `assignment_id`, `owner_user_id`, `revision`, `deleted`)'
            . ' VALUES (?, ?, 71, 81, 20, 1, ?)', [$collection, $id, (int)$deleted]);
    }

    /** @param bool $down Whether to remove the physical metadata table */
    private static function stub(bool $down): void
    {
        // external-boundary: up stub filenames carry no direction suffix
        $suffix = $down ? '_down' : '';
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            Database::sqlRun(file_get_contents(
                dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . $suffix . '.sql',
            ));
        }
    }
}
