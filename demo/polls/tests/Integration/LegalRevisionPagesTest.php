<?php

declare(strict_types=1);

namespace Demo\Polls\Tests\Integration;

use Demo\Polls\Agents\Hilos\DemoHilosLegalAgent;
use Demo\Polls\Core\Router\PollsSignalRouter;
use Demo\Polls\Hilos;
use Demo\Polls\Pages\Hilos\Legal\LegalRevisionPage;
use Demo\Polls\Pages\Hilos\Legal\LegalRevisionTextPage;
use Demo\Polls\Runtime\View\Context\PollsRtContext;
use Demo\Polls\Tables\PollsTableContext;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosPageRouteParams;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Database;
use Hilos\Legal\LegalDocument;
use Hilos\Legal\StandardSetCatalog;
use Hilos\Pages\Legal\LegalAdminAudience;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\Legal\HilosLegalRevisionTableRow;
use Hilos\Tables\Legal\HilosLegalRevisionsTable;
use Hilos\TruthSource\RtTruthSourceRegistry;

/** Polls' real Revision bindings answer once, with exact counts and non-personal declaration data. */
final class LegalRevisionPagesTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';
    private const string ADMIN_KEY = 'legal-revision-admin';
    private const string VIEWER_KEY = 'legal-revision-viewer';
    private const string ANONYMOUS_KEY = 'legal-revision-anonymous';
    private const string VIEWER_NAME = 'Legal revision viewer';
    private const string HISTORICAL_REVISION = '2026-10-01';
    private const string CURRENT_REVISION = '2026-10-07';
    private const string RECORDED_REVISION = 'removed-revision';

    private ?SignalRouter $previousRouter = null;
    private int $adminId;
    private int $viewerId;

    /** A direct Text mount needs neither an Overview subscription nor an accepted-count viewport. */
    public function testInitialTextResponseCarriesHistoricalClausesAndIdentityWithoutAWindow(): void
    {
        $response = $this->subscribe(LegalRevisionTextPage::PAGE, self::HISTORICAL_REVISION);

        $this->assertHistoricalRevision($response);
        $this->assertRevisionIdentity($response);
        self::assertSame('Text', $response->payload->data['pageLabel']);
        self::assertSame('The assembled text of this revision.', $response->payload->data['pageLead']);
        self::assertSame([], $response->payload->windows);
        self::assertSame([], $response->payload->refusedWindows);
        self::assertNull(Hilos::$sr->getTableViewport(self::ADMIN_KEY, PollsTableContext::hilosLegalRevisions));
    }

    /** Accepting a newer revision moves heldCount without changing the historical acceptedCount. */
    public function testInitialOverviewResponseCarriesIdentityAndTheExactRevisionCount(): void
    {
        Hilos::$db->legalAcceptances->actions->accept($this->adminId, LegalDocument::TERMS, self::HISTORICAL_REVISION);
        Hilos::$db->legalAcceptances->actions->accept($this->adminId, LegalDocument::TERMS, self::CURRENT_REVISION);
        Hilos::$db->legalAcceptances->actions->accept($this->viewerId, LegalDocument::TERMS, self::CURRENT_REVISION);

        $response = $this->subscribe(LegalRevisionPage::PAGE, self::HISTORICAL_REVISION);

        $this->assertHistoricalRevision($response);
        $this->assertRevisionIdentity($response);
        self::assertSame('Revision', $response->payload->data['pageLabel']);
        self::assertSame([PollsTableContext::hilosLegalRevisions], array_keys($response->payload->windows));
        self::assertSame([], $response->payload->refusedWindows);
        $rows = $response->payload->windows[PollsTableContext::hilosLegalRevisions][TableWindowSignalData::rows];
        self::assertCount(1, $rows);
        self::assertSame(self::HISTORICAL_REVISION, $rows[0][PagePayload::rowKey]);
        self::assertSame(1, $rows[0][PagePayload::slots]['revision'][HilosLegalRevisionTableRow::acceptedCount]);
        self::assertSame(0, $rows[0][PagePayload::slots]['revision'][HilosLegalRevisionTableRow::heldCount]);
        self::assertSame([
            HilosLegalRevisionsTable::FILTER_DOCUMENT => LegalDocument::TERMS->value,
            HilosLegalRevisionsTable::FILTER_REVISION => self::HISTORICAL_REVISION,
        ], Hilos::$sr->getTableViewport(self::ADMIN_KEY, PollsTableContext::hilosLegalRevisions)->filter);
    }

    /** Records retain their Overview after a declaration disappears, without inventing its text. */
    public function testRecordedOnlyOverviewKeepsItsCountAndDirectTextRefuses(): void
    {
        // The public accept action admits declarations only; this fixture represents a removed declaration.
        Database::sqlRun(
            'INSERT INTO hilos_legal_acceptance (user_id, document, revision_id, accepted_at) VALUES (?, ?, ?, ?)',
            [$this->adminId, LegalDocument::TERMS->value, self::RECORDED_REVISION, '2026-10-01 12:00:00'],
        );
        $response = $this->subscribe(LegalRevisionPage::PAGE, self::RECORDED_REVISION);

        self::assertNull($response->payload->data['legalCatalogRefusal']);
        self::assertSame([
            'document' => 'terms',
            'revisionId' => self::RECORDED_REVISION,
            'declared' => false,
            'revision' => null,
            'current' => null,
            'predecessorId' => null,
            'clauses' => null,
            'changes' => null,
        ], $response->payload->data['legalRevision']);
        $this->assertRevisionIdentity($response, declared: false);
        $rows = $response->payload->windows[PollsTableContext::hilosLegalRevisions][TableWindowSignalData::rows];
        self::assertCount(1, $rows);
        self::assertSame(self::RECORDED_REVISION, $rows[0][PagePayload::rowKey]);
        self::assertSame(1, $rows[0][PagePayload::slots]['revision'][HilosLegalRevisionTableRow::acceptedCount]);

        try {
            $this->subscribe(LegalRevisionTextPage::PAGE, self::RECORDED_REVISION);
            self::fail('A recorded-only revision must not have a Text subscription');
        } catch (PageResourceNotFoundException $exception) {
            self::assertSame(404, $exception->httpCode);
            self::assertSame('not_found', $exception->errorCode);
            self::assertSame('No such legal revision', $exception->getMessage());
        }
    }

    /** Anonymous and signed-in viewers receive the same public declaration and counts as an administrator. */
    public function testViewersReceivePermittedTextMetadataAndAggregatesThroughPollsBindings(): void
    {
        Hilos::$db->legalAcceptances->actions->accept($this->viewerId, LegalDocument::TERMS, self::HISTORICAL_REVISION);
        $adminText = $this->subscribe(LegalRevisionTextPage::PAGE, self::HISTORICAL_REVISION);
        $adminOverview = $this->subscribe(LegalRevisionPage::PAGE, self::HISTORICAL_REVISION);

        foreach ([self::ANONYMOUS_KEY, self::VIEWER_KEY] as $acceptKey) {
            $text = $this->subscribe(LegalRevisionTextPage::PAGE, self::HISTORICAL_REVISION, $acceptKey);
            $overview = $this->subscribe(LegalRevisionPage::PAGE, self::HISTORICAL_REVISION, $acceptKey);
            $this->assertHistoricalRevision($text);
            $this->assertRevisionIdentity($text);
            $this->assertRevisionIdentity($overview);
            foreach ([[$adminText, $text], [$adminOverview, $overview]] as [$adminResponse, $viewerResponse]) {
                self::assertCount(count($adminResponse->payload->data), $viewerResponse->payload->data);
                foreach ($adminResponse->payload->data as $field => $value) {
                    self::assertSame($value, $viewerResponse->payload->data[$field], $field);
                }
            }
            self::assertSame([], $text->payload->windows);
            self::assertSame([], $text->payload->refusedWindows);
            self::assertSame([], $overview->payload->refusedWindows);
            self::assertSame([], $text->payload->entities);
            self::assertSame([], $overview->payload->entities);
            self::assertSame([], $overview->payload->lists);
            self::assertSame([], $overview->payload->tables);
            $rows = $overview->payload->windows[PollsTableContext::hilosLegalRevisions][TableWindowSignalData::rows];
            self::assertCount(1, $rows);
            self::assertSame([
                'rowKey', 'declared', 'revision', 'current', 'origin', 'heldCount', 'acceptedCount', 'publishedOn', 'effectiveOn',
            ], array_keys($rows[0][PagePayload::slots]['revision']));
            self::assertSame(1, $rows[0][PagePayload::slots]['revision'][HilosLegalRevisionTableRow::acceptedCount]);
            self::assertSame(1, $rows[0][PagePayload::slots]['revision'][HilosLegalRevisionTableRow::heldCount]);
            self::assertStringNotContainsString(self::VIEWER_NAME, json_encode($overview->toArray(), JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('"userId"', json_encode($overview->toArray(), JSON_THROW_ON_ERROR));
        }
    }

    /** Mounts the Polls browser and router with an administrator and both viewer identities. */
    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        LegalAdminAudience::reset();
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new PollsSignalRouter();
        RtTruthSourceRegistry::register(PollsRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);
        $this->adminId = (int) Hilos::$db->users->actions->registerAdmin()->id;
        $this->viewerId = (int) Hilos::$db->users->actions->createWithName(self::VIEWER_NAME)->id;
        Hilos::$rt->connections->actions->register(self::ADMIN_KEY, $this->adminId);
        Hilos::$rt->connections->actions->register(self::VIEWER_KEY, $this->viewerId);
        Hilos::$rt->connections->actions->register(self::ANONYMOUS_KEY, null);
    }

    /** Removes this case's rows and restores the router, browser and admin view mode. */
    protected function tearDown(): void
    {
        LegalAdminAudience::reset();
        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        Hilos::$rt->connections->actions->clear();
        Hilos::$db->legalAcceptances->actions->deleteForUser($this->adminId);
        Hilos::$db->legalAcceptances->actions->deleteForUser($this->viewerId);
        Hilos::$db->users[$this->adminId]->actions->delete();
        Hilos::$db->users[$this->viewerId]->actions->delete();
        Hilos::$sr = $this->previousRouter;
        Hilos::initBrowser();
        parent::tearDown();
    }

    /**
     * @param string $page Registered Polls page key
     * @param string $revisionId Exact revision to open
     * @param string $acceptKey Subscribing connection
     * @return PageResponseSignalData The subscription's sole browser frame
     */
    private function subscribe(string $page, string $revisionId, string $acceptKey = self::ADMIN_KEY): PageResponseSignalData
    {
        $this->drainSignals();
        Hilos::$sr->unsubscribeFromAll($acceptKey);
        LegalAdminAudience::removeSubscriber($acceptKey);
        $params = [
            HilosPageRouteParams::HILOS_LEGAL_DOCUMENT_KEY => LegalDocument::TERMS->value,
            HilosPageRouteParams::HILOS_LEGAL_REVISION_ID => $revisionId,
        ];
        Hilos::$sr->subscribeToPage($page, new WebSocketPageSubscribeSignalDTO($acceptKey, $page, $params));
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), static function () use ($page, $params, $acceptKey): void {
            new HilosPageFactory(new DemoHilosLegalAgent(), Hilos::class)
                ->getPage($page)->onSubscribe($acceptKey, new PageRouteParams($params));
        });
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertSame($acceptKey, $signal->data->targetAcceptKey);
            self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
            self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
            $frames[] = $signal->data->data;
        }
        self::assertCount(1, $frames);
        self::assertSame($page, $frames[0]->pageKey);

        return $frames[0];
    }

    /**
     * @param PageResponseSignalData $response First response for the historical declaration
     */
    private function assertHistoricalRevision(PageResponseSignalData $response): void
    {
        self::assertNull($response->payload->data['legalCatalogRefusal']);
        $revision = $response->payload->data['legalRevision'];
        self::assertSame([
            'document', 'revisionId', 'declared', 'revision', 'current', 'predecessorId', 'clauses', 'changes',
        ], array_keys($revision));
        self::assertSame('terms', $revision['document']);
        self::assertSame(self::HISTORICAL_REVISION, $revision['revisionId']);
        self::assertTrue($revision['declared']);
        self::assertFalse($revision['current']);
        self::assertSame('2026-09-17', $revision['predecessorId']);
        self::assertSame([
            'revisionId' => self::HISTORICAL_REVISION,
            'publishedOn' => '2026-10-01',
            'effectiveOn' => '2026-10-01',
            'significance' => 'substantial',
            'setVersion' => 1,
            'deviationCount' => 1,
        ], $revision['revision']);
        self::assertCount(6, $revision['clauses']);
        $availability = array_column($revision['clauses'], null, 'clauseKey')[StandardSetCatalog::CLAUSE_AVAILABILITY];
        self::assertSame('deviation', $availability['source']);
        self::assertStringContainsString('it is reset without notice', $availability['text']);
        self::assertStringNotContainsString('reset without warning', $availability['text']);
        self::assertCount(1, $revision['changes']);
        self::assertSame(StandardSetCatalog::CLAUSE_AVAILABILITY, $revision['changes'][0]['clauseKey']);
        self::assertSame($availability['text'], $revision['changes'][0]['after']['text']);
    }

    /**
     * @param PageResponseSignalData $response First response from either Revision page
     * @param bool $declared Whether the revision has a declaration and a Text tab
     */
    private function assertRevisionIdentity(PageResponseSignalData $response, bool $declared = true): void
    {
        self::assertSame([
            'page' => HilosPageConstants::HILOS_LEGAL_REVISION,
            'label' => 'Revision',
            'lead' => 'The exact text, its changes, and its acceptances.',
            'icon' => 'bi-file-earmark-text',
        ], $response->payload->data['pageHeadOwner']);
        self::assertSame([
            ['page' => HilosPageConstants::HILOS_DASHBOARD, 'label' => 'Hilos'],
            ['page' => HilosPageConstants::HILOS_LEGAL, 'label' => 'Legal'],
            ['page' => HilosPageConstants::HILOS_LEGAL_DOCUMENTS, 'label' => 'Documents'],
            ['page' => HilosPageConstants::HILOS_LEGAL_DOCUMENT, 'label' => 'Document'],
            ['page' => HilosPageConstants::HILOS_LEGAL_DOCUMENT_REVISIONS, 'label' => 'Revisions'],
            ['page' => HilosPageConstants::HILOS_LEGAL_REVISION, 'label' => 'Revision'],
        ], $response->payload->data['pageBreadcrumb']);
        self::assertSame($declared ? [
            ['page' => HilosPageConstants::HILOS_LEGAL_REVISION, 'label' => 'Overview'],
            ['page' => HilosPageConstants::HILOS_LEGAL_REVISION_TEXT, 'label' => 'Text'],
        ] : [
            ['page' => HilosPageConstants::HILOS_LEGAL_REVISION, 'label' => 'Overview'],
        ], $response->payload->data['pageTabs']);
        self::assertSame([], $response->payload->data['pageChildren']);
    }
}
