<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\StockLibraryAgent;
use Demo\EcommerceShop\Browser\Data\AdminProductBrowserData;
use Demo\EcommerceShop\Agents\ProductsLibraryAgent;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Agent\EcommerceShopAgentManager;
use Demo\EcommerceShop\Core\Router\EcommerceShopSignalRouter;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Core\Router\DTO\StockSetUnitsSignalData;
use Demo\EcommerceShop\Database\Actions\Collection\ProductWarehouseStocksActions;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\Entity\Item\ProductWarehouseStock;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock as ObjectProductWarehouseStock;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\AdminProductPage;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Demo\EcommerceShop\Pages\AdminProductStockPage;
use Demo\EcommerceShop\Pages\DTO\AdminProductPageSubscribeParams;
use Demo\EcommerceShop\Pages\DTO\ProductStockSetUnitsActionDTO;
use Demo\EcommerceShop\Tables\ProductStock\ProductStockTable;
use Demo\EcommerceShop\Tables\ProductStock\ProductStockTableRow;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\ActionViewModeException;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\SignalRouteConfig;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Exception\Transaction\AnnouncementFailedException;
use Hilos\Database\ChangeLog\JournalTriggerRenderer;
use Hilos\Fs\FsPath;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Closure;
use RuntimeException;

/**
 * Tracked Stock edits go through a real worker dispatch and commit on the migrated shop stand.
 */
final class AdminProductStockActionTest extends IntegrationTestCase
{
    use CatalogFixtureRows;

    private const string ACTION = EcommerceShopSignalConstants::PRODUCT_STOCK_SET_UNITS;
    private const string ADMIN_KEY = 'stock-edit-admin';
    private const string VISITOR_KEY = 'stock-edit-viewer';
    private const string RUNTIME_OWNER = 'stock-edit-test';

    /** @var list<string> Stock triggers installed by this case */
    private array $installedTriggers = [];
    /** @var array<string, int> Fixture user ids keyed by connection */
    private array $users = [];
    /** @var array<string, string> Record keys of the fixture's published stock journal entries */
    private array $stockKeys = [];
    private ?SignalRouter $previousRouter = null;
    private ?BrowserContext $previousBrowser = null;
    private bool $viewModeWasEnabled = false;
    private bool $runtimeClaimed = false;
    private PageSignalRouter $pages;
    private StockLibraryAgent $stockLibrary;
    private ProductsLibraryAgent $library;
    private StockActionTestWorker $worker;
    private int $categoryId = 0;
    private int $productId = 0;
    private int $otherProductId = 0;
    private int $warehouseId = 0;

    /** Commits fixture rows before exercising the writer's own transaction. */
    protected function setUp(): void
    {
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        parent::setUp();
        $this->installStockTriggers();
        Database::transaction(DatabaseNames::PRIMARY, function (): void {
            $this->categoryId = self::category();
            $this->warehouseId = self::warehouse();
            $this->productId = self::product(ProductKind::STOCK, $this->categoryId);
            $this->otherProductId = self::product(ProductKind::STOCK, $this->categoryId);
            foreach ([self::ADMIN_KEY => true, self::VISITOR_KEY => false] as $key => $admin) {
                Database::sql('INSERT INTO hilos_user (name, admin, block) VALUES (?, ?, 0)', [$key, (int)$admin]);
                $this->users[$key] = Database::lastInsertId();
            }
        });
        Hilos::$db->forgetLoadedDataForTest();
        $this->stockLibrary = new StockLibraryAgent();
        $this->library = new ProductsLibraryAgent();
        $this->worker = new StockActionTestWorker($this->stockLibrary, $this->library);
        Hilos::initBrowser();
        $this->underAgent($this->stockLibrary, fn () => $this->startAgent($this->stockLibrary));

        $this->viewModeWasEnabled = Hilos::$rt->hilosAdminViewModeRuntime?->enabled ?? false;
        RtTruthSourceRegistry::register(EcommerceShopRtContext::connections, TruthSourceKeys::all(), self::RUNTIME_OWNER);
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);
        $this->runtimeClaimed = true;
        ExecutionContext::run(new ExecutionFrame(), function (): void {
            Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(true);
            foreach ($this->users as $key => $id) {
                Hilos::$rt->connections->actions->register($key, $id);
            }
        });
        $this->pages = StockActionTestWorker::pageRouter($this->library);
        while (self::nextFrame() !== null) {
            // The fixtures' runtime registration frames precede the browser requests.
        }
    }

    /** Restores only this case's committed fixtures and process state. */
    protected function tearDown(): void
    {
        try {
            $this->removeFixtureRows();
        } finally {
            try {
                if ($this->runtimeClaimed) {
                    ExecutionContext::run(new ExecutionFrame(), function (): void {
                        foreach ($this->users as $key => $id) {
                            if (isset(Hilos::$rt->connections[$key])) {
                                Hilos::$rt->connections[$key]->actions->unregister();
                            }
                        }
                        Hilos::$rt->hilosAdminViewModeRuntime?->actions->set($this->viewModeWasEnabled);
                    });
                    RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
                    RtTruthSourceRegistry::unregisterAgent(self::RUNTIME_OWNER);
                }
                if (isset($this->stockLibrary)) {
                    TruthSourceRegistry::unregisterAgent($this->stockLibrary->getId());
                    SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->stockLibrary->getId()));
                    Hilos::$db->forgetLoadedDataForTest();
                }
            } finally {
                Hilos::$sr = $this->previousRouter;
                Hilos::$browser = $this->previousBrowser;
                parent::tearDown();
            }
        }
    }

    /** Uses the stock triggers shipped with the schema when the stand has no daemon to install them. */
    private function installStockTriggers(): void
    {
        $live = Database::sql(
            'SELECT TRIGGER_NAME FROM INFORMATION_SCHEMA.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?',
            [ProductWarehouseStock::_table],
        )->rows();
        $present = array_column($live, 'TRIGGER_NAME');
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        foreach (['insert', 'update', 'delete'] as $event) {
            $name = 'hilos_cl_' . ProductWarehouseStock::_table . '_after_' . $event;
            if (in_array($name, $present, true)) {
                continue;
            }

            Database::sql(str_replace(
                JournalTriggerRenderer::DATABASE_TOKEN,
                $journal,
                FsPath::read(dirname(__DIR__, 2) . '/backend/Database/Migration/Triggers/' . $name . '.sql'),
            ));
            $this->installedTriggers[] = $name;
        }
    }

    /** Removes this case's stock, catalog, people and their stock journal entries. */
    private function removeFixtureRows(): void
    {
        foreach ($this->installedTriggers as $name) {
            Database::sql('DROP TRIGGER `' . $name . '`');
        }
        if ($this->categoryId === 0) {
            return;
        }
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $productIds = [$this->productId, $this->otherProductId];
        foreach (Database::sql('SELECT id FROM product_warehouse_stock WHERE product_id IN (?, ?)', $productIds)->rows() as $row) {
            $this->rememberStockKey($row['id']);
        }
        Database::sql('DELETE FROM product_warehouse_stock WHERE product_id IN (?, ?)', $productIds);
        foreach ($this->stockKeys as $recordKey) {
            Database::sql(
                "DELETE entry, field_change, field_value FROM {$journal}.hilos_change_log AS entry
                 JOIN {$journal}.hilos_change_log_table AS catalog ON catalog.id = entry.table_id
                 LEFT JOIN {$journal}.hilos_change_log_change AS field_change ON field_change.log_id = entry.id
                 LEFT JOIN {$journal}.hilos_change_log_value AS field_value ON field_value.change_id = field_change.id
                 WHERE catalog.name = ? AND entry.record_key = ?",
                [ProductWarehouseStock::_table, $recordKey],
            );
        }
        $files = Database::sql('SELECT picture_file_id FROM product WHERE id IN (?, ?)
            UNION SELECT picture_file_id FROM category WHERE id = ?', [...$productIds, $this->categoryId])->rows();
        Database::sql('DELETE FROM product WHERE id IN (?, ?)', $productIds);
        Database::sql('DELETE FROM category WHERE id = ?', [$this->categoryId]);
        Database::sql('DELETE FROM warehouse WHERE id = ?', [$this->warehouseId]);
        foreach ($files as $file) {
            Database::sql('DELETE FROM hilos_file WHERE id = ?', [$file['picture_file_id']]);
        }
        foreach ($this->users as $id) {
            Database::sql("DELETE FROM {$journal}.hilos_change_log_receipt WHERE actor_user_id = ?", [$id]);
            Database::sql('DELETE FROM hilos_user WHERE id = ?', [$id]);
        }
    }

    /**
     * @param string $id Stock row's primary key
     */
    private function rememberStockKey(string $id): void
    {
        $this->stockKeys[$id] = json_encode([(int)$id], JSON_THROW_ON_ERROR);
    }

    /** Create, repeat, increase and remove stock through the admin connection, keeping its live views current. */
    public function testTrackedEditsWriteOnceAndReachStockAndOverview(): void
    {
        $stock = $this->subscribe(AdminProductStockPage::PAGE, self::ADMIN_KEY);
        $overview = $this->subscribe(AdminProductPage::PAGE, self::VISITOR_KEY);
        $rows = $stock->windows[ProductStockTable::TABLE][TableWindowSignalData::rows];
        self::assertSame(0, $rows[0][PagePayload::slots][ProductStockTable::ROW_SLOT][ProductStockTableRow::units]);
        self::assertSame(self::stockTotals(0), $overview->data[AdminProductBrowserData::DATA][AdminProductBrowserData::FIELD_STOCK]);

        // The browser in the serving worker observes the committed stock fact.
        $reaction = new class ($this->heldUnits(...)) implements SourceChangeSubscriberInterface {
            /** @var list<SourceChange> Stock facts released by the current action */
            public array $changes = [];

            /** @var list<list<int>> Held units at the moment each fact was released */
            public array $visibleUnits = [];

            /** @param ?Closure(): list<int> $readUnits Reads the owner's held collection while the case is active */
            public function __construct(public ?Closure $readUnits)
            {
            }

            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                if ($this->readUnits !== null && $change->sourceKey === EcommerceShopDbContext::productWarehouseStocks) {
                    $this->changes[] = $change;
                    $this->visibleUnits[] = ($this->readUnits)();
                }
            }
        };
        SourceChangeBus::subscribe($reaction);

        try {
            $before = self::journalCount();
            $mutations = 0;
            foreach ([
                ['create', '  00012  ', 0, 12, true],
                ['repeat', '12', 0, 12, false],
                ['increase', (string)ProductWarehouseStocksActions::UNITS_MAX, 12, ProductWarehouseStocksActions::UNITS_MAX, true],
                ['remove', '0', ProductWarehouseStocksActions::UNITS_MAX, 0, true],
                ['repeat-remove', '0', ProductWarehouseStocksActions::UNITS_MAX, 0, false],
            ] as [$requestId, $input, $expectedUnits, $savedUnits, $changed]) {
                $reaction->changes = [];
                $reaction->visibleUnits = [];
                $reply = $this->runEdit($input, $expectedUnits, $requestId);
                self::assertInstanceOf(PageActionSuccessSignalData::class, $reply);
                self::assertNull($reply->reply);
                self::assertNull($reply->message);
                self::assertSame($savedUnits === 0 ? [] : [$savedUnits], $this->storedUnits());
                $mutations += (int)$changed;
                self::assertSame($before + $mutations, self::journalCount(), $requestId);
                self::assertCount((int)$changed, $reaction->changes, $requestId);
                if ($changed) {
                    self::assertSame([$savedUnits === 0 ? [] : [$savedUnits]], $reaction->visibleUnits);
                    $fact = $reaction->changes[0];
                    self::assertSame(self::ADMIN_KEY, $fact->origin);
                    self::assertSame($requestId, $fact->originRequestId);
                    $this->assertLiveUnits($fact, $savedUnits);
                }
            }
        } finally {
            $reaction->readUnits = null;
            $reaction->changes = [];
            $reaction->visibleUnits = [];
        }
    }

    /** The common admin gate and the current Stock address both precede handover. */
    public function testViewerAndLateEditAreRefusedBeforeTheWriter(): void
    {
        $this->subscribe(AdminProductStockPage::PAGE, self::VISITOR_KEY);
        $this->subscribe(AdminProductStockPage::PAGE, self::ADMIN_KEY);
        $before = self::journalCount();

        $this->submit('7', 0, 'viewer-edit', self::VISITOR_KEY);
        $viewer = self::actionReply('viewer-edit', self::VISITOR_KEY);
        self::assertInstanceOf(PageActionErrorSignalData::class, $viewer);
        self::assertSame(ActionViewModeException::ERROR_CODE, $viewer->errorCode);
        self::assertNull($viewer->errorDetail);
        self::assertNull(self::nextFrame(), 'The viewer never reaches the stock writer');

        $update = new WebSocketPageUpdateSubscriptionSignalDTO(
            self::ADMIN_KEY,
            AdminProductStockPage::PAGE,
            [AdminProductPageSubscribeParams::PRODUCT_ID => (string)$this->otherProductId],
        );
        $this->underAgent($this->library, fn () => $this->pages->dispatchPageUpdateSubscription($update, 'ws', AdminProductStockPage::PAGE));
        $page = self::browserFrame(self::ADMIN_KEY);
        self::assertInstanceOf(PageResponseSignalData::class, $page);
        self::assertSame($this->otherProductId, $page->payload->data[AdminProductBrowserData::DATA][AdminProductBrowserData::productId]);

        $this->submit('7', 0, 'late-edit');
        $late = self::actionReply('late-edit');
        self::assertInstanceOf(PageActionErrorSignalData::class, $late);
        self::assertSame('Open Stock for this product before setting its units.', $late->reason);
        self::assertNull(self::nextFrame(), 'An edit for the previous product never reaches the stock writer');
        self::assertSame([], $this->storedUnits());
        self::assertSame($before, self::journalCount());
    }

    /** Invalid input and stale baselines return their reason and leave storage and the journal untouched. */
    public function testWriterRefusalsKeepTheCurrentUnitsAndJournal(): void
    {
        $this->subscribe(AdminProductStockPage::PAGE, self::ADMIN_KEY);
        $before = self::journalCount();
        $valid = ['productId' => $this->productId, 'warehouseId' => $this->warehouseId, 'units' => '8', 'expectedUnits' => 0];
        foreach ([
            array_diff_key($valid, ['expectedUnits' => true]),
            array_replace($valid, ['productId' => 0]),
            array_replace($valid, ['warehouseId' => -1]),
            array_replace($valid, ['units' => 8]),
            array_replace($valid, ['expectedUnits' => -1]),
            array_replace($valid, ['expectedUnits' => ProductWarehouseStocksActions::UNITS_MAX + 1]),
        ] as $payload) {
            $this->dispatchAction(self::ACTION, $payload, 'malformed-edit');
            self::assertInstanceOf(PageActionErrorSignalData::class, self::actionReply('malformed-edit'));
            self::assertNull(self::nextFrame(), 'A malformed edit never reaches the stock writer');
        }
        $absent = $this->runEdit('8', 2, 'stale-against-empty');
        self::assertInstanceOf(PageActionErrorSignalData::class, $absent);
        self::assertSame('Stock changed elsewhere. Review the current units and try again.', $absent->reason);
        self::assertSame([], $this->storedUnits());
        self::assertSame($before, self::journalCount());

        self::assertInstanceOf(PageActionSuccessSignalData::class, $this->runEdit('12', 0, 'initial-units'));
        $before = self::journalCount();
        foreach ([
            ['decimal', '12.5', 12, 'Enter a whole number of units.'],
            ['exponent', '1e2', 12, 'Enter a whole number of units.'],
            ['plus', '+12', 12, 'Enter a whole number of units.'],
            ['empty', '   ', 12, 'Enter a whole number of units.'],
            ['negative', '-0001', 12, 'Units cannot be negative'],
            ['overflow', str_repeat('9', 80), 12, 'Units cannot be more than 999,999'],
            ['stale', '0', 0, 'Stock changed elsewhere. Review the current units and try again.'],
        ] as [$requestId, $input, $expectedUnits, $reason]) {
            $reply = $this->runEdit($input, $expectedUnits, $requestId);
            self::assertInstanceOf(PageActionErrorSignalData::class, $reply);
            self::assertSame($reason, $reply->reason);
            self::assertNull($reply->errorType);
            self::assertNull($reply->errorDetail);
            self::assertSame([12], $this->storedUnits());
            self::assertSame($before, self::journalCount());
        }
    }

    /** A write that fails in the local membership mirror rolls back storage, the journal and the held set. */
    public function testStockMembershipAndJournalRollBackWhenTheMirrorFails(): void
    {
        $this->subscribe(AdminProductStockPage::PAGE, self::ADMIN_KEY);
        $mirror = new class implements SourceMirrorSubscriberInterface {
            public ?TableMutationType $failOn = null;

            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                if ($change->sourceKey === EcommerceShopDbContext::productWarehouseStocks && $change->mutationType === $this->failOn) {
                    $this->failOn = null;
                    throw new RuntimeException('Stock membership mirror failed');
                }
            }
        };
        SourceChangeBus::subscribe($mirror);
        try {
            $before = self::journalCount();
            $mirror->failOn = TableMutationType::Create;
            self::assertInstanceOf(PageActionErrorSignalData::class, $this->runEdit('12', 0, 'failed-insert'));
            self::assertSame([], $this->storedUnits());
            self::assertSame([], $this->heldUnits());
            self::assertSame($before, self::journalCount());

            self::assertInstanceOf(PageActionSuccessSignalData::class, $this->runEdit('12', 0, 'created'));
            self::assertSame([12], $this->heldUnits());
            $mirror->failOn = TableMutationType::Delete;
            self::assertInstanceOf(PageActionErrorSignalData::class, $this->runEdit('0', 12, 'failed-delete'));
            self::assertSame([12], $this->storedUnits());
            self::assertSame([12], $this->heldUnits());
            self::assertSame($before + 1, self::journalCount());
            self::assertInstanceOf(PageActionSuccessSignalData::class, $this->runEdit('0', 12, 'removed'));
            self::assertSame([], $this->heldUnits());
        } finally {
            $mirror->failOn = null;
        }
    }

    /** A committed write still gets its success reply when release of an announcement fails. */
    public function testCommitAnnouncementFailureKeepsTheStockSuccess(): void
    {
        $this->subscribe(AdminProductStockPage::PAGE, self::ADMIN_KEY);
        $reaction = new class implements SourceChangeSubscriberInterface {
            public bool $armed = true;

            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                if ($this->armed && $change->sourceKey === EcommerceShopDbContext::productWarehouseStocks) {
                    throw new RuntimeException('Stock reaction failed after commit');
                }
            }
        };
        SourceChangeBus::subscribe($reaction);
        $before = self::journalCount();
        try {
            $reply = $this->runEdit('12', 0, 'announcement-failed');
        } finally {
            $reaction->armed = false;
            $failures = Database::handlerEnd();
            Database::handlerStart();
        }
        self::assertInstanceOf(PageActionSuccessSignalData::class, $reply);
        self::assertSame([12], $this->storedUnits());
        self::assertSame([12], $this->heldUnits());
        self::assertSame($before + 1, self::journalCount());
        self::assertCount(1, $failures);
        self::assertInstanceOf(AnnouncementFailedException::class, $failures[0]);
    }

    /**
     * @param string $page Product page to open
     * @param string $acceptKey Subscribing connection
     * @return PagePayload Page data and initial windows
     */
    private function subscribe(string $page, string $acceptKey): PagePayload
    {
        $request = new WebSocketPageSubscribeSignalDTO(
            $acceptKey,
            $page,
            [AdminProductPageSubscribeParams::PRODUCT_ID => (string)$this->productId],
        );
        $this->underAgent($this->library, fn () => $this->pages->dispatchPageSubscribe($request, 'ws', $page));
        Hilos::$sr->subscribeToPage($page, $request);
        $reply = self::browserFrame($acceptKey);
        self::assertInstanceOf(PageResponseSignalData::class, $reply);
        self::assertSame($page, $reply->pageKey);

        return $reply->payload;
    }

    /**
     * @param string $action Page action name
     * @param array<string, mixed> $payload Action body from the browser
     * @param string $requestId Request correlation
     * @param string $acceptKey Calling browser connection
     */
    private function dispatchAction(string $action, array $payload, string $requestId, string $acceptKey = self::ADMIN_KEY): void
    {
        ExecutionContext::run(
            new ExecutionFrame(agentId: $this->library->getId(), acceptKey: $acceptKey),
            fn () => $this->pages->dispatchAction(new WebSocketActionSignalDTO($acceptKey, $action, $payload, $requestId), SignalSource::WEBSOCKET),
        );
    }

    /**
     * @param string $units Submitted field text
     * @param int $expectedUnits Units when editing began
     * @param string $requestId Action correlation
     * @param string $acceptKey Calling connection
     */
    private function submit(string $units, int $expectedUnits, string $requestId, string $acceptKey = self::ADMIN_KEY): void
    {
        $this->dispatchAction(self::ACTION, (new ProductStockSetUnitsActionDTO(
            $this->productId,
            $this->warehouseId,
            $units,
            $expectedUnits,
        ))->toArray(), $requestId, $acceptKey);
    }

    /**
     * Exercises both routes after crossing their agent-signal envelope boundary.
     *
     * @param string $units Submitted field text
     * @param int $expectedUnits Units when editing began
     * @param string $requestId Action correlation
     * @return PageActionErrorSignalData|PageActionSuccessSignalData The tracked reply from the page
     */
    private function runEdit(string $units, int $expectedUnits, string $requestId): PageActionErrorSignalData|PageActionSuccessSignalData
    {
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());
        $expectedReceipts = $this->stockActionReceipts($journal);

        $this->submit($units, $expectedUnits, $requestId);
        $askName = EcommerceShopSignalConstants::STOCK_SET_UNITS;
        $frame = self::nextFrame();
        self::assertNotNull($frame);
        self::assertSame($askName, $frame->signalName->getName());
        self::assertInstanceOf(AgentSignalData::class, $frame->data);
        self::assertNull(self::nextFrame(), 'The page defers its ack until the library responds');

        $ask = $frame->data->data;
        self::assertInstanceOf(StockSetUnitsSignalData::class, $ask);
        self::assertSame([
            'productId' => $this->productId,
            'warehouseId' => $this->warehouseId,
            'units' => $units,
            'expectedUnits' => $expectedUnits,
            'replySignal' => EcommerceShopSignalConstants::ADMIN_PRODUCT_STOCK_SET_UNITS_DONE,
            'acceptKey' => self::ADMIN_KEY,
            'requestId' => $requestId,
            'action' => self::ACTION,
            'successMessage' => null,
        ], $ask->toArray());
        $receiptId = $frame->data->receiptId;
        self::assertNotNull($receiptId);
        $this->worker->deliver($this->stockLibrary, $frame);

        $answer = null;
        $stockChange = null;
        while (($outbound = self::nextFrame()) !== null) {
            $data = $outbound->data;
            if ($data instanceof AgentSignalData) {
                self::assertNull($answer, 'The writer sends one answer');
                self::assertSame(EcommerceShopSignalConstants::ADMIN_PRODUCT_STOCK_SET_UNITS_DONE, $outbound->signalName->getName());
                self::assertInstanceOf(HandoverAnswerSignalData::class, $data->data);
                self::assertSame($receiptId, $data->receiptId);
                $answer = $outbound;
            } elseif (($data instanceof DbSyncCreatedSignalData || $data instanceof DbSyncUpdatedSignalData
                || $data instanceof DbSyncDeletedSignalData) && $data->collectionKey === EcommerceShopDbContext::productWarehouseStocks
            ) {
                self::assertNull($answer, 'The committed stock fact precedes the writer reply');
                $this->rememberStockKey($data->idString);
                self::assertSame(self::ADMIN_KEY, $data->origin);
                self::assertSame($requestId, $data->originRequestId);
                self::assertSame($receiptId, $data->receiptId);
                self::assertNull($stockChange, 'One Stock edit publishes at most one change');
                $stockChange = $data;
            } else {
                self::assertNotInstanceOf(WebSocketSignalData::class, $data, 'Only the page sends the browser reply');
            }
        }
        self::assertNotNull($answer);
        self::assertFalse(Database::hasOpenTransactions());
        $this->worker->deliver($this->library, $answer);

        $reply = self::actionReply($requestId);
        self::assertNull(self::nextFrame(), 'The page acknowledges the tracked edit once');

        $expectedEntries = [];
        if ($stockChange !== null) {
            $expectedEntries[] = [
                'receipt_id' => (string)$receiptId,
                'table_name' => ProductWarehouseStock::_table,
                'record_key' => $this->stockKeys[$stockChange->idString],
                'mutation_type' => match (true) {
                    $stockChange instanceof DbSyncCreatedSignalData => 'create',
                    $stockChange instanceof DbSyncUpdatedSignalData => 'update',
                    $stockChange instanceof DbSyncDeletedSignalData => 'delete',
                },
            ];
            $expectedReceipts[] = [
                'id' => (string)$receiptId,
                'actor_user_id' => (string)$this->users[self::ADMIN_KEY],
                'channel' => 'web',
                'action' => self::ACTION,
                'open_handovers' => '0',
            ];
        }
        self::assertSame(
            $expectedEntries,
            Database::sql(
                "SELECT entry.receipt_id, catalog.name AS table_name, entry.record_key, entry.mutation_type
                 FROM {$journal}.hilos_change_log AS entry
                 LEFT JOIN {$journal}.hilos_change_log_table AS catalog ON catalog.id = entry.table_id
                 WHERE entry.receipt_id = ?",
                [$receiptId],
            )->rows(),
            $requestId,
        );
        self::assertSame($expectedReceipts, $this->stockActionReceipts($journal, $receiptId), $requestId);

        return $reply;
    }

    /**
     * @param string $journal Quoted journal database identifier
     * @param ?int $receiptId Original handover receipt to check even if its attribution differs
     * @return list<array{id: string, actor_user_id: ?string, channel: string, action: string, open_handovers: string}>
     */
    private function stockActionReceipts(string $journal, ?int $receiptId = null): array
    {
        return Database::sql(
            "SELECT id, actor_user_id, channel, action, open_handovers FROM {$journal}.hilos_change_log_receipt
             WHERE id = ? OR (actor_user_id = ? AND action = ?) ORDER BY id",
            [$receiptId, $this->users[self::ADMIN_KEY], self::ACTION],
        )->rows();
    }

    /**
     * @param string $requestId Expected action correlation
     * @param string $acceptKey Connection being answered
     * @return PageActionErrorSignalData|PageActionSuccessSignalData Acknowledgement
     */
    private static function actionReply(string $requestId, string $acceptKey = self::ADMIN_KEY): PageActionErrorSignalData|PageActionSuccessSignalData
    {
        $reply = self::browserFrame($acceptKey);
        self::assertTrue($reply instanceof PageActionErrorSignalData || $reply instanceof PageActionSuccessSignalData);
        self::assertSame(self::ACTION, $reply->action);
        self::assertSame($requestId, $reply->requestId);

        return $reply;
    }

    /** @return ?SignalDTO The next business frame, excluding the receipt's own bookkeeping sync */
    private static function nextFrame(): ?SignalDTO
    {
        while (($frame = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($frame->data instanceof DbSyncSignalDataInterface && $frame->data->collectionKey === EcommerceShopDbContext::changeLogReceipts) {
                continue;
            }

            return $frame;
        }

        return null;
    }

    /**
     * @param string $acceptKey Expected recipient
     * @return SignalDataInterface Payload of the next outgoing frame
     */
    private static function browserFrame(string $acceptKey): SignalDataInterface
    {
        $signal = self::nextFrame();
        self::assertNotNull($signal);
        self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
        self::assertSame($acceptKey, $signal->data->targetAcceptKey);
        if ($signal->data->data instanceof PageActionSuccessSignalData) {
            self::assertSame(SignalConstants::ACTION_SUCCESS, $signal->signalName->getName());
        } elseif ($signal->data->data instanceof PageActionErrorSignalData) {
            self::assertSame(SignalConstants::ACTION_ERROR, $signal->signalName->getName());
        }

        return $signal->data->data;
    }

    /**
     * @param SourceChange $fact Stock write seen by the worker's browser
     * @param int $units Units now kept by the warehouse
     */
    private function assertLiveUnits(SourceChange $fact, int $units): void
    {
        Hilos::$browser->record($fact);
        self::assertSame([], Hilos::$browser->flushToSignalRouter());

        $deltas = [];
        $overviews = [];
        while (($signal = self::nextFrame()) !== null) {
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            $data = $signal->data->data;
            if ($data instanceof TableViewportDeltaDTO) {
                self::assertSame(self::ADMIN_KEY, $signal->data->targetAcceptKey);
                $deltas[] = $data;
            } elseif ($data instanceof PageResponseSignalData && $signal->data->targetAcceptKey === self::VISITOR_KEY) {
                self::assertSame(AdminProductPage::PAGE, $data->pageKey);
                $overviews[] = $data->payload->data[AdminProductBrowserData::DATA][AdminProductBrowserData::FIELD_STOCK];
            }
        }

        self::assertCount(1, $deltas);
        self::assertSame(ProductStockTable::TABLE, $deltas[0]->tableKey);
        self::assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $deltas[0]->kind);
        self::assertSame((string)$this->warehouseId, (string)$deltas[0]->rowKey);
        self::assertTrue($deltas[0]->own);
        self::assertSame($units, $deltas[0]->row[PagePayload::slots][ProductStockTable::ROW_SLOT][ProductStockTableRow::units]);
        self::assertSame([self::stockTotals($units)], $overviews);
    }

    /**
     * @param int $units Units summed across the fixture's warehouses
     * @return array<string, int|null> Expected card stock field
     */
    private static function stockTotals(int $units): array
    {
        return [
            AdminProductBrowserData::STOCK_UNITS => $units,
            AdminProductBrowserData::STOCK_WAREHOUSES => (int)($units > 0),
            AdminProductBrowserData::STOCK_WAREHOUSE_ID => null,
        ];
    }

    /** @return list<int> Independently read units in persisted stock rows */
    private function storedUnits(): array
    {
        $rows = Database::sql('SELECT units FROM product_warehouse_stock WHERE product_id = ?', [$this->productId])->rows();

        return array_map(static fn (array $row): int => (int)$row[ProductWarehouseStock::units], $rows);
    }

    /** @return list<int> Units currently visible in the owner's held collection */
    private function heldUnits(): array
    {
        $units = [];
        foreach (Hilos::$db->productWarehouseStocks->whereColumnIs(ObjectProductWarehouseStock::productId, $this->productId) as $row) {
            $units[] = $row->units;
        }

        return $units;
    }

    /** @return int Number of journal entries the database has written for stock */
    private static function journalCount(): int
    {
        $journal = ChangeLogDatabase::identifier(ChangeLogDatabase::configuredName());

        return (int)Database::sql(
            "SELECT COUNT(*) AS n FROM {$journal}.hilos_change_log AS entry
             JOIN {$journal}.hilos_change_log_table AS catalog ON catalog.id = entry.table_id WHERE catalog.name = ?",
            [ProductWarehouseStock::_table],
        )->field('n');
    }
}

/** Sends serialized handovers through the same hydration, receipt and origin scopes as a live worker. */
final class StockActionTestWorker extends WorkerManager
{
    /**
     * @param StockLibraryAgent $stock Writer of the handover
     * @param ProductsLibraryAgent $products Agent serving Stock
     */
    public function __construct(StockLibraryAgent $stock, ProductsLibraryAgent $products)
    {
        parent::__construct(1);
        $this->agentManager->addAgent($stock->getId(), $stock);
        $this->agentManager->addAgent($products->getId(), $products);
    }

    /**
     * @param AgentInterface $recipient Agent the daemon addresses
     * @param SignalDTO $frame Outbound frame to pass across the worker envelope
     */
    public function deliver(AgentInterface $recipient, SignalDTO $frame): void
    {
        $message = new DaemonAgentMessageDTO($recipient->getId(), $frame);
        ExecutionContext::run(new ExecutionFrame(), fn () => $this->handleDaemonMessage(DaemonAgentMessageDTO::fromArray($message->toArray())));
    }

    /**
     * @param AgentInterface $agent Project agent serving the page
     * @return PageSignalRouter Router using the shop's page and signal declarations
     */
    public static function pageRouter(AgentInterface $agent): PageSignalRouter
    {
        return new PageSignalRouter(
            new HilosPageFactory($agent, Hilos::class),
            new ActionRouteConfig(Hilos::getPageActionRoutes()),
            new SignalRouteConfig(Hilos::getPageSignalRoutes()),
        );
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new EcommerceShopSignalRouter();
    }

    protected function createAgentManager(): AgentManager
    {
        return new EcommerceShopAgentManager();
    }

    /**
     * @param AgentInterface $agent Project agent serving the page
     * @return PageSignalRouter Worker router for the gatekeeper
     */
    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        return self::pageRouter($agent);
    }
}
