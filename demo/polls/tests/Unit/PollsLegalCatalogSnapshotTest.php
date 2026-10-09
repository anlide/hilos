<?php

declare(strict_types=1);

namespace Demo\Polls\Tests\Unit;

use Demo\Polls\Hilos;
use Demo\Polls\Legal\PollsLegalCatalog;
use Hilos\Legal\DeviationDirection;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\StandardSetCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Snapshot of what the polls demo publishes as legal documents.
 *
 * A person holds a revision, so removing one from the catalog turns "show me the text I agreed
 * to" into a lie. This test makes that removal a red build, before the deploy: publishing a
 * revision appends to the snapshot, and nothing already listed may change. It reads through the
 * resolver, so the whole declaration is validated on the way.
 */
final class PollsLegalCatalogSnapshotTest extends TestCase
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
        self::assertSame(PollsLegalCatalog::class, Hilos::legalCatalogClass());
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
                LegalDocument::TERMS->value => ['2026-09-17' => 1, '2026-10-01' => 1, '2026-10-07' => 1],
                LegalDocument::PRIVACY->value => ['2026-09-17' => 1, '2026-10-09' => 1],
            ],
            $published,
            'revision ids and the standard set version each adopts',
        );
    }

    /** First Terms has no deviations; latest Terms has availability; current Privacy declares deletion. */
    public function testTheCurrentDocumentsDeclareTheAnalyticsException(): void
    {
        $firstTerms = LegalCatalogResolver::compose(LegalDocument::TERMS, '2026-09-17');
        foreach ($firstTerms->clauses as $clause) {
            self::assertNull($clause->deviation, $clause->standard->key);
        }

        $latestTerms = LegalCatalogResolver::latestRevision(LegalDocument::TERMS);
        $termsDeviations = [];
        foreach (LegalCatalogResolver::compose(LegalDocument::TERMS, $latestTerms->id)->clauses as $clause) {
            if ($clause->deviation !== null) {
                $termsDeviations[$clause->standard->key] = $clause->deviation->direction;
            }
        }
        self::assertSame([StandardSetCatalog::CLAUSE_AVAILABILITY => DeviationDirection::STRICTER], $termsDeviations);

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

        $page = file_get_contents(dirname(__DIR__, 2) . '/frontend/src/app/views/privacy/privacy.ts');
        self::assertIsString($page);
        $page = preg_replace('/\s+/', ' ', $page);
        self::assertIsString($page);
        self::assertStringContainsString('records analytics', $page);
        self::assertStringContainsString('network addresses remain', $page);
        self::assertStringContainsString('no automatic deletion period', $page);
        self::assertStringNotContainsString('No analytics', $page);
    }
}
