<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Pages\Legal;

use DateTimeImmutable;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageResender;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\TableRowPlacement;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Collection\LegalAcceptances;
use Hilos\Pages\Legal\AbstractHilosLegalAcceptancesPage;
use Hilos\Pages\Legal\DTO\HilosLegalAcceptanceFiltersSignalData;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\Legal\LegalAcceptanceChangeSubscriber;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\LegalRevision;
use Hilos\Legal\LegalSignificance;
use Hilos\Legal\LegalStandingResolver;
use Hilos\Legal\LegalTally;
use Hilos\Pages\Legal\LegalAdminAudience;
use Hilos\Pages\Legal\AbstractHilosLegalPage;
use Hilos\Pages\Legal\AbstractHilosLegalDocumentsPage;
use Hilos\Pages\Legal\AbstractHilosLegalDocumentPage;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\Legal\HilosLegalDocumentTableRow;
use Hilos\Tables\Legal\HilosLegalDocumentsTable;
use Hilos\Tables\Legal\HilosLegalChecksTable;
use Hilos\Tests\Unit\Fixtures\IdentityTestBrowser;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\Exception\UnknownRevisionException;
use ReflectionProperty;
use ReflectionMethod;

/** Histogram reuse, date folding and the source-change route to every subscribed window. */
final class LegalAdminAudienceTest extends LegalAdminTestCase
{
    private ?TableContext $previousTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTables = Hilos::$table;
    }

    protected function tearDown(): void
    {
        Hilos::$table = $this->previousTables;
        parent::tearDown();
    }

    public function testAllWindowsShareOneReadUntilAChangeInvalidatesIt(): void
    {
        $first = LegalAdminAudience::tallies();
        self::assertSame(3, $this->reads->reads);
        self::assertSame($first, LegalAdminAudience::tallies());
        self::assertSame(3, $this->reads->reads);
        LegalAdminAudience::markStale();
        LegalAdminAudience::markStale();
        $this->reads->held['current']++;
        self::assertSame(3, $this->reads->reads);
        self::assertSame(3, LegalAdminAudience::tallies()['terms']->covered);
        self::assertSame(6, $this->reads->reads);
    }

    public function testTheDateFoldsTheCachedHistogramsWithoutSql(): void
    {
        $yesterday = new DateTimeImmutable(LegalStandingResolver::today())->modify('-1 day')->format('Y-m-d');
        $before = LegalTally::of('terms', $this->reads->held, $this->reads->accepted, $yesterday);
        self::assertSame(3, $before->window);
        new ReflectionProperty(LegalAdminAudience::class, 'cache')->setValue(null, ['terms' => $before]);
        new ReflectionProperty(LegalAdminAudience::class, 'cacheDate')->setValue(null, $yesterday);
        self::assertSame(3, LegalAdminAudience::tallies()['terms']->lapsed);
        self::assertSame(0, LegalAdminAudience::tallies()['terms']->window);
        self::assertSame(0, $this->reads->reads);
    }

    /** Document counters reuse the audience after coalesced invalidation and date folding. */
    public function testDocumentCountersFollowTheSharedTalliesWithoutExtraReads(): void
    {
        $page = new class ($this->createMock(PageAgentInterface::class)) extends AbstractHilosLegalDocumentPage {
        };
        $payload = new ReflectionMethod($page, 'buildPagePayload');
        $params = new PageRouteParams(['documentKey' => 'terms']);
        $yesterday = new DateTimeImmutable(LegalStandingResolver::today())->modify('-1 day')->format('Y-m-d');
        new ReflectionProperty(LegalAdminAudience::class, 'cache')->setValue(null, [
            'terms' => LegalTally::of('terms', $this->reads->held, $this->reads->accepted, $yesterday),
        ]);
        new ReflectionProperty(LegalAdminAudience::class, 'cacheDate')->setValue(null, $yesterday);
        $document = $payload->invoke($page, 'document', $params)->data['legalDocument'];
        self::assertSame([2, 0, 3], [$document['covered'], $document['window'], $document['lapsed']]);
        self::assertSame(0, $this->reads->reads);
        $this->reads->held['current']++;
        $this->acceptanceChanges();
        $document = $payload->invoke($page, 'document', $params)->data['legalDocument'];
        self::assertSame([3, 0, 3], [$document['covered'], $document['window'], $document['lapsed']]);
        self::assertSame(3, $this->reads->reads);
        self::assertSame($document, $payload->invoke($page, 'document', $params)->data['legalDocument']);
        self::assertSame(3, $this->reads->reads);

        new ReflectionMethod($page, 'onSubscribeAfterResponse')->invoke($page, 'document', $params);
        self::assertSame(['document' => HilosPageConstants::HILOS_LEGAL_DOCUMENT],
            new ReflectionProperty(LegalAdminAudience::class, 'subscribers')->getValue());
        $page->onUnsubscribe('document');
        self::assertSame([], new ReflectionProperty(LegalAdminAudience::class, 'subscribers')->getValue());
    }

    public function testATickWithNoSubscribersDoesNotReadOrSend(): void
    {
        Hilos::$browser = $this->createMock(BrowserContext::class);
        Hilos::$browser->expects(self::never())->method('sendTableWindow');
        Hilos::$browser->expects(self::never())->method('resendPageWhole');
        Hilos::$browser->expects(self::never())->method('publishTableMutations');
        LegalAdminAudience::markStale();
        LegalAdminAudience::onAgentTick($this->createMock(PageAgentInterface::class));
        self::assertSame(0, $this->reads->reads);
    }

    public function testRemoteAndLocalChangesRefreshBothViewersOncePerTick(): void
    {
        $agent = $this->createMock(PageAgentInterface::class);
        Hilos::$browser = $this->createMock(BrowserContext::class);
        Hilos::$browser->expects(self::never())->method('sendTableWindow');
        Hilos::$browser->expects(self::exactly(2))->method('resendPageWhole')
            ->with(HilosPageConstants::HILOS_LEGAL, 'first-viewer')
            ->willReturnCallback(static function (): void { LegalAdminAudience::tallies(); });
        Hilos::$browser->expects(self::exactly(2))->method('publishTableMutations')
            ->with(HilosPageConstants::HILOS_LEGAL_DOCUMENTS, 'second-viewer', HilosLegalDocumentsTable::TABLE, self::isCallable())
            ->willReturnCallback(static function (string $page, string $acceptKey, string $table, callable $build): void {
                $build(new HilosLegalDocumentsTable(), new TableQueryDTO());
            });
        Hilos::$sr->setTableViewport('second-viewer', new TableViewportSubscription(HilosLegalDocumentsTable::TABLE));
        LegalAdminAudience::addSubscriber('first-viewer', HilosPageConstants::HILOS_LEGAL);
        LegalAdminAudience::addSubscriber('second-viewer', HilosPageConstants::HILOS_LEGAL_DOCUMENTS);
        LegalAdminAudience::onAgentTick($agent);
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame(3, $this->reads->reads);
        $subscriber = new LegalAcceptanceChangeSubscriber();
        foreach ([SourceChangeProvenance::LocalWrite, SourceChangeProvenance::AppliedRemote] as $origin) {
            foreach ([TableMutationType::Create, TableMutationType::Delete, TableMutationType::Clear] as $mutation) {
                $subscriber->onSourceChange(new SourceChange(
                    SourceChange::KIND_DB,
                    HilosDbContext::legalAcceptances,
                    '42',
                    $mutation,
                ), $origin);
            }
        }
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame(6, $this->reads->reads);
        LegalAdminAudience::removeSubscriber('first-viewer');
        LegalAdminAudience::removeSubscriber('second-viewer');
        LegalAdminAudience::markStale();
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame(6, $this->reads->reads);
    }

    public function testDateRefreshReachesExistingViewersEvenIfAnotherReaderFoldedTheCache(): void
    {
        $agent = $this->createMock(PageAgentInterface::class);
        Hilos::$browser = $this->createMock(BrowserContext::class);
        Hilos::$browser->expects(self::never())->method('sendTableWindow');
        Hilos::$browser->expects(self::exactly(2))->method('resendPageWhole')
            ->with(HilosPageConstants::HILOS_LEGAL, 'viewer')
            ->willReturnCallback(static function (): void { LegalAdminAudience::tallies(); });
        Hilos::$sr->setTableViewport('viewer', new TableViewportSubscription(HilosLegalDocumentsTable::TABLE));
        LegalAdminAudience::addSubscriber('viewer', HilosPageConstants::HILOS_LEGAL);
        LegalAdminAudience::onAgentTick($agent);
        $yesterday = new DateTimeImmutable(LegalStandingResolver::today())->modify('-1 day')->format('Y-m-d');
        new ReflectionProperty(LegalAdminAudience::class, 'cacheDate')->setValue(null, $yesterday);
        new ReflectionProperty(LegalAdminAudience::class, 'deliveredDate')->setValue(null, $yesterday);
        LegalAdminAudience::tallies();
        LegalAdminAudience::onAgentTick($agent);
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame(3, $this->reads->reads);
    }

    public function testDocumentsOnlyPublishesValuesAndMovesThroughTheRealClassifier(): void
    {
        $agent = $this->delivery();
        $viewport = $this->documentsWindow('documents', new TableViewportSubscription(
            HilosLegalDocumentsTable::TABLE,
            sort: TableSortOrderDTO::of(new TableSortDTO('covered', TableConstants::ORDER_ASC)),
            limit: 1,
            pageIndex: 1,
        ));
        self::assertSame(['terms'], $viewport->rowIds());
        $this->reads->held['current'] = 0;
        $this->acceptanceChanges();
        LegalAdminAudience::onAgentTick($agent);
        Hilos::$browser->flushToSignalRouter();
        $frames = $this->frames();
        self::assertSame([SignalTypeConstants::TABLE_VIEWPORT_DELTA], array_column($frames, 0));
        self::assertSame('row_moved', $frames[0][1]->kind);
        self::assertSame(0, $frames[0][1]->row['slots']['document']['covered']);
        self::assertSame('terms', $frames[0][1]->rowKey);
        self::assertFalse($frames[0][1]->own);
        self::assertSame($viewport, Hilos::$sr->getTableViewport('documents', HilosLegalDocumentsTable::TABLE));
        self::assertSame(1, $viewport->pageIndex);
        self::assertSame('covered', $viewport->sort->components[0]->field);
        self::assertSame(1, $viewport->limit);
        self::assertSame(8, $this->reads->reads);
    }

    public function testSearchKeepsRowsOutsideItsWindowOutAndDeletesOnlyMissingKeys(): void
    {
        $agent = $this->delivery();
        $viewport = $this->documentsWindow('documents', new TableViewportSubscription(
            HilosLegalDocumentsTable::TABLE,
            filter: ['search' => 'Terms'],
            sort: TableSortOrderDTO::of(new TableSortDTO('covered', TableConstants::ORDER_DESC)),
            limit: 1,
        ));
        self::assertSame(['terms'], $viewport->rowIds());
        $this->reads->held['current']++;
        $this->acceptanceChanges();
        LegalAdminAudience::onAgentTick($agent);
        Hilos::$browser->flushToSignalRouter();
        $frames = $this->frames();
        self::assertSame([SignalTypeConstants::TABLE_VIEWPORT_DELTA], array_column($frames, 0));
        self::assertSame('row_updated', $frames[0][1]->kind);
        self::assertSame('terms', $frames[0][1]->rowKey);
        self::assertSame(3, $frames[0][1]->row['slots']['document']['covered']);
        self::assertFalse($frames[0][1]->own);
        self::assertSame(['search' => 'Terms'], $viewport->filter);
        self::assertSame(['terms'], $viewport->rowIds());

        $viewport = $this->documentsWindow('historical', new TableViewportSubscription(
            HilosLegalDocumentsTable::TABLE, filter: ['search' => 'retired'], limit: 1,
        ));
        self::assertSame(['zz-retired'], $viewport->rowIds());
        $this->reads->recorded = ['terms' => ['first', 'current']];
        $this->acceptanceChanges();
        LegalAdminAudience::onAgentTick($agent);
        Hilos::$browser->flushToSignalRouter();
        $frames = $this->frames();
        $deletions = array_values(array_filter($frames, static fn (array $frame): bool =>
            $frame[0] === SignalTypeConstants::TABLE_VIEWPORT_DELTA && $frame[1]->kind === 'row_removed'));
        self::assertCount(1, $deletions);
        self::assertSame('zz-retired', $deletions[0][1]->rowKey);
        self::assertSame('deleted', $deletions[0][1]->reason);
    }

    /** An unseen document entering a searched window is announced while both delivered rows stay unchanged. */
    public function testSearchAnnouncesAnUnseenDocumentWhoseCoverageBringsItInside(): void
    {
        $agent = $this->delivery();
        LegalAudienceSearchHilos::initBrowser(Hilos::$browser);
        $privacyCovered = 0;
        $acceptances = $this->createStub(LegalAcceptances::class);
        $acceptances->method('documentsOnRecord')->willReturn(['terms', 'zz-retired']);
        $acceptances->method('heldCounts')->willReturnCallback(function (string $document, array $declaredIds) use (&$privacyCovered): array {
            if ($document === 'privacy') {
                self::assertSame(['current'], $declaredIds);
                return ['current' => $privacyCovered];
            }
            return $this->reads->heldCounts($document, $declaredIds);
        });
        $acceptances->method('acceptedCounts')->willReturnCallback(function (string $document) use (&$privacyCovered): array {
            return match ($document) {
                'terms' => $this->reads->accepted,
                'privacy' => ['current' => $privacyCovered],
                'zz-retired' => ['old' => 1],
            };
        });
        Hilos::$db->legalAcceptances = $acceptances;

        $sort = TableSortOrderDTO::of(new TableSortDTO('covered', TableConstants::ORDER_DESC));
        $query = new TableQueryDTO(search: 'r', sort: $sort);
        $table = new HilosLegalDocumentsTable();
        $before = array_map(static fn (HilosLegalDocumentTableRow $row): array => $row->toArray(), $table->getPage($query)->rows);
        self::assertSame(['terms', 'zz-retired', 'privacy'], array_column($before, 'rowKey'));
        self::assertSame([2, 0, 0], array_column($before, 'covered'));
        $viewport = $this->documentsWindow('documents', new TableViewportSubscription(
            HilosLegalDocumentsTable::TABLE, filter: ['search' => 'r'], sort: $sort, limit: 2, pageIndex: 0,
        ));
        self::assertSame(['terms', 'zz-retired'], $viewport->rowIds());
        $anchors = [$viewport->firstAnchor(), $viewport->lastAnchor(), $viewport->rowAnchors()];
        $nonmatching = $this->documentsWindow('terms-only', new TableViewportSubscription(
            HilosLegalDocumentsTable::TABLE, filter: ['search' => 'Terms'], sort: $sort, limit: 2,
        ));
        self::assertSame(['terms'], $nonmatching->rowIds());

        $privacyCovered = 1;
        $this->acceptanceChanges();
        LegalAdminAudience::onAgentTick($agent);
        Hilos::$browser->flushToSignalRouter();
        $frames = $this->frames();
        self::assertSame([
            SignalTypeConstants::TABLE_VIEWPORT_ANNOUNCE,
            SignalTypeConstants::TABLE_VIEWPORT_COUNT,
        ], array_column($frames, 0));
        self::assertSame(HilosPageConstants::HILOS_LEGAL_DOCUMENTS, $frames[0][1]->page);
        self::assertSame(HilosLegalDocumentsTable::TABLE, $frames[0][1]->tableKey);
        self::assertSame('privacy', $frames[0][1]->rowKey);
        self::assertSame(TableRowPlacement::Inside, $frames[0][1]->placement);
        self::assertSame(3, $frames[0][1]->totalCount);
        self::assertSame(3, $frames[1][1]->totalCount);
        self::assertFalse($frames[1][1]->hasRowsAfter);
        self::assertSame(['terms', 'zz-retired'], $viewport->rowIds());
        self::assertSame(['terms'], $nonmatching->rowIds());
        self::assertSame($viewport, Hilos::$sr->getTableViewport('documents', HilosLegalDocumentsTable::TABLE));
        self::assertSame($anchors, [$viewport->firstAnchor(), $viewport->lastAnchor(), $viewport->rowAnchors()]);
        self::assertSame(['search' => 'r'], $viewport->filter);
        self::assertSame($sort, $viewport->sort);
        self::assertSame(2, $viewport->limit);
        self::assertSame(0, $viewport->pageIndex);
        $after = array_map(static fn (HilosLegalDocumentTableRow $row): array => $row->toArray(), $table->getPage($query)->rows);
        self::assertSame(['terms', 'privacy', 'zz-retired'], array_column($after, 'rowKey'));
        self::assertSame([2, 1, 0], array_column($after, 'covered'));
        self::assertSame($before[0], $after[0]);
        self::assertSame($before[1], $after[2]);
    }

    public function testCatalogOnlyTabsNeverAttemptCounterDrivenDelivery(): void
    {
        Hilos::$browser = $this->createMock(BrowserContext::class);
        Hilos::$browser->expects(self::never())->method('sendTableWindow');
        Hilos::$browser->expects(self::never())->method('publishTableMutations');
        Hilos::$browser->expects(self::exactly(2))->method('resendPageWhole')
            ->with(HilosPageConstants::HILOS_LEGAL_DOCUMENT, 'overview')
            ->willReturnCallback(static function (): void { LegalAdminAudience::tallies(); });
        LegalAdminAudience::addSubscriber('overview', HilosPageConstants::HILOS_LEGAL_DOCUMENT);
        LegalAdminAudience::addSubscriber('set', HilosPageConstants::HILOS_LEGAL_DOCUMENT_SET);
        LegalAdminAudience::addSubscriber('deviations', HilosPageConstants::HILOS_LEGAL_DOCUMENT_DEVIATIONS);
        $agent = $this->createMock(PageAgentInterface::class);
        LegalAdminAudience::onAgentTick($agent);
        $this->acceptanceChanges();
        LegalAdminAudience::onAgentTick($agent);
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame(6, $this->reads->reads);
        LegalAdminAudience::removeSubscriber('overview');
        $this->acceptanceChanges();
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame(6, $this->reads->reads);
    }

    /** Overview owns the counters; catalog-only tabs receive no counter-driven resend. */
    public function testDocumentOverviewRefreshesOnceAndRechecksAccessWhileOtherTabsStayQuiet(): void
    {
        $agent = $this->delivery();
        $page = HilosPageConstants::HILOS_LEGAL_DOCUMENT;
        Hilos::$sr->subscribeToPage($page, new WebSocketPageSubscribeSignalDTO('document', $page, ['documentKey' => 'terms']));
        LegalAdminAudience::addSubscriber('document', $page);
        LegalAdminAudience::addSubscriber('set', HilosPageConstants::HILOS_LEGAL_DOCUMENT_SET);
        LegalAdminAudience::addSubscriber('deviations', HilosPageConstants::HILOS_LEGAL_DOCUMENT_DEVIATIONS);
        LegalAdminAudience::onAgentTick($agent);
        Hilos::$browser->flushToSignalRouter();
        $frames = $this->frames();
        self::assertSame([SignalTypeConstants::PAGE_RESPONSE], array_column($frames, 0));
        self::assertSame(2, $frames[0][1]->payload->data['legalDocument']['covered']);
        self::assertSame([], $frames[0][1]->payload->windows);
        self::assertSame(4, $this->reads->reads);

        $this->reads->held['current']++;
        $this->acceptanceChanges();
        LegalAdminAudience::onAgentTick($agent);
        LegalAdminAudience::onAgentTick($agent);
        Hilos::$browser->flushToSignalRouter();
        $frames = $this->frames();
        self::assertSame([SignalTypeConstants::PAGE_RESPONSE], array_column($frames, 0));
        self::assertSame(3, $frames[0][1]->payload->data['legalDocument']['covered']);
        self::assertSame(8, $this->reads->reads);
        LegalAdminAudience::tallies();
        self::assertSame(8, $this->reads->reads);

        $yesterday = new DateTimeImmutable(LegalStandingResolver::today())->modify('-1 day')->format('Y-m-d');
        new ReflectionProperty(LegalAdminAudience::class, 'cacheDate')->setValue(null, $yesterday);
        new ReflectionProperty(LegalAdminAudience::class, 'deliveredDate')->setValue(null, $yesterday);
        LegalAdminAudience::tallies();
        LegalAdminAudience::onAgentTick($agent);
        Hilos::$browser->flushToSignalRouter();
        self::assertSame([SignalTypeConstants::PAGE_RESPONSE], array_column($this->frames(), 0));
        self::assertSame(8, $this->reads->reads);

        Hilos::$browser->admin = false;
        $this->acceptanceChanges();
        LegalAdminAudience::onAgentTick($agent);
        Hilos::$browser->flushToSignalRouter();
        $frames = $this->frames();
        self::assertSame([SignalConstants::SUBSCRIPTION_PAGE_ERROR], array_column($frames, 0));
        self::assertSame(403, $frames[0][1]->httpCode);
        self::assertSame(8, $this->reads->reads);
        LegalAdminAudience::removeSubscriber('document');
        LegalAdminAudience::markStale();
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame([], $this->frames());
        self::assertSame(8, $this->reads->reads);
    }

    public function testWholeRootAndDocumentsShareAReadAndRootRechecksItsGuards(): void
    {
        $agent = $this->delivery();
        Hilos::$sr->subscribeToPage(HilosPageConstants::HILOS_LEGAL,
            new WebSocketPageSubscribeSignalDTO('root', HilosPageConstants::HILOS_LEGAL));
        LegalAdminAudience::addSubscriber('root', HilosPageConstants::HILOS_LEGAL);
        $this->documentsWindow('documents', new TableViewportSubscription(HilosLegalDocumentsTable::TABLE));
        LegalAdminAudience::onAgentTick($agent);
        Hilos::$browser->flushToSignalRouter();
        $frames = $this->frames();
        self::assertSame([SignalTypeConstants::PAGE_RESPONSE], array_column($frames, 0));
        $payload = $frames[0][1]->payload;
        self::assertSame(1, $payload->data['legalOverview']['documentCount']);
        self::assertSame(['hilosLegalChecks'], array_keys($payload->windows));
        self::assertSame(4, $this->reads->reads);

        Hilos::$browser->admin = false;
        $this->acceptanceChanges();
        LegalAdminAudience::onAgentTick($agent);
        Hilos::$browser->flushToSignalRouter();
        $frames = $this->frames();
        self::assertSame([SignalConstants::SUBSCRIPTION_PAGE_ERROR], array_column($frames, 0));
        self::assertSame(403, $frames[0][1]->httpCode);
        self::assertSame(4, $this->reads->reads);
    }

    /** The root updates without a document window, even beside an unrelated Documents query. */
    public function testRootSummaryRefreshesWithoutHoldingADocumentsWindow(): void
    {
        $agent = $this->delivery();
        Hilos::$sr->subscribeToPage(HilosPageConstants::HILOS_LEGAL,
            new WebSocketPageSubscribeSignalDTO('root', HilosPageConstants::HILOS_LEGAL));
        LegalAdminAudience::addSubscriber('root', HilosPageConstants::HILOS_LEGAL);
        LegalAdminAudience::onAgentTick($agent);
        $frames = $this->frames();
        self::assertSame([SignalTypeConstants::PAGE_RESPONSE], array_column($frames, 0));
        self::assertSame(['hilosLegalChecks'], array_keys($frames[0][1]->payload->windows));
        self::assertSame(3, $frames[0][1]->payload->data['legalOverview']['documents'][0]['lapsed']);

        $this->documentsWindow('documents', new TableViewportSubscription(
            HilosLegalDocumentsTable::TABLE,
            sort: TableSortOrderDTO::of(new TableSortDTO('covered', TableConstants::ORDER_DESC)),
            filter: ['search' => 'zz-retired'], limit: 1,
        ));
        $this->reads->held['current']++;
        $this->reads->held['first']--;
        $this->acceptanceChanges();
        LegalAdminAudience::onAgentTick($agent);
        Hilos::$browser->flushToSignalRouter();
        $responses = array_values(array_filter($this->frames(), static fn (array $frame): bool =>
            $frame[0] === SignalTypeConstants::PAGE_RESPONSE));
        self::assertCount(1, $responses);
        self::assertSame(['hilosLegalChecks'], array_keys($responses[0][1]->payload->windows));
        self::assertSame([
            ['document' => 'terms', 'documentLabel' => 'Terms of use', 'window' => 0, 'lapsed' => 2],
        ], $responses[0][1]->payload->data['legalOverview']['documents']);
    }

    public function testHistogramFailureFreezesDocumentsAndRecoversOnTheNextSourceChange(): void
    {
        $agent = $this->delivery();
        $this->documentsWindow('documents', new TableViewportSubscription(HilosLegalDocumentsTable::TABLE));
        $collection = Hilos::$db->legalAcceptances;
        $broken = $this->createStub(LegalAcceptances::class);
        $broken->method('documentsOnRecord')->willThrowException(new DatabaseException('histograms unavailable'));
        Hilos::$db->legalAcceptances = $broken;
        $this->acceptanceChanges();
        LegalAdminAudience::onAgentTick($agent);
        $frames = $this->frames();
        self::assertSame([SignalTypeConstants::TABLE_VIEWPORT_FROZEN], array_column($frames, 0));
        self::assertTrue(Hilos::$sr->isTableWindowOwed('documents', HilosLegalDocumentsTable::TABLE));
        Hilos::$browser->flushToSignalRouter();

        Hilos::$db->legalAcceptances = $collection;
        $this->acceptanceChanges();
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame([SignalTypeConstants::TABLE_WINDOW], array_column($this->frames(), 0));
        self::assertFalse(Hilos::$sr->isTableWindowOwed('documents', HilosLegalDocumentsTable::TABLE));
    }

    public function testDocumentsDateDeliverySurvivesAnotherConsumerFoldingTheCache(): void
    {
        $agent = $this->delivery();
        $today = LegalStandingResolver::today();
        $yesterday = new DateTimeImmutable($today)->modify('-1 day')->format('Y-m-d');
        new ReflectionProperty(LegalAdminAudience::class, 'cache')->setValue(null, [
            'terms' => LegalTally::of('terms', $this->reads->held, $this->reads->accepted, $yesterday),
        ]);
        new ReflectionProperty(LegalAdminAudience::class, 'cacheDate')->setValue(null, $today);
        new ReflectionProperty(LegalAdminAudience::class, 'deliveredDate')->setValue(null, $yesterday);
        $this->documentsWindow('documents', new TableViewportSubscription(HilosLegalDocumentsTable::TABLE));
        new ReflectionProperty(LegalAdminAudience::class, 'cacheDate')->setValue(null, $yesterday);
        LegalAdminAudience::tallies();
        LegalAdminAudience::onAgentTick($agent);
        Hilos::$browser->flushToSignalRouter();
        $frames = $this->frames();
        self::assertSame([SignalTypeConstants::TABLE_VIEWPORT_DELTA], array_column($frames, 0));
        self::assertSame('row_updated', $frames[0][1]->kind);
        self::assertSame(0, $frames[0][1]->row['slots']['document']['window']);
        self::assertSame(3, $frames[0][1]->row['slots']['document']['lapsed']);
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame([], $this->frames());
        self::assertSame(0, $this->reads->reads);
    }

    public function testHistogramFailureReachesTheWholeRootRecoveryBoundary(): void
    {
        $agent = $this->delivery();
        Hilos::$sr->subscribeToPage(HilosPageConstants::HILOS_LEGAL,
            new WebSocketPageSubscribeSignalDTO('root', HilosPageConstants::HILOS_LEGAL));
        LegalAdminAudience::addSubscriber('root', HilosPageConstants::HILOS_LEGAL);
        $collection = Hilos::$db->legalAcceptances;
        $broken = $this->createStub(LegalAcceptances::class);
        $broken->method('documentsOnRecord')->willThrowException(new DatabaseException('histograms unavailable'));
        $broken->method('ofUser')->willReturnSelf();
        Hilos::$db->legalAcceptances = $broken;
        LegalAdminAudience::onAgentTick($agent);
        $frames = $this->frames();
        self::assertSame([SignalConstants::SUBSCRIPTION_PAGE_ERROR], array_column($frames, 0));
        self::assertSame(500, $frames[0][1]->httpCode);
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure('root'));
        Hilos::$browser->flushToSignalRouter();

        Hilos::$db->legalAcceptances = $collection;
        $this->acceptanceChanges();
        LegalAdminAudience::onAgentTick($agent);
        Hilos::$browser->recordDatabaseBack();
        Hilos::$browser->flushToSignalRouter();
        $frames = $this->frames();
        self::assertSame([SignalTypeConstants::PAGE_RESPONSE], array_column($frames, 0));
        self::assertSame(1, $frames[0][1]->payload->data['legalOverview']['documentCount']);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure('root'));
    }

    public function testUnrelatedSourcesDoNotInvalidateHistograms(): void
    {
        LegalAdminAudience::tallies();
        $subscriber = new LegalAcceptanceChangeSubscriber();
        foreach ([SourceChange::KIND_DB, SourceChange::KIND_RT] as $kind) {
            $subscriber->onSourceChange(new SourceChange(
                $kind,
                $kind === SourceChange::KIND_RT ? HilosDbContext::legalAcceptances : HilosDbContext::settings,
                '42',
                TableMutationType::Update,
            ), SourceChangeProvenance::AppliedRemote);
        }
        LegalAdminAudience::tallies();
        self::assertSame(3, $this->reads->reads);
    }
    public function testFiltersIncludeDeclaredDocumentsAndAllRecordedRevisionsInStableOrder(): void
    {
        $this->reads->recorded = ['retired' => ['a', 'z'], 'terms' => ['first', 'gone', 'current']];
        $filters = LegalAdminAudience::filters();
        self::assertSame([
            ['document' => 'terms', 'declared' => true, 'revisions' => [
                ['revisionId' => 'current', 'declared' => true],
                ['revisionId' => 'first', 'declared' => true],
                ['revisionId' => 'gone', 'declared' => false],
            ]],
            ['document' => 'retired', 'declared' => false, 'revisions' => [
                ['revisionId' => 'z', 'declared' => false],
                ['revisionId' => 'a', 'declared' => false],
            ]],
        ], $filters->documents);
        self::assertSame($filters, LegalAdminAudience::filters());
        self::assertSame(1, $this->reads->revisionReads);
        self::assertSame(0, $this->reads->reads);
        self::assertSame($filters->toArray(), HilosLegalAcceptanceFiltersSignalData::fromArray($filters->toArray())->toArray());
        $this->reads->recorded = [];
        LegalAdminAudience::markStale();
        self::assertSame([['document' => 'terms', 'declared' => true, 'revisions' => []]], LegalAdminAudience::filters()->documents);
    }

    public function testCatalogRefusalLeavesRecordedOptionsWithUnknownDeclarationStatus(): void
    {
        LegalFiltersBrokenHilos::initBrowser();
        $this->reads->recorded = ['terms' => ['first', 'gone'], 'retired' => ['old']];
        $data = LegalAdminAudience::filters();
        self::assertSame(['retired', 'terms'], array_column($data->documents, 'document'));
        self::assertSame([null, null], array_column($data->documents, 'declared'));
        self::assertSame([
            ['revisionId' => 'gone', 'declared' => null],
            ['revisionId' => 'first', 'declared' => null],
        ], $data->documents[1]['revisions']);
        self::assertSame($data->toArray(), HilosLegalAcceptanceFiltersSignalData::fromArray($data->toArray())->toArray());
    }

    public function testFiltersGoOnlyToAcceptanceViewersAndOnlyWhenTheVocabularyChanges(): void
    {
        $agent = $this->createMock(PageAgentInterface::class);
        LegalAdminAudience::addSubscriber('first', HilosPageConstants::HILOS_LEGAL_ACCEPTANCES);
        LegalAdminAudience::addSubscriber('second', HilosPageConstants::HILOS_LEGAL_ACCEPTANCES);
        LegalAdminAudience::addSubscriber('settings', HilosPageConstants::HILOS_LEGAL_SETTINGS);
        LegalAdminAudience::markStale();
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame(['first', 'second'], $this->filterTargets());
        self::assertSame(0, $this->reads->reads);
        LegalAdminAudience::markStale();
        LegalAdminAudience::markStale();
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame([], $this->filterTargets());
        self::assertSame(2, $this->reads->revisionReads);
        $this->reads->recorded['terms'][] = 'new-unknown';
        LegalAdminAudience::markStale();
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame(['first', 'second'], $this->filterTargets());
        self::assertSame(3, $this->reads->revisionReads);
        self::assertSame(0, $this->reads->reads);
        LegalAdminAudience::removeSubscriber('first');
        LegalAdminAudience::removeSubscriber('second');
        LegalAdminAudience::markStale();
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame([], $this->filterTargets());
        self::assertSame(3, $this->reads->revisionReads);
    }

    public function testDateChangeDoesNotReadOrSendFilters(): void
    {
        $agent = $this->createMock(PageAgentInterface::class);
        LegalAdminAudience::addSubscriber('viewer', HilosPageConstants::HILOS_LEGAL_ACCEPTANCES);
        LegalAdminAudience::markStale();
        LegalAdminAudience::onAgentTick($agent);
        $this->filterTargets();
        new ReflectionProperty(LegalAdminAudience::class, 'deliveredDate')->setValue(null, '2000-01-01');
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame([], $this->filterTargets());
        self::assertSame(1, $this->reads->revisionReads);
        self::assertSame(0, $this->reads->reads);
    }

    public function testNewSubscriptionDoesNotConsumeTheBroadcastOwedToExistingViewers(): void
    {
        $agent = $this->createMock(PageAgentInterface::class);
        $page = new class ($agent) extends AbstractHilosLegalAcceptancesPage {
            /** @param string $acceptKey Viewer to answer and register */
            public function subscribeForTest(string $acceptKey): void
            {
                $params = new PageRouteParams([]);
                $this->onSubscribeBeforeResponse($acceptKey, $params);
                $this->onSubscribeAfterResponse($acceptKey, $params);
            }
        };
        $page->subscribeForTest('first');
        self::assertSame(['first'], $this->filterTargets());
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame([], $this->filterTargets());
        LegalAdminAudience::markStale();
        LegalAdminAudience::onAgentTick($agent);
        $this->filterTargets();
        $this->reads->recorded['terms'][] = 'new-unknown';
        LegalAdminAudience::markStale();
        $page->subscribeForTest('second');
        self::assertSame(['second'], $this->filterTargets());
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame(['first', 'second'], $this->filterTargets());
        self::assertSame(3, $this->reads->revisionReads);
        $page->onUnsubscribe('first');
        $page->onUnsubscribe('second');
        LegalAdminAudience::markStale();
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame([], $this->filterTargets());
        self::assertSame(3, $this->reads->revisionReads);
    }

    public function testNumericHistoricalDocumentKeysRemainStringsOnTheWire(): void
    {
        $this->reads->recorded = ['123' => ['old']];
        $data = LegalAdminAudience::filters();
        self::assertSame('123', $data->documents[1]['document']);
        self::assertSame($data->toArray(), HilosLegalAcceptanceFiltersSignalData::fromArray($data->toArray())->toArray());
    }

    public function testEachBroadcastRechecksTheCurrentRightsAndBrowserGuards(): void
    {
        $admin = true;
        Hilos::$browser = $this->createMock(BrowserContext::class);
        Hilos::$browser->method('resolveActionUserId')->willReturn(1);
        Hilos::$browser->method('actsAsAdmin')->willReturnCallback(static function () use (&$admin): bool { return $admin; });
        Hilos::$browser->method('assertSubscriptionAccess')->willReturnCallback(
            static function (string $page, string $acceptKey): void {
                if ($acceptKey === 'guard-refused') {
                    throw new PageForbiddenException();
                }
            },
        );
        LegalAdminAudience::addSubscriber('viewer', HilosPageConstants::HILOS_LEGAL_ACCEPTANCES);
        LegalAdminAudience::addSubscriber('guard-refused', HilosPageConstants::HILOS_LEGAL_ACCEPTANCES);
        $agent = $this->createMock(PageAgentInterface::class);
        LegalAdminAudience::markStale();
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame(['viewer'], $this->filterTargets());
        $admin = false;
        $this->reads->recorded['terms'][] = 'after-revocation';
        LegalAdminAudience::markStale();
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame([], $this->filterTargets());
        Hilos::$browser = null;
        $this->reads->recorded['terms'][] = 'without-identity';
        LegalAdminAudience::markStale();
        LegalAdminAudience::onAgentTick($agent);
        self::assertSame([], $this->filterTargets());
    }

    /** @return PageAgentInterface Agent with the real page factory, guards and publisher */
    private function delivery(): PageAgentInterface
    {
        $agent = $this->createMock(PageAgentInterface::class);
        $agent->method('getAgentSignalSource')->willReturn(new SignalSource(SignalSource::WORKER));
        LegalAudienceDeliveryHilos::initBrowser(new LegalAudienceDeliveryBrowser());
        Hilos::$table = new LegalAudienceDeliveryTables();
        Hilos::$table->configure();
        $router = new PageSignalRouter(new HilosPageFactory($agent, LegalAudienceDeliveryHilos::class), new ActionRouteConfig());
        $resender = $this->createMock(PageResender::class);
        $resender->method('resendPage')->willReturnCallback($router->resendPage(...));
        Hilos::$browser->bindPageResender($resender);
        $this->reads->recorded['zz-retired'] = ['old'];
        $collection = $this->createStub(LegalAcceptances::class);
        $collection->method('documentsOnRecord')->willReturnCallback(function (): array {
            $this->reads->reads++;
            return array_keys($this->reads->recorded);
        });
        $collection->method('heldCounts')->willReturnCallback($this->reads->heldCounts(...));
        $collection->method('acceptedCounts')->willReturnCallback(function (string $document): array {
            $this->reads->reads++;
            return $document === 'terms' ? $this->reads->accepted : ['old' => 1];
        });
        $collection->method('ofUser')->willReturnSelf();
        Hilos::$db->legalAcceptances = $collection;

        return $agent;
    }

    /**
     * @param string $acceptKey Documents subscriber
     * @param TableViewportSubscription $viewport Current user-selected query
     * @return TableViewportSubscription Window recorded by the real browser
     */
    private function documentsWindow(string $acceptKey, TableViewportSubscription $viewport): TableViewportSubscription
    {
        Hilos::$sr->subscribeToPage(HilosPageConstants::HILOS_LEGAL_DOCUMENTS,
            new WebSocketPageSubscribeSignalDTO($acceptKey, HilosPageConstants::HILOS_LEGAL_DOCUMENTS));
        Hilos::$sr->setTableViewport($acceptKey, $viewport);
        self::assertTrue(Hilos::$browser->sendTableWindow(HilosPageConstants::HILOS_LEGAL_DOCUMENTS, $acceptKey, $viewport));
        $this->frames();
        LegalAdminAudience::addSubscriber($acceptKey, HilosPageConstants::HILOS_LEGAL_DOCUMENTS);

        return Hilos::$sr->getTableViewport($acceptKey, HilosLegalDocumentsTable::TABLE);
    }

    /** Coalesces both supported source-change provenances before the next delivery. */
    private function acceptanceChanges(): void
    {
        $subscriber = new LegalAcceptanceChangeSubscriber();
        foreach ([SourceChangeProvenance::LocalWrite, SourceChangeProvenance::AppliedRemote] as $provenance) {
            $subscriber->onSourceChange(SourceChange::dbCleared(HilosDbContext::legalAcceptances), $provenance);
        }
    }

    /** @return list<array{string, object}> Native wire names and payloads */
    private function frames(): array
    {
        $frames = [];
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $frames[] = [$frame->signalName->getName(), $frame->data->data];
        }
        return $frames;
    }

    /** @return list<string> Recipients of queued filter vocabulary frames */
    private function filterTargets(): array
    {
        $targets = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LEGAL_ACCEPTANCES, $signal->signalName->getName());
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertInstanceOf(HilosLegalAcceptanceFiltersSignalData::class, $signal->data->data);
            $targets[] = $signal->data->targetAcceptKey;
        }
        return $targets;
    }
}

/** Refuses independently of any catalog used by neighboring tests. */
final class LegalFiltersBrokenCatalog implements LegalCatalogProviderInterface
{
    /**
     * @return array Never returns
     * @throws UnknownRevisionException Always refuses this fixture
     */
    public static function revisions(): array
    {
        throw new UnknownRevisionException('Broken filter catalog');
    }
}

/** Binds the refusing filter catalog. */
abstract class LegalFiltersBrokenHilos extends Hilos
{
    protected const ?string LEGAL_CATALOG = LegalFiltersBrokenCatalog::class;
}

/** Framework-only activation keeps every project binding unchanged. */
abstract class LegalAudienceDeliveryHilos extends LegalAdminTestHilos
{
    public const array PAGES = [
        HilosPageConstants::HILOS_LEGAL => LegalAudienceRootPage::class,
        HilosPageConstants::HILOS_LEGAL_DOCUMENTS => LegalAudienceDocumentsPage::class,
        HilosPageConstants::HILOS_LEGAL_DOCUMENT => LegalAudienceDocumentPage::class,
    ];
    public const array TABLES = [
        HilosLegalDocumentsTable::TABLE => HilosLegalDocumentsTable::class,
        HilosLegalChecksTable::TABLE => HilosLegalChecksTable::class,
    ];
    public const array PAGE_TABLES = [
        HilosPageConstants::HILOS_LEGAL => [HilosLegalChecksTable::TABLE => []],
        HilosPageConstants::HILOS_LEGAL_DOCUMENTS => [HilosLegalDocumentsTable::TABLE => []],
    ];
}

/** Adds the second declared document only for the searched-window regression. */
abstract class LegalAudienceSearchHilos extends LegalAudienceDeliveryHilos
{
    protected const ?string LEGAL_CATALOG = LegalAudienceSearchCatalog::class;
}

/** Privacy starts without holders, behind the recorded-only row in coverage order. */
final class LegalAudienceSearchCatalog implements LegalCatalogProviderInterface
{
    /** @return array<string, list<LegalRevision>> Two declared documents */
    public static function revisions(): array
    {
        return [...LegalAdminTestCatalog::revisions(), 'privacy' => [
            new LegalRevision(LegalDocument::PRIVACY, 'current', '2020-02-01', 1, LegalSignificance::SUBSTANTIAL, '2020-02-01', []),
        ]];
    }
}

final class LegalAudienceRootPage extends AbstractHilosLegalPage
{
}

final class LegalAudienceDocumentsPage extends AbstractHilosLegalDocumentsPage
{
}

final class LegalAudienceDeliveryTables extends TableContext
{
    /** Registers real typed projections without database or runtime table substitutes. */
    public function configure(): void
    {
        $this->register(HilosLegalDocumentsTable::TABLE, new HilosLegalDocumentsTable());
        $this->register(HilosLegalChecksTable::TABLE, new HilosLegalChecksTable());
    }
}

/** Current identity remains the real browser guard's input. */
final class LegalAudienceDeliveryBrowser extends IdentityTestBrowser
{
    public bool $admin = true;

    public function __construct()
    {
        parent::__construct(1, true);
    }

    /**
     * @param int $userId Current viewer
     * @return bool Mutable admin verdict
     */
    public function isAdmin(int $userId): bool
    {
        return $this->admin;
    }
}

/** Real common document payload for guarded resends. */
final class LegalAudienceDocumentPage extends AbstractHilosLegalDocumentPage
{
}
