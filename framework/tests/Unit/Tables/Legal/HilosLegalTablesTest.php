<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Tables\Legal;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageRouteParams;
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
use Hilos\Legal\Deviation;
use Hilos\Legal\DeviationDirection;
use Hilos\Legal\Exception\UnknownRevisionException;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSettings;
use Hilos\Legal\LegalSettingsCatalog;
use Hilos\Legal\LegalSignificance;
use Hilos\Legal\StandardSet;
use Hilos\Legal\StandardSetCatalog;
use Hilos\Pages\Legal\LegalAdminAudience;
use Hilos\Tables\Legal\HilosLegalChecksTable;
use Hilos\Tables\Legal\HilosLegalDocumentTableRow;
use Hilos\Tables\Legal\HilosLegalDocumentsTable;
use Hilos\Tables\Legal\HilosLegalDocumentDeviationsTable;
use Hilos\Tables\Legal\HilosLegalDocumentSetTable;
use Hilos\Tables\Legal\HilosLegalRevisionTableRow;
use Hilos\Tables\Legal\HilosLegalRevisionsTable;
use Hilos\Tables\Legal\HilosLegalSettingsTable;
use Hilos\Tests\Unit\Pages\Legal\LegalAdminTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

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
        self::assertSame(['2020-02-01', '2020-01-01', null], array_column($snapshot->rows, 'publishedOn'));
        self::assertNull($snapshot->rows[2]->effectiveOn);
        foreach ($snapshot->rows as $row) {
            self::assertSame($row->revision['publishedOn'] ?? null, $row->publishedOn);
            self::assertSame($row->revision['effectiveOn'] ?? null, $row->effectiveOn);
            self::assertSame($row->toArray(), HilosLegalRevisionTableRow::fromArray($row->toArray())->toArray());
        }
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

    /** All document tables preset their cold window from the route, preserving exact Revision scope. */
    public function testLegalDocumentTablesDeclareRouteDefaults(): void
    {
        foreach ([new HilosLegalDocumentSetTable(), new HilosLegalDocumentDeviationsTable(), new HilosLegalRevisionsTable()] as $table) {
            self::assertSame(25, $table->windowSize());
            self::assertSame(['document' => 'retired'], $table->defaultFilter(new PageRouteParams(['documentKey' => 'retired'])));
        }
        self::assertSame(
            ['document' => 'terms', 'revision' => 'gone-29'],
            new HilosLegalRevisionsTable()->defaultFilter(new PageRouteParams(['documentKey' => 'terms', 'revisionId' => 'gone-29'])),
        );
        self::assertSame([['field' => 'position', 'direction' => 'asc']], new HilosLegalDocumentSetTable()->defaultSort()->toArray());
        self::assertSame([['field' => 'position', 'direction' => 'asc']], new HilosLegalDocumentDeviationsTable()->defaultSort()->toArray());
        self::assertSame([['field' => 'publishedOn', 'direction' => 'desc']], new HilosLegalRevisionsTable()->defaultSort()->toArray());
    }

    /** A missing route cannot silently open an unscoped declaration window. */
    public function testDocumentTablePresetRequiresTheDocumentKey(): void
    {
        $this->expectException(MissingPageRouteParamException::class);
        new HilosLegalDocumentSetTable()->defaultFilter(new PageRouteParams([]));
    }

    /** Both tables use the adopted set even after a new framework set exists; declarations cannot reorder clauses. */
    public function testSetAndDeviationRowsUseTheAdoptedSetAndItsClauseOrder(): void
    {
        LegalClausesTestHilos::initBrowser();
        $adopted = StandardSetCatalog::set(LegalDocument::TERMS, 1);
        $previousSets = new ReflectionProperty(StandardSetCatalog::class, 'sets')->getValue();
        try {
            new ReflectionProperty(StandardSetCatalog::class, 'sets')->setValue(null, [
                ...$previousSets,
                'terms' => [$adopted, new StandardSet(LegalDocument::TERMS, 2, '2026-10-01', LegalSignificance::EDITORIAL, [])],
            ]);
            $set = new HilosLegalDocumentSetTable();
            $rows = $set->getPage(new TableQueryDTO(sort: $set->defaultSort(), filter: ['document' => 'terms']))->rows;
            self::assertSame(array_column($adopted->clauses, 'key'), array_column($rows, 'rowKey'));
            self::assertSame([0, 1, 2, 3, 4, 5], array_column($rows, 'position'));
            self::assertSame(array_column($rows, 'rowKey'), array_column($rows, 'clauseKey'));
            foreach ($rows as $row) {
                self::assertSame($row->toArray(), $row::fromArray($row->toArray())->toArray());
                self::assertSame(['clause' => $row->toArray()], $set->browserRow($row)['sources']);
            }
            $table = new HilosLegalDocumentDeviationsTable();
            $rows = $table->getPage(new TableQueryDTO(sort: $table->defaultSort(), filter: ['document' => 'terms']))->rows;
            self::assertSame(['standard.file_access', 'standard.moderation'], array_column($rows, 'rowKey'));
            self::assertSame([0, 2], array_column($rows, 'position'));
            self::assertSame(['looser', 'stricter'], array_column($rows, 'direction'));
            self::assertSame(['Shared files', 'Reviewed posts'], array_column($rows, 'statement'));
            self::assertSame([$adopted->clauses[0]->statement, $adopted->clauses[2]->statement], array_column($rows, 'standardStatement'));
            foreach ($rows as $row) {
                self::assertSame($row->rowKey, $row->clauseKey);
                self::assertNotSame('', $row->text);
                self::assertSame($row->toArray(), $row::fromArray($row->toArray())->toArray());
                self::assertSame(['deviation' => $row->toArray()], $table->browserRow($row)['sources']);
            }
        } finally {
            new ReflectionProperty(StandardSetCatalog::class, 'sets')->setValue(null, $previousSets);
        }
        self::assertSame(0, $this->reads->reads);
    }

    /** Search matches the declared fields, and all offered scalar orders run on the server. */
    public function testCatalogTablesServeTheirDeclaredSearchAndOrders(): void
    {
        LegalClausesTestHilos::initBrowser();
        $set = new HilosLegalDocumentSetTable();
        $table = new HilosLegalDocumentDeviationsTable();
        foreach (['STANDARD.MODERATION', 'automation'] as $term) {
            self::assertSame(['standard.moderation'], array_column(
                $set->getPage(new TableQueryDTO(search: $term, filter: ['document' => 'terms']))->rows, 'rowKey',
            ));
        }
        foreach (['STANDARD.MODERATION', 'REVIEWED POSTS'] as $term) {
            self::assertSame(['standard.moderation'], array_column(
                $table->getPage(new TableQueryDTO(search: $term, filter: ['document' => 'terms']))->rows, 'rowKey',
            ));
        }
        self::assertSame(['standard.file_access'], array_column(
            $table->getPage(new TableQueryDTO(search: 'guessing or sharing', filter: ['document' => 'terms']))->rows, 'rowKey',
        ));
        foreach ([
            [$set, 'clauseKey', ['account_rules', 'availability', 'file_access', 'moderation', 'ownership', 'retention']],
            [$set, 'statement', ['retention', 'moderation', 'file_access', 'availability', 'account_rules', 'ownership']],
            [$table, 'direction', ['file_access', 'moderation']],
            [$table, 'clauseKey', ['file_access', 'moderation']],
        ] as [$projection, $field, $keys]) {
            $expectedKeys = array_map(static fn (string $key): string => 'standard.' . $key, $keys);
            foreach (['asc', 'desc'] as $direction) {
                $rows = $projection->getPage(new TableQueryDTO(
                    sort: TableSortOrderDTO::of(new TableSortDTO($field, $direction)), filter: ['document' => 'terms'],
                ))->rows;
                self::assertSame($direction === 'asc' ? $expectedKeys : array_reverse($expectedKeys), array_column($rows, 'rowKey'));
            }
        }
    }

    /** Historical keys have no invented catalog rows; catalog refusals leave both windows safely empty. */
    public function testCatalogTablesKeepHistoricalAndRefusedWindowsEmpty(): void
    {
        foreach ([new HilosLegalDocumentSetTable(), new HilosLegalDocumentDeviationsTable()] as $table) {
            foreach ([[], ['document' => 'retired'], ['document' => 'privacy']] as $filter) {
                self::assertSame(0, $table->getPage(new TableQueryDTO(filter: $filter))->totalCount);
            }
        }
        LegalRefusedTableTestHilos::initBrowser();
        foreach ([new HilosLegalDocumentSetTable(), new HilosLegalDocumentDeviationsTable()] as $table) {
            self::assertSame(0, $table->getPage(new TableQueryDTO(filter: ['document' => 'terms'], search: 'anything'))->totalCount);
        }
    }

    /** New metadata and catalog fields are explicitly visible in admin view mode. */
    public function testLegalCatalogFieldOriginsCoverEveryWireField(): void
    {
        LegalClausesTestHilos::initBrowser();
        foreach ([new HilosLegalDocumentSetTable(), new HilosLegalDocumentDeviationsTable()] as $table) {
            $row = $table->getPage(new TableQueryDTO(filter: ['document' => 'terms']))->rows[0];
            self::assertSame(array_keys($row->toArray()), array_keys($table->wireFields()));
            foreach ($table->wireFields() as $field) {
                self::assertEquals(WireField::notPersonal(), $field);
            }
        }
        $fields = new HilosLegalRevisionsTable()->wireFields();
        self::assertEquals(WireField::notPersonal(), $fields['publishedOn']);
        self::assertEquals(WireField::notPersonal(), $fields['effectiveOn']);
    }

    /** Publication and deadline stay calendar dates, nulls last in both directions, with the usual key tie-breaker. */
    public function testRevisionsServeSearchAndScalarOrders(): void
    {
        $this->reads->accepted['gone-a'] = 4;
        $table = new HilosLegalRevisionsTable();
        foreach (['CURRENT' => ['current'], '2020-01' => ['first'], 'gone' => ['gone', 'gone-a'], 'substantial' => []] as $term => $keys) {
            self::assertSame($keys, array_column($table->getPage(new TableQueryDTO(search: $term, filter: ['document' => 'terms']))->rows, 'rowKey'));
        }
        foreach ([
            ['publishedOn', 'asc', ['first', 'current', 'gone', 'gone-a']],
            ['publishedOn', 'desc', ['current', 'first', 'gone-a', 'gone']],
            ['effectiveOn', 'asc', ['first', 'current', 'gone', 'gone-a']],
            ['effectiveOn', 'desc', ['current', 'first', 'gone-a', 'gone']],
            ['heldCount', 'asc', ['gone', 'current', 'first', 'gone-a']],
            ['heldCount', 'desc', ['gone-a', 'first', 'current', 'gone']],
        ] as [$field, $direction, $keys]) {
            self::assertSame($keys, array_column($table->getPage(new TableQueryDTO(
                sort: TableSortOrderDTO::of(new TableSortDTO($field, $direction)), filter: ['document' => 'terms'],
            ))->rows, 'rowKey'));
        }
        self::assertTrue($table->containsRow('current', new TableQueryDTO(
            search: '2020-02', filter: ['document' => 'terms'], limit: 1, pageIndex: 9,
        )));
        self::assertFalse($table->containsRow('current', new TableQueryDTO(filter: ['document' => 'privacy'])));
        self::assertFalse($table->containsRow('current', new TableQueryDTO(filter: ['document' => 'terms', 'revision' => 'first'])));
    }

    /** The complete scope drives mutations; errors propagate before any successful removals can be delivered. */
    public function testRevisionAggregateMutationsKeepDocumentAndExactRevisionScope(): void
    {
        $table = new HilosLegalRevisionsTable();
        $query = new TableQueryDTO(search: 'current', limit: 1, pageIndex: 20, filter: ['document' => 'terms']);
        $mutations = $table->aggregateMutations($query, ['current', 'retired']);
        self::assertSame(['current', 'first', 'gone', 'retired'], array_column($mutations, 'rowKey'));
        self::assertSame([TableMutationType::Update, TableMutationType::Update, TableMutationType::Update, TableMutationType::Delete],
            array_column($mutations, 'type'));
        self::assertSame(5, $mutations[1]->row->acceptedCount);
        self::assertSame(3, $mutations[1]->row->heldCount);
        self::assertSame('first', $mutations[1]->row->origin);
        unset($this->reads->accepted['gone']);
        LegalAdminAudience::markStale();
        $mutations = $table->aggregateMutations($query->withFilter('revision', 'gone'), ['gone']);
        self::assertCount(1, $mutations);
        self::assertSame(TableMutationType::Delete, $mutations[0]->type);
        LegalRefusedTableTestHilos::initBrowser();
        LegalAdminAudience::markStale();
        $this->expectException(UnknownRevisionException::class);
        $table->aggregateMutations($query, ['current']);
    }

    /** SQL histogram failures are not successful removal of previously shown revisions. */
    public function testRevisionAggregateReadFailurePropagates(): void
    {
        $acceptances = $this->createStub(LegalAcceptances::class);
        $acceptances->method('documentsOnRecord')->willThrowException(new DatabaseException('Histogram read failed'));
        Hilos::$db->legalAcceptances = $acceptances;
        $this->expectException(DatabaseException::class);
        new HilosLegalRevisionsTable()->aggregateMutations(new TableQueryDTO(filter: ['document' => 'terms']), ['current']);
    }

    /** A calendar scalar on the wire cannot be replaced by a number. */
    public function testRevisionRowRefusesANumericDate(): void
    {
        $row = new HilosLegalRevisionsTable()->getPage(new TableQueryDTO(filter: ['document' => 'terms']))->rows[0]->toArray();
        $row['effectiveOn'] = 20201001;
        $this->expectException(InvalidFormatException::class);
        HilosLegalRevisionTableRow::fromArray($row);
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

/** Two deviations deliberately declared in reverse clause order. */
final class LegalClausesTestCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Revision adopting the original framework set */
    public static function revisions(): array
    {
        $clauses = StandardSetCatalog::set(LegalDocument::TERMS, 1)->clauses;

        return ['terms' => [new LegalRevision(
            LegalDocument::TERMS, 'adopted', '2026-09-17', 1, LegalSignificance::SUBSTANTIAL, '2026-09-17', [
                new Deviation($clauses[2]->key, DeviationDirection::STRICTER, 'Reviewed posts', $clauses[2]->textFile),
                new Deviation($clauses[0]->key, DeviationDirection::LOOSER, 'Shared files', $clauses[0]->textFile),
            ],
        )]];
    }
}

/** Separate provider binding without changing any project registry. */
abstract class LegalClausesTestHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = LegalClausesTestCatalog::class;
}

/** A source refusal must not become a successful deletion from an aggregate viewport. */
final class LegalRefusedTableTestCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Never returns */
    public static function revisions(): array
    {
        throw new UnknownRevisionException('Broken table catalog');
    }
}

/** Installs the refused catalog for snapshot and mutation boundaries. */
abstract class LegalRefusedTableTestHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = LegalRefusedTableTestCatalog::class;
}
