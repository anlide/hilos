<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Database\Database;
use Demo\Chat\Hilos;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\JournalTriggerInstaller;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\ChangeLogEntry as EntityChangeLogEntry;
use Hilos\Database\Entity\Item\ChangeLogReceipt as EntityChangeLogReceipt;
use Hilos\Database\Migration;
use Hilos\Database\View\Item\ChangeLogReceipt;
use mysqli;
use ReflectionProperty;
use Throwable;

/**
 * Production receipt actions on the stand's journal.
 *
 * Two items loaded while the counter is still 2 stand in for two writers. InnoDB
 * runs each statement to completion; the case pins that a remembered counter is
 * not written back. Announcements are the local source bus and one queued DB
 * sync frame, released on commit.
 */
final class JournalReceiptActionsIntegrationTest extends IntegrationTestCase
{
    private const string OWNER_ID = 'journal-receipt-actions';

    private const string KEY_PREFIX = 'journal.receipt.actions.';

    private const string STALE_WRITER = 'stale-writer';

    private const array JOURNAL_CLEANUP_ORDER = [
        'hilos_change_log_value', 'hilos_change_log_change', 'hilos_change_log',
        'hilos_change_log_receipt', 'hilos_change_log_field', 'hilos_change_log_table',
    ];

    /** @var array<string, int> Journal high water marks before the case */
    private array $baseline = [];

    /** @var list<array<string, mixed>> Trigger definitions present before this case */
    private array $originalTriggers = [];

    private bool $triggersInstalled = false;

    private bool $grantHeld = false;

    private bool $reactionsSaved = false;

    private bool $routerReplaced = false;

    private ?SignalRouter $previousRouter = null;

    /** @var list<SourceChangeSubscriberInterface> Reactions restored after the observer is removed */
    private array $previousReactions = [];

    private ?JournalReceiptActionObserver $observer = null;

    /** @var list<SignalDTO> DB sync frames drained for the receipt collection */
    private array $frames = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRouter = Hilos::$sr;
        $this->routerReplaced = true;
        Hilos::$sr = new SignalRouter();
        $this->previousReactions = new ReflectionProperty(SourceChangeBus::class, 'reactions')->getValue();
        $this->reactionsSaved = true;
        $this->observer = new JournalReceiptActionObserver();
        SourceChangeBus::subscribe($this->observer);
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Migration::setMigrationListPath(dirname(__DIR__, 2) . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
        JournalTriggerFiles::setPath(dirname(__DIR__, 2) . '/backend/Database/Migration/Triggers');
        $this->originalTriggers = self::triggerCatalog();
        $database = $this->journalDatabase();
        foreach (self::JOURNAL_CLEANUP_ORDER as $table) {
            Database::sql("SELECT COALESCE(MAX(`id`), 0) AS `id` FROM {$database}.`{$table}`");
            $this->baseline[$table] = (int)Database::field('id');
        }
        JournalTriggerInstaller::apply();
        $this->triggersInstalled = true;
        OwnershipDeclaration::claimDb(JournalReceiptScope::class, self::OWNER_ID);
        $this->grantHeld = true;
    }

    protected function tearDown(): void
    {
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::setJournalReceiptId(null);
        if ($this->grantHeld) {
            TruthSourceRegistry::unregisterAgent(self::OWNER_ID);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(self::OWNER_ID));
        }
        if ($this->reactionsSaved) {
            new ReflectionProperty(SourceChangeBus::class, 'reactions')->setValue(null, $this->previousReactions);
        }
        if ($this->routerReplaced) {
            Hilos::$sr = $this->previousRouter;
        }
        Database::sqlRun('DELETE FROM `hilos_setting` WHERE `key` LIKE ?', [self::KEY_PREFIX . '%']);
        if ($this->triggersInstalled) {
            foreach (self::triggerCatalog() as $row) {
                Database::sql('DROP TRIGGER `' . $row['TRIGGER_NAME'] . '`');
            }
            $database = $this->journalDatabase();
            foreach (self::JOURNAL_CLEANUP_ORDER as $table) {
                Database::sqlRun(
                    "DELETE FROM {$database}.`{$table}` WHERE `id` > ?",
                    [$this->baseline[$table]],
                );
            }
            foreach ($this->originalTriggers as $row) {
                Database::sql('CREATE TRIGGER `' . $row['TRIGGER_NAME'] . '` ' . $row['ACTION_TIMING']
                    . ' ' . $row['EVENT_MANIPULATION'] . ' ON `' . $row['EVENT_OBJECT_TABLE']
                    . '` FOR EACH ROW ' . $row['ACTION_STATEMENT']);
            }
        }
        parent::tearDown();
    }

    /**
     * A retained local hold plus one outgoing hold leave the counter at 2.
     * The next two deltas, each from its own snapshot, store 1 and then 0.
     */
    public function testStaleSnapshotsDecrementTheDurableCounterToZero(): void
    {
        $created = $this->retainedReceipt('receipt.stale');
        $id = (int)$created->id;
        $this->assertSame(1, $this->storedReceipt($id)[EntityChangeLogReceipt::open_handovers]);
        $created->actions->adjustHandovers(1);
        $this->assertSame(2, $this->storedReceipt($id)[EntityChangeLogReceipt::open_handovers]);

        $first = Hilos::$db->changeLogReceipts[(string)$id];
        $second = Hilos::$db->changeLogReceipts[(string)$id];
        $this->assertNotSame($created, $first);
        $this->assertNotSame($first, $second);
        $this->assertSame(2, $first->open_handovers);
        $this->assertSame(2, $second->open_handovers);

        $first->actions->adjustHandovers(-1);
        $this->assertSame(1, $this->storedReceipt($id)[EntityChangeLogReceipt::open_handovers]);
        $this->assertSame(1, $first->open_handovers);
        $this->assertSame(2, $second->open_handovers);

        $this->forgetAnnouncements();
        $second->actions->deleteIfEmpty();
        $this->assertSame(1, $this->storedReceipt($id)[EntityChangeLogReceipt::open_handovers]);
        $this->assertAnnounced((string)$id, [], []);

        $second->actions->concludePart(-1, null, self::STALE_WRITER);
        $stored = $this->storedReceipt($id);
        $this->assertNotNull($stored);
        $this->assertSame(0, $stored[EntityChangeLogReceipt::open_handovers]);
        $this->assertSame(self::OWNER_ID, $stored[EntityChangeLogReceipt::agent]);
        $this->assertSame(0, $second->open_handovers);
        $this->assertSame(1, $first->open_handovers);
        $fresh = Hilos::$db->changeLogReceipts[(string)$id];
        $this->assertNotSame($first, $fresh);
        $this->assertNotSame($second, $fresh);
        $this->assertSame(0, $fresh->open_handovers);
        $this->assertSame(0, $fresh->countEntries());

        $this->forgetAnnouncements();
        $first->actions->deleteIfEmpty();
        $this->assertNull($this->storedReceipt($id));
        $this->assertAnnounced((string)$id, [TableMutationType::Delete], [SignalConstants::DB_SYNC_DELETED]);
    }

    /**
     * An outgoing hold is added to the durable counter. A snapshot that still
     * remembers an earlier value does not replace that counter with its memory.
     */
    public function testExtraOutgoingHoldsAreAddedToTheDurableCounter(): void
    {
        $created = $this->retainedReceipt('receipt.holds');
        $id = (int)$created->id;
        $created->actions->adjustHandovers(1);
        $this->assertSame(2, $this->storedReceipt($id)[EntityChangeLogReceipt::open_handovers]);

        $stale = Hilos::$db->changeLogReceipts[(string)$id];
        $untouched = Hilos::$db->changeLogReceipts[(string)$id];
        $this->assertNotSame($stale, $untouched);
        $this->assertSame(2, $stale->open_handovers);
        $created->actions->adjustHandovers(2);
        $this->assertSame(4, $this->storedReceipt($id)[EntityChangeLogReceipt::open_handovers]);
        $this->assertSame(2, $stale->open_handovers);

        $stale->actions->adjustHandovers(1);
        $this->assertSame(5, $this->storedReceipt($id)[EntityChangeLogReceipt::open_handovers]);
        $this->assertSame(5, $stale->open_handovers);
        $this->assertSame(2, $untouched->open_handovers);
        $fresh = Hilos::$db->changeLogReceipts[(string)$id];
        $this->assertNotSame($untouched, $fresh);
        $this->assertSame(5, $fresh->open_handovers);
    }

    /** A journal row keeps the receipt when the handover counter is already zero. */
    public function testJournalRowKeepsAReceiptWhoseCounterIsZero(): void
    {
        $receipt = Hilos::$db->changeLogReceipts->actions->createReceipt(
            JournalReceiptData::agent('receipt.occupied', self::OWNER_ID),
        );
        $id = (int)$receipt->id;
        $this->assertSame(0, $this->storedReceipt($id)[EntityChangeLogReceipt::open_handovers]);
        $this->writeJournalRow($id, 'occupied');
        $this->assertSame(1, $receipt->countEntries());
        $this->assertSame(1, $this->logCount($id));

        $this->forgetAnnouncements();
        $receipt->actions->deleteIfEmpty();
        $this->assertSame(0, $this->storedReceipt($id)[EntityChangeLogReceipt::open_handovers]);
        $this->assertSame(1, $this->logCount($id));
        $this->assertAnnounced((string)$id, [], []);
    }

    /** A receipt another writer already removed is not inserted again and publishes nothing. */
    public function testAlreadyGoneReceiptIsNotReplayed(): void
    {
        $created = $this->retainedReceipt('receipt.gone');
        $id = (int)$created->id;
        $held = Hilos::$db->changeLogReceipts[(string)$id];
        $database = $this->journalDatabase();
        Database::sqlRun(
            "DELETE FROM {$database}.`" . EntityChangeLogReceipt::_table . '` WHERE `'
            . EntityChangeLogReceipt::id . '` = ?',
            [$id],
        );
        $this->assertNull($this->storedReceipt($id));
        $newest = $this->newestReceiptId();

        $this->forgetAnnouncements();
        $held->actions->adjustHandovers(-1);
        $held->actions->concludePart(-1, 0, self::STALE_WRITER);
        $held->actions->deleteIfEmpty();
        $this->assertNull($this->storedReceipt($id));
        $this->assertSame($newest, $this->newestReceiptId());
        $this->assertAnnounced((string)$id, [], []);
    }

    /** Create, update and delete each announce once. A zero delta and a missed delete announce nothing. */
    public function testCreateUpdateAndDeleteAnnounceOnce(): void
    {
        $this->forgetAnnouncements();
        $receipt = $this->retainedReceipt('receipt.publish');
        $id = (int)$receipt->id;
        $idString = (string)$id;
        $this->assertSame(1, $this->storedReceipt($id)[EntityChangeLogReceipt::open_handovers]);
        $this->assertAnnounced($idString, [TableMutationType::Create], [SignalConstants::DB_SYNC_CREATED]);
        $this->assertSame(1, $this->frames[0]->data->row[EntityChangeLogReceipt::open_handovers]);

        $this->forgetAnnouncements();
        $receipt->actions->adjustHandovers(1);
        $this->assertSame(2, $this->storedReceipt($id)[EntityChangeLogReceipt::open_handovers]);
        $this->assertAnnounced($idString, [TableMutationType::Update], [SignalConstants::DB_SYNC_UPDATED]);
        $this->assertSame(2, $this->frames[0]->data->row[EntityChangeLogReceipt::open_handovers]);

        $this->forgetAnnouncements();
        $receipt->actions->adjustHandovers(0);
        $receipt->actions->concludePart(0, null, self::OWNER_ID);
        $receipt->actions->deleteIfEmpty();
        $this->assertSame(2, $this->storedReceipt($id)[EntityChangeLogReceipt::open_handovers]);
        $this->assertAnnounced($idString, [], []);

        $this->forgetAnnouncements();
        $receipt->actions->adjustHandovers(-2);
        $this->assertAnnounced($idString, [TableMutationType::Update], [SignalConstants::DB_SYNC_UPDATED]);

        $this->forgetAnnouncements();
        $fresh = Hilos::$db->changeLogReceipts[$idString];
        $this->assertNotSame($receipt, $fresh);
        $this->assertSame(0, $fresh->open_handovers);
        $fresh->actions->deleteIfEmpty();
        $this->assertNull($this->storedReceipt($id));
        $this->assertAnnounced($idString, [TableMutationType::Delete], [SignalConstants::DB_SYNC_DELETED]);
        $this->assertSame(0, $this->frames[0]->data->row[EntityChangeLogReceipt::open_handovers]);
    }

    /**
     * An open transaction holds the announcement. Commit releases it once.
     * Rollback drops it, leaves the row, and does not store the in-memory counter.
     */
    public function testCommitReleasesTheAnnouncementAndRollbackDropsIt(): void
    {
        $receipt = $this->retainedReceipt('receipt.transaction');
        $id = (int)$receipt->id;
        $this->forgetAnnouncements();
        Database::transactionStart();
        try {
            $receipt->actions->adjustHandovers(4);
            $during = Hilos::$db->changeLogReceipts[(string)$id];
            $this->assertSame(5, $during->open_handovers);
            $this->assertSame(5, $this->storedReceipt($id)[EntityChangeLogReceipt::open_handovers]);
            $this->assertSame([], $this->observer->changes);
            $this->captureFrames();
            $this->assertSame([], $this->frames);
            Database::transactionRollback();
        } catch (Throwable $failure) {
            Database::transactionRollbackSilent();
            throw $failure;
        }
        $this->captureFrames();
        $this->assertSame([], $this->observer->changes);
        $this->assertSame([], $this->frames);
        $this->assertSame(1, $this->storedReceipt($id)[EntityChangeLogReceipt::open_handovers]);
        $this->assertSame(1, $receipt->open_handovers);

        Database::transactionStart();
        try {
            $receipt->actions->adjustHandovers(-1);
            $receipt->actions->deleteIfEmpty();
            $this->assertSame([], $this->observer->changes);
            $this->captureFrames();
            $this->assertSame([], $this->frames);
            Database::transactionRollback();
        } catch (Throwable $failure) {
            Database::transactionRollbackSilent();
            throw $failure;
        }
        $this->captureFrames();
        $this->assertSame([], $this->observer->changes);
        $this->assertSame([], $this->frames);
        $this->assertSame(1, $this->storedReceipt($id)[EntityChangeLogReceipt::open_handovers]);
        $this->assertSame(1, $receipt->open_handovers);

        Database::transactionStart();
        try {
            $receipt->actions->adjustHandovers(1);
            $this->assertSame([], $this->observer->changes);
            $this->captureFrames();
            $this->assertSame([], $this->frames);
            Database::transactionCommit();
        } catch (Throwable $failure) {
            Database::transactionRollbackSilent();
            throw $failure;
        }
        $this->assertSame(2, $this->storedReceipt($id)[EntityChangeLogReceipt::open_handovers]);
        $this->assertAnnounced((string)$id, [TableMutationType::Update], [SignalConstants::DB_SYNC_UPDATED]);
        $committed = Hilos::$db->changeLogReceipts[(string)$id];
        $this->assertNotSame($receipt, $committed);
        $this->assertSame(2, $committed->open_handovers);
    }

    /** The item is loaded before the link dies. The delta is not sent again. */
    public function testLostLinkDoesNotReplayTheDelta(): void
    {
        $created = $this->retainedReceipt('receipt.lost');
        $id = (int)$created->id;
        $loaded = Hilos::$db->changeLogReceipts[(string)$id];
        $before = $this->storedReceipt($id);
        $newest = $this->newestReceiptId();
        $this->forgetAnnouncements();
        $this->killPrimaryConnection();
        $lost = false;
        try {
            $loaded->actions->adjustHandovers(-1);
        } catch (DatabaseException) {
            $lost = true;
        }
        $this->assertTrue($lost);
        $this->assertSame($before, $this->storedReceipt($id));
        $this->assertSame($newest, $this->newestReceiptId());
        $this->assertAnnounced((string)$id, [], []);

        $reloaded = Hilos::$db->changeLogReceipts[(string)$id];
        $reloaded->actions->adjustHandovers(-1);
        $this->assertSame($before[EntityChangeLogReceipt::open_handovers] - 1, $this->storedReceipt($id)[EntityChangeLogReceipt::open_handovers]);
        $this->assertSame($newest, $this->newestReceiptId());
    }

    /**
     * Migration cleanup removes an empty migration receipt and leaves one that has
     * a journal row. Any other channel is refused before a delete.
     */
    public function testMigrationCleanupDeletesOnlyAnEmptyMigrationReceipt(): void
    {
        $this->forgetAnnouncements();
        $empty = Hilos::$db->changeLogReceipts->actions->createReceipt(
            JournalReceiptData::migration('up', 1, '/tmp/journal-receipt-actions.sql'),
        );
        $emptyId = (int)$empty->id;
        $this->assertSame(0, $this->storedReceipt($emptyId)[EntityChangeLogReceipt::open_handovers]);
        $this->forgetAnnouncements();
        $empty->actions->deleteEmptyMigrationReceipt();
        $this->assertNull($this->storedReceipt($emptyId));
        $this->assertAnnounced((string)$emptyId, [TableMutationType::Delete], [SignalConstants::DB_SYNC_DELETED]);

        $occupied = Hilos::$db->changeLogReceipts->actions->createReceipt(
            JournalReceiptData::migration('down', 1, '/tmp/journal-receipt-actions-down.sql'),
        );
        $occupiedId = (int)$occupied->id;
        $this->writeJournalRow($occupiedId, 'migration');
        $this->assertSame(1, $occupied->countEntries());
        $this->forgetAnnouncements();
        $occupied->actions->deleteEmptyMigrationReceipt();
        $this->assertNotNull($this->storedReceipt($occupiedId));
        $this->assertSame(1, $this->logCount($occupiedId));
        $this->assertAnnounced((string)$occupiedId, [], []);

        $web = Hilos::$db->changeLogReceipts->actions->createReceipt(
            JournalReceiptData::web(null, null, null, 'receipt.web-channel', null),
            true,
        );
        $webId = (int)$web->id;
        $this->forgetAnnouncements();
        $refused = false;
        try {
            $web->actions->deleteEmptyMigrationReceipt();
        } catch (LogicException $failure) {
            $refused = true;
            $this->assertSame('Migration receipt cleanup refuses channel web', $failure->getMessage());
        }
        $this->assertTrue($refused);
        $this->assertSame(1, $this->storedReceipt($webId)[EntityChangeLogReceipt::open_handovers]);
        $this->assertAnnounced((string)$webId, [], []);
    }

    /**
     * @param string $action Attribution stored on the receipt
     */
    private function retainedReceipt(string $action): ChangeLogReceipt
    {
        return Hilos::$db->changeLogReceipts->actions->createReceipt(
            JournalReceiptData::agent($action, self::OWNER_ID),
            true,
        );
    }

    /**
     * @param string $idString Receipt id the announcements name
     * @param list<TableMutationType> $mutations Source facts, in order
     * @param list<string> $signals Queued DB sync names, in order
     */
    private function assertAnnounced(string $idString, array $mutations, array $signals): void
    {
        $this->captureFrames();
        $changes = [];
        foreach ($this->observer->changes as $index => $change) {
            $this->assertSame($idString, $change->sourceId);
            $this->assertSame(HilosDbContext::changeLogReceipts, $change->sourceKey);
            $this->assertSame(SourceChange::KIND_DB, $change->kind);
            $this->assertSame(SourceChangeProvenance::LocalWrite, $this->observer->provenances[$index]);
            $changes[] = $change->mutationType;
        }
        $this->assertSame($mutations, $changes);
        $names = [];
        foreach ($this->frames as $frame) {
            $this->assertSame(SignalSource::DB, $frame->signalSource->getSource());
            $this->assertInstanceOf(DbSyncSignalDataInterface::class, $frame->data);
            $this->assertSame(HilosDbContext::changeLogReceipts, $frame->data->collectionKey);
            $this->assertSame($idString, $frame->data->idString);
            $this->assertNull($frame->data->receiptId);
            $this->assertNull($frame->data->writerAgentId);
            $this->assertSame($frame->signalName->getName(), $frame->signalType->getType());
            $names[] = $frame->signalName->getName();
        }
        $this->assertSame($signals, $names);
    }

    private function captureFrames(): void
    {
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if (!$frame->data instanceof DbSyncSignalDataInterface
                || $frame->data->collectionKey !== HilosDbContext::changeLogReceipts) {
                continue;
            }
            $this->frames[] = $frame;
        }
    }

    private function forgetAnnouncements(): void
    {
        $this->observer->changes = [];
        $this->observer->provenances = [];
        $this->frames = [];
        $this->captureFrames();
        $this->frames = [];
    }

    /**
     * @param int $receiptId Receipt the trigger attributes the journal row to
     * @param string $key Suffix of the setting key that produces the row
     */
    private function writeJournalRow(int $receiptId, string $key): void
    {
        Database::setJournalReceiptId($receiptId);
        try {
            Database::sql(
                'INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)',
                [self::KEY_PREFIX . $key, 'string', 'written'],
            );
        } finally {
            Database::setJournalReceiptId(null);
        }
    }

    /** @return ?array{open_handovers: int, agent: ?string} Physical counter and writer, or null when the row is gone */
    private function storedReceipt(int $id): ?array
    {
        $database = $this->journalDatabase();
        $table = EntityChangeLogReceipt::_table;
        $handovers = EntityChangeLogReceipt::open_handovers;
        $agent = EntityChangeLogReceipt::agent;
        Database::sql(
            "SELECT `{$handovers}` AS `open_handovers`, `{$agent}` AS `agent` FROM {$database}.`{$table}`"
            . ' WHERE `' . EntityChangeLogReceipt::id . '` = ?',
            [$id],
        );
        $row = Database::row();
        if ($row === null) {
            return null;
        }

        return [
            EntityChangeLogReceipt::open_handovers => (int)$row['open_handovers'],
            EntityChangeLogReceipt::agent => $row['agent'] === null ? null : (string)$row['agent'],
        ];
    }

    private function logCount(int $receiptId): int
    {
        $database = $this->journalDatabase();
        Database::sql(
            'SELECT COUNT(*) AS `count` FROM ' . $database . '.`' . EntityChangeLogEntry::_table . '`'
            . ' WHERE `' . EntityChangeLogEntry::receipt_id . '` = ?',
            [$receiptId],
        );

        return (int)Database::field('count');
    }

    private function newestReceiptId(): int
    {
        $database = $this->journalDatabase();
        Database::sql(
            'SELECT COALESCE(MAX(`' . EntityChangeLogReceipt::id . '`), 0) AS `id` FROM '
            . $database . '.`' . EntityChangeLogReceipt::_table . '`',
        );

        return (int)Database::field('id');
    }

    private function journalDatabase(): string
    {
        return ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
    }

    private function killPrimaryConnection(): void
    {
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::sql('SELECT CONNECTION_ID() AS `id`');
        $connectionId = (int)Database::field('id');
        $killer = new mysqli(
            Hilos::$env[EnvConstants::DB_HOST]->string(),
            Hilos::$env[EnvConstants::DB_USERNAME]->string(),
            Hilos::$env[EnvConstants::DB_PASSWORD]->string(),
            Hilos::$env[EnvConstants::DB_DATABASE]->string(),
            Hilos::$env[EnvConstants::DB_PORT]->int(),
        );
        $killer->query('KILL ' . $connectionId);
        $killer->close();
    }

    /** @return list<array<string, mixed>> Primary schema trigger definitions */
    private static function triggerCatalog(): array
    {
        Database::sql(
            'SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT'
            . ' FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME',
        );

        return Database::rows();
    }
}

/** Records local receipt announcements and nothing else. */
final class JournalReceiptActionObserver implements SourceChangeSubscriberInterface
{
    /** @var list<SourceChange> Receipt mutations, in announcement order */
    public array $changes = [];

    /** @var list<SourceChangeProvenance> Provenance of each change, same order */
    public array $provenances = [];

    /**
     * @param SourceChange $change Announced mutation
     * @param SourceChangeProvenance $provenance Whether this process wrote it
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        if ($change->kind !== SourceChange::KIND_DB || $change->sourceKey !== HilosDbContext::changeLogReceipts) {
            return;
        }
        $this->changes[] = $change;
        $this->provenances[] = $provenance;
    }
}
