<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Legal;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Hilos;
use Hilos\Legal\DTO\LegalOverviewData;
use Hilos\Legal\DTO\LegalOverviewDocumentData;
use Hilos\Legal\Exception\UnknownRevisionException;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSignificance;
use Hilos\Legal\LegalStandingResolver;
use Hilos\Legal\LegalTally;
use Hilos\Legal\StandardSet;
use Hilos\Legal\StandardSetCatalog;
use Hilos\Pages\Legal\AbstractHilosLegalAcceptancesPage;
use Hilos\Pages\Legal\AbstractHilosLegalDocumentPage;
use Hilos\Pages\Legal\AbstractHilosLegalDocumentsPage;
use Hilos\Pages\Legal\AbstractHilosLegalPage;
use Hilos\Pages\Legal\AbstractHilosLegalRevisionPage;
use Hilos\Pages\Legal\AbstractHilosLegalSettingsPage;
use Hilos\Pages\Legal\DTO\HilosLegalSettingSetActionDTO;
use Hilos\Pages\Legal\LegalAdminAudience;
use Hilos\Tables\Legal\HilosLegalChecksTable;
use Hilos\Tables\Legal\HilosLegalDocumentsTable;
use Hilos\Tables\Legal\HilosLegalRevisionsTable;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/** First-response contents, catalog refusal and validation at the read-only admin boundary. */
final class LegalAdminPagesTest extends LegalAdminTestCase
{
    public function testEveryLegalPageRequiresAdmin(): void
    {
        foreach ([
            AbstractHilosLegalPage::class,
            AbstractHilosLegalDocumentsPage::class,
            AbstractHilosLegalDocumentPage::class,
            AbstractHilosLegalRevisionPage::class,
            AbstractHilosLegalAcceptancesPage::class,
            AbstractHilosLegalSettingsPage::class,
        ] as $page) {
            self::assertSame(PageAccessLevel::ADMIN, $page::ACCESS_LEVEL);
        }
    }

    public function testDocumentsIsAReadOnlyRouteWithOnlyItsBrowserSubscription(): void
    {
        self::assertSame(PageReach::ROUTE, AbstractHilosLegalDocumentsPage::REACH);
        self::assertSame('hilos_legal_documents', AbstractHilosLegalDocumentsPage::PAGE);
        self::assertSame([
            BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LEGAL_DOCUMENTS,
        ], AbstractHilosLegalDocumentsPage::BROWSER);
        self::assertSame('subscription_page_hilos_legal_documents', HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LEGAL_DOCUMENTS);
        self::assertSame([], AbstractHilosLegalDocumentsPage::ACTIONS);
        self::assertSame([], AbstractHilosLegalDocumentsPage::READING_ACTIONS);
        self::assertSame([], AbstractHilosLegalDocumentsPage::SIGNALS);
        self::assertTrue(new HilosPageFactory($this->createMock(PageAgentInterface::class), Hilos::class)
            ->hasPage(AbstractHilosLegalDocumentsPage::PAGE));
        self::assertSame(['legalCatalogRefusal' => null], $this->payload(LegalDocumentsPageFixture::class, [])->data);
        self::assertSame(0, $this->reads->reads);

        $page = new LegalDocumentsPageFixture($this->createMock(PageAgentInterface::class));
        new ReflectionMethod($page, 'onSubscribeAfterResponse')->invoke($page, 'documents', new PageRouteParams([]));
        self::assertSame(['documents' => 'hilos_legal_documents'], new ReflectionProperty(LegalAdminAudience::class, 'subscribers')->getValue());
        $page->onUnsubscribe('documents');
        self::assertSame([], new ReflectionProperty(LegalAdminAudience::class, 'subscribers')->getValue());
    }

    public function testRootPayloadCarriesTheSharedOverviewOnItsFirstRead(): void
    {
        $payload = $this->payload(LegalRootPageFixture::class, []);
        self::assertSame([
            'legalCatalogRefusal' => null,
            'legalOverview' => [
                'documentCount' => 1,
                'documents' => [['document' => 'terms', 'documentLabel' => 'Terms of use', 'window' => 0, 'lapsed' => 3]],
                'newerSetCount' => 0,
            ],
        ], $payload->data);
        new HilosLegalDocumentsTable()->getFullSnapshot();
        self::assertSame(3, $this->reads->reads);
    }

    public function testRootKeepsIndependentCountsForBothDocuments(): void
    {
        LegalOverviewTestHilos::initBrowser();
        new ReflectionProperty(LegalAdminAudience::class, 'cache')->setValue(null, [
            'privacy' => new LegalTally('privacy', true, 0, 0, 1, [], [], []),
            'terms' => new LegalTally('terms', true, 0, 1, 0, [], [], []),
        ]);
        new ReflectionProperty(LegalAdminAudience::class, 'cacheDate')->setValue(null, LegalStandingResolver::today());
        $overview = $this->payload(LegalRootPageFixture::class, [])->data['legalOverview'];
        self::assertSame([
            'documentCount' => 2,
            'documents' => [
                ['document' => 'terms', 'documentLabel' => 'Terms of use', 'window' => 1, 'lapsed' => 0],
                ['document' => 'privacy', 'documentLabel' => 'Privacy policy', 'window' => 0, 'lapsed' => 1],
            ],
            'newerSetCount' => 0,
        ], $overview);
        self::assertArrayNotHasKey('legalOverview', $this->payload(LegalDocumentsPageFixture::class, [])->data);
    }

    /** The overview keeps separate counts and catalog order, excluding recorded-only documents. */
    public function testLegalOverviewProjectsOnlyDeclaredDocuments(): void
    {
        LegalOverviewTestHilos::initBrowser();
        $overview = LegalOverviewData::fromTallies([
            'privacy' => new LegalTally('privacy', true, 10, 2, 3, [], [], []),
            'retired' => new LegalTally('retired', false, 0, 20, 30, [], [], []),
            'terms' => new LegalTally('terms', true, 10, 2, 3, [], [], []),
        ]);
        self::assertSame(2, $overview->documentCount);
        self::assertCount(2, $overview->documents);
        self::assertInstanceOf(LegalOverviewDocumentData::class, $overview->documents[0]);
        self::assertInstanceOf(LegalOverviewDocumentData::class, $overview->documents[1]);
        self::assertSame('legalOverview', LegalOverviewData::DATA);
        self::assertSame([
            'documentCount' => 2,
            'documents' => [
                ['document' => 'terms', 'documentLabel' => 'Terms of use', 'window' => 2, 'lapsed' => 3],
                ['document' => 'privacy', 'documentLabel' => 'Privacy policy', 'window' => 2, 'lapsed' => 3],
            ],
            'newerSetCount' => 0,
        ], $overview->toArray());
    }

    /** Declared documents remain present with zero counters when nobody has accepted them. */
    public function testLegalOverviewRetainsZeroDocumentCounts(): void
    {
        LegalOverviewTestHilos::initBrowser();
        $overview = LegalOverviewData::fromTallies([
            'terms' => LegalTally::of('terms', [], [], '2026-10-01'),
            'privacy' => LegalTally::of('privacy', [], [], '2026-10-01'),
        ]);
        self::assertSame([
            'documentCount' => 2,
            'documents' => [
                ['document' => 'terms', 'documentLabel' => 'Terms of use', 'window' => 0, 'lapsed' => 0],
                ['document' => 'privacy', 'documentLabel' => 'Privacy policy', 'window' => 0, 'lapsed' => 0],
            ],
            'newerSetCount' => 0,
        ], $overview->toArray());
        new ReflectionProperty(LegalAdminAudience::class, 'cache')->setValue(null, [
            'terms' => LegalTally::of('terms', [], [], '2026-10-01'),
            'privacy' => LegalTally::of('privacy', [], [], '2026-10-01'),
        ]);
        self::assertSame($overview->toArray(), $this->payload(LegalRootPageFixture::class, [])->data['legalOverview']);
    }

    /** An installation without declarations has no invented overview documents, even with historical records. */
    public function testLegalOverviewWithoutACatalogIsEmpty(): void
    {
        Hilos::initBrowser();
        self::assertSame([
            'documentCount' => 0,
            'documents' => [],
            'newerSetCount' => 0,
        ], LegalOverviewData::fromTallies([
            'retired' => LegalTally::of('retired', [], ['old' => 10], '2026-10-01'),
        ])->toArray());
        self::assertSame([
            'documentCount' => 0,
            'documents' => [],
            'newerSetCount' => 0,
        ], LegalOverviewData::fromTallies([])->toArray());
        self::assertSame([
            'legalCatalogRefusal' => null,
            'legalOverview' => ['documentCount' => 0, 'documents' => [], 'newerSetCount' => 0],
        ], $this->payload(LegalRootPageFixture::class, [])->data);
    }

    /** Version gaps contribute one count per document, rather than the number of skipped versions. */
    public function testLegalOverviewCountsDocumentsWithNewerStandardSets(): void
    {
        LegalOverviewTestHilos::initBrowser();
        $previousSets = new ReflectionProperty(StandardSetCatalog::class, 'sets')->getValue();
        try {
            $terms = StandardSetCatalog::set(LegalDocument::TERMS, 1);
            $privacy = StandardSetCatalog::set(LegalDocument::PRIVACY, 1);
            $sets = [
                'terms' => [$terms, new StandardSet(LegalDocument::TERMS, 5, '2026-10-01', LegalSignificance::EDITORIAL, $terms->clauses)],
                'privacy' => [
                    $privacy,
                    new StandardSet(LegalDocument::PRIVACY, 8, '2026-10-01', LegalSignificance::EDITORIAL, $privacy->clauses),
                ],
            ];
            new ReflectionProperty(StandardSetCatalog::class, 'sets')->setValue(null, $sets);
            $tallies = [
                'terms' => LegalTally::of('terms', [], [], '2026-10-01'),
                'privacy' => LegalTally::of('privacy', [], [], '2026-10-01'),
            ];
            self::assertSame(2, LegalOverviewData::fromTallies($tallies)->newerSetCount);
            self::assertSame(2, LegalOverviewData::fromTallies($tallies)->toArray()['newerSetCount']);
            new ReflectionProperty(LegalAdminAudience::class, 'cache')->setValue(null, $tallies);
            self::assertSame(2, $this->payload(LegalRootPageFixture::class, [])->data['legalOverview']['newerSetCount']);
            $sets['privacy'] = [$privacy];
            new ReflectionProperty(StandardSetCatalog::class, 'sets')->setValue(null, $sets);
            self::assertSame(1, LegalOverviewData::fromTallies($tallies)->newerSetCount);
        } finally {
            new ReflectionProperty(StandardSetCatalog::class, 'sets')->setValue(null, $previousSets);
        }
    }

    /** A faulty declaration remains a refusal rather than an empty successful overview. */
    public function testLegalOverviewPropagatesCatalogRefusal(): void
    {
        LegalBrokenCatalogHilos::initBrowser();
        $this->expectException(UnknownRevisionException::class);
        LegalOverviewData::fromTallies([]);
    }

    public function testDocumentResponseContainsTheSetAndTheCurrentRevision(): void
    {
        $payload = $this->payload(LegalDocumentPageFixture::class, ['documentKey' => 'terms']);
        self::assertNull($payload->data['legalCatalogRefusal']);
        self::assertArrayNotHasKey('legalOverview', $payload->data);
        $document = $payload->data['legalDocument'];
        self::assertTrue($document['declared']);
        self::assertSame('current', $document['revision']['revisionId']);
        self::assertSame(1, $document['set']['version']);
        self::assertCount(6, $document['set']['clauses']);
        self::assertSame([], $document['deviations']);
        self::assertNull($document['newerSet']);
    }

    public function testRevisionResponseCarriesTextAndComparisonWithoutAFollowupAction(): void
    {
        $payload = $this->payload(LegalRevisionPageFixture::class, ['documentKey' => 'terms', 'revisionId' => 'current']);
        self::assertArrayNotHasKey('legalOverview', $payload->data);
        $revision = $payload->data['legalRevision'];
        self::assertTrue($revision['declared']);
        self::assertTrue($revision['current']);
        self::assertSame('first', $revision['predecessorId']);
        self::assertCount(6, $revision['clauses']);
        self::assertSame([], $revision['changes']);
        $first = $this->payload(LegalRevisionPageFixture::class, ['documentKey' => 'terms', 'revisionId' => 'first']);
        self::assertNull($first->data['legalRevision']['changes']);
        self::assertNull($first->data['legalRevision']['predecessorId']);
    }

    public function testAnUndeclaredRevisionWithRecordsHasNoInventedText(): void
    {
        $payload = $this->payload(LegalRevisionPageFixture::class, ['documentKey' => 'terms', 'revisionId' => 'gone']);
        self::assertFalse($payload->data['legalRevision']['declared']);
        self::assertNull($payload->data['legalRevision']['clauses']);
        self::assertNull($payload->data['legalRevision']['revision']);
    }

    public function testAnUnknownDocumentIsRefused(): void
    {
        $this->expectException(PageResourceNotFoundException::class);
        $this->expectExceptionMessage('No such legal document');
        $this->payload(LegalDocumentPageFixture::class, ['documentKey' => 'unknown']);
    }

    public function testARevisionWithNeitherDeclarationNorRecordsIsRefused(): void
    {
        $this->expectException(PageResourceNotFoundException::class);
        $this->expectExceptionMessage('No such legal revision');
        $this->payload(LegalRevisionPageFixture::class, ['documentKey' => 'terms', 'revisionId' => 'unknown']);
    }

    public function testCatalogFailureReachesAllThreePagesAndEmptiesTheirTables(): void
    {
        LegalBrokenCatalogHilos::initBrowser();
        foreach ([LegalRootPageFixture::class, LegalDocumentsPageFixture::class,
            LegalDocumentPageFixture::class, LegalRevisionPageFixture::class] as $page) {
            $payload = $this->payload($page, ['documentKey' => 'terms', 'revisionId' => 'first']);
            self::assertSame(
                SignalConstants::ACTION_FAILED_REASON,
                $payload->data['legalCatalogRefusal'],
                'A non-admin viewer receives the fallback failure text; an admin receives the raw exception in chat integration test',
            );
            if ($page === LegalRootPageFixture::class) {
                self::assertArrayHasKey('legalOverview', $payload->data);
                self::assertNull($payload->data['legalOverview']);
            } else {
                self::assertArrayNotHasKey('legalOverview', $payload->data);
            }
        }
        foreach ([new HilosLegalDocumentsTable(), new HilosLegalChecksTable(), new HilosLegalRevisionsTable()] as $table) {
            self::assertSame(0, $table->getFullSnapshot()->totalCount);
        }
    }

    public function testTheSettingActionRoundtripKeepsItsKeyAndValue(): void
    {
        $dto = new HilosLegalSettingSetActionDTO('legal.consent_form', 'line');
        self::assertSame(HilosSignalConstants::LEGAL_SETTING_SET, $dto->getAction());
        self::assertSame($dto->toArray(), HilosLegalSettingSetActionDTO::fromArray($dto->toArray())->toArray());
    }

    /**
     * @param class-string<AbstractHilosLegalPage> $pageClass Page fixture
     * @param array<string, string> $params Route keys
     * @return PagePayload First-response data
     */
    private function payload(string $pageClass, array $params): PagePayload
    {
        return new ReflectionMethod($pageClass, 'buildPagePayload')->invoke(
            new ReflectionClass($pageClass)->newInstanceWithoutConstructor(),
            'viewer',
            new PageRouteParams($params),
        );
    }
}

/** Concrete root for projection calls only. */
final class LegalRootPageFixture extends AbstractHilosLegalPage
{
}

/** Concrete Documents page without a project activation. */
final class LegalDocumentsPageFixture extends AbstractHilosLegalDocumentsPage
{
}

/** Concrete document for projection calls only. */
final class LegalDocumentPageFixture extends AbstractHilosLegalDocumentPage
{
}

/** Concrete revision for projection calls only. */
final class LegalRevisionPageFixture extends AbstractHilosLegalRevisionPage
{
}

/** Deliberate catalog refusal; structural catalog checks have their own suite. */
final class LegalBrokenCatalog implements LegalCatalogProviderInterface
{
    /**
     * @return array<string, list<LegalRevision>> Never returns
     * @throws UnknownRevisionException Always, to exercise the page refusal boundary
     */
    public static function revisions(): array
    {
        throw new UnknownRevisionException('Broken catalog fixture');
    }
}

/** Binds the faulty declaration provider. */
abstract class LegalBrokenCatalogHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = LegalBrokenCatalog::class;
}

/** Declarations deliberately listed opposite the catalog's document order. */
final class LegalOverviewTestCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> One current revision per declared document */
    public static function revisions(): array
    {
        return [
            'privacy' => [
                new LegalRevision(LegalDocument::PRIVACY, 'current', '2020-01-01', 1, LegalSignificance::SUBSTANTIAL, '2020-01-01', []),
            ],
            'terms' => [
                new LegalRevision(LegalDocument::TERMS, 'current', '2020-01-01', 1, LegalSignificance::SUBSTANTIAL, '2020-01-01', []),
            ],
        ];
    }
}

/** Binds both documents without changing the page-delivery fixtures. */
abstract class LegalOverviewTestHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = LegalOverviewTestCatalog::class;
}
