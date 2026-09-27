<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Legal;

use Hilos\Hilos;
use Hilos\Legal\Deviation;
use Hilos\Legal\DeviationDirection;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalChecks;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSignificance;
use Hilos\Legal\LegalTally;
use Hilos\Legal\StandardSet;
use Hilos\Legal\StandardSetCatalog;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Each diagnostic is exercised with declarations or records that trigger it. */
final class LegalChecksTest extends TestCase
{
    private array $previousSets;
    private array $previousCatalogs;

    /** Isolates the fixture's catalogs and standard-set versions. */
    protected function setUp(): void
    {
        $this->previousSets = new ReflectionProperty(StandardSetCatalog::class, 'sets')->getValue();
        $this->previousCatalogs = new ReflectionProperty(LegalCatalogResolver::class, 'catalogs')->getValue();
        new ReflectionProperty(LegalCatalogResolver::class, 'catalogs')->setValue(null, []);
        ChecksHilos::initBrowser();
    }

    /** Restores both declaration caches and the default facade. */
    protected function tearDown(): void
    {
        new ReflectionProperty(StandardSetCatalog::class, 'sets')->setValue(null, $this->previousSets);
        new ReflectionProperty(LegalCatalogResolver::class, 'catalogs')->setValue(null, $this->previousCatalogs);
        Hilos::initBrowser();
        Hilos::resetBrowser();
        parent::tearDown();
    }

    public function testMissingRevisionsNameTheDocumentRevisionAndDistinctPeople(): void
    {
        $rows = LegalChecks::run([
            'terms' => LegalTally::of('terms', ['current' => 1], ['gone' => 2, 'current' => 1], '2026-09-27'),
            'retired' => LegalTally::of('retired', [], ['older' => 1], '2026-09-27'),
        ]);
        self::assertSame('undeclared_revision', $rows[0]['check']);
        self::assertFalse($rows[0]['ok']);
        self::assertSame([
            ['document' => 'terms', 'revisionId' => 'gone', 'people' => 2],
            ['document' => 'retired', 'revisionId' => 'older', 'people' => 1],
        ], $rows[0]['items']);
    }

    public function testZeroWindowExcludesTheFirstAndEditorialRevisions(): void
    {
        $row = LegalChecks::run([])[1];
        self::assertSame('zero_window', $row['check']);
        self::assertFalse($row['ok']);
        self::assertSame([['document' => 'terms', 'revisionId' => 'no-window']], $row['items']);
    }

    public function testNewerStandardSetsAreJudgedPerDocument(): void
    {
        $first = StandardSetCatalog::set(LegalDocument::PRIVACY, 1);
        $sets = new ReflectionProperty(StandardSetCatalog::class, 'sets')->getValue();
        $sets['privacy'][] = new StandardSet(LegalDocument::PRIVACY, 2, '2026-09-27', LegalSignificance::EDITORIAL, $first->clauses);
        new ReflectionProperty(StandardSetCatalog::class, 'sets')->setValue(null, $sets);

        $row = LegalChecks::run([])[2];
        self::assertSame('newer_standard_set', $row['check']);
        self::assertFalse($row['ok']);
        self::assertSame([
            ['document' => 'privacy', 'documentSetVersion' => 1, 'setVersion' => 2, 'significance' => 'editorial'],
        ], $row['items']);
    }

    public function testDeviationsUseTheCurrentRevisionAndKeepZeroCounts(): void
    {
        $row = LegalChecks::run([])[3];
        self::assertSame('deviations', $row['check']);
        self::assertFalse($row['ok']);
        self::assertSame([
            ['document' => 'terms', 'deviations' => 1],
            ['document' => 'privacy', 'deviations' => 0],
        ], $row['items']);
    }

    public function testAbsentFaultsHaveEmptyItemsAndPass(): void
    {
        Hilos::initBrowser();
        foreach (LegalChecks::run([]) as $row) {
            self::assertTrue($row['ok']);
            self::assertSame([], $row['items']);
        }
    }
}

/** Terms has one zero-length window; privacy has no deviations. */
final class ChecksCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Deliberate diagnostic triggers */
    public static function revisions(): array
    {
        return [
            'terms' => [
                new LegalRevision(LegalDocument::TERMS, 'first', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
                new LegalRevision(LegalDocument::TERMS, 'no-window', '2026-02-01', 1, LegalSignificance::SUBSTANTIAL, '2026-02-01', []),
                new LegalRevision(LegalDocument::TERMS, 'current', '2026-03-01', 1, LegalSignificance::EDITORIAL, '2026-03-01', [
                    new Deviation(StandardSetCatalog::CLAUSE_RETENTION, DeviationDirection::STRICTER, 'Project wording',
                        __DIR__ . '/Fixtures/padded.txt'),
                ]),
            ],
            'privacy' => [
                new LegalRevision(LegalDocument::PRIVACY, 'first', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
            ],
        ];
    }
}

/** Binds the diagnostic fixture. */
abstract class ChecksHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = ChecksCatalog::class;
}
