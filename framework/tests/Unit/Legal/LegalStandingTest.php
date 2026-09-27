<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Legal;

use Hilos\Hilos;
use Hilos\Legal\LegalAgreementsProjector;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalRevisionOrigin;
use Hilos\Legal\LegalSignificance;
use Hilos\Legal\LegalStanding;
use Hilos\Legal\LegalStandingResolver;
use Hilos\Legal\Exception\UnknownRevisionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Acceptance coverage follows declaration order, not acceptance timestamps or publication deadlines. */
final class LegalStandingTest extends TestCase
{
    protected function setUp(): void
    {
        StandingHilos::initBrowser();
    }

    protected function tearDown(): void
    {
        Hilos::initBrowser();
        Hilos::resetBrowser();
        parent::tearDown();
    }

    /** @return iterable<string, array{list<string>, string, LegalStanding, ?string, ?string}> Coverage cases */
    public static function standings(): iterable
    {
        yield 'none' => [[], '2026-09-01', LegalStanding::NONE, null, null];
        yield 'unknown' => [['absent'], '2026-09-01', LegalStanding::NONE, null, null];
        yield 'covered' => [['substantial-two'], '2026-09-01', LegalStanding::COVERED, 'substantial-two', null];
        yield 'editorial keeps coverage' => [['substantial-two'], '2027-01-01', LegalStanding::COVERED, 'substantial-two', null];
        yield 'window' => [['first'], '2026-03-19', LegalStanding::WINDOW, 'first', '2026-03-20'];
        yield 'deadline day' => [['first'], '2026-03-20', LegalStanding::LAPSED, 'first', '2026-03-20'];
        yield 'later publication does not extend deadline' => [['first'], '2026-04-01', LegalStanding::LAPSED, 'first', '2026-03-20'];
        yield 'latest declaration wins over input order' => [
            ['wording', 'first', 'substantial-one', 'absent'], '2027-01-01', LegalStanding::COVERED, 'wording', null,
        ];
        yield 'next substantial deadline' => [['substantial-one'], '2026-03-01', LegalStanding::WINDOW, 'substantial-one', '2026-05-01'];
    }

    /**
     * @param list<string> $accepted Accepted revision keys
     * @param string $today Calendar date
     * @param LegalStanding $expected Expected state
     * @param ?string $held Expected held revision
     * @param ?string $deadline Expected outstanding deadline
     */
    #[DataProvider('standings')]
    public function testStanding(array $accepted, string $today, LegalStanding $expected, ?string $held, ?string $deadline): void
    {
        $state = LegalStandingResolver::standingOf(LegalDocument::TERMS, $accepted, $today);
        self::assertSame(LegalDocument::TERMS, $state->document);
        self::assertSame($expected, $state->standing);
        self::assertSame($held, $state->held?->id);
        self::assertSame($deadline, $state->deadline);
    }

    public function testCurrentPredecessorAndProvenanceFollowDeclarationOrder(): void
    {
        self::assertSame('wording', LegalCatalogResolver::latestRevision(LegalDocument::TERMS)->id);
        self::assertNull(LegalCatalogResolver::predecessor(LegalDocument::TERMS, 'first'));
        self::assertSame('substantial-two', LegalCatalogResolver::predecessor(LegalDocument::TERMS, 'wording')?->id);
        self::assertSame(LegalRevisionOrigin::FIRST, LegalStandingResolver::origin(LegalDocument::TERMS, 'first'));
        self::assertSame(LegalRevisionOrigin::PROJECT, LegalStandingResolver::origin(LegalDocument::TERMS, 'wording'));
        $this->expectException(UnknownRevisionException::class);
        LegalCatalogResolver::predecessor(LegalDocument::TERMS, 'unknown');
    }

    public function testALaterSubstantialRevisionCanCarryTheNearestDeadline(): void
    {
        EarlierDeadlineHilos::initBrowser();
        $state = LegalStandingResolver::standingOf(LegalDocument::TERMS, ['first'], '2026-03-20');
        self::assertSame('2026-03-20', $state->deadline);
        self::assertSame(LegalStanding::LAPSED, $state->standing);
    }

    public function testAnInstallationWithoutDocumentsNeedsNoDatabase(): void
    {
        Hilos::initBrowser();
        self::assertSame(['documents' => []], LegalAgreementsProjector::stateFor(1, '2026-09-27')->toArray());
        self::assertSame(['documents' => []], LegalAgreementsProjector::textsFor(1, '2026-09-27'));
        self::assertSame(['documents' => []], LegalAgreementsProjector::revisions());
    }
}

/** Two substantial deadlines followed by wording only. */
final class StandingCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Fixture revision declarations */
    public static function revisions(): array
    {
        return [LegalDocument::TERMS->value => [
            new LegalRevision(LegalDocument::TERMS, 'first', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
            new LegalRevision(LegalDocument::TERMS, 'substantial-one', '2026-02-01', 1, LegalSignificance::SUBSTANTIAL, '2026-03-20', []),
            new LegalRevision(LegalDocument::TERMS, 'substantial-two', '2026-03-01', 1, LegalSignificance::SUBSTANTIAL, '2026-05-01', []),
            new LegalRevision(LegalDocument::TERMS, 'wording', '2026-04-01', 1, LegalSignificance::EDITORIAL, '2026-04-01', []),
        ]];
    }
}

/** Binds the coverage fixture. */
abstract class StandingHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = StandingCatalog::class;
}

/** Later publication, earlier outstanding deadline. */
final class EarlierDeadlineCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Fixture declarations */
    public static function revisions(): array
    {
        return ['terms' => [
            new LegalRevision(LegalDocument::TERMS, 'first', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
            new LegalRevision(LegalDocument::TERMS, 'second', '2026-02-01', 1, LegalSignificance::SUBSTANTIAL, '2026-05-01', []),
            new LegalRevision(LegalDocument::TERMS, 'third', '2026-03-01', 1, LegalSignificance::SUBSTANTIAL, '2026-03-20', []),
        ]];
    }
}

/** Binds the deadline-order fixture. */
abstract class EarlierDeadlineHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = EarlierDeadlineCatalog::class;
}
