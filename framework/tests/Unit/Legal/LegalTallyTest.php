<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Legal;

use Hilos\Hilos;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSignificance;
use Hilos\Legal\LegalStandingResolver;
use Hilos\Legal\LegalTally;
use PHPUnit\Framework\TestCase;

/** Coverage counts exclude people with no declared acceptance; record counts retain their history. */
final class LegalTallyTest extends TestCase
{
    /** Binds two deadlines and a final editorial revision. */
    protected function setUp(): void
    {
        TallyHilos::initBrowser();
    }

    /** Restores the facade used by other legal tests. */
    protected function tearDown(): void
    {
        Hilos::initBrowser();
        Hilos::resetBrowser();
        parent::tearDown();
    }

    public function testCountsCoverageHeldRevisionsAndAllRecordsSeparately(): void
    {
        $tally = LegalTally::of(
            'terms',
            ['first' => 1, 'second' => 1, 'third' => 1, 'wording' => 1],
            ['first' => 2, 'old-missing' => 2, 'second' => 1, 'third' => 2, 'wording' => 1],
            '2026-03-20',
        );
        self::assertTrue($tally->declared);
        self::assertSame([2, 1, 1], [$tally->covered, $tally->window, $tally->lapsed]);
        self::assertSame(['first' => 1, 'second' => 1, 'third' => 1, 'wording' => 1], $tally->heldByRevision);
        self::assertSame(['first' => 2, 'old-missing' => 2, 'second' => 1, 'third' => 2, 'wording' => 1], $tally->acceptedByRevision);
        self::assertSame(['old-missing' => 2], $tally->undeclared);
    }

    public function testTheDeadlineMovesWindowToLapsedOnThatDay(): void
    {
        $before = LegalTally::of('terms', ['first' => 3], ['first' => 3], '2026-03-19');
        $on = LegalTally::of('terms', $before->heldByRevision, $before->acceptedByRevision, '2026-03-20');
        self::assertSame([3, 0], [$before->window, $before->lapsed]);
        self::assertSame([0, 3], [$on->window, $on->lapsed]);
        self::assertSame($before->heldByRevision, $on->heldByRevision);
    }

    public function testEditorialChangesKeepAnEarlierAcceptanceCovered(): void
    {
        $tally = LegalTally::of('terms', ['third' => 1], ['third' => 1], '2027-01-01');
        self::assertSame([1, 0, 0], [$tally->covered, $tally->window, $tally->lapsed]);
        self::assertSame(['third' => 1], $tally->heldByRevision);
    }

    public function testUnknownDocumentsAndDocumentsWithoutACatalogRetainTheirRecords(): void
    {
        foreach (['privacy', 'retired-document'] as $document) {
            $tally = LegalTally::of($document, [], ['gone' => 2, 'also-gone' => 1], '2026-09-27');
            self::assertFalse($tally->declared);
            self::assertSame([0, 0, 0], [$tally->covered, $tally->window, $tally->lapsed]);
            self::assertSame([], $tally->heldByRevision);
            self::assertSame(['gone' => 2, 'also-gone' => 1], $tally->undeclared);
            self::assertSame($tally->acceptedByRevision, $tally->undeclared);
        }
        Hilos::initBrowser();
        self::assertFalse(LegalTally::of('terms', [], ['first' => 1], '2026-09-27')->declared);
    }

    public function testAnEmptyDocumentHasNoInventedPeople(): void
    {
        $tally = LegalTally::of('terms', [], [], '2026-09-27');
        self::assertTrue($tally->declared);
        self::assertSame([0, 0, 0], [$tally->covered, $tally->window, $tally->lapsed]);
        self::assertSame([], $tally->acceptedByRevision);
    }

    public function testHistogramsMatchIndividualStandingsAcrossBothDeadlines(): void
    {
        $histories = [
            ['first', 'old-missing'],
            ['second'],
            ['third', 'first'],
            ['wording', 'third'],
            ['old-missing'],
            [],
            ['first', 'second'],
            ['third', 'second', 'first'],
        ];
        foreach (['2026-03-19', '2026-03-20', '2026-04-30', '2026-05-01'] as $today) {
            $expected = ['none' => 0, 'covered' => 0, 'window' => 0, 'lapsed' => 0];
            foreach ($histories as $ids) {
                $expected[LegalStandingResolver::standingOf(LegalDocument::TERMS, $ids, $today)->standing->value]++;
            }
            $tally = LegalTally::of(
                'terms',
                ['first' => 1, 'second' => 2, 'third' => 2, 'wording' => 1],
                ['first' => 4, 'second' => 3, 'third' => 3, 'wording' => 1, 'old-missing' => 2],
                $today,
            );
            self::assertSame(
                [$expected['covered'], $expected['window'], $expected['lapsed']],
                [$tally->covered, $tally->window, $tally->lapsed],
                $today,
            );
        }
    }
}

/** Three substantive revisions and an editorial successor. */
final class TallyCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Fixture declarations */
    public static function revisions(): array
    {
        return ['terms' => [
            new LegalRevision(LegalDocument::TERMS, 'first', '2026-01-01', 1, LegalSignificance::SUBSTANTIAL, '2026-01-01', []),
            new LegalRevision(LegalDocument::TERMS, 'second', '2026-02-01', 1, LegalSignificance::SUBSTANTIAL, '2026-03-20', []),
            new LegalRevision(LegalDocument::TERMS, 'third', '2026-03-01', 1, LegalSignificance::SUBSTANTIAL, '2026-05-01', []),
            new LegalRevision(LegalDocument::TERMS, 'wording', '2026-04-01', 1, LegalSignificance::EDITORIAL, '2026-04-01', []),
        ]];
    }
}

/** Binds the tally declarations. */
abstract class TallyHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = TallyCatalog::class;
}
