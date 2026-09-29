<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Tests\Unit;

use Demo\BinanceBtcTracker\Hilos;
use Demo\BinanceBtcTracker\Legal\BinanceBtcTrackerLegalCatalog;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;
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
                LegalDocument::PRIVACY->value => ['2026-09-29' => 1],
            ],
            $published,
            'revision ids and the standard set version each adopts',
        );
    }

    /**
     * The binance-btc-tracker demo departs from nothing: every clause of both documents is the standard one.
     */
    public function testNeitherDocumentDeviatesFromTheStandard(): void
    {
        foreach (LegalCatalogResolver::documents() as $document) {
            $revision = LegalCatalogResolver::latestRevision($document);
            foreach (LegalCatalogResolver::compose($document, $revision->id)->clauses as $clause) {
                self::assertNull($clause->deviation, "{$document->value}: {$clause->standard->key}");
            }
        }
    }
}
