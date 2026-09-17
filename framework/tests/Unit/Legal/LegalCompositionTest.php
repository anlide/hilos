<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Legal;

use Hilos\Hilos;
use Hilos\Legal\ComposedClause;
use Hilos\Legal\Deviation;
use Hilos\Legal\DeviationDirection;
use Hilos\Legal\Exception\UnknownRevisionException;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalCatalogStub;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSignificance;
use Hilos\Legal\StandardClause;
use Hilos\Legal\StandardSetCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for composing a revision over its standard set, and for the reads around it.
 *
 * The project half is bound the way a running process binds it - a facade fixture naming its own
 * provider, captured by initBrowser() - so the resolver reads exactly what an installation would.
 */
final class LegalCompositionTest extends TestCase
{
    /**
     * Restores the base facade so a later test sees the process as it found it.
     */
    protected function tearDown(): void
    {
        Hilos::initBrowser();
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testCompositionKeepsTheStandardOrder(): void
    {
        LegalCompositionTestHilos::initBrowser();

        $composed = LegalCatalogResolver::compose(LegalDocument::TERMS, LegalCompositionTestCatalog::DEVIATING);

        self::assertSame(
            self::keysOf(StandardSetCatalog::set(LegalDocument::TERMS, 1)->clauses),
            array_map(static fn (ComposedClause $clause): string => $clause->standard->key, $composed->clauses),
        );
    }

    /**
     * A deviation replaces its clause in place and never travels to the end: the composed
     * document must still read as the standard document does, and every consumer picks its own
     * side of the clause.
     */
    public function testADeviatedClauseKeepsItsPlaceAndCarriesBothSides(): void
    {
        LegalCompositionTestHilos::initBrowser();

        $set = StandardSetCatalog::set(LegalDocument::TERMS, 1);
        $revision = LegalCatalogResolver::revision(LegalDocument::TERMS, LegalCompositionTestCatalog::DEVIATING);
        $composed = LegalCatalogResolver::compose(LegalDocument::TERMS, LegalCompositionTestCatalog::DEVIATING);

        $position = array_search(StandardSetCatalog::CLAUSE_RETENTION, self::keysOf($set->clauses), true);
        self::assertIsInt($position);
        self::assertSame($set->clauses[$position], $composed->clauses[$position]->standard);
        self::assertSame($revision->deviations[0], $composed->clauses[$position]->deviation);

        $deviated = [];
        foreach ($composed->clauses as $clause) {
            if ($clause->deviation !== null) {
                $deviated[] = $clause->standard->key;
            }
        }
        self::assertSame([StandardSetCatalog::CLAUSE_RETENTION, StandardSetCatalog::CLAUSE_MODERATION], $deviated);
    }

    public function testARevisionWithoutDeviationsComposesToTheStandardSetUnchanged(): void
    {
        LegalCompositionTestHilos::initBrowser();

        $set = StandardSetCatalog::set(LegalDocument::TERMS, 1);
        $composed = LegalCatalogResolver::compose(LegalDocument::TERMS, LegalCompositionTestCatalog::PLAIN);

        self::assertSame($set, $composed->set);
        self::assertCount(count($set->clauses), $composed->clauses);
        foreach ($set->clauses as $position => $clause) {
            self::assertSame($clause, $composed->clauses[$position]->standard);
            self::assertNull($composed->clauses[$position]->deviation, $clause->key);
        }
    }

    public function testOnlyTheDeclaredDocumentsAreListed(): void
    {
        LegalCompositionTestHilos::initBrowser();

        self::assertSame([LegalDocument::TERMS], LegalCatalogResolver::documents());
        self::assertSame([], LegalCatalogResolver::revisions(LegalDocument::PRIVACY));
    }

    /**
     * "Latest" is the last declared and nothing more: which revision is in force needs effective
     * dates to mean something, and they do not yet.
     */
    public function testTheLatestRevisionIsTheLastDeclared(): void
    {
        LegalCompositionTestHilos::initBrowser();

        self::assertSame(
            LegalCompositionTestCatalog::DEVIATING,
            LegalCatalogResolver::latestRevision(LegalDocument::TERMS)->id,
        );
    }

    public function testADocumentNobodyDeclaresHasNoLatestRevision(): void
    {
        LegalCompositionTestHilos::initBrowser();

        $this->expectException(UnknownRevisionException::class);

        LegalCatalogResolver::latestRevision(LegalDocument::PRIVACY);
    }

    /**
     * The third case beside a project that deviates and one that does not: an installation that
     * binds no catalog publishes no documents, and that is not an error.
     */
    public function testAnInstallationWithoutACatalogPublishesNoDocuments(): void
    {
        Hilos::initBrowser();

        self::assertNull(Hilos::legalCatalogClass());
        self::assertSame([], LegalCatalogResolver::documents());
        self::assertSame([], LegalCatalogResolver::revisions(LegalDocument::TERMS));
    }

    /**
     * The stub is the example a project copies, so it has to compose - an example that fails
     * validation would teach the fault.
     */
    public function testTheStubComposes(): void
    {
        LegalCompositionStubHilos::initBrowser();

        $revision = LegalCatalogResolver::latestRevision(LegalDocument::TERMS);
        $composed = LegalCatalogResolver::compose(LegalDocument::TERMS, $revision->id);

        self::assertCount(1, $revision->deviations);
        self::assertNotSame('', LegalCatalogResolver::text($revision->deviations[0]->textFile));
        self::assertCount(count(StandardSetCatalog::set(LegalDocument::TERMS, 1)->clauses), $composed->clauses);
    }

    public function testTextComesWithoutSurroundingBlankLines(): void
    {
        self::assertSame("First paragraph.\n\nSecond paragraph.", LegalCatalogResolver::text(__DIR__ . '/Fixtures/padded.txt'));
    }

    /**
     * @param list<StandardClause> $clauses Clauses of a set
     * @return list<string> Their keys in order
     */
    private static function keysOf(array $clauses): array
    {
        return array_map(static fn (StandardClause $clause): string => $clause->key, $clauses);
    }
}

/**
 * Terms only: one revision on the plain standard, then one deviating from two clauses.
 */
final class LegalCompositionTestCatalog implements LegalCatalogProviderInterface
{
    public const string PLAIN = '2026-01-10';

    public const string DEVIATING = '2026-02-20';

    /**
     * @return array<string, list<LegalRevision>> Revisions per document value
     */
    public static function revisions(): array
    {
        return [
            LegalDocument::TERMS->value => [
                new LegalRevision(LegalDocument::TERMS, self::PLAIN, self::PLAIN, 1, LegalSignificance::SUBSTANTIAL, self::PLAIN, []),
                new LegalRevision(
                    LegalDocument::TERMS,
                    self::DEVIATING,
                    self::DEVIATING,
                    1,
                    LegalSignificance::SUBSTANTIAL,
                    '2026-03-20',
                    [
                        new Deviation(
                            StandardSetCatalog::CLAUSE_RETENTION,
                            DeviationDirection::STRICTER,
                            'Messages are kept indefinitely',
                            __DIR__ . '/Fixtures/terms/standard.retention.2026-02-20.txt',
                        ),
                        new Deviation(
                            StandardSetCatalog::CLAUSE_MODERATION,
                            DeviationDirection::STRICTER,
                            'Conversations are visible to moderators',
                            __DIR__ . '/Fixtures/terms/standard.moderation.2026-02-20.txt',
                        ),
                    ],
                ),
            ],
        ];
    }
}

/**
 * Facade fixture binding the composition catalog.
 */
abstract class LegalCompositionTestHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = LegalCompositionTestCatalog::class;
}

/**
 * Facade fixture binding the framework stub.
 */
abstract class LegalCompositionStubHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = LegalCatalogStub::class;
}
