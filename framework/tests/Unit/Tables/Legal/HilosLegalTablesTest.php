<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Tables\Legal;

use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Hilos;
use Hilos\Legal\LegalSettings;
use Hilos\Legal\LegalSettingsCatalog;
use Hilos\Tables\Legal\HilosLegalChecksTable;
use Hilos\Tables\Legal\HilosLegalDocumentsTable;
use Hilos\Tables\Legal\HilosLegalRevisionsTable;
use Hilos\Tables\Legal\HilosLegalSettingsTable;
use Hilos\Tests\Unit\Pages\Legal\LegalAdminTestCase;

/** Catalog table windows share aggregates, preserve declaration order and keep unknown records. */
final class HilosLegalTablesTest extends LegalAdminTestCase
{
    public function testDocumentsCarryLiveCoverageAndRevisionMetadata(): void
    {
        $table = new HilosLegalDocumentsTable();
        $snapshot = $table->getFullSnapshot();
        self::assertSame(1, $snapshot->totalCount);
        $row = $snapshot->rows[0];
        self::assertSame('terms', $row->rowKey);
        self::assertTrue($row->declared);
        self::assertSame([2, 0, 3], [$row->covered, $row->window, $row->lapsed]);
        self::assertSame('current', $row->revision['revisionId']);
        self::assertSame(['document' => $row->toArray()], $table->browserRow($row)['sources']);
        self::assertSame($row->toArray(), $row::fromArray($row->toArray())->toArray());
    }

    public function testChecksNameMissingRevisionsAndDoNotRepeatTheSqlRead(): void
    {
        new HilosLegalDocumentsTable()->getFullSnapshot();
        $table = new HilosLegalChecksTable();
        $snapshot = $table->getFullSnapshot();
        self::assertSame(4, $snapshot->totalCount);
        self::assertSame(3, $this->reads->reads);
        self::assertSame('undeclared_revision', $snapshot->rows[0]->rowKey);
        self::assertFalse($snapshot->rows[0]->ok);
        self::assertSame([
            ['document' => 'terms', 'revisionId' => 'gone', 'people' => 1],
        ], $snapshot->rows[0]->items);
        self::assertSame(['check' => $snapshot->rows[0]->toArray()], $table->browserRow($snapshot->rows[0])['sources']);
    }

    public function testRevisionWindowKeepsUnknownHistoryAfterDeclaredRevisions(): void
    {
        $table = new HilosLegalRevisionsTable();
        $snapshot = $table->getPage(new TableQueryDTO(filter: [HilosLegalRevisionsTable::FILTER_DOCUMENT => 'terms']));
        self::assertSame(['current', 'first', 'gone'], array_map(static fn ($row): string => $row->rowKey, $snapshot->rows));
        self::assertSame([2, 3, 1], array_map(static fn ($row): int => $row->heldCount, $snapshot->rows));
        self::assertSame([2, 5, 1], array_map(static fn ($row): int => $row->acceptedCount, $snapshot->rows));
        self::assertTrue($snapshot->rows[0]->current);
        self::assertSame('first', $snapshot->rows[1]->origin);
        self::assertFalse($snapshot->rows[2]->declared);
        self::assertNull($snapshot->rows[2]->revision);
        self::assertNull($snapshot->rows[2]->origin);
        self::assertSame(0, $table->getPage(new TableQueryDTO())->totalCount);
        self::assertSame(0, $table->getPage(new TableQueryDTO(filter: ['document' => 'unknown']))->totalCount);
        $window = $table->getPage(new TableQueryDTO(limit: 1, filter: ['document' => 'terms'], pageIndex: 1));
        self::assertSame(3, $window->totalCount);
        self::assertSame('first', $window->rows[0]->rowKey);
        self::assertSame(['revision' => $window->rows[0]->toArray()], $table->browserRow($window->rows[0])['sources']);
    }

    public function testRevisionDetailCountDoesNotDependOnTheHistoryWindow(): void
    {
        for ($index = 0; $index < 30; $index++) {
            $this->reads->accepted['gone-' . $index] = $index + 1;
        }
        $table = new HilosLegalRevisionsTable();
        $history = $table->getPage(new TableQueryDTO(limit: 25, filter: ['document' => 'terms']));
        self::assertNotContains('gone-29', array_column($history->rows, 'rowKey'));
        $detail = $table->getPage(new TableQueryDTO(limit: 25, filter: ['document' => 'terms', 'revision' => 'gone-29']));
        self::assertSame(1, $detail->totalCount);
        self::assertSame('gone-29', $detail->rows[0]->rowKey);
        self::assertSame(30, $detail->rows[0]->acceptedCount);
    }

    public function testAggregateTablesLeaveSourceEventsToTheAudience(): void
    {
        $change = new SourceChange(SourceChange::KIND_DB, HilosDbContext::legalAcceptances, '1', TableMutationType::Create);
        foreach ([new HilosLegalDocumentsTable(), new HilosLegalChecksTable(), new HilosLegalRevisionsTable()] as $table) {
            self::assertNull($table->buildMutationForSourceEvent($change));
        }
    }

    public function testSettingsUseDefaultsAndRefreshTheirOwnRows(): void
    {
        $previousSetting = Hilos::$setting;
        $previousDb = Hilos::$db;
        try {
            Hilos::$db = null;
            Hilos::$setting = new SettingsAccessor(LegalSettingsCatalog::class);
            $table = new HilosLegalSettingsTable();
            $snapshot = $table->getFullSnapshot();
            self::assertSame(LegalSettings::KEYS, array_map(static fn ($row): string => $row->rowKey, $snapshot->rows));
            self::assertSame(['checkbox', 'freeze'], array_map(static fn ($row): string => $row->value, $snapshot->rows));
            $mutation = $table->buildMutationForSourceEvent(new SourceChange(
                SourceChange::KIND_DB,
                HilosDbContext::settings,
                '1',
                TableMutationType::Create,
                ['key' => LegalSettings::CONSENT_FORM_KEY],
            ));
            self::assertNotNull($mutation);
            self::assertSame(LegalSettings::CONSENT_FORM_KEY, $mutation->rowKey);
        } finally {
            Hilos::$setting = $previousSetting;
            Hilos::$db = $previousDb;
        }
    }
}
