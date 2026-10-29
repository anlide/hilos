<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\WireField;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Hilos;
use Hilos\Legal\Deviation;
use Hilos\Legal\DeviationDirection;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSignificance;
use Hilos\Legal\StandardSet;
use Hilos\Legal\StandardSetCatalog;
use Hilos\Tables\Legal\HilosPrivacyHistoryTable;
use Hilos\Tables\Legal\HilosPublicLegalHistoryTableRow;
use Hilos\Tables\Legal\HilosTermsHistoryTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Public catalog windows never depend on acceptance records or client document filters. */
final class HilosPublicLegalHistoryTableTest extends TestCase
{
    private array $previousSets;
    private array $previousCatalogs;
    private string $previousAppClass;

    protected function setUp(): void
    {
        $this->previousAppClass = Hilos::appClass();
        $this->previousSets = new ReflectionProperty(StandardSetCatalog::class, 'sets')->getValue();
        $this->previousCatalogs = new ReflectionProperty(LegalCatalogResolver::class, 'catalogs')->getValue();
        $first = StandardSetCatalog::set(LegalDocument::TERMS, 1);
        new ReflectionProperty(StandardSetCatalog::class, 'sets')->setValue(null, [
            'terms' => [$first, new StandardSet(LegalDocument::TERMS, 2, '2026-10-03', LegalSignificance::SUBSTANTIAL, $first->clauses)],
        ]);
        new ReflectionProperty(LegalCatalogResolver::class, 'catalogs')->setValue(null, []);
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, PublicHistoryHilos::class);
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(StandardSetCatalog::class, 'sets')->setValue(null, $this->previousSets);
        new ReflectionProperty(LegalCatalogResolver::class, 'catalogs')->setValue(null, $this->previousCatalogs);
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousAppClass);
        parent::tearDown();
    }

    public function testEachTableListsOnlyItsDocumentInReverseDeclarationOrder(): void
    {
        $terms = new HilosTermsHistoryTable()->getFullSnapshot();
        self::assertSame(['2026-10-03', '2026-10-02', '2026-10-01'], array_column($terms->rows, 'revisionId'));
        self::assertSame(['standard', 'project', 'first'], array_column($terms->rows, 'origin'));
        self::assertSame([1, 1, null], array_column($terms->rows, 'previousSetVersion'));
        self::assertSame([2, 1, 1], array_column($terms->rows, 'setVersion'));
        self::assertSame([0, 1, 0], array_column($terms->rows, 'deviationCount'));
        self::assertSame(['substantial', 'editorial', 'substantial'], array_column($terms->rows, 'significance'));
        self::assertSame(3, $terms->totalCount);
        self::assertTrue($terms->totalExact);
        $privacy = new HilosPrivacyHistoryTable()->getFullSnapshot();
        self::assertSame(['2026-09-17'], array_column($privacy->rows, 'revisionId'));
        self::assertSame(1, $privacy->totalCount);
        self::assertSame('2026-10-03', $terms->rows[0]->publishedOn);
        self::assertSame('2026-10-10', $terms->rows[0]->effectiveOn);
    }

    public function testBrowserEnvelopeAndTypedRowCarryExactlyPublicMetadata(): void
    {
        $table = new HilosTermsHistoryTable();
        $row = $table->getFullSnapshot()->rows[0];
        self::assertInstanceOf(HilosPublicLegalHistoryTableRow::class, $row);
        $wire = [
            'rowKey' => '2026-10-03', 'revisionId' => '2026-10-03',
            'publishedOn' => '2026-10-03', 'effectiveOn' => '2026-10-10',
            'significance' => 'substantial', 'setVersion' => 2, 'deviationCount' => 0,
            'origin' => 'standard', 'previousSetVersion' => 1,
        ];
        self::assertSame($wire, $row->toArray());
        self::assertSame($wire, HilosPublicLegalHistoryTableRow::fromArray($wire)->toArray());
        self::assertSame(['rowKey' => '2026-10-03', 'sources' => ['revision' => $wire]], $table->browserRow($row));
        self::assertSame('rowKey', $row::keyField());
        self::assertSame('2026-10-03', $row->requireRowKey());
        self::assertSame(array_keys($wire), array_keys($table->wireFields()));
        foreach ($table->wireFields() as $field) {
            self::assertEquals(WireField::notPersonal(), $field);
        }
        $first = $table->getFullSnapshot()->rows[2];
        self::assertNull(HilosPublicLegalHistoryTableRow::fromArray($first->toArray())->previousSetVersion);
        self::assertNull($table->defaultSort());
        self::assertSame([], $table->defaultFilter(new PageRouteParams([])));
        self::assertSame([], $table->bulkActions());
        self::assertNull($table->buildMutationForSourceEvent(new SourceChange(
            SourceChange::KIND_DB, 'legalAcceptances', 'undeclared', TableMutationType::Create,
        )));
    }

    public function testClientFiltersCannotSelectAnotherDocumentOrAcceptanceRows(): void
    {
        foreach ([[], ['document' => 'privacy'], ['document' => 'unknown'], ['revision' => 'undeclared']] as $filter) {
            self::assertSame(['2026-10-03', '2026-10-02', '2026-10-01'], array_column(
                new HilosTermsHistoryTable()->getPage(new TableQueryDTO(filter: $filter))->rows, 'revisionId',
            ));
        }
        self::assertSame(['2026-09-17'], array_column(new HilosPrivacyHistoryTable()->getPage(
            new TableQueryDTO(filter: ['document' => 'terms']),
        )->rows, 'revisionId'));
    }

    public function testPaginationKeepsAnExactTotalBeyondTheInitialWindow(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, PublicHistoryManyHilos::class);
        $table = new HilosTermsHistoryTable();
        self::assertSame(25, $table->windowSize());
        $first = $table->getPage(new TableQueryDTO(limit: $table->windowSize()));
        self::assertCount(25, $first->rows);
        self::assertSame(30, $first->totalCount);
        self::assertTrue($first->totalExact);
        self::assertSame(0, $first->rowsBefore);
        self::assertSame(['2026-10-30', '2026-10-06'], [$first->rows[0]->rowKey, $first->rows[24]->rowKey]);
        $next = $table->getPage(new TableQueryDTO(limit: 25, pageIndex: 1));
        self::assertCount(5, $next->rows);
        self::assertSame(30, $next->totalCount);
        self::assertTrue($next->totalExact);
        self::assertSame(25, $next->rowsBefore);
        self::assertSame(['2026-10-05', '2026-10-01'], [$next->rows[0]->rowKey, $next->rows[4]->rowKey]);
        self::assertSame($next->toArray(), $table->getPage(new TableQueryDTO(limit: 25, anchor: $first->lastAnchor))->toArray());
    }

    public function testAbsentAndFaultyCatalogsGiveEmptyExactSnapshots(): void
    {
        foreach ([Hilos::class, PublicHistoryFaultyHilos::class] as $facade) {
            new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $facade);
            foreach ([new HilosTermsHistoryTable(), new HilosPrivacyHistoryTable()] as $table) {
                $snapshot = $table->getFullSnapshot();
                self::assertSame([], $snapshot->rows);
                self::assertSame(0, $snapshot->totalCount);
                self::assertTrue($snapshot->totalExact);
            }
        }
    }

    public function testSearchIsNotAdvertisedOrSupported(): void
    {
        $this->expectException(TableSearchNotSupportedException::class);
        new HilosTermsHistoryTable()->getPage(new TableQueryDTO(search: 'revision'));
    }

    /** @param array<string, mixed> $fields Invalid row fields */
    #[DataProvider('invalidRows')]
    public function testTypedRowRejectsMistypedOrMissingMetadata(array $fields): void
    {
        $wire = new HilosTermsHistoryTable()->getFullSnapshot()->rows[0]->toArray();
        foreach ($fields as $key => $value) {
            if ($value === 'missing') {
                unset($wire[$key]);
            } else {
                $wire[$key] = $value;
            }
        }
        $this->expectException(InvalidFormatException::class);
        HilosPublicLegalHistoryTableRow::fromArray($wire);
    }

    /** @return array<string, array{array<string, mixed>}> Required and strictly typed row fields */
    public static function invalidRows(): array
    {
        return [
            'missing identity' => [['rowKey' => 'missing']],
            'numeric revision' => [['revisionId' => 123]],
            'string version' => [['setVersion' => '2']],
            'string previous version' => [['previousSetVersion' => '1']],
            'missing date' => [['publishedOn' => 'missing']],
        ];
    }
}

/** Two documents, with editorial and standard changes in Terms. */
final class PublicHistoryCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Published fixture metadata */
    public static function revisions(): array
    {
        return [
            'terms' => [
                new LegalRevision(LegalDocument::TERMS, '2026-10-01', '2026-10-01', 1, LegalSignificance::SUBSTANTIAL, '2026-10-01', []),
                new LegalRevision(LegalDocument::TERMS, '2026-10-02', '2026-10-02', 1, LegalSignificance::EDITORIAL, '2026-10-02', [
                    new Deviation(StandardSetCatalog::CLAUSE_RETENTION, DeviationDirection::STRICTER, 'Shorter retention',
                        __DIR__ . '/Legal/Fixtures/padded.txt'),
                ]),
                new LegalRevision(LegalDocument::TERMS, '2026-10-03', '2026-10-03', 2, LegalSignificance::SUBSTANTIAL, '2026-10-10', []),
            ],
            'privacy' => [new LegalRevision(
                LegalDocument::PRIVACY, '2026-09-17', '2026-09-17', 1, LegalSignificance::SUBSTANTIAL, '2026-09-17', [],
            )],
        ];
    }
}

abstract class PublicHistoryHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = PublicHistoryCatalog::class;
}

final class PublicHistoryManyCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> More revisions than one public window */
    public static function revisions(): array
    {
        $revisions = [];
        for ($day = 1; $day <= 30; $day++) {
            $date = sprintf('2026-10-%02d', $day);
            $revisions[] = new LegalRevision(LegalDocument::TERMS, $date, $date, 1, LegalSignificance::EDITORIAL, $date, []);
        }

        return ['terms' => $revisions];
    }
}

abstract class PublicHistoryManyHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = PublicHistoryManyCatalog::class;
}

final class PublicHistoryFaultyCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Invalid published declaration */
    public static function revisions(): array
    {
        return ['terms' => []];
    }
}

abstract class PublicHistoryFaultyHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = PublicHistoryFaultyCatalog::class;
}
