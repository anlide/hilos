<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\ProductsLibraryAgent;
use Demo\EcommerceShop\Browser\Data\AdminProductBrowserData;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Object\Item\Product as ObjectProduct;
use Demo\EcommerceShop\Database\Object\Item\Warehouse as ObjectWarehouse;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\AdminProductPage;
use Demo\EcommerceShop\Pages\AdminProductStockPage;
use Demo\EcommerceShop\Pages\AdminProductsPage;
use Demo\EcommerceShop\Pages\DTO\AdminProductPageSubscribeParams;
use Demo\EcommerceShop\Tables\ProductStock\ProductStockTable;
use Demo\EcommerceShop\Tables\ProductStock\ProductStockTableRow;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageResender;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\Pages\HilosPageCatalog;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketTableViewportSignalDTO;
use Hilos\Testing\IntegrationTest;
use Hilos\Users\AccountStandingResolver;
use PHPUnit\Framework\Attributes\DataProvider;

/** The Stock subscription shares its product's head and opens a warehouse window scoped to that product. */
#[IntegrationTest]
final class AdminProductStockPageTest extends AdminProductsPageEnvironment
{
    private const string ADMIN_KEY = 'product-stock-admin';
    private const string VIEWER_KEY = 'product-stock-viewer';
    private const array OVERVIEW_TAB = [
        PageCatalogConstants::WIRE_TAB_PAGE => AdminProductPage::PAGE,
        PageCatalogConstants::WIRE_TAB_LABEL => HilosPageCatalog::OVERVIEW_LABEL,
    ];

    private PageSignalRouter $pages;
    private int $kyiv;
    private int $lviv;
    private int $bulbs;
    private int $seeds;
    private int $vase;

    /** Two warehouses, two stock products with distinct quantities, and one unique piece. */
    protected function setUpInTransaction(): void
    {
        parent::setUpInTransaction();

        $category = self::category('Garden');
        $this->lviv = self::warehouse('Lviv');
        $this->kyiv = self::warehouse('Kyiv');
        $this->bulbs = self::productIn($category, ProductKind::STOCK, 'Tulip bulbs', 41000, 1795132800123);
        $this->seeds = self::productIn($category, ProductKind::STOCK, 'Seeds', 3000, 1795132800124);
        $this->vase = self::productIn($category, ProductKind::UNIQUE, 'Blue vase', 450000, 1795132800125);
        self::holdCatalog();
        Hilos::$db->productWarehouseStocks->actions->setUnits($this->bulbs, $this->kyiv, 30);
        Hilos::$db->productWarehouseStocks->actions->setUnits($this->seeds, $this->lviv, 5);
        self::registerPerson(self::ADMIN_KEY, true);
        self::registerPerson(self::VIEWER_KEY, false);
        $this->pages = new PageSignalRouter(new HilosPageFactory(new ProductsLibraryAgent(), Hilos::class), new ActionRouteConfig());
    }

    /** Overview and Stock have the same identity, and the Stock reply includes the card and first window. */
    public function testStockSharesTheProductHeadAndAnswersWithItsFirstWindow(): void
    {
        $overview = $this->subscribe(AdminProductPage::PAGE, $this->bulbs);
        $stock = $this->subscribe(AdminProductStockPage::PAGE, $this->bulbs);

        foreach ([$overview, $stock] as $reply) {
            self::assertInstanceOf(PageResponseSignalData::class, $reply);
            $data = $reply->payload->data;
            self::assertSame(AdminProductPage::PAGE, $data[PageCatalogConstants::WIRE_PAGE_HEAD_OWNER][PageCatalogConstants::WIRE_OWNER_PAGE]);
            self::assertSame(
                [HilosPageConstants::HILOS_DASHBOARD, AdminProductsPage::PAGE, AdminProductPage::PAGE],
                array_column($data[PageCatalogConstants::WIRE_PAGE_BREADCRUMB], PageCatalogConstants::WIRE_CRUMB_PAGE),
            );
            self::assertSame([
                self::OVERVIEW_TAB,
                [PageCatalogConstants::WIRE_TAB_PAGE => AdminProductStockPage::PAGE, PageCatalogConstants::WIRE_TAB_LABEL => 'Stock'],
            ], $data[PageCatalogConstants::WIRE_PAGE_TABS]);
        }

        self::assertSame(
            $overview->payload->data[PageCatalogConstants::WIRE_PAGE_HEAD_OWNER],
            $stock->payload->data[PageCatalogConstants::WIRE_PAGE_HEAD_OWNER],
        );
        self::assertSame($overview->payload->data[AdminProductBrowserData::DATA], $stock->payload->data[AdminProductBrowserData::DATA]);
        self::assertSame([], $stock->payload->lists);
        $window = $stock->payload->windows[ProductStockTable::TABLE];
        self::assertSame([ProductStockTable::FILTER_PRODUCT => $this->bulbs], (array)$window[TableWindowDescriptorDTO::FILTER]);
        self::assertSame(Hilos::$table->productStock->defaultSort()->toArray(), $window[TableWindowDescriptorDTO::SORT]);
        self::assertSame([
            self::stockRow($this->kyiv, 'Kyiv', 30),
            self::stockRow($this->lviv, 'Lviv', 0),
        ], $window[TableWindowSignalData::rows]);
    }

    /** Unique pieces have only Overview, and both a unique piece and a missing product refuse a direct Stock address. */
    public function testUnavailableStockAddressesAreNotFound(): void
    {
        $overview = $this->subscribe(AdminProductPage::PAGE, $this->vase);
        self::assertInstanceOf(PageResponseSignalData::class, $overview);
        self::assertSame([self::OVERVIEW_TAB], $overview->payload->data[PageCatalogConstants::WIRE_PAGE_TABS]);

        foreach ([$this->vase, $this->vase + 1] as $productId) {
            $this->assertStockRefused(404, 'not_found', $productId);
        }
    }

    /** The standard route validation and the ADMIN gate refuse the subscription before it opens a window. */
    public function testInvalidAddressesAndMissingAdminAccessAreRefused(): void
    {
        $this->assertStockRefused(400, 'invalid_page_route_param', 'abc');
        self::unattended(static function (): void {
            Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        });
        $this->assertStockRefused(403, 'forbidden', $this->bulbs, self::VIEWER_KEY);
    }

    /** View mode exposes the product name and the warehouse names and units. */
    public function testAViewerReceivesTheStockRowsWithoutHiddenFields(): void
    {
        $reply = $this->subscribe(AdminProductStockPage::PAGE, $this->bulbs, acceptKey: self::VIEWER_KEY);
        self::assertInstanceOf(PageResponseSignalData::class, $reply);

        self::assertSame('Tulip bulbs', $reply->payload->data[AdminProductBrowserData::DATA][ObjectProduct::name]);
        self::assertSame([
            self::stockRow($this->kyiv, 'Kyiv', 30),
            self::stockRow($this->lviv, 'Lviv', 0),
        ], $reply->payload->windows[ProductStockTable::TABLE][TableWindowSignalData::rows]);
        self::assertStringNotContainsString(HiddenValue::KEY, json_encode($reply->toArray(), JSON_THROW_ON_ERROR));
    }

    /** Same-product restores keep their search. Moving the address discards a foreign report, viewport and focus. */
    public function testRestoringAWindowKeepsOnlyStateOfTheAddressedProduct(): void
    {
        $filter = [ProductStockTable::FILTER_PRODUCT => $this->bulbs, TableConstants::FILTER_KEY_SEARCH => 'Kyiv'];
        $report = [ProductStockTable::TABLE => new TableWindowDescriptorDTO(
            filter: $filter,
            sort: Hilos::$table->productStock->defaultSort(),
            limit: 1,
        )];
        $reply = $this->subscribe(AdminProductStockPage::PAGE, $this->bulbs, $report);
        self::assertInstanceOf(PageResponseSignalData::class, $reply);
        $window = $reply->payload->windows[ProductStockTable::TABLE];
        self::assertSame($filter, (array)$window[TableWindowDescriptorDTO::FILTER]);
        self::assertSame([self::stockRow($this->kyiv, 'Kyiv', 30)], $window[TableWindowSignalData::rows]);

        $reply = $this->subscribe(AdminProductStockPage::PAGE, $this->bulbs);
        self::assertInstanceOf(PageResponseSignalData::class, $reply);
        self::assertSame($filter, (array)$reply->payload->windows[ProductStockTable::TABLE][TableWindowDescriptorDTO::FILTER]);

        Hilos::$sr->setTableFocus(self::ADMIN_KEY, ProductStockTable::TABLE, (string)$this->kyiv);
        $reply = $this->subscribe(AdminProductStockPage::PAGE, $this->seeds, $report);
        self::assertInstanceOf(PageResponseSignalData::class, $reply);
        self::assertSame($this->seeds, $reply->payload->data[AdminProductBrowserData::DATA][AdminProductBrowserData::productId]);
        $window = $reply->payload->windows[ProductStockTable::TABLE];
        self::assertSame([ProductStockTable::FILTER_PRODUCT => $this->seeds], (array)$window[TableWindowDescriptorDTO::FILTER]);
        self::assertSame(TableConstants::DEFAULT_WINDOW_SIZE, $window[TableWindowSignalData::limit]);
        self::assertSame([
            self::stockRow($this->kyiv, 'Kyiv', 0),
            self::stockRow($this->lviv, 'Lviv', 5),
        ], $window[TableWindowSignalData::rows]);
        self::assertNull(Hilos::$sr->getTableFocus(self::ADMIN_KEY, ProductStockTable::TABLE));

        $this->subscribe(AdminProductStockPage::PAGE, $this->seeds, [ProductStockTable::TABLE => new TableWindowDescriptorDTO(
            filter: [ProductStockTable::FILTER_PRODUCT => $this->seeds, TableConstants::FILTER_KEY_SEARCH => 'Lviv'],
            sort: Hilos::$table->productStock->defaultSort(),
            limit: 1,
            anchor: new TableAnchorDTO([ProductStockTableRow::warehouseName => 'Kyiv', ProductStockTableRow::warehouseId => $this->kyiv]),
        )]);
        self::assertNotNull(Hilos::$sr->getTableViewport(self::ADMIN_KEY, ProductStockTable::TABLE)?->anchor);
        $update = new WebSocketPageUpdateSubscriptionSignalDTO(
            self::ADMIN_KEY,
            AdminProductStockPage::PAGE,
            [AdminProductPageSubscribeParams::PRODUCT_ID => (string)$this->bulbs],
        );
        ExecutionContext::run(
            new ExecutionFrame(acceptKey: self::ADMIN_KEY),
            fn () => $this->pages->dispatchPageUpdateSubscription($update, 'ws', AdminProductStockPage::PAGE),
        );

        $updated = $this->pageReply(self::ADMIN_KEY);
        self::assertInstanceOf(PageResponseSignalData::class, $updated);
        $window = $updated->payload->windows[ProductStockTable::TABLE];
        self::assertSame([ProductStockTable::FILTER_PRODUCT => $this->bulbs], (array)$window[TableWindowDescriptorDTO::FILTER]);
        self::assertSame([$this->kyiv, $this->lviv], array_column($window[TableWindowSignalData::rows], PagePayload::rowKey));
        self::assertNull(Hilos::$sr->getTableViewport(self::ADMIN_KEY, ProductStockTable::TABLE)?->anchor);
    }

    /** A delayed window request for the previous address cannot replace the current Stock window. */
    public function testLateViewportLeavesTheAddressedStockWindowInPlace(): void
    {
        $this->subscribe(AdminProductStockPage::PAGE, $this->bulbs);
        $reply = $this->updateStockAddress($this->seeds);
        self::assertInstanceOf(PageResponseSignalData::class, $reply);
        self::assertSame([
            self::stockRow($this->kyiv, 'Kyiv', 0),
            self::stockRow($this->lviv, 'Lviv', 5),
        ], $reply->payload->windows[ProductStockTable::TABLE][TableWindowSignalData::rows]);

        $this->requestStockWindow($this->bulbs, 'Kyiv');
        self::assertSame([], self::stockFrames());
        self::assertSame(
            [ProductStockTable::FILTER_PRODUCT => $this->seeds],
            Hilos::$sr->getTableViewport(self::ADMIN_KEY, ProductStockTable::TABLE)?->filter,
        );

        $this->requestStockWindow($this->seeds, 'Lviv');
        $frames = self::stockFrames();
        self::assertCount(1, $frames);
        self::assertInstanceOf(TableWindowSignalData::class, $frames[0]);
        self::assertSame([self::stockRow($this->lviv, 'Lviv', 5)], $frames[0]->rows);
        self::assertSame(
            [ProductStockTable::FILTER_PRODUCT => $this->seeds, TableConstants::FILTER_KEY_SEARCH => 'Lviv'],
            Hilos::$sr->getTableViewport(self::ADMIN_KEY, ProductStockTable::TABLE)?->filter,
        );
    }

    /** A refused address releases the old window and the next warehouse fact cannot revive it. */
    public function testRefusedAddressChangeReleasesStockAndRetriesTheRefusedAddress(): void
    {
        $this->assertRefusedAddressChange($this->vase, 404, 'not_found');
    }

    /**
     * An empty value replaces the old id in the merge and reaches the DTO as a missing parameter.
     *
     * @param string $productId New value of the product address
     * @param string $code Expected wire refusal code
     */
    #[DataProvider('malformedStockAddresses')]
    public function testMalformedAddressChangeDoesNotReviveThePreviousStockPage(string $productId, string $code): void
    {
        $this->assertRefusedAddressChange($productId, 400, $code);
    }

    /** @return array<string, array{string, string}> Product address values and their refusal codes */
    public static function malformedStockAddresses(): array
    {
        return [
            'invalid product id' => ['abc', 'invalid_page_route_param'],
            'missing product id' => ['', 'missing_page_route_param'],
        ];
    }

    /**
     * @param int|string $productId Refused product address
     * @param int $status Expected HTTP status
     * @param string $code Expected wire refusal code
     */
    private function assertRefusedAddressChange(int|string $productId, int $status, string $code): void
    {
        $resender = $this->createMock(PageResender::class);
        $resender->expects(self::once())->method('resendPage')->willReturnCallback($this->pages->resendPage(...));
        Hilos::$browser->bindPageResender($resender);
        $reply = $this->subscribe(AdminProductStockPage::PAGE, $this->bulbs);
        self::assertInstanceOf(PageResponseSignalData::class, $reply);
        self::assertNotNull(Hilos::$sr->getTableViewport(self::ADMIN_KEY, ProductStockTable::TABLE));
        Hilos::$sr->setTableFocus(self::ADMIN_KEY, ProductStockTable::TABLE, (string)$this->kyiv);

        $refused = $this->updateStockAddress($productId);
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $refused);
        self::assertSame($status, $refused->httpCode);
        self::assertSame($code, $refused->errorCode);
        self::assertNull(Hilos::$sr->getTableViewport(self::ADMIN_KEY, ProductStockTable::TABLE));
        self::assertNull(Hilos::$sr->getTableFocus(self::ADMIN_KEY, ProductStockTable::TABLE));
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::ADMIN_KEY));

        Hilos::$browser->record(SourceChange::dbUpdated(
            EcommerceShopDbContext::warehouses,
            (string)$this->kyiv,
            [ObjectWarehouse::name => 'Kyiv'],
        ));
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
        self::assertSame([], self::stockFrames());
        self::assertNull(Hilos::$sr->getTableViewport(self::ADMIN_KEY, ProductStockTable::TABLE));
        self::assertNull(Hilos::$sr->getTableFocus(self::ADMIN_KEY, ProductStockTable::TABLE));
        self::assertSame(
            [AdminProductPageSubscribeParams::PRODUCT_ID => (string)$productId],
            Hilos::$sr->pageSubscription(self::ADMIN_KEY)?->params,
        );
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::ADMIN_KEY));

        $recovered = $this->updateStockAddress($this->seeds);
        self::assertInstanceOf(PageResponseSignalData::class, $recovered);
        self::assertSame(
            [ProductStockTable::FILTER_PRODUCT => $this->seeds],
            Hilos::$sr->getTableViewport(self::ADMIN_KEY, ProductStockTable::TABLE)?->filter,
        );
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::ADMIN_KEY));
    }

    /**
     * @param int|string $productId New Stock address sent by the tab
     * @return PageResponseSignalData|PageSubscriptionErrorSignalData Reply to the address change
     */
    private function updateStockAddress(int|string $productId): PageResponseSignalData|PageSubscriptionErrorSignalData
    {
        $update = new WebSocketPageUpdateSubscriptionSignalDTO(
            self::ADMIN_KEY,
            AdminProductStockPage::PAGE,
            [AdminProductPageSubscribeParams::PRODUCT_ID => (string)$productId],
        );
        ExecutionContext::run(
            new ExecutionFrame(acceptKey: self::ADMIN_KEY),
            fn () => $this->pages->dispatchPageUpdateSubscription($update, 'ws', AdminProductStockPage::PAGE),
        );

        return $this->pageReply(self::ADMIN_KEY);
    }

    /**
     * @param int $productId Stock product in the requested window
     * @param string $search Warehouse name to search for
     */
    private function requestStockWindow(int $productId, string $search): void
    {
        $request = new WebSocketTableViewportSignalDTO(
            self::ADMIN_KEY,
            AdminProductStockPage::PAGE,
            ProductStockTable::TABLE,
            filter: [ProductStockTable::FILTER_PRODUCT => $productId, TableConstants::FILTER_KEY_SEARCH => $search],
            sort: Hilos::$table->productStock->defaultSort(),
            limit: 1,
        );
        ExecutionContext::run(
            new ExecutionFrame(acceptKey: self::ADMIN_KEY),
            fn () => $this->pages->dispatchTableViewport($request, 'ws', AdminProductStockPage::PAGE),
        );
    }

    /** @return list<SignalDataInterface> All pending browser frames for the Stock subscriber */
    private static function stockFrames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData && $signal->data->targetAcceptKey === self::ADMIN_KEY) {
                $frames[] = $signal->data->data;
            }
        }

        return $frames;
    }

    /**
     * @param string $acceptKey Connection to register
     * @param bool $admin Whether the person can act as an administrator
     */
    private static function registerPerson(string $acceptKey, bool $admin): void
    {
        Database::sql('INSERT INTO hilos_user (name, admin, block) VALUES (?, ?, 0)', [$acceptKey, (int)$admin]);
        $userId = Database::lastInsertId();
        AccountStandingResolver::forget($userId);
        self::unattended(static function () use ($acceptKey, $userId): void {
            Hilos::$rt->connections->actions->register($acceptKey, $userId);
        });
    }

    /**
     * @param string $page Page to open
     * @param int|string $productId Product address to send
     * @param array<string, TableWindowDescriptorDTO> $reported Windows held by the tab
     * @param string $acceptKey Connection opening the page
     * @return PageResponseSignalData|PageSubscriptionErrorSignalData The single page reply
     */
    private function subscribe(
        string $page,
        int|string $productId,
        array $reported = [],
        string $acceptKey = self::ADMIN_KEY,
    ): PageResponseSignalData|PageSubscriptionErrorSignalData {
        $request = new WebSocketPageSubscribeSignalDTO(
            $acceptKey,
            $page,
            [AdminProductPageSubscribeParams::PRODUCT_ID => (string)$productId],
            $reported,
        );
        ExecutionContext::run(
            new ExecutionFrame(acceptKey: $acceptKey),
            fn () => $this->pages->dispatchPageSubscribe($request, 'ws', $page),
        );
        Hilos::$sr->subscribeToPage($page, $request);

        $reply = $this->pageReply($acceptKey);
        self::assertSame($page, $reply instanceof PageResponseSignalData ? $reply->pageKey : $reply->page);

        return $reply;
    }

    /**
     * @param string $acceptKey Connection whose reply is expected
     * @return PageResponseSignalData|PageSubscriptionErrorSignalData The one page response or refusal queued for the connection
     */
    private function pageReply(string $acceptKey): PageResponseSignalData|PageSubscriptionErrorSignalData
    {
        $replies = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if (!$signal->data instanceof WebSocketSignalData || $signal->data->targetAcceptKey !== $acceptKey) {
                continue;
            }
            $name = $signal->signalName->getName();
            if ($name === SignalTypeConstants::PAGE_RESPONSE || $name === SignalConstants::SUBSCRIPTION_PAGE_ERROR) {
                $replies[] = $signal->data->data;
            }
        }

        self::assertCount(1, $replies);
        return $replies[0];
    }

    /**
     * @param int $status Expected HTTP status
     * @param string $code Expected wire refusal code
     * @param int|string $productId Product address sent
     * @param string $acceptKey Connection requesting Stock
     */
    private function assertStockRefused(int $status, string $code, int|string $productId, string $acceptKey = self::ADMIN_KEY): void
    {
        $reply = $this->subscribe(AdminProductStockPage::PAGE, $productId, acceptKey: $acceptKey);
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $reply);
        self::assertSame($status, $reply->httpCode);
        self::assertSame($code, $reply->errorCode);
        self::assertNull(Hilos::$sr->getTableViewport($acceptKey, ProductStockTable::TABLE));
    }

    /**
     * @param int $warehouseId Warehouse the row represents
     * @param string $name Warehouse name
     * @param int $units Units of the addressed product at this warehouse
     * @return array<string, mixed> Warehouse stock row in the page reply
     */
    private static function stockRow(int $warehouseId, string $name, int $units): array
    {
        return [
            PagePayload::rowKey => $warehouseId,
            PagePayload::slots => [ProductStockTable::ROW_SLOT => [
                ProductStockTableRow::warehouseId => $warehouseId,
                ProductStockTableRow::warehouseName => $name,
                ProductStockTableRow::units => $units,
            ]],
        ];
    }
}
