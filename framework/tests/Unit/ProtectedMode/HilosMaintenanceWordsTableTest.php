<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\AdminViewMode\WireField;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\ProtectedMode\ProtectedModeStubConstants;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Tables\ProtectedMode\HilosMaintenanceWordsTable;
use Hilos\Tables\ProtectedMode\HilosMaintenanceWordsTableRow;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the maintenance words table (HIL-1677).
 */
final class HilosMaintenanceWordsTableTest extends TestCase
{
    protected function tearDown(): void
    {
        Hilos::initBrowser();
        Hilos::resetBrowser();

        parent::tearDown();
    }

    /**
     * Real framework registry entries: default, manual_maintenance, and fallback restore row.
     */
    public function testSnapshotDeliversRealRegistryRowsWithDefaultOrder(): void
    {
        $table = new HilosMaintenanceWordsTable();
        $snapshot = $table->getFullSnapshot();

        self::assertSame(3, $snapshot->totalCount);
        self::assertCount(3, $snapshot->rows);

        /** @var list<HilosMaintenanceWordsTableRow> $rows */
        $rows = $snapshot->rows;
        self::assertSame(
            [
                ProtectedModeStubConstants::DEFAULT_OPERATION,
                ProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE,
                BackupAgent::RESTORE_OPERATION,
            ],
            array_map(static fn(HilosMaintenanceWordsTableRow $row): string => $row->operation, $rows),
        );

        $defaultRow = $rows[0];
        self::assertSame(ProtectedModeStubConstants::DEFAULT_OPERATION, $defaultRow->getRowKey());
        self::assertSame('Maintenance in progress', $defaultRow->title);
        self::assertStringContainsString('maintenance operation finishes', (string)$defaultRow->message);
        self::assertStringContainsString('being verified', (string)$defaultRow->bannerMessage);
        self::assertFalse($defaultRow->usesDefault);

        $manualRow = $rows[1];
        self::assertSame(ProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE, $manualRow->getRowKey());
        self::assertSame('Closed for maintenance', $manualRow->title);
        self::assertStringContainsString('check back later', (string)$manualRow->message);
        self::assertStringContainsString('Turn it off in the Maintenance section', (string)$manualRow->bannerMessage);
        self::assertFalse($manualRow->usesDefault);

        // Missing restore entry produces fallback row with exact three nulls
        $restoreRow = $rows[2];
        self::assertSame(BackupAgent::RESTORE_OPERATION, $restoreRow->getRowKey());
        self::assertNull($restoreRow->title);
        self::assertNull($restoreRow->message);
        self::assertNull($restoreRow->bannerMessage);
        self::assertTrue($restoreRow->usesDefault);
    }

    /**
     * Project facade override via the running app facade reflects in table rows.
     */
    public function testProjectFacadeOverrideReachesTheTableRows(): void
    {
        WordsOverrideTestHilos::initBrowser();

        $table = new HilosMaintenanceWordsTable();
        $snapshot = $table->getFullSnapshot();

        self::assertSame(3, $snapshot->totalCount);
        /** @var list<HilosMaintenanceWordsTableRow> $rows */
        $rows = $snapshot->rows;

        self::assertSame('Custom maintenance title', $rows[0]->title);
        self::assertSame('Custom maintenance message', $rows[0]->message);
        self::assertSame('Custom banner message', $rows[0]->bannerMessage);
        self::assertFalse($rows[0]->usesDefault);
    }

    /**
     * When restore is defined in the project registry, it produces an explicit row (usesDefault=false),
     * and additional custom operations appear in registry order.
     */
    public function testCustomProjectKeyAndExplicitRestore(): void
    {
        WordsCustomKeysTestHilos::initBrowser();

        $table = new HilosMaintenanceWordsTable();
        $snapshot = $table->getFullSnapshot();

        self::assertSame(4, $snapshot->totalCount);
        /** @var list<HilosMaintenanceWordsTableRow> $rows */
        $rows = $snapshot->rows;

        self::assertSame(
            [
                ProtectedModeStubConstants::DEFAULT_OPERATION,
                ProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE,
                BackupAgent::RESTORE_OPERATION,
                'cluster_upgrade',
            ],
            array_map(static fn(HilosMaintenanceWordsTableRow $row): string => $row->operation, $rows),
        );

        // Restore is explicitly provided
        $restoreRow = $rows[2];
        self::assertSame(BackupAgent::RESTORE_OPERATION, $restoreRow->operation);
        self::assertSame('Restoring from backup', $restoreRow->title);
        self::assertSame('Database is being restored.', $restoreRow->message);
        self::assertSame('Restore is in progress.', $restoreRow->bannerMessage);
        self::assertFalse($restoreRow->usesDefault);

        // Additional project key
        $upgradeRow = $rows[3];
        self::assertSame('cluster_upgrade', $upgradeRow->operation);
        self::assertSame('Upgrading cluster', $upgradeRow->title);
        self::assertSame('Node upgrade in progress.', $upgradeRow->message);
        self::assertSame('Cluster upgrade running.', $upgradeRow->bannerMessage);
        self::assertFalse($upgradeRow->usesDefault);
    }

    /**
     * In-memory pagination handles page index and limit properly.
     */
    public function testPaginationWithLimitAndOffset(): void
    {
        $table = new HilosMaintenanceWordsTable();
        $snapshot = $table->getPage(new TableQueryDTO(limit: 1, pageIndex: 1));

        self::assertSame(3, $snapshot->totalCount);
        self::assertCount(1, $snapshot->rows);
        /** @var HilosMaintenanceWordsTableRow $row */
        $row = $snapshot->rows[0];
        self::assertSame(ProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE, $row->operation);
    }

    /**
     * Row DTO serialization, deserialization, keyField, and browserRow round-trip.
     */
    public function testRowSerializationRoundTripAndBrowserRowEnvelope(): void
    {
        $table = new HilosMaintenanceWordsTable();
        $row = new HilosMaintenanceWordsTableRow(
            operation: 'test_op',
            title: 'Test Title',
            message: 'Test Message',
            bannerMessage: 'Test Banner',
            usesDefault: false,
        );

        self::assertSame('test_op', $row->getRowKey());
        self::assertSame('operation', HilosMaintenanceWordsTableRow::keyField());

        $array = $row->toArray();
        self::assertSame([
            HilosMaintenanceWordsTableRow::operation => 'test_op',
            HilosMaintenanceWordsTableRow::title => 'Test Title',
            HilosMaintenanceWordsTableRow::message => 'Test Message',
            HilosMaintenanceWordsTableRow::bannerMessage => 'Test Banner',
            HilosMaintenanceWordsTableRow::usesDefault => false,
        ], $array);

        $restored = HilosMaintenanceWordsTableRow::fromArray($array);
        self::assertSame($row->operation, $restored->operation);
        self::assertSame($row->title, $restored->title);
        self::assertSame($row->message, $restored->message);
        self::assertSame($row->bannerMessage, $restored->bannerMessage);
        self::assertSame($row->usesDefault, $restored->usesDefault);

        $envelope = $table->browserRow($row);
        self::assertSame('test_op', $envelope[BrowserPageSignalData::rowKey]);
        self::assertSame(['stub' => $array], $envelope[BrowserPageSignalData::sources]);
        self::assertArrayNotHasKey('id', $envelope);
    }

    /**
     * Table has no DB/RT sources and produces no row mutations for source events.
     */
    public function testBuildMutationForSourceEventAlwaysReturnsNull(): void
    {
        $table = new HilosMaintenanceWordsTable();

        $change = new SourceChange(
            SourceChange::KIND_DB,
            'any_collection',
            '1',
            TableMutationType::Update,
            [],
        );

        self::assertNull($table->buildMutationForSourceEvent($change));
    }

    /**
     * All row fields are declared notPersonal for the admin view mode.
     */
    public function testWireFieldsDeclaresAllFieldsNotPersonal(): void
    {
        $table = new HilosMaintenanceWordsTable();
        $fields = $table->wireFields();

        self::assertSame(
            [
                HilosMaintenanceWordsTableRow::operation,
                HilosMaintenanceWordsTableRow::title,
                HilosMaintenanceWordsTableRow::message,
                HilosMaintenanceWordsTableRow::bannerMessage,
                HilosMaintenanceWordsTableRow::usesDefault,
            ],
            array_keys($fields),
        );

        foreach ($fields as $fieldName => $wireField) {
            self::assertInstanceOf(WireField::class, $wireField, "Field {$fieldName} must be a WireField");
            self::assertFalse($wireField->isColumn(), "Field {$fieldName} must not be a column");
            self::assertFalse($wireField->isSetting(), "Field {$fieldName} must not be a setting");
            self::assertNull($wireField->fields, "Field {$fieldName} must not be nested");
        }
    }
}

/**
 * Fixture overriding protected-mode stub copy in project facade.
 */
abstract class WordsOverrideTestHilos extends Hilos
{
    protected const array PROTECTED_MODE_STUB = [
        ProtectedModeStubConstants::DEFAULT_OPERATION => [
            ProtectedModeStubConstants::TITLE => 'Custom maintenance title',
            ProtectedModeStubConstants::MESSAGE => 'Custom maintenance message',
            ProtectedModeStubConstants::BANNER_MESSAGE => 'Custom banner message',
        ],
        ProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE => [
            ProtectedModeStubConstants::TITLE => 'Custom closed title',
            ProtectedModeStubConstants::MESSAGE => 'Custom closed message',
            ProtectedModeStubConstants::BANNER_MESSAGE => 'Custom closed banner',
        ],
    ];

    protected static function createDb(): HilosDbContext
    {
        return new WordsTestDummyDbContext();
    }
}

/**
 * Fixture declaring an explicit restore entry and an additional custom operation.
 */
abstract class WordsCustomKeysTestHilos extends Hilos
{
    protected const array PROTECTED_MODE_STUB = [
        ProtectedModeStubConstants::DEFAULT_OPERATION => [
            ProtectedModeStubConstants::TITLE => 'Default title',
            ProtectedModeStubConstants::MESSAGE => 'Default message',
            ProtectedModeStubConstants::BANNER_MESSAGE => 'Default banner',
        ],
        ProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE => [
            ProtectedModeStubConstants::TITLE => 'Manual title',
            ProtectedModeStubConstants::MESSAGE => 'Manual message',
            ProtectedModeStubConstants::BANNER_MESSAGE => 'Manual banner',
        ],
        BackupAgent::RESTORE_OPERATION => [
            ProtectedModeStubConstants::TITLE => 'Restoring from backup',
            ProtectedModeStubConstants::MESSAGE => 'Database is being restored.',
            ProtectedModeStubConstants::BANNER_MESSAGE => 'Restore is in progress.',
        ],
        'cluster_upgrade' => [
            ProtectedModeStubConstants::TITLE => 'Upgrading cluster',
            ProtectedModeStubConstants::MESSAGE => 'Node upgrade in progress.',
            ProtectedModeStubConstants::BANNER_MESSAGE => 'Cluster upgrade running.',
        ],
    ];

    protected static function createDb(): HilosDbContext
    {
        return new WordsTestDummyDbContext();
    }
}

final class WordsTestDummyDbContext extends HilosDbContext
{
    public function configure(): void
    {
    }
}
