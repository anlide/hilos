<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Tests\Unit;

use Demo\OnlineTesting\Hilos;
use Demo\OnlineTesting\Legal\OnlineTestingLegalCatalog;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;
use PHPUnit\Framework\TestCase;

/**
 * Snapshot of what the online-testing demo publishes as legal documents.
 *
 * A person holds a revision, so removing one from the catalog turns "show me the text I agreed
 * to" into a lie. This test makes that removal a red build, before the deploy: publishing a
 * revision appends to the snapshot, and nothing already listed may change. It reads through the
 * resolver, so the whole declaration is validated on the way.
 */
final class OnlineTestingLegalCatalogSnapshotTest extends TestCase
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
        self::assertSame(OnlineTestingLegalCatalog::class, Hilos::legalCatalogClass());
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
                LegalDocument::TERMS->value => ['2026-09-30' => 1],
                LegalDocument::PRIVACY->value => ['2026-09-30' => 1],
            ],
            $published,
            'revision ids and the standard set version each adopts',
        );
    }

    /**
     * The online-testing demo departs from nothing: every clause of both documents is the standard one.
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
