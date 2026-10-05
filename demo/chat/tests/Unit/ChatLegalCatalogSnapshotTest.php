<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\Hilos;
use Demo\Chat\Legal\LegalCatalog;
use Hilos\Legal\DeviationDirection;
use Hilos\Legal\LegalCatalogResolver;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\StandardSetCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Snapshot of what the chat publishes as legal documents.
 *
 * A person holds a revision, so removing one from the catalog turns "show me the text I agreed
 * to" into a lie. This test makes that removal a red build, before the deploy: publishing a
 * revision appends to the snapshot, and nothing already listed may change. It reads through the
 * resolver, so the whole declaration is validated on the way.
 */
final class ChatLegalCatalogSnapshotTest extends TestCase
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

    public function testTheFacadeBindsTheChatCatalog(): void
    {
        self::assertSame(LegalCatalog::class, Hilos::legalCatalogClass());
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
                LegalDocument::TERMS->value => ['2026-09-17' => 1, '2026-09-27' => 1, '2026-10-01' => 1],
                LegalDocument::PRIVACY->value => ['2026-09-17' => 1, '2026-10-05' => 1],
            ],
            $published,
            'revision ids and the standard set version each adopts',
        );
    }

    /**
     * The latest revisions declare four stricter terms clauses and two looser privacy clauses.
     */
    public function testTheLatestRevisionsCarryTheChatDeviations(): void
    {
        self::assertSame(
            [
                LegalDocument::TERMS->value => [
                    StandardSetCatalog::CLAUSE_FILE_ACCESS => DeviationDirection::STRICTER,
                    StandardSetCatalog::CLAUSE_RETENTION => DeviationDirection::STRICTER,
                    StandardSetCatalog::CLAUSE_MODERATION => DeviationDirection::STRICTER,
                    StandardSetCatalog::CLAUSE_AVAILABILITY => DeviationDirection::STRICTER,
                ],
                LegalDocument::PRIVACY->value => [
                    StandardSetCatalog::CLAUSE_DELETION => DeviationDirection::LOOSER,
                    StandardSetCatalog::CLAUSE_ACCESS_LOG => DeviationDirection::LOOSER,
                ],
            ],
            [
                LegalDocument::TERMS->value => self::deviationsOf(LegalCatalogResolver::latestRevision(LegalDocument::TERMS)),
                LegalDocument::PRIVACY->value => self::deviationsOf(LegalCatalogResolver::latestRevision(LegalDocument::PRIVACY)),
            ],
        );
    }

    /** The current legal text and the public static page tell the same analytics truth. */
    public function testPrivacyTextStatesTheAnalyticsException(): void
    {
        $revision = LegalCatalogResolver::latestRevision(LegalDocument::PRIVACY);
        $texts = [];
        foreach ($revision->deviations as $deviation) {
            $texts[$deviation->clauseKey] = LegalCatalogResolver::text($deviation->textFile);
        }
        self::assertStringContainsString('Analytics still records', $texts[StandardSetCatalog::CLAUSE_ACCESS_LOG]);
        self::assertStringContainsString('network addresses remain', $texts[StandardSetCatalog::CLAUSE_DELETION]);
        self::assertStringContainsString('no automatic deletion period', $texts[StandardSetCatalog::CLAUSE_DELETION]);

        $page = file_get_contents(dirname(__DIR__, 2) . '/frontend/src/views/Privacy/Privacy.vue');
        self::assertIsString($page);
        self::assertStringContainsString('records analytics', $page);
        self::assertStringContainsString('network addresses remain', $page);
        self::assertStringContainsString('no automatic deletion period', $page);
        self::assertStringNotContainsString('No analytics', $page);
    }

    /**
     * @param LegalRevision $revision Revision to compose
     * @return array<string, DeviationDirection> Direction per deviated clause key, in the standard's order
     */
    private static function deviationsOf(LegalRevision $revision): array
    {
        $deviations = [];
        foreach (LegalCatalogResolver::compose($revision->document, $revision->id)->clauses as $clause) {
            if ($clause->deviation === null) {
                continue;
            }

            self::assertNotSame('', LegalCatalogResolver::text($clause->deviation->textFile));
            $deviations[$clause->standard->key] = $clause->deviation->direction;
        }

        return $deviations;
    }
}
