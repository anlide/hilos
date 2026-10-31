<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\Hilos\DemoHilosLegalAgent;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Hilos;
use Demo\Chat\Legal\LegalCatalog;
use Demo\Chat\Pages\Hilos\Legal\LegalAcceptancesPage;
use Demo\Chat\Pages\Hilos\Legal\LegalDocumentPage;
use Demo\Chat\Pages\Hilos\Legal\LegalDocumentSetPage;
use Demo\Chat\Pages\Hilos\Legal\LegalDocumentDeviationsPage;
use Demo\Chat\Pages\Hilos\Legal\LegalDocumentRevisionsPage;
use Demo\Chat\Pages\Hilos\Legal\LegalPage;
use Demo\Chat\Pages\Hilos\Legal\LegalDocumentsPage;
use Demo\Chat\Pages\Hilos\Legal\LegalRevisionPage;
use Demo\Chat\Pages\Hilos\Legal\LegalSettingsPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Tables\ChatTableContext;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageResender;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\DTO\TableViewportAppendDTO;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Hilos as FrameworkHilos;
use Hilos\Legal\Exception\UnknownRevisionException;
use Hilos\Legal\Export\LegalAcceptancesExportProjector;
use Hilos\Legal\Export\LegalAcceptancesExportState;
use Hilos\Legal\LegalCatalogProviderInterface;
use Hilos\Legal\LegalAcceptanceChangeSubscriber;
use Hilos\Legal\StandardSetCatalog;
use Hilos\Pages\Legal\DTO\HilosLegalAcceptanceFiltersSignalData;
use Hilos\Pages\Legal\LegalAdminAudience;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Tables\Legal\HilosLegalAcceptanceTableRow;
use Hilos\Tables\Legal\HilosLegalRevisionsTable;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\TimeHelper;
use ReflectionMethod;

/** Real demo bindings answer read-only legal pages and supply options beyond the first SQL window. */
final class LegalAdminPagesTest extends IntegrationTestCase
{
    private const string ACCEPT_KEY = 'legal-admin-viewer';
    private const string TEST_AGENT = 'legal-admin-test';

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        LegalAdminAudience::reset();
        Database::sqlRun('DELETE FROM hilos_legal_acceptance');
        Database::sqlRun('DELETE FROM hilos_legal_acceptance_export');
        Database::sqlRun('DELETE FROM hilos_session');
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        Hilos::$rt->connections->actions->clear();
        $user = Hilos::$db->users->actions->createWithName('Legal administrator');
        $user->actions->setAdmin(true);
        $this->userId = (int) $user->id;
        $session = Hilos::$db->sessions->actions->createAnonymous(substr(hash('sha256', $this->name()), 0, 32));
        $session->actions->bindUser($this->userId);
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $this->userId, $session->token, (int) $session->id);
        Hilos::$sr = new ChatSignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::initBrowser();
        LegalAdminAudience::reset();
        Database::sqlRun('DELETE FROM hilos_legal_acceptance');
        Database::sqlRun('DELETE FROM hilos_legal_acceptance_export');
        Database::sqlRun('DELETE FROM hilos_session');
        Hilos::$rt->connections->actions->clear();
        RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
        Hilos::$sr = null;
        parent::tearDown();
    }

    public function testRootDocumentAndRevisionResponsesUseTheDemoCatalogWithoutWritingAcceptances(): void
    {
        $this->record($this->userId, '2026-09-17');
        Database::sqlRun(
            'INSERT INTO hilos_legal_acceptance (user_id, document, revision_id, accepted_at) VALUES (?, ?, ?, ?)',
            [$this->userId, 'privacy', '2026-09-17', '2026-09-27 12:00:00'],
        );
        $root = $this->response($this->subscribe(LegalPage::class));
        self::assertNull($root->payload->data['legalCatalogRefusal']);
        self::assertSame(['hilosLegalChecks', 'hilosLegalSettings'], array_keys($root->payload->windows));
        self::assertSame([
            'documentCount' => 2,
            'documents' => [
                ['document' => 'terms', 'documentLabel' => 'Terms of use', 'window' => 0, 'lapsed' => 1],
                ['document' => 'privacy', 'documentLabel' => 'Privacy policy', 'window' => 0, 'lapsed' => 1],
            ],
            'newerSetCount' => 0,
        ], $root->payload->data['legalOverview']);
        $documents = Hilos::$table->hilosLegalDocuments->getFullSnapshot();
        self::assertSame(['terms', 'privacy'], array_column($documents->rows, 'rowKey'));
        // The third terms revision is substantial, so a holder of the first is no longer covered (HIL-500).
        self::assertSame(0, $documents->rows[0]->covered);
        self::assertCount(4, Hilos::$table->hilosLegalChecks->getFullSnapshot()->rows);
        $document = $this->response($this->subscribe(LegalDocumentPage::class, ['documentKey' => 'terms']));
        self::assertArrayNotHasKey('legalOverview', $document->payload->data);
        self::assertSame(1, $document->payload->data['legalDocument']['set']['version']);
        self::assertCount(4, $document->payload->data['legalDocument']['deviations']);
        $common = $document->payload->data['legalDocument'];
        self::assertSame([0, 0, 1], [$common['covered'], $common['window'], $common['lapsed']]);
        self::assertSame(StandardSetCatalog::class, $common['standardSetCatalogClass']);
        self::assertSame(LegalCatalog::class, $common['projectCatalogClass']);
        self::assertSame(['hilosLegalSettings'], array_keys($document->payload->windows));
        self::assertNull(Hilos::$sr->getTableViewport(self::ACCEPT_KEY, 'hilosLegalRevisions'));
        $revision = $this->response($this->subscribe(LegalRevisionPage::class, ['documentKey' => 'terms', 'revisionId' => '2026-09-27']));
        self::assertArrayNotHasKey('legalOverview', $revision->payload->data);
        self::assertCount(6, $revision->payload->data['legalRevision']['clauses']);
        self::assertCount(1, $revision->payload->data['legalRevision']['changes']);
        self::assertSame(['document' => 'terms', 'revision' => '2026-09-27'],
            Hilos::$sr->getTableViewport(self::ACCEPT_KEY, 'hilosLegalRevisions')->filter);
        $exact = $revision->payload->windows['hilosLegalRevisions']['rows'];
        self::assertCount(1, $exact);
        self::assertSame('2026-09-27', $exact[0]['slots']['revision']['publishedOn']);
        $rows = Hilos::$table->hilosLegalRevisions->getPage(new TableQueryDTO(filter: ['document' => 'terms']))->rows;
        self::assertSame([0, 0, 1], array_column($rows, 'heldCount'));
        self::assertSame(2, (int) Database::sql('SELECT COUNT(*) AS total FROM hilos_legal_acceptance')->firstRow()['total']);
    }

    /** A reused table key cannot carry another document's search, order or paging into the first response. */
    public function testDocumentTransitionsRejectForeignReportedWindows(): void
    {
        foreach ([
            [LegalDocumentSetPage::class, ChatTableContext::hilosLegalDocumentSet],
            [LegalDocumentDeviationsPage::class, ChatTableContext::hilosLegalDocumentDeviations],
            [LegalDocumentRevisionsPage::class, ChatTableContext::hilosLegalRevisions],
        ] as [$page, $tableKey]) {
            $this->subscribe($page, ['documentKey' => 'terms']);
            $foreign = new TableWindowDescriptorDTO(
                filter: ['document' => 'terms', TableConstants::FILTER_KEY_SEARCH => 'no matching legal row'],
                sort: TableSortOrderDTO::of(new TableSortDTO('clauseKey', TableConstants::ORDER_DESC)),
                limit: 1,
                pageIndex: 3,
            );
            $response = $this->response($this->subscribe($page, ['documentKey' => 'privacy'], [$tableKey => $foreign]));
            $viewport = Hilos::$sr->getTableViewport(self::ACCEPT_KEY, $tableKey);
            self::assertSame('privacy', $response->payload->data['legalDocument']['document']);
            self::assertSame(['document' => 'privacy'], $viewport->filter);
            self::assertNull($viewport->pageIndex);
            self::assertNull($viewport->anchor);
            self::assertSame(25, $viewport->limit);
            self::assertArrayHasKey($tableKey, $response->payload->windows);
            self::assertArrayNotHasKey($tableKey, $response->payload->refusedWindows);
            self::assertSame(0, $response->payload->windows[$tableKey]['rowsBefore']);
        }
    }

    /** The shared revisions table returns the full list after exact detail and keeps an exact reconnect intact. */
    public function testRevisionListAndExactTransitionsUseTheirOwnScope(): void
    {
        $params = ['documentKey' => 'terms', 'revisionId' => '2026-09-27'];
        $own = new TableWindowDescriptorDTO(
            filter: ['document' => 'terms', 'revision' => '2026-09-27', TableConstants::FILTER_KEY_SEARCH => '2026'],
            sort: TableSortOrderDTO::of(new TableSortDTO('heldCount', TableConstants::ORDER_ASC)),
            limit: 1,
            pageIndex: 0,
        );
        $this->subscribe(LegalRevisionPage::class, $params);
        $exact = $this->response($this->subscribe(LegalRevisionPage::class, $params, [ChatTableContext::hilosLegalRevisions => $own]));
        $viewport = Hilos::$sr->getTableViewport(self::ACCEPT_KEY, ChatTableContext::hilosLegalRevisions);
        self::assertSame($own->filter, $viewport->filter);
        self::assertSame($own->sort, $viewport->sort);
        self::assertSame(1, $viewport->limit);
        self::assertSame(0, $viewport->pageIndex);
        self::assertCount(1, $exact->payload->windows[ChatTableContext::hilosLegalRevisions]['rows']);

        $list = $this->response($this->subscribe(LegalDocumentRevisionsPage::class, ['documentKey' => 'terms'], [
            ChatTableContext::hilosLegalRevisions => $own,
        ]));
        self::assertSame(['document' => 'terms'], Hilos::$sr->getTableViewport(self::ACCEPT_KEY, ChatTableContext::hilosLegalRevisions)->filter);
        self::assertSame(['2026-10-01', '2026-09-27', '2026-09-17'], array_column($list->payload->windows['hilosLegalRevisions']['rows'], 'rowKey'));
        $exact = $this->response($this->subscribe(LegalRevisionPage::class, $params, [
            ChatTableContext::hilosLegalRevisions => new TableWindowDescriptorDTO(filter: ['document' => 'terms']),
        ]));
        self::assertSame(['document' => 'terms', 'revision' => '2026-09-27'],
            Hilos::$sr->getTableViewport(self::ACCEPT_KEY, ChatTableContext::hilosLegalRevisions)->filter);
        self::assertSame(['2026-09-27'], array_column($exact->payload->windows['hilosLegalRevisions']['rows'], 'rowKey'));
    }

    /** Prepared aggregate mutations update values while position changes remain ordinary pending deltas. */
    public function testRevisionAggregateMutationsUseTheOrdinaryViewportPath(): void
    {
        $this->record($this->userId, '2026-09-17');
        $sort = TableSortOrderDTO::of(new TableSortDTO('heldCount', TableConstants::ORDER_DESC));
        $this->subscribe(LegalDocumentRevisionsPage::class, ['documentKey' => 'terms'], [
            ChatTableContext::hilosLegalRevisions => new TableWindowDescriptorDTO(sort: $sort, limit: 25, filter: ['document' => 'terms']),
        ]);
        $viewport = Hilos::$sr->getTableViewport(self::ACCEPT_KEY, ChatTableContext::hilosLegalRevisions);
        self::assertSame(['2026-09-17', '2026-10-01', '2026-09-27'], $viewport->rowIds());
        $anchors = [$viewport->firstAnchor(), $viewport->lastAnchor()];
        $this->record($this->userId, '2026-10-01');
        LegalAdminAudience::markStale();
        LegalAdminAudience::onAgentTick(new DemoHilosLegalAgent());
        Hilos::$browser->flushToSignalRouter();
        $deltas = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertNotSame(SignalTypeConstants::TABLE_WINDOW, $signal->signalName->getName());
            if ($signal->signalName->getName() === SignalTypeConstants::TABLE_VIEWPORT_DELTA) {
                $deltas[(string) $signal->data->data->rowKey] = $signal->data->data;
            }
        }
        self::assertArrayHasKey('2026-10-01', $deltas);
        self::assertSame('row_moved', $deltas['2026-10-01']->kind);
        self::assertFalse($deltas['2026-10-01']->own);
        self::assertSame(1, $deltas['2026-10-01']->row['slots']['revision']['heldCount']);
        self::assertSame(1, $deltas['2026-10-01']->row['slots']['revision']['acceptedCount']);
        self::assertSame(0, $deltas['2026-09-17']->row['slots']['revision']['heldCount']);
        self::assertSame(1, $deltas['2026-09-17']->row['slots']['revision']['acceptedCount']);
        self::assertSame(['2026-09-17', '2026-10-01', '2026-09-27'], $viewport->rowIds());
        self::assertSame($anchors, [$viewport->firstAnchor(), $viewport->lastAnchor()]);
    }

    /** Count values arrive as updates when the revision stays in the same publication order. */
    public function testRevisionAggregateValuesUpdateWithoutPendingMovement(): void
    {
        $this->record($this->userId, '2026-10-01');
        $response = $this->response($this->subscribe(LegalDocumentRevisionsPage::class, ['documentKey' => 'terms']));
        self::assertSame(0, $response->payload->windows['hilosLegalRevisions']['rows'][1]['slots']['revision']['acceptedCount']);
        $viewport = Hilos::$sr->getTableViewport(self::ACCEPT_KEY, ChatTableContext::hilosLegalRevisions);
        self::assertSame(['2026-10-01', '2026-09-27', '2026-09-17'], $viewport->rowIds());
        $anchors = array_map(static fn (?TableAnchorDTO $anchor): ?array => $anchor?->toArray(), $viewport->rowAnchors());
        $this->record($this->userId, '2026-09-27');
        LegalAdminAudience::markStale();
        self::assertSame(1, LegalAdminAudience::tallies()['terms']->acceptedByRevision['2026-09-27']);
        LegalAdminAudience::onAgentTick(new DemoHilosLegalAgent());
        Hilos::$browser->flushToSignalRouter();
        $deltas = [];
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $frames[] = $signal->signalName->getName();
            if ($signal->signalName->getName() === SignalTypeConstants::TABLE_VIEWPORT_DELTA) {
                $deltas[] = $signal->data->data;
            }
        }
        self::assertCount(1, $deltas, implode(', ', $frames));
        self::assertSame('row_updated', $deltas[0]->kind);
        self::assertSame('2026-09-27', $deltas[0]->rowKey);
        self::assertSame(0, $deltas[0]->row['slots']['revision']['heldCount']);
        self::assertSame(1, $deltas[0]->row['slots']['revision']['acceptedCount']);
        self::assertSame(['2026-10-01', '2026-09-27', '2026-09-17'], $viewport->rowIds());
        self::assertSame($anchors, array_map(static fn (?TableAnchorDTO $anchor): ?array => $anchor?->toArray(), $viewport->rowAnchors()));
    }

    /** Every section page carries its common owner, trail and row in its one answer. */
    public function testLegalSectionPagesCarryTheAdoptedHeadInTheirFirstResponse(): void
    {
        foreach ([LegalPage::class, LegalDocumentsPage::class, LegalAcceptancesPage::class, LegalSettingsPage::class] as $page) {
            $frames = $this->subscribe($page);
            $responses = array_values(array_filter(
                $frames, static fn (array $frame): bool => $frame['name'] === SignalTypeConstants::PAGE_RESPONSE,
            ));
            self::assertCount(1, $responses);
            self::assertInstanceOf(PageResponseSignalData::class, $responses[0]['data']);
            $data = $responses[0]['data']->payload->data;
            self::assertSame([
                'page' => 'hilos_legal',
                'label' => 'Legal',
                'lead' => 'What the project declares, and who accepted it.',
                'icon' => 'bi-file-earmark-check',
            ], $data['pageHeadOwner']);
            self::assertSame([
                ['page' => 'hilos', 'label' => 'Hilos'],
                ['page' => 'hilos_legal', 'label' => 'Legal'],
            ], $data['pageBreadcrumb']);
            self::assertSame([
                ['page' => 'hilos_legal', 'label' => 'Overview'],
                ['page' => 'hilos_legal_documents', 'label' => 'Documents'],
                ['page' => 'hilos_legal_acceptances', 'label' => 'Acceptances'],
                ['page' => 'hilos_legal_settings', 'label' => 'Settings'],
            ], $data['pageTabs']);
            self::assertSame([], $data['pageChildren']);
        }
    }

    /** Every document address adopts the complete Document head in the first response. */
    public function testDocumentPagesCarryTheAdoptedHeadInTheirFirstResponse(): void
    {
        foreach ([LegalDocumentPage::class, LegalDocumentSetPage::class,
            LegalDocumentDeviationsPage::class, LegalDocumentRevisionsPage::class] as $page) {
            $response = $this->response($this->subscribe($page, ['documentKey' => 'terms']));
            $data = $response->payload->data;
            self::assertSame([
                'page' => 'hilos_legal_document',
                'label' => 'Document',
                'lead' => 'The standard set, project deviations, and declared revisions.',
                'icon' => 'bi-file-earmark-text',
            ], $data['pageHeadOwner']);
            self::assertSame([
                ['page' => 'hilos', 'label' => 'Hilos'],
                ['page' => 'hilos_legal', 'label' => 'Legal'],
                ['page' => 'hilos_legal_documents', 'label' => 'Documents'],
                ['page' => 'hilos_legal_document', 'label' => 'Document'],
            ], $data['pageBreadcrumb']);
            self::assertSame([
                ['page' => 'hilos_legal_document', 'label' => 'Overview'],
                ['page' => 'hilos_legal_document_set', 'label' => 'Standard set'],
                ['page' => 'hilos_legal_document_deviations', 'label' => 'Deviations'],
                ['page' => 'hilos_legal_document_revisions', 'label' => 'Revisions'],
            ], $data['pageTabs']);
            self::assertSame([], $data['pageChildren']);
        }
    }

    /** The cold Documents reply opens both windows without a root overview projection. */
    public function testDocumentsFirstResponseCarriesTheSortedDocumentsAndSettingsWindows(): void
    {
        $this->record($this->userId, 'removed');
        $frames = $this->subscribe(LegalDocumentsPage::class);
        $response = $this->response($frames);
        self::assertSame('hilos_legal_documents', $response->pageKey);
        self::assertSame(null, $response->payload->data['legalCatalogRefusal']);
        self::assertArrayNotHasKey('legalOverview', $response->payload->data);
        self::assertSame(['hilosLegalDocuments', 'hilosLegalSettings'], array_keys($response->payload->windows));
        $window = $response->payload->windows[ChatTableContext::hilosLegalDocuments];
        self::assertSame([['field' => 'publishedOn', 'direction' => 'desc']], $window['sort']);
        self::assertSame(['privacy', 'terms'], array_column($window['rows'], 'rowKey'));
        $privacy = reset($window['rows'][0][PagePayload::slots]);
        $terms = reset($window['rows'][1][PagePayload::slots]);
        self::assertSame('Privacy policy', $privacy['documentLabel']);
        self::assertSame('2026-10-05', $privacy['publishedOn']);
        self::assertSame('Terms of use', $terms['documentLabel']);
        self::assertSame('2026-10-01', $terms['publishedOn']);
        self::assertCount(2, $response->payload->windows[ChatTableContext::hilosLegalSettings]['rows']);
        self::assertNotContains(SignalTypeConstants::TABLE_WINDOW, array_column($frames, 'name'));
    }

    /** A catalog failure still arrives in the Documents page's ordinary response. */
    public function testDocumentsFirstResponseCarriesTheCatalogRefusal(): void
    {
        BrokenLegalAdminHilos::initBrowser(Hilos::$browser);
        try {
            $response = $this->response($this->subscribe(LegalDocumentsPage::class));
            self::assertSame('Broken admin catalog', $response->payload->data['legalCatalogRefusal']);
            self::assertArrayNotHasKey('legalOverview', $response->payload->data);
        } finally {
            Hilos::initBrowser();
        }
    }

    public function testAcceptanceVocabularyPrecedesTheResponseAndIncludesAnOffWindowRevision(): void
    {
        for ($index = 0; $index < 26; $index++) {
            $user = Hilos::$db->users->actions->createWithName('Legal acceptor ' . $index);
            $this->record((int) $user->id, '2026-09-27');
        }
        $this->record($this->userId, 'removed', '2026-01-01 00:00:00');
        $sort = TableSortOrderDTO::of(new TableSortDTO(HilosLegalAcceptanceTableRow::acceptedAt, TableConstants::ORDER_DESC));
        $frames = $this->subscribe(LegalAcceptancesPage::class, [], [
            ChatTableContext::hilosLegalAcceptances => new TableWindowDescriptorDTO(sort: $sort, limit: 25),
        ]);
        $filtersAt = array_search(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LEGAL_ACCEPTANCES, array_column($frames, 'name'), true);
        $responseAt = array_search(SignalTypeConstants::PAGE_RESPONSE, array_column($frames, 'name'), true);
        self::assertIsInt($filtersAt);
        self::assertIsInt($responseAt);
        self::assertLessThan($responseAt, $filtersAt);
        self::assertInstanceOf(HilosLegalAcceptanceFiltersSignalData::class, $frames[$filtersAt]['data']);
        self::assertSame(['2026-09-27', 'removed'], array_column($frames[$filtersAt]['data']->documents[0]['revisions'], 'revisionId'));
        self::assertFalse($frames[$filtersAt]['data']->documents[0]['revisions'][1]['declared']);

        $pageResponse = $frames[$responseAt]['data'];
        self::assertInstanceOf(PageResponseSignalData::class, $pageResponse);
        $firstWindow = $pageResponse->payload->windows[ChatTableContext::hilosLegalAcceptances] ?? null;
        self::assertIsArray($firstWindow);
        self::assertCount(25, $firstWindow['rows']);
        $firstRowSlot = reset($firstWindow['rows'][0][PagePayload::slots]);
        self::assertIsInt($firstRowSlot[HilosLegalAcceptanceTableRow::acceptedAt]);
        self::assertSame(1790510400000, $firstRowSlot[HilosLegalAcceptanceTableRow::acceptedAt]);
        self::assertSame(TimeHelper::sqlToMs('2026-09-27 12:00:00'), $firstRowSlot[HilosLegalAcceptanceTableRow::acceptedAt]);

        $firstRowKey = $firstWindow['rows'][0]['rowKey'];
        Database::sqlRun('UPDATE hilos_legal_acceptance SET revision_id = ? WHERE id = ?', ['live-updated', $firstRowKey]);
        Hilos::$browser?->record(SourceChange::dbUpdated(HilosDbContext::legalAcceptances, (string) $firstRowKey, ['revision_id' => 'live-updated']));
        Hilos::$browser?->flushToSignalRouter();
        $updateSignals = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData) {
                $updateSignals[] = ['name' => $signal->signalName->getName(), 'data' => $signal->data->data];
            }
        }
        $deltaAt = array_search(SignalTypeConstants::TABLE_VIEWPORT_DELTA, array_column($updateSignals, 'name'), true);
        self::assertIsInt($deltaAt);
        $delta = $updateSignals[$deltaAt]['data'];
        self::assertInstanceOf(TableViewportDeltaDTO::class, $delta);
        self::assertSame((int) $firstRowKey, $delta->rowKey);
        $deltaSlots = $delta->row[PagePayload::slots];
        $deltaSlot = reset($deltaSlots);
        self::assertSame('live-updated', $deltaSlot[HilosLegalAcceptanceTableRow::revisionId]);
        self::assertIsInt($deltaSlot[HilosLegalAcceptanceTableRow::acceptedAt]);
        self::assertSame(1790510400000, $deltaSlot[HilosLegalAcceptanceTableRow::acceptedAt]);
        self::assertSame(TimeHelper::sqlToMs('2026-09-27 12:00:00'), $deltaSlot[HilosLegalAcceptanceTableRow::acceptedAt]);

        $nextViewport = new TableViewportSubscription(tableKey: ChatTableContext::hilosLegalAcceptances, sort: $sort, limit: 25, pageIndex: 1);
        Hilos::$sr->setTableViewport(self::ACCEPT_KEY, $nextViewport);
        Hilos::$browser?->sendTableWindow(
            LegalAcceptancesPage::PAGE,
            self::ACCEPT_KEY,
            $nextViewport,
        );
        $nextWindowSignals = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData) {
                $nextWindowSignals[] = ['name' => $signal->signalName->getName(), 'data' => $signal->data->data];
            }
        }
        $windowAt = array_search(SignalTypeConstants::TABLE_WINDOW, array_column($nextWindowSignals, 'name'), true);
        self::assertIsInt($windowAt);
        $nextWindowData = $nextWindowSignals[$windowAt]['data'];
        self::assertInstanceOf(TableWindowSignalData::class, $nextWindowData);
        self::assertCount(2, $nextWindowData->rows);
        $row1Slots = $nextWindowData->rows[1][PagePayload::slots];
        $lastRowSlot = reset($row1Slots);
        self::assertSame('removed', $lastRowSlot[HilosLegalAcceptanceTableRow::revisionId]);
        self::assertIsInt($lastRowSlot[HilosLegalAcceptanceTableRow::acceptedAt]);
        self::assertSame(1767225600000, $lastRowSlot[HilosLegalAcceptanceTableRow::acceptedAt]);
        self::assertSame(TimeHelper::sqlToMs('2026-01-01 00:00:00'), $lastRowSlot[HilosLegalAcceptanceTableRow::acceptedAt]);

        $this->record($this->userId, 'live-created', '2025-12-15 10:00:00');
        $liveId = (int) Database::sql('SELECT LAST_INSERT_ID() AS id')->firstRow()['id'];
        $createdChange = SourceChange::dbCreated(HilosDbContext::legalAcceptances, (string) $liveId, [
            'user_id' => $this->userId,
            'document' => 'terms',
            'revision_id' => 'live-created',
            'accepted_at' => '2025-12-15 10:00:00',
        ]);
        Hilos::$browser?->record($createdChange);
        Hilos::$browser?->flushToSignalRouter();
        $createSignals = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData) {
                $createSignals[] = ['name' => $signal->signalName->getName(), 'data' => $signal->data->data];
            }
        }
        $appendAt = array_search(SignalTypeConstants::TABLE_VIEWPORT_APPEND, array_column($createSignals, 'name'), true);
        self::assertIsInt($appendAt);
        $append = $createSignals[$appendAt]['data'];
        self::assertInstanceOf(TableViewportAppendDTO::class, $append);
        $appendSlots = $append->row[PagePayload::slots];
        $appendSlot = reset($appendSlots);
        self::assertSame('live-created', $appendSlot[HilosLegalAcceptanceTableRow::revisionId]);
        self::assertIsInt($appendSlot[HilosLegalAcceptanceTableRow::acceptedAt]);
        self::assertSame(1765792800000, $appendSlot[HilosLegalAcceptanceTableRow::acceptedAt]);
        self::assertSame(TimeHelper::sqlToMs('2025-12-15 10:00:00'), $appendSlot[HilosLegalAcceptanceTableRow::acceptedAt]);

        $snapshot = Hilos::$table->hilosLegalAcceptances->getPage(new TableQueryDTO(limit: 25));
        self::assertSame(28, $snapshot->totalCount);
        self::assertCount(25, $snapshot->rows);
        self::assertSame(['live-updated', '2026-09-27'], array_values(array_unique(array_column($snapshot->rows, 'revisionId'))));
        self::assertSame(2, Hilos::$table->hilosLegalAcceptances->getPage(new TableQueryDTO(search: 'administrator', limit: 25))->totalCount);
        self::assertSame(28, (int) Database::sql('SELECT COUNT(*) AS total FROM hilos_legal_acceptance')->firstRow()['total']);
    }

    public function testAnUndeclaredRevisionWithRecordsStillOpens(): void
    {
        $this->record($this->userId, 'removed');
        $revision = $this->response($this->subscribe(LegalRevisionPage::class, ['documentKey' => 'terms', 'revisionId' => 'removed']));
        self::assertFalse($revision->payload->data['legalRevision']['declared']);
        self::assertNull($revision->payload->data['legalRevision']['clauses']);
    }

    public function testRevokedAdministratorReceivesNoLaterFilterVocabulary(): void
    {
        $this->subscribe(LegalAcceptancesPage::class);
        foreach ([true, false] as $admin) {
            Hilos::$db->users[$this->userId]->actions->setAdmin($admin);
            $this->record($this->userId, $admin ? 'before-revocation' : 'after-revocation');
            LegalAdminAudience::markStale();
            while (Hilos::$sr->getNextQueuedSignal() !== null) {
            }
            LegalAdminAudience::onAgentTick(new DemoHilosLegalAgent());
            $filters = 0;
            while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
                if ($signal->signalName->getName() === HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_LEGAL_ACCEPTANCES) {
                    $filters++;
                }
            }
            self::assertSame($admin ? 1 : 0, $filters);
        }
    }

    /**
     * The acceptances page answers with the administrator's own export of acceptance records, and only theirs (HIL-1234).
     */
    public function testTheAcceptancesPageAnswersWithTheAdministratorsOwnExport(): void
    {
        self::assertSame([LegalAcceptancesExportProjector::SECTION => null], $this->exportSection());

        $other = Hilos::$db->users->actions->createWithName('Another administrator');
        Database::sqlRun(
            'INSERT INTO hilos_legal_acceptance_export (user_id, state, document, revision_id, search, requested_at, finished_at,'
            . ' expires_at, stored_name, size_bytes, records) VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?), (?, ?, NULL, NULL, NULL, ?,'
            . ' NULL, NULL, NULL, NULL, NULL)',
            [
                $this->userId, LegalAcceptancesExportState::READY, 'terms', 'olena', '2026-09-27 12:00:00', '2026-09-27 12:00:05',
                '2026-09-28 12:00:05', 'a.csv', 2048, 12,
                (int) $other->id, LegalAcceptancesExportState::PREPARING, '2026-09-27 13:00:00',
            ],
        );

        self::assertSame([LegalAcceptancesExportProjector::SECTION => [
            'state' => LegalAcceptancesExportState::READY,
            'document' => 'terms',
            'revisionId' => null,
            'search' => 'olena',
            'requestedAt' => TimeHelper::sqlToMs('2026-09-27 12:00:00'),
            'finishedAt' => TimeHelper::sqlToMs('2026-09-27 12:00:05'),
            'expiresAt' => TimeHelper::sqlToMs('2026-09-28 12:00:05'),
            'sizeBytes' => 2048,
            'records' => 12,
        ]], $this->exportSection());
    }

    /** Cold tabs carry common data, identity and their route-scoped window in one response. */
    public function testDocumentTabsNeedNoClientWindowOrFollowupRequest(): void
    {
        $overview = $this->response($this->subscribe(LegalDocumentPage::class, ['documentKey' => 'terms']));
        foreach ([
            LegalDocumentSetPage::class => [ChatTableContext::hilosLegalDocumentSet, 'clause', 6],
            LegalDocumentDeviationsPage::class => [ChatTableContext::hilosLegalDocumentDeviations, 'deviation', 4],
            LegalDocumentRevisionsPage::class => [ChatTableContext::hilosLegalRevisions, 'revision', 3],
        ] as $page => [$table, $slot, $count]) {
            $frames = $this->subscribe($page, ['documentKey' => 'terms']);
            $response = $this->response($frames);
            self::assertSame($page::PAGE, $response->pageKey);
            self::assertSame($overview->payload->data['legalDocument'], $response->payload->data['legalDocument']);
            self::assertSame(['hilos', 'hilos_legal', 'hilos_legal_documents', 'hilos_legal_document'],
                array_column($response->payload->data['pageBreadcrumb'], 'page'));
            self::assertSame([$table], array_keys($response->payload->windows));
            self::assertSame(['document' => 'terms'], Hilos::$sr->getTableViewport(self::ACCEPT_KEY, $table)->filter);
            $window = $response->payload->windows[$table];
            self::assertCount($count, $window['rows']);
            foreach ($window['rows'] as $row) {
                self::assertSame([$slot], array_keys($row['slots']));
                self::assertSame($row['rowKey'], $row['slots'][$slot]['rowKey']);
            }
            self::assertNotContains(SignalTypeConstants::TABLE_WINDOW, array_column($frames, 'name'));
        }
    }

    /** A committed acceptance invalidates the existing Overview, with its guarded whole-page path. */
    public function testDocumentOverviewCountersRefreshAfterRealAcceptanceChangesWithoutReload(): void
    {
        $this->record($this->userId, '2026-09-17');
        $initial = $this->response($this->subscribe(LegalDocumentPage::class, ['documentKey' => 'terms']));
        self::assertSame(1, $initial->payload->data['legalDocument']['lapsed']);
        $agent = new DemoHilosLegalAgent();
        $router = new PageSignalRouter(new HilosPageFactory($agent, Hilos::class), new ActionRouteConfig());
        $resender = $this->createMock(PageResender::class);
        $resender->method('resendPage')->willReturnCallback($router->resendPage(...));
        Hilos::$browser->bindPageResender($resender);
        $this->record($this->userId, '2026-10-01');
        $subscriber = new LegalAcceptanceChangeSubscriber();
        foreach ([SourceChangeProvenance::LocalWrite, SourceChangeProvenance::AppliedRemote] as $provenance) {
            $subscriber->onSourceChange(SourceChange::dbCleared(HilosDbContext::legalAcceptances), $provenance);
        }
        LegalAdminAudience::onAgentTick($agent);
        LegalAdminAudience::onAgentTick($agent);
        Hilos::$browser->flushToSignalRouter();
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData) {
                $frames[] = ['name' => $signal->signalName->getName(), 'data' => $signal->data->data];
            }
        }
        $response = $this->response($frames);
        $document = $response->payload->data['legalDocument'];
        self::assertSame([1, 0, 0], [$document['covered'], $document['window'], $document['lapsed']]);
        self::assertSame(['hilosLegalSettings'], array_keys($response->payload->windows));
        self::assertNotContains(SignalTypeConstants::TABLE_WINDOW, array_column($frames, 'name'));
    }

    public function testAllDocumentTabsRefuseUnknownKeysAndRetainRecordedOnlyKeys(): void
    {
        Database::sqlRun(
            'INSERT INTO hilos_legal_acceptance (user_id, document, revision_id, accepted_at) VALUES (?, ?, ?, ?)',
            [$this->userId, 'retired', 'old', '2026-09-27 12:00:00'],
        );
        foreach ([LegalDocumentPage::class, LegalDocumentSetPage::class,
            LegalDocumentDeviationsPage::class, LegalDocumentRevisionsPage::class] as $page) {
            $data = $this->response($this->subscribe($page, ['documentKey' => 'retired']))->payload->data['legalDocument'];
            self::assertSame('retired', $data['document']);
            self::assertFalse($data['declared']);
            self::assertNull($data['revision']);
            self::assertNull($data['set']);
            try {
                $this->subscribe($page, ['documentKey' => 'missing']);
                self::fail('Unknown document must be refused on every tab');
            } catch (PageResourceNotFoundException $failure) {
                self::assertSame('No such legal document', $failure->getMessage());
            }
        }
    }

    public function testUnknownDocumentRefusesTheSubscription(): void
    {
        $this->expectException(PageResourceNotFoundException::class);
        $this->expectExceptionMessage('No such legal document');
        $this->subscribe(LegalDocumentPage::class, ['documentKey' => 'missing']);
    }

    public function testUnknownRevisionRefusesTheSubscription(): void
    {
        $this->expectException(PageResourceNotFoundException::class);
        $this->expectExceptionMessage('No such legal revision');
        $this->subscribe(LegalRevisionPage::class, ['documentKey' => 'terms', 'revisionId' => 'missing']);
    }

    public function testCatalogRefusalReachesEachDeclarationPageWhileRecordsRemainReadable(): void
    {
        $this->record($this->userId, 'removed');
        BrokenLegalAdminHilos::initBrowser(Hilos::$browser);
        foreach ([LegalPage::class, LegalDocumentsPage::class, LegalDocumentPage::class,
            LegalDocumentSetPage::class, LegalDocumentDeviationsPage::class, LegalDocumentRevisionsPage::class,
            LegalRevisionPage::class] as $page) {
            $payload = $this->response($this->subscribe($page, ['documentKey' => 'terms', 'revisionId' => 'removed']))->payload;
            self::assertSame('Broken admin catalog', $payload->data['legalCatalogRefusal']);
            foreach ($payload->windows as $table => $window) {
                if ($table !== ChatTableContext::hilosLegalSettings) {
                    self::assertSame([], $window['rows']);
                }
            }
            if ($page === LegalPage::class) {
                self::assertArrayHasKey('legalOverview', $payload->data);
                self::assertNull($payload->data['legalOverview']);
            }
        }
        self::assertNull(Hilos::$table->hilosLegalAcceptances->getPage(new TableQueryDTO(limit: 25))->rows[0]->declared);

        $guestKey = 'legal-admin-guest';
        Hilos::$rt->connections->actions->register($guestKey, null);
        foreach ([LegalPage::class, LegalDocumentsPage::class, LegalDocumentPage::class,
            LegalDocumentSetPage::class, LegalDocumentDeviationsPage::class, LegalDocumentRevisionsPage::class,
            LegalRevisionPage::class] as $page) {
            $payload = new ReflectionMethod($page, 'buildPagePayload')->invoke(
                new $page(new DemoHilosLegalAgent()), $guestKey,
                new PageRouteParams(['documentKey' => 'terms', 'revisionId' => 'removed']),
            );
            self::assertInstanceOf(PagePayload::class, $payload);
            self::assertSame(SignalConstants::ACTION_FAILED_REASON, $payload->data['legalCatalogRefusal']);
        }
    }

    /**
     * @return array<string, mixed> The export section of the acceptances page's answer, the key alone kept
     */
    private function exportSection(): array
    {
        $responses = array_values(array_filter(
            $this->subscribe(LegalAcceptancesPage::class),
            static fn (array $frame): bool => $frame['name'] === SignalTypeConstants::PAGE_RESPONSE,
        ));
        self::assertCount(1, $responses);
        self::assertInstanceOf(PageResponseSignalData::class, $responses[0]['data']);

        return array_intersect_key($responses[0]['data']->payload->data, [LegalAcceptancesExportProjector::SECTION => true]);
    }

    /**
     * @param int $userId Person giving the acceptance
     * @param string $revisionId Exact recorded revision
     * @param string $acceptedAt SQL acceptance timestamp
     */
    private function record(int $userId, string $revisionId, string $acceptedAt = '2026-09-27 12:00:00'): void
    {
        Database::sqlRun(
            'INSERT INTO hilos_legal_acceptance (user_id, document, revision_id, accepted_at) VALUES (?, ?, ?, ?)',
            [$userId, 'terms', $revisionId, $acceptedAt],
        );
    }

    /**
     * @param class-string<AbstractPage> $pageClass Page being opened
     * @param array<string, string> $params Route parameters
     * @param array<string, TableWindowDescriptorDTO> $tableWindows Windows the tab holds, by table key
     * @return list<array{name: string, data: object}> Queued browser frames in delivery order
     */
    private function subscribe(string $pageClass, array $params = [], array $tableWindows = []): array
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $previous = Hilos::$sr->pageSubscription(self::ACCEPT_KEY);
        if ($previous !== null && ($previous->page !== $pageClass::PAGE || $previous->params !== $params)) {
            Hilos::$sr->unsubscribeFromAll(self::ACCEPT_KEY);
            LegalAdminAudience::removeSubscriber(self::ACCEPT_KEY);
        }
        $subscribeSignal = new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, $pageClass::PAGE, $params, $tableWindows);
        Hilos::$sr->subscribeToPage($pageClass::PAGE, $subscribeSignal);
        if ($tableWindows !== []) {
            Hilos::$sr->reportTableWindows(self::ACCEPT_KEY, $tableWindows);
        }
        ExecutionContext::run(new ExecutionFrame(acceptKey: self::ACCEPT_KEY), static function () use ($pageClass, $params): void {
            new $pageClass(new DemoHilosLegalAgent())->onSubscribe(self::ACCEPT_KEY, new PageRouteParams($params));
        });
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData) {
                $frames[] = ['name' => $signal->signalName->getName(), 'data' => $signal->data->data];
            }
        }
        return $frames;
    }

    /**
     * @param list<array{name: string, data: object}> $frames Frames from one subscription
     * @return PageResponseSignalData The declaration page's one answer
     */
    private function response(array $frames): PageResponseSignalData
    {
        $responses = array_values(array_filter($frames, static fn (array $frame): bool =>
            $frame['name'] === SignalTypeConstants::PAGE_RESPONSE
            && array_key_exists('legalCatalogRefusal', $frame['data']->payload->data)));
        self::assertCount(1, $responses);
        self::assertInstanceOf(PageResponseSignalData::class, $responses[0]['data']);
        return $responses[0]['data'];
    }
}

/** Refusing catalog bound only for one integration case. */
final class BrokenLegalAdminCatalog implements LegalCatalogProviderInterface
{
    /**
     * @return array Never returns
     * @throws UnknownRevisionException Always refuses the fixture catalog
     */
    public static function revisions(): array
    {
        throw new UnknownRevisionException('Broken admin catalog');
    }
}

/** Keeps the refusal isolated from the demo's normal catalog. */
abstract class BrokenLegalAdminHilos extends FrameworkHilos
{
    public const array PAGES = Hilos::PAGES;
    public const array TABLES = Hilos::TABLES;
    public const array PAGE_TABLES = Hilos::PAGE_TABLES;

    protected const ?string LEGAL_CATALOG = BrokenLegalAdminCatalog::class;
}
