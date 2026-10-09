<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Tests\Unit;

use Demo\BinanceBtcTracker\Hilos;
use Demo\BinanceBtcTracker\Legal\BinanceBtcTrackerLegalCatalog;
use Hilos\Legal\DeviationDirection;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\StandardSetCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Snapshot of what the binance-btc-tracker demo publishes as legal documents.
 *
 * A person holds a revision, so removing one from the catalog turns "show me the text I agreed
 * to" into a lie. This test makes that removal a red build, before the deploy: publishing a
 * revision appends to the snapshot, and nothing already listed may change. It reads through the
 * resolver, so the whole declaration is validated on the way.
 */
final class BinanceBtcTrackerLegalCatalogSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Hilos::initBrowser();
    }

    protected function tearDown(): void
    {
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testTheFacadeBindsTheProjectCatalog(): void
    {
        self::assertSame(BinanceBtcTrackerLegalCatalog::class, Hilos::legalCatalogClass());
    }

    public function testNoPublishedRevisionIsEverTakenAway(): void
    {
        $published = [];
        foreach (LegalCatalogResolver::documents() as $document) {
            foreach (LegalCatalogResolver::revisions($document) as $revision) {
                $published[$document->value][$revision->id] = $revision->setVersion;
            }
        }

        self::assertSame(
            [
                LegalDocument::TERMS->value => ['2026-09-29' => 1],
                LegalDocument::PRIVACY->value => ['2026-09-29' => 1, '2026-10-09' => 1],
            ],
            $published,
            'revision ids and the standard set version each adopts',
        );
    }

    /** Terms stays standard; the current Privacy revision declares one stricter deletion clause. */
    public function testTheCurrentDocumentsDeclareTheAnalyticsException(): void
    {
        $terms = LegalCatalogResolver::latestRevision(LegalDocument::TERMS);
        foreach (LegalCatalogResolver::compose(LegalDocument::TERMS, $terms->id)->clauses as $clause) {
            self::assertNull($clause->deviation, $clause->standard->key);
        }

        $privacy = LegalCatalogResolver::latestRevision(LegalDocument::PRIVACY);
        $deviations = [];
        foreach (LegalCatalogResolver::compose(LegalDocument::PRIVACY, $privacy->id)->clauses as $clause) {
            if ($clause->deviation !== null) {
                $deviations[$clause->standard->key] = $clause->deviation->direction;
            }
        }
        self::assertSame([StandardSetCatalog::CLAUSE_DELETION => DeviationDirection::STRICTER], $deviations);
    }

    /** The legal deviation and public Privacy page disclose the analytics that remains. */
    public function testPrivacyTextStatesTheAnalyticsException(): void
    {
        $privacy = LegalCatalogResolver::latestRevision(LegalDocument::PRIVACY);
        self::assertCount(1, $privacy->deviations);
        $text = LegalCatalogResolver::text($privacy->deviations[0]->textFile);
        self::assertStringContainsString('network addresses remain', $text);
        self::assertStringContainsString('no automatic deletion period', $text);
        self::assertStringContainsString('Analytics records', $text);

        $page = file_get_contents(dirname(__DIR__, 2) . '/frontend/src/views/Privacy/Privacy.vue');
        self::assertIsString($page);
        $page = preg_replace('/\s+/', ' ', $page);
        self::assertIsString($page);
        self::assertStringContainsString('records analytics', $page);
        self::assertStringContainsString('network addresses remain', $page);
        self::assertStringContainsString('no automatic deletion period', $page);
        self::assertStringNotContainsString('No analytics', $page);
    }
}
