<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\AdminViewMode\ViewerFields;
use Hilos\AdminViewMode\WireField;
use Hilos\Backup\Anonymization\AnonymizationCompatibilityValidator;
use Hilos\Backup\Anonymization\AnonymizationCoverageValidator;
use Hilos\Backup\Anonymization\AnonymizationSqlBuilder;
use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Collection\StatusSubscribersActions;
use Hilos\Database\Actions\Item\StatusSubscriberActions;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\StatusSubscriber as EntityStatusSubscriber;
use Hilos\Database\Object\Collection\StatusSubscribers as ObjectStatusSubscribers;
use Hilos\Database\Object\Item\StatusSubscriber as ObjectStatusSubscriber;
use Hilos\Database\Object\Objects;
use Hilos\Database\Schema\EntitySchemaAudit;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Collection\StatusSubscribers;
use Hilos\Hilos;
use Hilos\Status\StatusAgent;
use Hilos\Testing\BreaksOrmState;
use Hilos\Testing\IntegrationTest;
use ReflectionProperty;

/** Real storage, conditional spends, detached audiences and restore verdicts; no guest or mail transport. */
#[IntegrationTest]
final class StatusSubscriberStorageIntegrationTest extends StatusSubscriberStorageIntegrationTestCase
{
    public function testCreationNormalizesAndKeepsSecretsOutsideEveryProjectionAndSyncFact(): void
    {
        $issued = Hilos::$db->statusSubscribers->actions->create('  Person@Example.org ', 1000, 'Europe/Warsaw');
        $row = $this->stored($issued->subscriberId);
        self::assertSame('person@example.org', $row['email']);
        self::assertSame(1000, (int)$row['subscribed_at']);
        self::assertSame(86401000, (int)$row['confirmation_expires_at']);
        self::assertSame(hash('sha256', $issued->token), $row['confirmation_token_hash']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $issued->token);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $row['unsubscribe_token']);
        self::assertNotSame($issued->token, $row['unsubscribe_token']);
        self::assertSame('Europe/Warsaw', $row['browser_time_zone']);
        self::assertSame($issued->subscriberId, Hilos::$db->statusSubscribers->findByEmail(' PERSON@example.org ')->id);
        self::assertSame($issued->subscriberId, Hilos::$db->statusSubscribers->findByConfirmationToken($issued->token)->id);
        self::assertSame($issued->subscriberId, Hilos::$db->statusSubscribers->findByUnsubscribeToken($row['unsubscribe_token'])->id);
        foreach ([
            Hilos::$db->statusSubscribers[$issued->subscriberId]->toArray(),
            Hilos::$db->statusSubscribers[$issued->subscriberId]->getObject()->toArray(),
            EntityStatusSubscriber::getById($issued->subscriberId)->toArray(),
            ...$this->syncMirror->rows,
        ] as $projection) {
            self::assertArrayNotHasKey('confirmation_token_hash', $projection);
            self::assertArrayNotHasKey('unsubscribe_token', $projection);
            self::assertStringNotContainsString($issued->token, json_encode($projection, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString($row['unsubscribe_token'], json_encode($projection, JSON_THROW_ON_ERROR));
        }
        self::assertNotEmpty($this->syncMirror->rows);
        $registry = PiiRegistry::collect();
        $hidden = ViewerFields::hide(
            ['email' => $row['email'], 'confirmed' => false],
            [
                'email' => WireField::column(HilosDbContext::statusSubscribers, EntityStatusSubscriber::email),
                'confirmed' => WireField::column(HilosDbContext::statusSubscribers, EntityStatusSubscriber::confirmed),
            ],
            static fn (string $collection, string $field): bool => $collection === HilosDbContext::statusSubscribers
                && in_array($field, $registry->notPersonalColumns(0, EntityStatusSubscriber::_table) ?? [], true),
        );
        self::assertSame(['email' => HiddenValue::mark(), 'confirmed' => false], $hidden);
        $delivered = null;
        Hilos::$db->statusSubscribers[$issued->subscriberId]->deliverMail(
            static function (string $email, string $unsubscribeToken, ?string $zone) use (&$delivered): void {
                $delivered = [$email, $unsubscribeToken, $zone];
            },
        );
        self::assertSame(['person@example.org', $row['unsubscribe_token'], 'Europe/Warsaw'], $delivered);
    }

    public function testConditionalConfirmHasOneWinnerAcrossStaleObjectsAndClearsTheHash(): void
    {
        $issued = Hilos::$db->statusSubscribers->actions->create('race@example.org', 1000);
        $stale = ObjectStatusSubscriber::fromEntity(EntityStatusSubscriber::getById($issued->subscriberId));
        self::assertSame($issued->subscriberId, Hilos::$db->statusSubscribers[$issued->subscriberId]->actions->confirm($issued->token, 2000));
        self::assertNull($stale->confirm($issued->token, 3000));
        self::assertNull(Hilos::$db->statusSubscribers[$issued->subscriberId]->actions->confirm($issued->token, 3000));
        $row = $this->stored($issued->subscriberId);
        self::assertSame(1, (int)$row['confirmed']);
        self::assertSame(2000, (int)$row['confirmed_at']);
        self::assertNull($row['confirmation_token_hash']);
        self::assertNull($row['confirmation_expires_at']);
        self::assertTrue(Hilos::$db->statusSubscribers[$issued->subscriberId]->confirmed);
        self::assertNull(Hilos::$db->statusSubscribers->findByConfirmationToken($issued->token));
        self::assertNull(Hilos::$db->statusSubscribers[$issued->subscriberId]->actions->issueConfirmation(4000));
        self::assertSame($row, $this->stored($issued->subscriberId));
    }

    public function testRollbackRestoresScalarStateAndTheSpendableHashTogether(): void
    {
        $issued = Hilos::$db->statusSubscribers->actions->create('rollback@example.org', 1000);
        $before = $this->stored($issued->subscriberId);
        Database::transactionStart();
        try {
            self::assertSame($issued->subscriberId,
                Hilos::$db->statusSubscribers[$issued->subscriberId]->actions->confirm($issued->token, 2000));
            self::assertNull($this->stored($issued->subscriberId)['confirmation_token_hash']);
        } finally {
            Database::transactionRollback();
        }
        self::assertSame($before, $this->stored($issued->subscriberId));
        self::assertFalse(Hilos::$db->statusSubscribers[$issued->subscriberId]->confirmed);
        self::assertSame($issued->subscriberId,
            Hilos::$db->statusSubscribers[$issued->subscriberId]->actions->confirm($issued->token, 3000));
    }

    public function testMailSecretReadRequiresTheSubscriptionOwner(): void
    {
        $issued = Hilos::$db->statusSubscribers->actions->create('owner@example.org', 1000);
        $token = ExecutionContext::push(new ExecutionFrame(agentId: 'unrelated-reader'));
        try {
            $this->expectException(WriteNotAllowedException::class);
            Hilos::$db->statusSubscribers[$issued->subscriberId]->deliverMail(static function (): void {
                self::fail('An unrelated reader must not receive the unsubscribe secret');
            });
        } finally {
            ExecutionContext::pop($token);
        }
    }

    public function testReplacementExpiryAndDeleteRecreateCannotReviveOldLinks(): void
    {
        $issued = Hilos::$db->statusSubscribers->actions->create('links@example.org', 1000, 'bad-zone');
        $row = $this->stored($issued->subscriberId);
        self::assertNull($row['browser_time_zone']);
        $renewed = Hilos::$db->statusSubscribers[$issued->subscriberId]->actions->issueConfirmation(2000);
        self::assertNotNull($renewed);
        self::assertSame(1000, (int)$this->stored($issued->subscriberId)['subscribed_at']);
        self::assertSame($row['unsubscribe_token'], $this->stored($issued->subscriberId)['unsubscribe_token']);
        self::assertNull(Hilos::$db->statusSubscribers->findByConfirmationToken($issued->token));
        self::assertNull(Hilos::$db->statusSubscribers[$issued->subscriberId]->actions->confirm($issued->token, 3000));
        self::assertNull(Hilos::$db->statusSubscribers[$issued->subscriberId]->actions->confirm($renewed->token, 86402000));
        self::assertSame(0, (int)$this->stored($issued->subscriberId)['confirmed']);
        $stale = Hilos::$db->statusSubscribers->findByConfirmationToken($renewed->token);
        Hilos::$db->statusSubscribers[$issued->subscriberId]->actions->delete();
        self::assertArrayNotHasKey($issued->subscriberId, Hilos::$db->statusSubscribers);
        $replacement = Hilos::$db->statusSubscribers->actions->create('links@example.org', 4000);
        self::assertGreaterThan($issued->subscriberId, $replacement->subscriberId);
        self::assertNull(Hilos::$db->statusSubscribers->findByUnsubscribeToken($row['unsubscribe_token']));
        self::assertNull(Hilos::$db->statusSubscribers->findByConfirmationToken($renewed->token));
        self::assertNull($stale->actions->confirm($renewed->token, 5000));
        self::assertSame($replacement->subscriberId,
            Hilos::$db->statusSubscribers[$replacement->subscriberId]->actions->confirm($replacement->token, 5000));
    }

    public function testTypedCountsAndRecipientCutoffsRemainBoundedAndDetached(): void
    {
        for ($index = 0; $index < 40; $index++) {
            $issued = Hilos::$db->statusSubscribers->actions->create(sprintf('recipient%02d@example.org', $index), 1000 + $index);
            if ($index < 37) {
                Hilos::$db->statusSubscribers[$issued->subscriberId]->actions->confirm($issued->token, $index === 36 ? 6000 : 2000);
            }
        }
        $counts = Hilos::$db->statusSubscribers->counts();
        self::assertSame([40, 37, 3], [$counts->total, $counts->confirmed, $counts->pending]);
        $later = Hilos::$db->statusSubscribers->actions->create('later@example.org', 1000);
        Hilos::$db->statusSubscribers[$later->subscriberId]->actions->confirm($later->token, 2000);
        $cold = new StatusSubscriberStorageDbContext();
        $cold->configure();
        self::assertFalse($cold->statusSubscribers->getObjectCollection()->isAllLoaded());
        self::assertSame(41, $cold->statusSubscribers->counts()->total);
        $first = $cold->statusSubscribers->confirmedRecipients(5000, $counts->maxSubscriberId, null, 1000);
        self::assertCount(32, $first);
        $second = $cold->statusSubscribers->confirmedRecipients(5000, $counts->maxSubscriberId, $first->last()->id, 32);
        self::assertCount(4, $second);
        self::assertCount(0, $cold->statusSubscribers->confirmedRecipients(5000, $counts->maxSubscriberId, $second->last()->id, 32));
        self::assertCount(0, $cold->statusSubscribers->confirmedRecipients(5000, $counts->maxSubscriberId, null, 0));
        self::assertFalse($cold->statusSubscribers->getObjectCollection()->isAllLoaded());
        self::assertSame([], (new ReflectionProperty(Objects::class, 'objects'))->getValue($cold->statusSubscribers->getObjectCollection()));
        self::assertSame([], EntitySchemaAudit::audit([EntityStatusSubscriber::class]));
        foreach ([TableConstants::ORDER_ASC, TableConstants::ORDER_DESC] as $direction) {
            foreach (['email', 'subscribed_at', 'confirmed'] as $field) {
                $page = $cold->statusSubscribers->queryPage(new TableQueryDTO(
                    sort: TableSortOrderDTO::of(new TableSortDTO($field, $direction), new TableSortDTO('id', $direction)), limit: 7,
                ));
                self::assertCount(7, $page[TableConstants::RESULT_KEY_ROWS]);
                self::assertSame(41, $page[TableConstants::RESULT_KEY_TOTAL_COUNT]);
                $rows = $page[TableConstants::RESULT_KEY_ROWS];
                $viewField = $field === 'subscribed_at' ? 'subscribedAt' : $field;
                for ($index = 1; $index < count($rows); $index++) {
                    $left = [$rows[$index - 1][$viewField], $rows[$index - 1]['id']];
                    $right = [$rows[$index][$viewField], $rows[$index]['id']];
                    self::assertTrue($direction === TableConstants::ORDER_ASC ? $left < $right : $left > $right);
                }
            }
        }
    }

    #[BreaksOrmState]
    public function testAnonymizationPreservesUniqueUndeliverableAddressesAndDisablesLinks(): void
    {
        $first = Hilos::$db->statusSubscribers->actions->create('one@example.org', 1000, 'UTC');
        $second = Hilos::$db->statusSubscribers->actions->create('two@example.org', 2000, 'Europe/Warsaw');
        Hilos::$db->statusSubscribers[$second->subscriberId]->actions->confirm($second->token, 3000);
        $schemas = LiveSchemaReader::read(0);
        $registry = new PiiRegistry(
            [0 => [EntityStatusSubscriber::_table => EntityStatusSubscriber::_pii]],
            [0 => [EntityStatusSubscriber::_table => EntityStatusSubscriber::_piiNotPersonal]],
        );
        AnonymizationCoverageValidator::validateLiveSchema(
            $registry, [0 => [EntityStatusSubscriber::_table => $schemas[EntityStatusSubscriber::_table]]],
        );
        AnonymizationCompatibilityValidator::validate($registry, 0, $schemas, static fn (): int => $second->subscriberId);
        Database::sql(new AnonymizationSqlBuilder('test-salt')->updateStatement(
            $schemas[EntityStatusSubscriber::_table], EntityStatusSubscriber::_pii,
        ));
        foreach ([$first->subscriberId, $second->subscriberId] as $id) {
            $row = $this->stored($id);
            self::assertSame('user' . $id . '@example.invalid', $row['email']);
            self::assertNull($row['confirmation_token_hash']);
            self::assertNull($row['unsubscribe_token']);
            self::assertNull($row['browser_time_zone']);
        }
        self::assertSame(1, (int)$this->stored($second->subscriberId)['confirmed']);
        self::assertSame(3000, (int)$this->stored($second->subscriberId)['confirmed_at']);
        self::assertNull(Hilos::$db->statusSubscribers->findByConfirmationToken($first->token));
        $delivered = false;
        Hilos::$db->statusSubscribers[$first->subscriberId]->deliverMail(
            static function () use (&$delivered): void { $delivered = true; },
        );
        self::assertFalse($delivered);
    }

    /**
     * @param int $id Persisted primary key
     * @return array<string, mixed> Independent SQL storage row, including DB-only secrets for assertions
     */
    private function stored(int $id): array
    {
        return Database::sql('SELECT * FROM `hilos_status_subscriber` WHERE `id` = ?', [$id])->first()->first();
    }
}

/** Keeps schema preparation and environment restoration outside marked test cases. */
abstract class StatusSubscriberStorageIntegrationTestCase extends FrameworkRollbackIntegrationTestCase
{
    private const string OWNER = 'status-subscriber-storage';

    protected StatusSubscriberSyncMirror $syncMirror;

    private ?int $ownerToken = null;
    /** @var array Original mirror registrations restored after each case */
    private array $previousMirrors = [];

    /** Raises the new table and mounts its production chain before the rollback scope opens. */
    protected function prepareIntegrationEnvironment(): void
    {
        parent::prepareIntegrationEnvironment();
        Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_status_subscriber.sql'));
        Schema::reset();
        Schema::initialize();
        OwnershipDeclaration::claimDb(StatusAgent::class, self::OWNER);
        $this->ownerToken = ExecutionContext::push(new ExecutionFrame(agentId: self::OWNER));
        Hilos::$db = new StatusSubscriberStorageDbContext();
        Hilos::$db->configure();
        $this->previousMirrors = (new ReflectionProperty(SourceChangeBus::class, 'mirrors'))->getValue();
        $this->syncMirror = new StatusSubscriberSyncMirror();
        SourceChangeBus::subscribe($this->syncMirror);
    }

    /** Drops the table after rollback and releases the owner before restoring the inherited environment. */
    protected function releaseIntegrationEnvironment(): void
    {
        try {
            Database::sqlRun('DROP TABLE IF EXISTS `hilos_status_subscriber`');
        } finally {
            (new ReflectionProperty(SourceChangeBus::class, 'mirrors'))->setValue(null, $this->previousMirrors);
            TruthSourceRegistry::unregisterAgent(self::OWNER);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(self::OWNER));
            if ($this->ownerToken !== null) {
                ExecutionContext::pop($this->ownerToken);
                $this->ownerToken = null;
            }
            parent::releaseIntegrationEnvironment();
        }
    }
}

/** Mounts only the new production chain while the environment base owns its own fixture tables. */
final class StatusSubscriberStorageDbContext extends HilosDbContext
{
    /** Configures the lazy production chain without requiring other Status feature dependencies. */
    public function configure(): void
    {
        $this->_objectCollections[self::statusSubscribers] = ObjectStatusSubscribers::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(self::statusSubscribers, StatusSubscribers::class, StatusSubscribersActions::class, StatusSubscriberActions::class);
    }
}

/** Captures the same scalar rows and diffs that feed the DB-sync publication. */
final class StatusSubscriberSyncMirror implements SourceMirrorSubscriberInterface
{
    /** @var list<array<string, mixed>> Non-secret subscription row facts */
    public array $rows = [];

    /**
     * @param SourceChange $change Store mutation
     * @param SourceChangeProvenance $provenance Origin of the write
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->sourceKey === HilosDbContext::statusSubscribers) {
            $this->rows[] = $change->row;
        }
    }
}
