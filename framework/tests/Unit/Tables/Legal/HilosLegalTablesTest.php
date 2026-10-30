<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Tables\Legal;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\View\Collection\LegalAcceptances;
use Hilos\Hilos;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSettings;
use Hilos\Legal\LegalSettingsCatalog;
use Hilos\Legal\LegalSignificance;
use Hilos\Pages\Legal\LegalAdminAudience;
use Hilos\Tables\Legal\HilosLegalChecksTable;
use Hilos\Tables\Legal\HilosLegalDocumentTableRow;
use Hilos\Tables\Legal\HilosLegalDocumentsTable;
use Hilos\Tables\Legal\HilosLegalRevisionsTable;
use Hilos\Tables\Legal\HilosLegalSettingsTable;
use Hilos\Tests\Unit\Pages\Legal\LegalAdminTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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
        self::assertSame('Terms of use', $row->documentLabel);
        self::assertSame('2020-02-01', $row->publishedOn);
        self::assertSame($row->revision['publishedOn'], $row->publishedOn);
        self::assertSame(['document' => $row->toArray()], $table->browserRow($row)['sources']);
        self::assertSame($row->toArray(), $row::fromArray($row->toArray())->toArray());
    }

    /** Historical document rows keep their key, label and nullable publication in a roundtrip. */
    public function testDocumentsKeepRecordedOnlyKeys(): void
    {
        $this->useDocumentSources();
        $table = new HilosLegalDocumentsTable();
        $snapshot = $table->getFullSnapshot();
        self::assertSame(4, $snapshot->totalCount);
        self::assertSame(['terms', 'privacy', 'legacy-b', 'legacy-a'], array_column($snapshot->rows, 'rowKey'));
        $row = $snapshot->rows[2];
        self::assertFalse($row->declared);
        self::assertSame('legacy-b', $row->documentLabel);
        self::assertNull($row->publishedOn);
        self::assertNull($row->revision);
        self::assertSame([0, 0, 0], [$row->covered, $row->window, $row->lapsed]);
        self::assertSame($row->toArray(), HilosLegalDocumentTableRow::fromArray($row->toArray())->toArray());
    }

    /** The added label remains a required string on the typed row boundary. */
    public function testDocumentRowRefusesAMissingLabel(): void
    {
        $payload = new HilosLegalDocumentsTable()->getFullSnapshot()->rows[0]->toArray();
        unset($payload['documentLabel']);
        $this->expectException(InvalidFormatException::class);
        HilosLegalDocumentTableRow::fromArray($payload);
    }

    /** A present calendar publication field is never coerced from another wire type. */
    public function testDocumentRowRefusesANonStringPublication(): void
    {
        $payload = new HilosLegalDocumentsTable()->getFullSnapshot()->rows[0]->toArray();
        $payload['publishedOn'] = 20200201;
        $this->expectException(InvalidFormatException::class);
        HilosLegalDocumentTableRow::fromArray($payload);
    }

    /** Labels inherit the recorded document column's verdict; publication is catalog metadata. */
    public function testDocumentFieldOriginsFollowTheirSources(): void
    {
        $fields = new HilosLegalDocumentsTable()->wireFields();
        self::assertEquals(WireField::column(HilosDbContext::legalAcceptances, 'document'), $fields['documentLabel']);
        self::assertEquals($fields['rowKey'], $fields['documentLabel']);
        self::assertEquals(WireField::notPersonal(), $fields['publishedOn']);
    }

    /** The browser's opening window takes its publication order from the backend declaration. */
    public function testDocumentsDeclareNewestPublicationAsTheirServerDefault(): void
    {
        self::assertSame(
            [['field' => 'publishedOn', 'direction' => 'desc']],
            new HilosLegalDocumentsTable()->defaultSort()->toArray(),
        );
    }

    /** Search reads labels and keys case-insensitively, without matching unrelated metadata. */
    public function testDocumentsSearchLabelsAndKeys(): void
    {
        $this->useDocumentSources();
        $table = new HilosLegalDocumentsTable();
        foreach ([
            'OF USE' => ['terms'],
            'pRiVaCy' => ['privacy'],
            'LEGACY-B' => ['legacy-b'],
            'current' => [],
        ] as $term => $expectedKeys) {
            $snapshot = $table->getPage(new TableQueryDTO(search: $term));
            self::assertSame($expectedKeys, array_column($snapshot->rows, 'rowKey'), $term);
            self::assertSame(count($expectedKeys), $snapshot->totalCount, $term);
        }
    }

    /** Membership uses the full current source and declared search fields, independently of the window. */
    public function testDocumentMembershipUsesTheCurrentSearchedSet(): void
    {
        $this->useDocumentSources();
        $table = new HilosLegalDocumentsTable();
        $query = new TableQueryDTO(search: 'OF USE', limit: 1, pageIndex: 10);
        self::assertSame([], $table->getPage($query)->rows);
        self::assertTrue($table->containsRow('terms', $query));
        self::assertFalse($table->containsRow('privacy', $query));
        self::assertFalse($table->containsRow('absent', new TableQueryDTO()));
        self::assertTrue($table->containsRow('privacy', new TableQueryDTO(search: 'pRiVaCy')));
        self::assertTrue($table->containsRow('legacy-b', new TableQueryDTO(search: 'LEGACY-B')));
        self::assertFalse($table->containsRow('terms', new TableQueryDTO(search: 'current')));
        self::assertFalse($table->containsRow('terms', new TableQueryDTO(search: 'OF USE', shownFields: ['rowKey'])));
        $this->useDocumentSources([]);
        self::assertFalse($table->containsRow('legacy-b', new TableQueryDTO(search: 'LEGACY-B')));
    }

    /**
     * The same table query serves all five scalar orders in both directions.
     *
     * @param string $field Requested scalar field
     * @param string $direction Requested direction
     * @param list<string> $expectedKeys Exact order including numeric, null and row-key ties
     */
    #[DataProvider('documentOrders')]
    public function testDocumentsOrderScalarFields(string $field, string $direction, array $expectedKeys): void
    {
        $this->useDocumentSources();
        $table = new HilosLegalDocumentsTable();
        $sort = TableSortOrderDTO::of(new TableSortDTO($field, $direction));
        $snapshot = $table->getPage(new TableQueryDTO(sort: $sort));
        self::assertSame($expectedKeys, array_column($snapshot->rows, 'rowKey'));
        self::assertSame(4, $snapshot->totalCount);
        $first = $table->getPage(new TableQueryDTO(sort: $sort, limit: 1));
        self::assertSame([$expectedKeys[0]], array_column($first->rows, 'rowKey'));
        self::assertSame(
            [$expectedKeys[1]],
            array_column($table->getPage(new TableQueryDTO(sort: $sort, limit: 1, anchor: $first->lastAnchor))->rows, 'rowKey'),
        );
    }

    /** @return array<string, array{string, string, list<string>}> Supported scalar orders with explicit expected results */
    public static function documentOrders(): array
    {
        return [
            'label ascending' => ['documentLabel', 'asc', ['legacy-a', 'legacy-b', 'privacy', 'terms']],
            'label descending' => ['documentLabel', 'desc', ['terms', 'privacy', 'legacy-b', 'legacy-a']],
            'publication ascending' => ['publishedOn', 'asc', ['terms', 'privacy', 'legacy-a', 'legacy-b']],
            'publication descending' => ['publishedOn', 'desc', ['privacy', 'terms', 'legacy-b', 'legacy-a']],
            'covered ascending' => ['covered', 'asc', ['legacy-a', 'legacy-b', 'terms', 'privacy']],
            'covered descending' => ['covered', 'desc', ['privacy', 'terms', 'legacy-b', 'legacy-a']],
            'window ascending' => ['window', 'asc', ['legacy-a', 'legacy-b', 'privacy', 'terms']],
            'window descending' => ['window', 'desc', ['terms', 'privacy', 'legacy-b', 'legacy-a']],
            'lapsed ascending' => ['lapsed', 'asc', ['legacy-a', 'legacy-b', 'terms', 'privacy']],
            'lapsed descending' => ['lapsed', 'desc', ['privacy', 'terms', 'legacy-b', 'legacy-a']],
        ];
    }

    /** Mutations use the complete projection, even after a searched and paged query. */
    public function testAggregateMutationsUpdateAllCurrentRowsAndDeleteOnlyVanishedKnownKeys(): void
    {
        $this->useDocumentSources(['legacy-b', 'legacy-a', 'retired']);
        $table = new HilosLegalDocumentsTable();
        $previousRowKeys = array_column($table->getFullSnapshot()->rows, 'rowKey');
        self::assertSame(['terms', 'privacy', 'legacy-b', 'legacy-a', 'retired'], $previousRowKeys);

        $this->useDocumentSources(['legacy-b', 'legacy-c']);
        $snapshot = $table->getPage(new TableQueryDTO(search: 'OF USE', limit: 1));
        self::assertSame(['terms'], array_column($snapshot->rows, 'rowKey'));
        $mutations = $table->aggregateMutations($previousRowKeys);
        self::assertSame(['terms', 'privacy', 'legacy-b', 'legacy-c', 'legacy-a', 'retired'], array_column($mutations, 'rowKey'));
        self::assertSame([
            TableMutationType::Update,
            TableMutationType::Update,
            TableMutationType::Update,
            TableMutationType::Update,
            TableMutationType::Delete,
            TableMutationType::Delete,
        ], array_column($mutations, 'type'));
        $current = $table->getFullSnapshot()->rows;
        foreach (array_slice($mutations, 0, 4) as $index => $mutation) {
            self::assertInstanceOf(HilosLegalDocumentTableRow::class, $mutation->row);
            self::assertSame($current[$index]->toArray(), $mutation->row->toArray());
        }
        self::assertNull($mutations[4]->row);
        self::assertNull($mutations[5]->row);
        self::assertSame(
            ['terms', 'privacy', 'legacy-b', 'legacy-c'],
            array_column($table->aggregateMutations([]), 'rowKey'),
        );
        self::assertSame(
            ['terms', 'privacy', 'legacy-b', 'legacy-c', 'retired'],
            array_column($table->aggregateMutations(['terms', 'retired']), 'rowKey'),
        );
    }

    /** An unreadable aggregate source cannot become a successful deletion of the viewport. */
    public function testAggregateReadFailurePropagatesInsteadOfDeletingRows(): void
    {
        $acceptances = $this->createStub(LegalAcceptances::class);
        $acceptances->method('documentsOnRecord')->willThrowException(new DatabaseException('Histogram read failed'));
        Hilos::$db->legalAcceptances = $acceptances;
        LegalAdminAudience::markStale();
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Histogram read failed');
        new HilosLegalDocumentsTable()->aggregateMutations(['terms', 'retired']);
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

    /** @param list<string> $recordedOnly Historical document keys that still have acceptance records */
    private function useDocumentSources(array $recordedOnly = ['legacy-b', 'legacy-a']): void
    {
        LegalDocumentsTestHilos::initBrowser();
        $acceptances = $this->createStub(LegalAcceptances::class);
        $acceptances->method('documentsOnRecord')->willReturn(['terms', 'privacy', ...$recordedOnly]);
        $acceptances->method('heldCounts')->willReturnMap([
            ['terms', ['first', 'window', 'current'], ['first' => 2, 'window' => 10, 'current' => 2]],
            ['privacy', ['first', 'window', 'current'], ['first' => 10, 'window' => 2, 'current' => 10]],
        ]);
        $acceptances->method('acceptedCounts')->willReturn(['first' => 10, 'window' => 10, 'current' => 10]);
        Hilos::$db->legalAcceptances = $acceptances;
        LegalAdminAudience::markStale();
    }
}

/** Both documents have covered, future-window and lapsed holders, with distinct publication dates. */
final class LegalDocumentsTestCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Three revisions per declared document */
    public static function revisions(): array
    {
        return [
            'terms' => [
                new LegalRevision(LegalDocument::TERMS, 'first', '2020-01-01', 1, LegalSignificance::SUBSTANTIAL, '2020-01-01', []),
                new LegalRevision(LegalDocument::TERMS, 'window', '2020-02-01', 1, LegalSignificance::SUBSTANTIAL, '2020-02-01', []),
                new LegalRevision(LegalDocument::TERMS, 'current', '2020-03-01', 1, LegalSignificance::SUBSTANTIAL, '2999-01-01', []),
            ],
            'privacy' => [
                new LegalRevision(LegalDocument::PRIVACY, 'first', '2020-01-01', 1, LegalSignificance::SUBSTANTIAL, '2020-01-01', []),
                new LegalRevision(LegalDocument::PRIVACY, 'window', '2020-02-01', 1, LegalSignificance::SUBSTANTIAL, '2020-02-01', []),
                new LegalRevision(LegalDocument::PRIVACY, 'current', '2020-04-01', 1, LegalSignificance::SUBSTANTIAL, '2999-01-01', []),
            ],
        ];
    }
}

/** Catalog binding for document search, order and aggregate mutation scenarios. */
abstract class LegalDocumentsTestHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = LegalDocumentsTestCatalog::class;
}
