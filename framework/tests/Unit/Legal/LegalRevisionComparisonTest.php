<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Legal;

use Hilos\Hilos;
use Hilos\Legal\Deviation;
use Hilos\Legal\DeviationDirection;
use Hilos\Legal\Exception\ReversedComparisonException;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalRevisionComparison;
use Hilos\Legal\LegalRevisionOrigin;
use Hilos\Legal\LegalSignificance;
use Hilos\Legal\LegalStandingResolver;
use Hilos\Legal\LegalWire;
use Hilos\Legal\StandardClause;
use Hilos\Legal\StandardSet;
use Hilos\Legal\StandardSetCatalog;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Future standard versions are injected into the catalog cache without publishing test legal text. */
final class LegalRevisionComparisonTest extends TestCase
{
    private array $previousSets;
    private array $previousCatalogs;

    protected function setUp(): void
    {
        $this->previousSets = new ReflectionProperty(StandardSetCatalog::class, 'sets')->getValue();
        $this->previousCatalogs = new ReflectionProperty(LegalCatalogResolver::class, 'catalogs')->getValue();
        new ReflectionProperty(LegalCatalogResolver::class, 'catalogs')->setValue(null, []);
        $old = __DIR__ . '/Fixtures/padded.txt';
        $new = __DIR__ . '/Fixtures/terms/standard.retention.2026-02-20.txt';
        new ReflectionProperty(StandardSetCatalog::class, 'sets')->setValue(null, ['terms' => [
            new StandardSet(LegalDocument::TERMS, 1, '2026-01-01', LegalSignificance::SUBSTANTIAL, [
                new StandardClause('text', 'Text', $old),
                new StandardClause('statement', 'Old statement', $old),
                new StandardClause('source', 'Source', $old),
                new StandardClause('direction', 'Direction', $old),
                new StandardClause('hidden', 'Hidden standard', $old),
                new StandardClause('removed', 'Removed', $old),
            ]),
            new StandardSet(LegalDocument::TERMS, 2, '2026-02-01', LegalSignificance::SUBSTANTIAL, [
                new StandardClause('direction', 'Direction', $old),
                new StandardClause('added', 'Added', $new),
                new StandardClause('hidden', 'Changed hidden standard', $new),
                new StandardClause('text', 'Text', $new),
                new StandardClause('statement', 'New statement', $old),
                new StandardClause('source', 'Source', $old),
            ]),
        ]]);
        ComparisonHilos::initBrowser();
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(StandardSetCatalog::class, 'sets')->setValue(null, $this->previousSets);
        new ReflectionProperty(LegalCatalogResolver::class, 'catalogs')->setValue(null, $this->previousCatalogs);
        Hilos::initBrowser();
        Hilos::resetBrowser();
        parent::tearDown();
    }

    public function testEffectiveChangesKeepNewOrderAndAppendRemovedClauses(): void
    {
        $changes = LegalWire::changes(LegalRevisionComparison::between(LegalDocument::TERMS, 'old', 'new'));
        self::assertSame(['direction', 'added', 'text', 'statement', 'source', 'removed'], array_column($changes, 'clauseKey'));
        self::assertSame(['changed', 'added', 'changed', 'changed', 'changed', 'removed'], array_column($changes, 'kind'));
        self::assertSame('stricter', $changes[0]['before']['direction']);
        self::assertSame('looser', $changes[0]['after']['direction']);
        self::assertNull($changes[1]['before']);
        self::assertSame('standard', $changes[1]['after']['source']);
        self::assertNotSame($changes[2]['before']['text'], $changes[2]['after']['text']);
        self::assertSame('New statement', $changes[3]['title']);
        self::assertSame($changes[3]['before']['text'], $changes[3]['after']['text']);
        self::assertSame('standard', $changes[4]['before']['source']);
        self::assertSame('deviation', $changes[4]['after']['source']);
        self::assertSame('Removed', $changes[5]['title']);
        self::assertNull($changes[5]['after']);
        self::assertSame(LegalRevisionOrigin::STANDARD, LegalStandingResolver::origin(LegalDocument::TERMS, 'new'));
    }

    public function testStandardSetsCompareTheirOwnTextWithoutProjectDeviations(): void
    {
        $changes = LegalWire::changes(LegalRevisionComparison::standardSets(LegalDocument::TERMS, 1, 2));
        self::assertSame(['added', 'hidden', 'text', 'statement', 'removed'], array_column($changes, 'clauseKey'));
        self::assertSame(['added', 'changed', 'changed', 'changed', 'removed'], array_column($changes, 'kind'));
        foreach ($changes as $change) {
            foreach ([$change['before'], $change['after']] as $side) {
                if ($side !== null) {
                    self::assertSame('standard', $side['source']);
                    self::assertNull($side['direction']);
                }
            }
        }
        self::assertSame([], LegalRevisionComparison::standardSets(LegalDocument::TERMS, 1, 1));
    }

    public function testSetAndDeviationWireShapesPreserveBothStatements(): void
    {
        $set = LegalWire::standardSet(StandardSetCatalog::set(LegalDocument::TERMS, 2));
        self::assertSame(2, $set['version']);
        self::assertSame('2026-02-01', $set['publishedOn']);
        self::assertSame('substantial', $set['significance']);
        self::assertSame(['direction', 'added', 'hidden', 'text', 'statement', 'source'], array_column($set['clauses'], 'clauseKey'));
        $deviations = LegalWire::deviations(LegalCatalogResolver::revision(LegalDocument::TERMS, 'new'));
        self::assertSame(['hidden', 'direction', 'source'], array_column($deviations, 'clauseKey'));
        self::assertSame('Changed hidden standard', $deviations[0]['standardStatement']);
        self::assertSame('Project text', $deviations[0]['statement']);
        self::assertSame(LegalCatalogResolver::text(__DIR__ . '/Fixtures/padded.txt'), $deviations[0]['text']);
        self::assertSame('stricter', $deviations[0]['direction']);
    }

    public function testAnUnchangedDeviationHidesChangesInTheUnderlyingStandard(): void
    {
        $clauses = LegalWire::clauses(LegalCatalogResolver::compose(LegalDocument::TERMS, 'new'));
        self::assertSame('hidden', $clauses[2]['clauseKey']);
        self::assertSame('Changed hidden standard', $clauses[2]['standardStatement']);
        self::assertSame('Project text', $clauses[2]['statement']);
        self::assertSame('deviation', $clauses[2]['source']);
        self::assertNotContains('hidden', array_column(
            LegalWire::changes(LegalRevisionComparison::between(LegalDocument::TERMS, 'old', 'new')), 'clauseKey',
        ));
    }

    public function testEqualRevisionsAreRefused(): void
    {
        $this->expectException(ReversedComparisonException::class);
        LegalRevisionComparison::between(LegalDocument::TERMS, 'old', 'old');
    }

    public function testReversedRevisionsAreRefused(): void
    {
        $this->expectException(ReversedComparisonException::class);
        LegalRevisionComparison::between(LegalDocument::TERMS, 'new', 'old');
    }
}

/** Revisions over the two synthetic standard versions. */
final class ComparisonCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Fixture declarations */
    public static function revisions(): array
    {
        $text = __DIR__ . '/Fixtures/padded.txt';
        $hidden = new Deviation('hidden', DeviationDirection::STRICTER, 'Project text', $text);

        return ['terms' => [
            new LegalRevision(LegalDocument::TERMS, 'old', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', [
                $hidden, new Deviation('direction', DeviationDirection::STRICTER, 'Direction', $text),
            ]),
            new LegalRevision(LegalDocument::TERMS, 'new', '2026-02-01', 2, LegalSignificance::SUBSTANTIAL, '2026-03-01', [
                $hidden, new Deviation('direction', DeviationDirection::LOOSER, 'Direction', $text),
                new Deviation('source', DeviationDirection::STRICTER, 'Source', $text),
            ]),
        ]];
    }
}

/** Binds the comparison declarations. */
abstract class ComparisonHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = ComparisonCatalog::class;
}
