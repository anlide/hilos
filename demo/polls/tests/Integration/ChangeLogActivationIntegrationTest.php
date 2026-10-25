<?php

declare(strict_types=1);

namespace Demo\Polls\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Demo\Polls\Database\Database;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\ChangeLogPartitions;
use Hilos\Database\ChangeLog\JournalReceiptData;
use Hilos\Database\ChangeLog\JournalReceiptScope;
use Hilos\Database\ChangeLog\JournalTriggerFiles;
use Hilos\Database\ChangeLog\JournalTriggerInstaller;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedFilter;
use Hilos\Database\ChangeLog\Section\ChangeLogFeedItemKind;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Database\MigrationClaim;
use Hilos\Database\MigrationClaimHolder;
use Hilos\Database\Schema\JournalCoverageGuard;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * Exercises this demo's actual schema and committed generated triggers on its test stand.
 * SQL here inspects schema/journal tables outside the primary ORM and supplies the write
 * past the application that the journal must also record. No application reader is copied.
 */
final class ChangeLogActivationIntegrationTest extends IntegrationTestCase
{
    private const string KEY_PREFIX = 'journal.activation.hil1453.';

    private const array JOURNAL_CLEANUP_ORDER = [
        'hilos_change_log_value', 'hilos_change_log_change', 'hilos_change_log',
        'hilos_change_log_receipt', 'hilos_change_log_field', 'hilos_change_log_table',
    ];

    private const array JOURNALED_TABLES = ['hilos_identity', 'hilos_second_factor', 'hilos_setting', 'hilos_user'];

    /** @var array<string, int> Journal row high water marks before the case */
    private array $baseline = [];

    /** @var list<array<string, mixed>> Live trigger catalog saved for restoration */
    private array $originalTriggers = [];

    /** @var int Caller connection restored at the end of the case */
    private int $originalIndex;

    /** @throws HilosException When the real databases or generated triggers cannot be prepared */
    protected function setUp(): void
    {
        parent::setUp();
        $this->originalIndex = Database::getCurrentIndex();
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Migration::setMigrationListPath(dirname(__DIR__, 2) . '/backend/Database/Migration');
        Migration::setMigrationName('Schema');
        JournalTriggerFiles::setPath(dirname(__DIR__, 2) . '/backend/Database/Migration/Triggers');
        $this->originalTriggers = self::triggerCatalog();
        $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        foreach (self::JOURNAL_CLEANUP_ORDER as $table) {
            Database::sql("SELECT COALESCE(MAX(`id`), 0) AS `id` FROM {$database}.`{$table}`");
            $this->baseline[$table] = (int)Database::field('id');
        }
        self::installTriggers();
    }

    /** @throws HilosException When fixture cleanup or restoring the original triggers fails */
    protected function tearDown(): void
    {
        try {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            Database::transactionRollback();
            Database::setJournalReceiptId(null);
            self::dropTriggers();
            Database::sqlRun('DELETE FROM `hilos_setting` WHERE `key` LIKE ?', [self::KEY_PREFIX . '%']);
            $database = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
            foreach (self::JOURNAL_CLEANUP_ORDER as $table) {
                Database::sqlRun("DELETE FROM {$database}.`{$table}` WHERE `id` > ?", [$this->baseline[$table]]);
            }
            foreach ($this->originalTriggers as $row) {
                Database::sql('CREATE TRIGGER `' . $row['TRIGGER_NAME'] . '` ' . $row['ACTION_TIMING']
                    . ' ' . $row['EVENT_MANIPULATION'] . ' ON `' . $row['EVENT_OBJECT_TABLE']
                    . '` FOR EACH ROW ' . $row['ACTION_STATEMENT']);
            }
        } finally {
            Database::useConnection($this->originalIndex);
            parent::tearDown();
        }
    }

    /** @throws HilosException When the configured schema, partitions or coverage cannot be read */
    public function testDerivedDatabaseSchemaPartitionsAndCoverage(): void
    {
        Database::initialize(initHilos: false);
        self::assertSame([0, 1], Database::getConfiguredIndices());
        self::assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, Database::getCurrentIndex());
        $primary = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $journal = Database::getConnectionConfig(ChangeLogDatabase::CONNECTION_INDEX);
        self::assertSame(ChangeLogDatabase::name($primary->database), $journal->database);
        foreach (['host', 'port', 'user', 'password', 'charset', 'socket'] as $field) {
            self::assertSame($primary->$field, $journal->$field, $field);
        }
        Database::sql('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
            [$journal->database]);
        $expected = self::JOURNAL_CLEANUP_ORDER;
        sort($expected, SORT_STRING);
        self::assertSame($expected, array_column(Database::rows(), 'TABLE_NAME'));

        $month = new DateTimeImmutable('first day of this month 00:00:00', new DateTimeZone('UTC'));
        foreach (ChangeLogPartitions::TABLES as $table) {
            Database::sql('SELECT PARTITION_NAME FROM INFORMATION_SCHEMA.PARTITIONS'
                . ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY PARTITION_ORDINAL_POSITION',
                [$journal->database, $table]);
            $partitions = array_column(Database::rows(), 'PARTITION_NAME');
            self::assertSame('p_future', $partitions[array_key_last($partitions)]);
            self::assertContains('p' . $month->format('Ym'), $partitions);
            self::assertContains('p' . $month->modify('+24 months')->format('Ym'), $partitions);
            self::assertCount(26, $partitions);
        }
        JournalCoverageGuard::assertMountedTablesPlaced();
        $overview = Hilos::$db->changeLogTables->sectionOverview();
        self::assertSame(self::JOURNALED_TABLES, $overview->journaledTables);
        self::assertGreaterThan(0, $overview->liveTables);
        self::assertGreaterThan(0, $overview->journalBytes);
    }

    /** @throws HilosException When the committed trigger set cannot be installed or read */
    public function testCommittedTriggerSetInstallsAndRepeatLeavesItUntouched(): void
    {
        self::dropTriggers();
        self::assertSame([], self::triggerCatalog());
        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        self::installTriggers();
        self::assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $first = self::triggerCatalog();
        $names = [];
        foreach (self::JOURNALED_TABLES as $table) {
            foreach (['delete', 'insert', 'update'] as $event) {
                $names[] = 'hilos_cl_' . $table . '_after_' . $event;
            }
        }
        self::assertSame($names, array_column($first, 'TRIGGER_NAME'));
        self::installTriggers();
        // CREATED is included: re-creating a matching body would not count as an untouched repeat.
        self::assertSame($first, self::triggerCatalog());
    }

    /** @throws HilosException When a receipt, fixture write, rollback or reader operation fails */
    public function testReceiptUnattributedWriteAndRollbackShareTheDataTransaction(): void
    {
        $since = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $receiptId = JournalReceiptScope::run(
            JournalReceiptData::web(11, null, 31, 'setting.create', 'activation-test'),
            static function (): ?int {
                self::insertSetting(self::KEY_PREFIX . 'receipt');
                return JournalReceiptScope::currentId();
            },
        );
        self::assertNotNull($receiptId);
        $entries = Hilos::$db->changeLogEntries->receiptEntries($receiptId, null, 50);
        self::assertCount(1, $entries);
        self::assertSame('hilos_setting', $entries->get(0)->table);
        self::assertSame('create', $entries->get(0)->mutation);
        self::assertNull(JournalReceiptScope::currentId());
        self::insertSetting(self::KEY_PREFIX . 'unattributed');
        $filter = new ChangeLogFeedFilter(table: 'hilos_setting', since: $since);
        $feed = Hilos::$db->changeLogReceipts->feedWindow($filter, null, TableAnchorDirection::After, 50);
        self::assertCount(2, $feed);
        self::assertSame(ChangeLogFeedItemKind::ENTRY, $feed->get(0)->kind);
        self::assertNull($feed->get(0)->receipt);
        self::assertNull($feed->get(0)->entry->receiptId);
        self::assertSame(ChangeLogFeedItemKind::RECEIPT, $feed->get(1)->kind);
        self::assertSame($receiptId, $feed->get(1)->receipt->id);
        self::assertSame('web', $feed->get(1)->receipt->channel);
        self::assertSame('setting.create', $feed->get(1)->receipt->action);

        $beforeRollback = Hilos::$db->changeLogTables->sectionOverview()->journalEntries;
        $rollbackReceipt = JournalReceiptScope::run(
            JournalReceiptData::web(11, null, 31, 'setting.rollback', 'activation-test'),
            function () use ($beforeRollback): ?int {
                Database::transactionStart();
                try {
                    self::insertSetting(self::KEY_PREFIX . 'rollback');
                    self::assertSame($beforeRollback + 1, Hilos::$db->changeLogTables->sectionOverview()->journalEntries);
                    self::assertCount(1, Hilos::$db->changeLogEntries->receiptEntries(JournalReceiptScope::currentId(), null, 50));
                    return JournalReceiptScope::currentId();
                } finally {
                    Database::transactionRollback();
                }
            },
        );
        self::assertNotNull($rollbackReceipt);
        self::assertSame($beforeRollback, Hilos::$db->changeLogTables->sectionOverview()->journalEntries);
        self::assertSame([], Hilos::$db->changeLogEntries->receiptEntries($rollbackReceipt, null, 50)->toArray());
        Database::sql('SELECT COUNT(*) AS `count` FROM `hilos_setting` WHERE `key` = ?', [self::KEY_PREFIX . 'rollback']);
        self::assertSame(0, (int)Database::field('count'));
        self::assertSame(2, Hilos::$db->changeLogReceipts->feedCount($filter));
        self::assertNull(JournalReceiptScope::currentId());
    }

    /**
     * @param string $key Fixture setting key, outside the catalog
     * @throws DatabaseException When the direct fixture write fails
     */
    private static function insertSetting(string $key): void
    {
        Database::sqlRun('INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES (?, ?, ?)', [$key, 'string', 'first']);
    }

    /** @throws DatabaseException When the live primary trigger catalog cannot be read */
    private static function dropTriggers(): void
    {
        foreach (self::triggerCatalog() as $row) {
            Database::sql('DROP TRIGGER `' . $row['TRIGGER_NAME'] . '`');
        }
    }

    /** @throws HilosException When the primary rollout claim or trigger installer fails */
    private static function installTriggers(): void
    {
        $index = Database::getCurrentIndex();
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $holder = MigrationClaimHolder::process();
        MigrationClaim::take($holder);
        try {
            Database::useConnection($index);
            JournalTriggerInstaller::apply();
        } finally {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            MigrationClaim::release($holder);
            Database::useConnection($index);
        }
    }

    /**
     * @return list<array<string, mixed>> Primary trigger definitions, including their creation time
     * @throws DatabaseException When the primary trigger catalog cannot be read
     */
    private static function triggerCatalog(): array
    {
        Database::sql('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT, CREATED'
            . ' FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME');
        return Database::rows();
    }
}
