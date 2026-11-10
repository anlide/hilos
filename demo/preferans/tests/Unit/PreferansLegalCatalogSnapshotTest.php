<?php

declare(strict_types=1);

namespace Demo\Preferans\Tests\Unit;

use Demo\Preferans\Hilos;
use Demo\Preferans\Legal\PreferansLegalCatalog;
use Hilos\Legal\DeviationDirection;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\StandardSetCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Snapshot of what the preferans demo publishes as legal documents.
 *
 * A person holds a revision, so removing one from the catalog turns "show me the text I agreed
 * to" into a lie. This test makes that removal a red build, before the deploy: publishing a
 * revision appends to the snapshot, and nothing already listed may change. It reads through the
 * resolver, so the whole declaration is validated on the way.
 */
final class PreferansLegalCatalogSnapshotTest extends TestCase
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
        self::assertSame(PreferansLegalCatalog::class, Hilos::legalCatalogClass());
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
                LegalDocument::TERMS->value => ['2026-11-10' => 1],
                LegalDocument::PRIVACY->value => ['2026-11-10' => 1],
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

    /** The legal deviation discloses the analytics that remains. */
    public function testPrivacyTextStatesTheAnalyticsException(): void
    {
        $privacy = LegalCatalogResolver::latestRevision(LegalDocument::PRIVACY);
        self::assertCount(1, $privacy->deviations);
        $text = LegalCatalogResolver::text($privacy->deviations[0]->textFile);
        self::assertStringContainsString('network addresses remain', $text);
        self::assertStringContainsString('no automatic deletion period', $text);
        self::assertStringContainsString('Analytics records', $text);


    }
}
