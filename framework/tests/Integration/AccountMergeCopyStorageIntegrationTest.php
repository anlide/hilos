<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\AccountOperation\AccountMergeDuty;
use Hilos\Auth\AccountOperation\AccountMergeGiveResult;
use Hilos\Auth\AccountOperation\AccountMergeHandoverCopy;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\AccountOperationAssignmentPayload;
use Hilos\Auth\AccountOperation\AccountOperationOwnerAddress;
use Hilos\Auth\AccountOperation\AccountOperationParticipantStep;
use Hilos\Auth\AccountOperation\AccountOperationResultPayload;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Backup\Anonymization\AnonymizationCoverageValidator;
use Hilos\Backup\Anonymization\AnonymizationSqlBuilder;
use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Backup\Anonymization\CatalogRestoreAnonymizer;
use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Entity\Item\AccountMergeCopy as EntityAccountMergeCopy;
use Hilos\Database\Identity\PasswordFate;
use Hilos\Database\View\Item\AccountMergeCopy;
use Hilos\Database\View\Item\AccountOperationAssignment;
use Hilos\Database\View\Item\AccountOperationResult;
use Hilos\Hilos;
use Hilos\HilosException;
use JsonException;
use RuntimeException;

/** Real participant commits and reconnects require no synthetic rollback root. */
final class AccountMergeCopyStorageIntegrationTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    private const string HOLDER_ID = 'merge-copy-storage-holder';
    private const int LOSER = 10;
    private const int SURVIVOR = 20;
    private const array TABLES = ['hilos_account_operation_assignment', 'hilos_account_operation_result', 'hilos_account_merge_copy'];
    private const string COPY_JSON = <<<'JSON'
{"operationId":2,"assignmentId":3,"loserUserId":10,"survivorUserId":20,
 "loserPasswordIdentityId":31,"survivorPasswordIdentityId":7,"effectiveWaitDays":14,
 "identities":[
  {"id":31,"userId":20,"type":"password","identifier":"loser@example.test","secret":"retained-password-hash",
   "provider":null,"verified":true,"passwordChangedAt":-1,"providerName":null,"createdAt":-1001,"updatedAt":0},
  {"id":32,"userId":20,"type":"passkey","identifier":"passkey-identifier","secret":null,
   "provider":"stored-provider","verified":false,"passwordChangedAt":null,"providerName":"Stored provider","createdAt":0,"updatedAt":1}],
 "passkeyCredentials":[
  {"id":41,"identityId":32,"userId":20,"credentialId":"credential-original","publicKey":"public-key-original",
   "algorithm":-7,"signCount":4294967295,"transports":"[\"usb\", \"internal\"]","aaguid":null,
   "userHandle":"_wCA","label":null,"lastUsedAt":0,"createdAt":-1}],
 "secondFactors":[
  {"id":51,"userId":20,"label":"Authenticator","secret":"retained-totp-secret","lastUsedStep":123456,
   "confirmedAt":-1,"lastUsedAt":null,"createdAt":0}],
 "secondFactorBackupCodes":[
  {"id":61,"userId":20,"code":"retained-backup-code","usedAt":null,"createdAt":-1}]}
JSON;

    private ?SignalRouter $previousRouter = null;

    /** Mounts real storage and the production personal owner's Add-only copy set. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        self::stubs(true);
        self::stubs(false);
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (10, 'Loser'), (20, 'Survivor'), (30, 'Other loser')");
        OwnershipDeclaration::claimDb(MergeCopyStorageHolder::class, self::HOLDER_ID);
    }

    /** Removes dependent schema and releases every owner and source interest. */
    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            self::stubs(true);
        } finally {
            $this->releasePersonAgents();
            TruthSourceRegistry::unregisterAgent(self::HOLDER_ID);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(self::HOLDER_ID));
            Hilos::$sr = $this->previousRouter;
            SourceChangeBus::reset();
            parent::tearDown();
        }
    }

    public function testLiveSchemaHasOnlyMetadataMappedAndEveryPhysicalColumnClassified(): void
    {
        $columns = array_column(Database::sql('SHOW FULL COLUMNS FROM `hilos_account_merge_copy`')->rows(), null, 'Field');
        self::assertSame(['assignment_id', 'owner_agent_id', 'payload_json', 'created_at'], array_keys($columns));
        self::assertSame('bigint(20) unsigned', $columns['assignment_id']['Type']);
        self::assertSame('NO', $columns['assignment_id']['Null']);
        self::assertSame('PRI', $columns['assignment_id']['Key']);
        self::assertSame('varchar(383)', $columns['owner_agent_id']['Type']);
        self::assertSame('utf8mb4_bin', $columns['owner_agent_id']['Collation']);
        self::assertSame('NO', $columns['owner_agent_id']['Null']);
        self::assertSame('YES', $columns['payload_json']['Null']);
        self::assertNull($columns['payload_json']['Default']);
        self::assertSame('bigint(20)', $columns['created_at']['Type']);
        self::assertSame('NO', $columns['created_at']['Null']);
        self::assertNull($columns['created_at']['Default']);
        $create = Database::sql('SHOW CREATE TABLE `hilos_account_merge_copy`')->firstRow()['Create Table'];
        self::assertStringContainsString('json_valid(`payload_json`)', $create);
        $indexes = Database::sql('SHOW INDEX FROM `hilos_account_merge_copy`')->rows();
        self::assertSame(['PRIMARY', 'idx_account_merge_copy_owner', 'idx_account_merge_copy_owner'], array_column($indexes, 'Key_name'));
        self::assertSame(['assignment_id', 'owner_agent_id', 'assignment_id'], array_column($indexes, 'Column_name'));
        $foreign = Database::sql(
            'SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE'
            . ' FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r'
            . ' ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME'
            . " WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = 'hilos_account_merge_copy' AND k.REFERENCED_TABLE_NAME IS NOT NULL",
        )->rows();
        self::assertSame([[
            'CONSTRAINT_NAME' => 'fk_account_merge_copy_assignment', 'COLUMN_NAME' => 'assignment_id',
            'REFERENCED_TABLE_NAME' => 'hilos_account_operation_assignment', 'REFERENCED_COLUMN_NAME' => 'id',
            'DELETE_RULE' => 'CASCADE', 'UPDATE_RULE' => 'RESTRICT',
        ]], $foreign);
        self::assertSame('assignment_id', EntityAccountMergeCopy::_primary);
        self::assertSame(['assignment_id', 'owner_agent_id', 'created_at'], EntityAccountMergeCopy::_columns);
        self::assertSame(EntityAccountMergeCopy::_columns, array_keys(EntityAccountMergeCopy::_types));
        self::assertSame('owner_agent_id', EntityAccountMergeCopy::_setVia);
        self::assertFalse(EntityAccountMergeCopy::_setRoot);
        self::assertSame(['created_at'], EntityAccountMergeCopy::_moments);
        self::assertSame(AnonymizationStrategy::PURGE, EntityAccountMergeCopy::_pii);
        self::assertFalse(EntityAccountMergeCopy::_journaled);
        self::assertFalse(property_exists(EntityAccountMergeCopy::class, 'payload_json'));
        self::assertTrue(PiiRegistry::collect()->isPurged(0, 'hilos_account_merge_copy'));
    }

    public function testCollectedRegistryCoverageCompatibilityAndRealPurgeRemoveEveryCopySecret(): void
    {
        Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_i18n_reflow.sql'));
        try {
            Database::sqlRun('INSERT INTO `hilos_i18n_reflow` (`id`, `fingerprint`) VALUES (1, ?)', [str_repeat('a', 64)]);
            $neighbour = Database::sql('SELECT * FROM `hilos_i18n_reflow`')->rows();
            $assignment = $this->assignment();
            $this->give($assignment);
            $saved = Database::sql('SELECT `payload_json` FROM `hilos_account_merge_copy`')->firstRow()['payload_json'];
            foreach (['retained-password-hash', 'retained-totp-secret', 'retained-backup-code'] as $marker) {
                self::assertStringContainsString($marker, $saved);
            }
            $registry = PiiRegistry::collect();
            $schema = LiveSchemaReader::read(0);
            AnonymizationCoverageValidator::validateLiveSchema($registry, [0 => $schema]);
            $anonymizer = new CatalogRestoreAnonymizer($registry, new AnonymizationSqlBuilder(str_repeat('b', 64)));
            $anonymizer->validateArchive([0 => array_keys($schema)]);
            $database = Database::getConnectionConfig(0)->database;
            $anonymizer->validateTargetSchema(0, $database);
            $anonymizer->anonymizeConnection(0, $database);
            self::assertSame([], Database::sql('SELECT * FROM `hilos_account_merge_copy`')->rows());
            self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows());
            self::assertSame($neighbour, Database::sql('SELECT * FROM `hilos_i18n_reflow`')->rows());
        } finally {
            Database::sqlRun('DROP TABLE `hilos_i18n_reflow`');
        }
    }

    public function testCopyBirthAndPayloadRollBackWithTheRealParticipantAndRetryOnce(): void
    {
        $assignment = $this->assignment();
        self::drainFrames();
        try {
            $this->asPerson(self::LOSER, static fn () => Hilos::$db->accountMergeCopies->actions->record($assignment, self::copy($assignment)));
            self::fail('Copy birth outside the primary participant transaction succeeded');
        } catch (DatabaseException) {
            self::assertTrue(true);
        }
        $this->assertNoBirth($assignment);
        foreach ([AccountMergeHandoverCopy::assignmentId, AccountMergeHandoverCopy::operationId] as $anchor) {
            try {
                $this->asPerson(self::LOSER, static fn () => AccountOperationParticipantStep::run(
                    $assignment->id,
                    static function (AccountOperationAssignment $assigned) use ($anchor): AccountOperationResultPayload {
                        $fields = self::copy($assigned)->toArray();
                        ++$fields[$anchor];
                        Hilos::$db->accountMergeCopies->actions->record($assigned, AccountMergeHandoverCopy::fromArray($fields));
                        return self::givePayload();
                    },
                ));
                self::fail('A copy with a foreign assignment or operation anchor was stored');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
            $this->assertNoBirth($assignment);
        }
        $foreign = $this->assignment(self::SURVIVOR, 30);
        self::drainFrames();
        try {
            $this->asPerson(self::SURVIVOR, static fn () => AccountOperationParticipantStep::run(
                $foreign->id,
                static function (AccountOperationAssignment $assigned): AccountOperationResultPayload {
                    Hilos::$db->accountMergeCopies->actions->record($assigned, self::copy($assigned));
                    return self::givePayload();
                },
            ));
            self::fail('A survivor give assignment was accepted as the canonical loser');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
        $this->assertNoBirth($foreign);
        $failure = new RuntimeException('Participant failed after copy birth');
        try {
            $this->asPerson(self::LOSER, static fn () => AccountOperationParticipantStep::run(
                $assignment->id,
                static function (AccountOperationAssignment $assigned) use ($failure): AccountOperationResultPayload {
                    Hilos::$db->accountMergeCopies->actions->record($assigned, self::copy($assigned));
                    throw $failure;
                },
            ));
            self::fail('Failed participant returned a give result');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        $this->assertNoBirth($assignment);
        try {
            $this->asPerson(self::LOSER, static fn () => AccountOperationParticipantStep::run(
                $assignment->id,
                static function (AccountOperationAssignment $assigned): AccountOperationResultPayload {
                    $fields = self::copy($assigned)->toArray();
                    $fields[AccountMergeHandoverCopy::identities][0]['secret'] = "\xff";
                    Hilos::$db->accountMergeCopies->actions->record($assigned, AccountMergeHandoverCopy::fromArray($fields));
                    return self::givePayload();
                },
            ));
            self::fail('Unencodable copy returned a give result');
        } catch (JsonException $caught) {
            self::assertStringNotContainsString('retained-', $caught->getMessage());
        }
        $this->assertNoBirth($assignment);
        $this->give($assignment);
        $calls = 0;
        $this->asPerson(self::LOSER, static fn () => AccountOperationParticipantStep::run(
            $assignment->id,
            static function () use (&$calls): AccountOperationResultPayload {
                ++$calls;
                return self::givePayload();
            },
        ));
        self::assertSame(0, $calls);
        self::assertCount(1, Database::sql('SELECT * FROM `hilos_account_merge_copy`')->rows());
        self::assertCount(1, Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows());
    }

    public function testReconnectAndGuardedReadKeepTheCopyOutOfOrmSyncAndNonsecretResult(): void
    {
        $assignment = $this->assignment();
        self::drainFrames();
        $result = $this->give($assignment);
        $frames = self::drainFrames();
        $copyFrames = array_values(array_filter($frames, static fn (DbSyncCreatedSignalData $frame): bool =>
            $frame->collectionKey === HilosDbContext::accountMergeCopies));
        self::assertCount(1, $copyFrames);
        self::assertSame((string)$assignment->id, $copyFrames[0]->idString);
        self::assertSame(['assignment_id', 'owner_agent_id', 'created_at'], array_keys($copyFrames[0]->row));
        self::assertGreaterThan(0, $copyFrames[0]->row['created_at']);
        $before = $this->asPerson(self::LOSER, static fn () => Hilos::$db->accountMergeCopies[$assignment->id]);
        Database::close();
        Database::connect();
        Hilos::$db = new HilosSessionTestDbContext();
        Hilos::$db->configure();
        $this->asPerson(self::LOSER, function () use ($assignment, $before, $result, $frames): void {
            $copy = Hilos::$db->accountMergeCopies[$assignment->id];
            self::assertNotSame($before->getObject(), $copy->getObject());
            self::assertSame(self::copy($assignment)->toArray(), $copy->readForMerge()->toArray());
            self::assertSame($before->toArray(), $copy->toArray());
            self::assertSame(['assignmentId', 'ownerAgentId', 'createdAt'], array_keys($copy->toArray()));
            self::assertSame($copy->toArray(), $copy->toArray(toFrontend: true));
            self::assertSame(['assignmentId'], $copy->getObject()->getPrimaryKeyArrayKeys());
            $serialized = json_encode([$copy->toArray(), $frames, $result->toArray()], JSON_THROW_ON_ERROR);
            foreach (['retained-password-hash', 'retained-totp-secret', 'retained-backup-code', 'userHandle', 'payloadJson'] as $marker) {
                if ($marker === 'payloadJson') {
                    self::assertArrayNotHasKey($marker, $copy->toArray());
                } else {
                    self::assertStringNotContainsString($marker, $serialized);
                }
            }
            try {
                $copy->payloadJson;
                self::fail('Private payload acquired a magic getter');
            } catch (HilosException) {
                self::assertTrue(true);
            }
            try {
                Database::transaction(DatabaseNames::PRIMARY, static fn () => $copy->getObject()->writePayloadAtBirth(self::copy($assignment)));
                self::fail('A completed copy was overwritten');
            } catch (DatabaseException $failure) {
                self::assertStringNotContainsString('retained-', $failure->getMessage());
            }
        });
        try {
            $this->asPerson(self::SURVIVOR, static fn () => Hilos::$db->accountMergeCopies[$assignment->id]->readForMerge());
            self::fail('Another owner read the private copy');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
        foreach (['{}', '{"give":"given","rows":{"identities":[],"passkeyCredentials":[],"secondFactors":[],"secondFactorBackupCodes":[]},'
            . '"identitiesMoved":0,"passwordKept":"survivor","secondFactorKept":"none"}'] as $wrongResult) {
            Database::sql('UPDATE `hilos_account_operation_result` SET `payload_json` = ? WHERE `assignment_id` = ?',
                [$wrongResult, $assignment->id]);
            Hilos::$db = new HilosSessionTestDbContext();
            Hilos::$db->configure();
            try {
                $this->asPerson(self::LOSER, static fn () => Hilos::$db->accountMergeCopies[$assignment->id]->readForMerge());
                self::fail('A copy without its exact saved give manifest was read');
            } catch (HilosException $failure) {
                self::assertStringNotContainsString('retained-', $failure->getMessage());
            }
        }
        Database::sql('DELETE FROM `hilos_account_operation_assignment` WHERE `id` = ?', [$assignment->id]);
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_merge_copy`')->rows());
    }

    /**
     * @param int $owner Personal participant, normally the canonical loser
     * @param int $loser Source account of this operation
     * @return AccountOperationAssignment Go/give created in the real holder frame
     */
    private function assignment(int $owner = self::LOSER, int $loser = self::LOSER): AccountOperationAssignment
    {
        return ExecutionContext::run(new ExecutionFrame(agentId: self::HOLDER_ID), static function () use ($owner, $loser) {
            $operation = Hilos::$db->accountOperations->actions->recordMerge(
                self::SURVIVOR, $loser, new AccountMergeChoice(null, null, null, null),
            );
            self::assertTrue($operation->actions->advance($operation->stage, AccountMergeStage::Go->value));
            return Hilos::$db->accountOperationAssignments->actions->record(
                $operation->id, AccountMergeStage::Go->value, AccountMergeDuty::Give->value,
                new AccountOperationOwnerAddress(HilosAgentType::HILOS_USER, (string)$owner),
                $owner, AccountOperationAssignmentPayload::fromJson('{}'),
            );
        });
    }

    /**
     * @param AccountOperationAssignment $assignment Real go/give assignment
     * @return AccountOperationResult Committed nonsecret give result
     */
    private function give(AccountOperationAssignment $assignment): AccountOperationResult
    {
        return $this->asPerson(self::LOSER, static fn () => AccountOperationParticipantStep::run(
            $assignment->id,
            static function (AccountOperationAssignment $assigned): AccountOperationResultPayload {
                $copy = Hilos::$db->accountMergeCopies->actions->record($assigned, self::copy($assigned));
                self::assertSame($assigned->id, $copy->assignmentId);
                self::assertNotNull(Database::sql('SELECT `payload_json` FROM `hilos_account_merge_copy` WHERE `assignment_id` = ?',
                    [$assigned->id])->firstRow()['payload_json']);
                try {
                    $copy->readForMerge();
                    self::fail('A private copy was read before its give result');
                } catch (ValidationException) {
                    self::assertTrue(true);
                }
                return self::givePayload();
            },
        ));
    }

    /**
     * @param AccountOperationAssignment $assignment Assignment whose references replace the literal fixture anchors
     * @return AccountMergeHandoverCopy Full typed secret-bearing storage fixture
     */
    private static function copy(AccountOperationAssignment $assignment): AccountMergeHandoverCopy
    {
        $fields = json_decode(self::COPY_JSON, true, flags: JSON_THROW_ON_ERROR);
        $fields[AccountMergeHandoverCopy::assignmentId] = $assignment->id;
        $fields[AccountMergeHandoverCopy::operationId] = $assignment->operationId;
        $fields[AccountMergeHandoverCopy::loserUserId] = $assignment->operation->loserUserId;
        return AccountMergeHandoverCopy::fromArray($fields);
    }

    /** @return AccountOperationResultPayload Exact independently specified nonsecret result */
    private static function givePayload(): AccountOperationResultPayload
    {
        return new AccountMergeGiveResult(
            [31, 32], [41], [51], [61], PasswordFate::SURVIVOR, AccountMergeGiveResult::SECOND_FACTOR_BOTH,
        )->toPayload();
    }

    /** @param AccountOperationAssignment $assignment Failed birth whose SQL, ORM and sync must all be absent */
    private function assertNoBirth(AccountOperationAssignment $assignment): void
    {
        self::assertFalse(Database::hasOpenTransactions());
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_merge_copy`')->rows());
        self::assertSame([], Database::sql('SELECT * FROM `hilos_account_operation_result`')->rows());
        self::assertSame([], self::drainFrames());
        $this->asPerson(self::LOSER, static function () use ($assignment): void {
            self::assertArrayNotHasKey($assignment->id, Hilos::$db->accountMergeCopies);
        });
    }

    /** @return list<DbSyncCreatedSignalData> Actual queued ORM frames */
    private static function drainFrames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof DbSyncCreatedSignalData) {
                $frames[] = $signal->data;
            }
        }
        return $frames;
    }

    /** @param bool $down Drop children before their assignment parent */
    private static function stubs(bool $down): void
    {
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            // external-boundary: the up stub has no direction suffix
            $suffix = $down ? '_down' : '';
            Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_' . $table . $suffix . '.sql'));
        }
    }
}

final class MergeCopyStorageHolder extends AbstractSessionsLibraryAgent
{
}
