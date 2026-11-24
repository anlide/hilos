<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\ProductsLibraryAgent;
use Demo\EcommerceShop\Agents\StockLibraryAgent;
use Demo\EcommerceShop\Agents\WarehousesLibraryAgent;
use Demo\EcommerceShop\Browser\Data\AdminProductBrowserData;
use Demo\EcommerceShop\Browser\EcommerceShopBrowserList;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Constants\PageConstants;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Core\Router\DTO\ProductWarehousePlaceSignalData;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\ProductWarehouseStock as EntityProductWarehouseStock;
use Demo\EcommerceShop\Database\Object\Item\Category as ObjectCategory;
use Demo\EcommerceShop\Database\Object\Item\Product as ObjectProduct;
use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock as ObjectProductWarehouseStock;
use Demo\EcommerceShop\Database\Object\Item\Warehouse as ObjectWarehouse;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\AdminProductPage;
use Demo\EcommerceShop\Pages\AdminWarehousesPage;
use Demo\EcommerceShop\Pages\DTO\ProductWarehousePlaceActionDTO;
use Demo\EcommerceShop\Tables\EcommerceShopTableContext;
use Demo\EcommerceShop\Tables\Warehouse\WarehousesTable;
use Demo\EcommerceShop\Tables\Warehouse\WarehouseTableRow;
use Hilos\AdminViewMode\HiddenValue;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\ActionForbiddenException;
use Hilos\Core\Page\Exception\ActionViewModeException;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Core\Page\PageErrorMessages;
use Hilos\Core\Page\PageResender;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\SignalRouteConfig;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\DbSyncApplicator;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Files\HilosFiles;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Testing\IntegrationTest;
use Hilos\Users\AccountStandingResolver;
use Hilos\Utils\Helpers\RandomHelper;

/** One product's admin page answers with its card, its stock total and the lists its names come from, and keeps them live. */
#[IntegrationTest]
final class AdminProductPageTest extends AdminProductsPageEnvironment
{
    private const string VISITOR_KEY = 'admin-product-visitor';
    private const string ADMIN_KEY = 'admin-product-admin';
    private const string WAREHOUSE_WINDOW_KEY = 'admin-product-warehouse-window';

    private int $categoryId;
    private string $categoryName;
    private int $categoryPosition;
    private int $kyiv;
    private int $lviv;
    private int $odesa;
    private int $bulbs;
    private string $bulbsName;
    private int $seeds;
    private int $vase;
    private int $jug;

    /**
     * Three warehouses, a stock product kept in two of them, a stock product no warehouse keeps, a
     * unique piece in one warehouse and one in none, and a person without admin rights and an
     * administrator connected.
     */
    protected function setUpInTransaction(): void
    {
        parent::setUpInTransaction();
        $tag = RandomHelper::hex(8);
        $this->categoryId = self::category("{$tag} Roses");
        $category = Database::sql('SELECT * FROM category WHERE id = ?', [$this->categoryId])->rows()[0];
        $this->categoryName = $category['name'];
        $this->categoryPosition = (int)$category['position'];
        $this->kyiv = self::warehouse("{$tag} Kyiv");
        $this->lviv = self::warehouse("{$tag} Lviv");
        $this->odesa = self::warehouse("{$tag} Odesa");
        $this->bulbsName = "{$tag} Tulip bulbs";
        $this->bulbs = self::productIn($this->categoryId, ProductKind::STOCK, $this->bulbsName, 41000, 1795132800123);
        $this->seeds = self::productIn($this->categoryId, ProductKind::STOCK, 'Seeds', 3000, 1795132800124);
        $this->vase = self::productIn($this->categoryId, ProductKind::UNIQUE, 'Blue vase', 450000, 1795132800125);
        $this->jug = self::productIn($this->categoryId, ProductKind::UNIQUE, 'Clay jug', 120000, 1795132800126);
        self::holdCatalog();
        Hilos::$db->productWarehouseStocks->actions->setUnits($this->bulbs, $this->kyiv, 30);
        Hilos::$db->productWarehouseStocks->actions->setUnits($this->bulbs, $this->lviv, 12);
        Hilos::$db->productWarehouseStocks->actions->place($this->vase, $this->lviv);
        $visitorId = self::person('Product visitor', false);
        $adminId = self::person('Product admin', true);
        self::unattended(static function () use ($visitorId, $adminId): void {
            Hilos::$rt->connections->actions->register(self::VISITOR_KEY, $visitorId);
            Hilos::$rt->connections->actions->register(self::ADMIN_KEY, $adminId);
            Hilos::$rt->connections->actions->register(self::WAREHOUSE_WINDOW_KEY, $adminId);
        });
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
            // Discard broadcasts from fixture setup.
        }
    }

    /** An administrator is sent one answer with the whole card, both lists and the head of the product's page. */
    public function testAnAdminIsSentTheWholeCardTheListsAndTheHead(): void
    {
        $response = $this->subscribe(self::ADMIN_KEY, $this->bulbs);
        $payload = $response->payload->toArray();

        self::assertSame(AdminProductPage::PAGE, $response->pageKey);
        self::assertSame([
            AdminProductBrowserData::productId => $this->bulbs,
            'name' => $this->bulbsName,
            'slug' => Hilos::$db->products[$this->bulbs]->slug,
            'kind' => ProductKind::STOCK,
            'price' => 41000,
            'categoryId' => $this->categoryId,
            'description' => 'Product of the case',
            ObjectProduct::pictureFileId => $this->pictureFileOf($this->bulbs),
            AdminProductBrowserData::FIELD_PICTURE_URL => HilosFiles::downloadPath(
                $this->pictureFileOf($this->bulbs),
                CatalogPictureUploadTarget::VARIANT_LG,
            ),
            AdminProductBrowserData::FIELD_EDIT_VERSION => $this->bulbsEditVersion(),
            AdminProductBrowserData::FIELD_STOCK => self::stockField(42, 2, null),
        ], $payload[PagePayload::data][AdminProductBrowserData::DATA]);
        self::assertStringContainsString(
            '"kind":"stock"',
            json_encode($payload[PagePayload::data][AdminProductBrowserData::DATA], JSON_THROW_ON_ERROR),
        );
        self::assertSame([
            ObjectCategory::id => $this->categoryId,
            ObjectCategory::name => $this->categoryName,
            ObjectCategory::position => $this->categoryPosition,
        ], self::slotFor(
            $payload[PagePayload::lists][EcommerceShopBrowserList::ADMIN_PRODUCT_CATEGORIES][PagePayload::items],
            $this->categoryId,
            EcommerceShopDbContext::categories,
        ));
        foreach ([$this->kyiv, $this->lviv, $this->odesa] as $warehouseId) {
            self::assertSame([
                ObjectWarehouse::id => $warehouseId,
                ObjectWarehouse::name => self::warehouseName($warehouseId),
            ], self::slotFor(
                $payload[PagePayload::lists][EcommerceShopBrowserList::ADMIN_PRODUCT_WAREHOUSES][PagePayload::items],
                $warehouseId,
                EcommerceShopDbContext::warehouses,
            ));
        }
        self::assertSame(
            PageConstants::ADMIN_PRODUCT,
            $response->payload->data[PageCatalogConstants::WIRE_PAGE_HEAD_OWNER]['page'] ?? null,
        );
        self::assertSame(
            [HilosPageConstants::HILOS_DASHBOARD, PageConstants::ADMIN_PRODUCTS, PageConstants::ADMIN_PRODUCT],
            array_column($response->payload->data[PageCatalogConstants::WIRE_PAGE_BREADCRUMB], 'page'),
        );
        self::assertStringNotContainsString(HiddenValue::KEY, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** A stock product no warehouse keeps is zero units in zero warehouses. */
    public function testAStockProductWithoutStockIsZeroUnitsInZeroWarehouses(): void
    {
        self::assertSame(
            self::stockField(0, 0, null),
            $this->cardOf($this->subscribe(self::ADMIN_KEY, $this->seeds))[AdminProductBrowserData::FIELD_STOCK],
        );
    }

    /** A unique piece names the warehouse keeping it, and a piece in no warehouse names none. */
    public function testAUniquePieceNamesItsWarehouseOnlyWhilePlaced(): void
    {
        self::assertSame(
            self::stockField(1, 1, $this->lviv),
            $this->cardOf($this->subscribe(self::ADMIN_KEY, $this->vase))[AdminProductBrowserData::FIELD_STOCK],
        );
        self::assertSame(
            self::stockField(0, 0, null),
            $this->cardOf($this->subscribe(self::ADMIN_KEY, $this->jug))[AdminProductBrowserData::FIELD_STOCK],
        );
    }

    /** A number that names no product is refused as not found. */
    public function testAnUnknownNumberIsNotFound(): void
    {
        $unknown = $this->bulbs + 1000;
        try {
            $this->subscribe(self::ADMIN_KEY, $unknown);
            self::fail('An unknown product must not be answered');
        } catch (PageResourceNotFoundException $e) {
            self::assertSame(404, $e->httpCode);
            self::assertSame('not_found', $e->errorCode);
        }
    }

    /** A live DB delete refuses the Overview that was subscribed to the deleted product. */
    public function testDeletingTheOpenProductSendsTheResourceRefusal(): void
    {
        $pages = new PageSignalRouter(new HilosPageFactory(new ProductsLibraryAgent(), Hilos::class), new ActionRouteConfig());
        $resender = $this->createMock(PageResender::class);
        $resender->method('refuseDeletedResource')->willReturnCallback($pages->refuseDeletedResource(...));
        Hilos::$browser->bindPageResender($resender);
        $this->subscribe(self::ADMIN_KEY, $this->jug);

        $id = (string)$this->jug;
        $row = Database::sql('SELECT * FROM product WHERE id = ?', [$this->jug])->rows()[0];
        Database::sql('DELETE FROM product WHERE id = ?', [$this->jug]);
        DbSyncApplicator::applyDeleted(new DbSyncDeletedSignalData(EcommerceShopDbContext::products, $id, $row));
        self::deliver(SourceChange::dbDeleted(EcommerceShopDbContext::products, $id, $row));

        $replies = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData && $signal->data->targetAcceptKey === self::ADMIN_KEY) {
                $replies[$signal->signalName->getName()][] = $signal->data->data;
            }
        }
        self::assertArrayNotHasKey(SignalTypeConstants::PAGE_RESPONSE, $replies);
        $errors = $replies[SignalConstants::SUBSCRIPTION_PAGE_ERROR] ?? [];
        self::assertCount(1, $errors);
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $errors[0]);
        self::assertSame(PageSubscriptionErrorSignalData::forStatus(
            AdminProductPage::PAGE,
            404,
            'not_found',
            PageErrorMessages::RESOURCE_DELETED_MESSAGE,
        )->toArray(), $errors[0]->toArray());
    }

    /** An address that is not a number is refused as an invalid route param. */
    public function testANonNumericAddressIsAnInvalidRouteParam(): void
    {
        try {
            $this->subscribeTo(self::ADMIN_KEY, 'abc');
            self::fail('A non-numeric product address must not be answered');
        } catch (InvalidPageRouteParamException $e) {
            self::assertSame(400, $e->httpCode);
            self::assertSame('invalid_page_route_param', $e->errorCode);
        }
    }

    /** With the view mode off the page is closed to a person without admin rights. */
    public function testANonAdminIsRefusedWhenTheViewModeIsOff(): void
    {
        self::unattended(static function (): void {
            Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        });
        $this->expectException(PageForbiddenException::class);
        PageAccessGate::assert(AdminProductPage::class, self::VISITOR_KEY);
    }

    /** A viewer of the view mode is shown the product's fields, not marks. */
    public function testAViewerIsShownTheProductsFields(): void
    {
        $card = $this->cardOf($this->subscribe(self::VISITOR_KEY, $this->bulbs));

        self::assertSame($this->bulbsName, $card['name']);
        self::assertSame(41000, $card['price']);
        self::assertSame($this->pictureFileOf($this->bulbs), $card[ObjectProduct::pictureFileId]);
        self::assertSame($this->bulbsEditVersion(), $card[AdminProductBrowserData::FIELD_EDIT_VERSION]);
        self::assertSame(self::stockField(42, 2, null), $card[AdminProductBrowserData::FIELD_STOCK]);
        foreach ($card as $field => $value) {
            self::assertFalse(HiddenValue::isMark($value), "field {$field} is a mark for the viewer");
        }
    }

    /** A stock row of this product redraws the card with its new total; one of another product sends nothing. */
    public function testAStockWriteOfThisProductRedrawsTheCardAndAnotherProductsDoesNot(): void
    {
        $this->subscribe(self::ADMIN_KEY, $this->bulbs);

        Hilos::$db->productWarehouseStocks->actions->setUnits($this->bulbs, $this->odesa, 8);
        self::deliver(SourceChange::dbCreated(
            EcommerceShopDbContext::productWarehouseStocks,
            (string)self::stockRowId($this->bulbs, $this->odesa),
            self::announcedStock($this->bulbs, $this->odesa),
        ));
        $cards = $this->sentCards(self::ADMIN_KEY);
        self::assertCount(1, $cards);
        self::assertSame(self::stockField(50, 3, null), $cards[0][AdminProductBrowserData::FIELD_STOCK]);
        self::assertSame($this->bulbsEditVersion(), $cards[0][AdminProductBrowserData::FIELD_EDIT_VERSION]);

        Hilos::$db->productWarehouseStocks->actions->setUnits($this->seeds, $this->kyiv, 5);
        self::deliver(SourceChange::dbCreated(
            EcommerceShopDbContext::productWarehouseStocks,
            (string)self::stockRowId($this->seeds, $this->kyiv),
            self::announcedStock($this->seeds, $this->kyiv),
        ));
        self::assertSame([], $this->sentCards(self::ADMIN_KEY));
    }

    /** A product edit redraws the card; a warehouse or a category rename redraws its item in its own list. */
    public function testAProductEditRedrawsTheCardAndRenamesRedrawTheirListItems(): void
    {
        $this->subscribe(self::ADMIN_KEY, $this->bulbs);

        Database::sql('UPDATE product SET name = ?, price = ?, slug = ? WHERE id = ?', ['Renamed bulbs', 52000, 'renamed-bulbs', $this->bulbs]);
        self::deliverUpdate(EcommerceShopDbContext::products, 'product', $this->bulbs);
        $cards = $this->sentCards(self::ADMIN_KEY);
        self::assertCount(1, $cards);
        self::assertSame('Renamed bulbs', $cards[0]['name']);
        self::assertSame('renamed-bulbs', $cards[0]['slug']);
        self::assertSame(52000, $cards[0]['price']);

        Database::sql('UPDATE warehouse SET name = ? WHERE id = ?', ['Renamed warehouse', $this->kyiv]);
        self::deliverUpdate(EcommerceShopDbContext::warehouses, 'warehouse', $this->kyiv);
        $warehouses = $this->sentListItems(self::ADMIN_KEY, EcommerceShopBrowserList::ADMIN_PRODUCT_WAREHOUSES);
        self::assertCount(1, $warehouses);
        self::assertSame(
            [ObjectWarehouse::id => $this->kyiv, ObjectWarehouse::name => 'Renamed warehouse'],
            self::slotFor($warehouses[0], $this->kyiv, EcommerceShopDbContext::warehouses),
        );

        Database::sql('UPDATE category SET name = ? WHERE id = ?', ['Renamed category', $this->categoryId]);
        self::deliverUpdate(EcommerceShopDbContext::categories, 'category', $this->categoryId);
        $categories = $this->sentListItems(self::ADMIN_KEY, EcommerceShopBrowserList::ADMIN_PRODUCT_CATEGORIES);
        self::assertCount(1, $categories);
        self::assertSame([
            ObjectCategory::id => $this->categoryId,
            ObjectCategory::name => 'Renamed category',
            ObjectCategory::position => $this->categoryPosition,
        ], self::slotFor($categories[0], $this->categoryId, EcommerceShopDbContext::categories));
    }

    /** Moving the subscription to another number answers with the whole page under that number. */
    public function testAnUpdateToAnotherNumberAnswersTheWholePageUnderIt(): void
    {
        $this->subscribe(self::ADMIN_KEY, $this->bulbs);

        $params = new PageRouteParams(['productId' => (string)$this->vase]);
        ExecutionContext::run(new ExecutionFrame(acceptKey: self::ADMIN_KEY), static function () use ($params): void {
            new AdminProductPage(new ProductsLibraryAgent())->onUpdateSubscription(self::ADMIN_KEY, $params);
        });
        $responses = $this->drainResponses(self::ADMIN_KEY);

        self::assertCount(1, $responses);
        $card = $this->cardOf($responses[0]);
        self::assertSame($this->vase, $card[AdminProductBrowserData::productId]);
        self::assertSame('Blue vase', $card['name']);
        self::assertSame(self::stockField(1, 1, $this->lviv), $card[AdminProductBrowserData::FIELD_STOCK]);
        $lists = $responses[0]->payload->toArray()[PagePayload::lists];
        self::assertArrayHasKey(EcommerceShopBrowserList::ADMIN_PRODUCT_CATEGORIES, $lists);
        self::assertArrayHasKey(EcommerceShopBrowserList::ADMIN_PRODUCT_WAREHOUSES, $lists);
    }

    /** First placement writes one unit, answers the initiator after the handover and redraws the observer's card. */
    public function testAnAdministratorPlacesAnUnplacedPieceAndTheObserverSeesIt(): void
    {
        $this->subscribe(self::VISITOR_KEY, $this->jug);
        $page = self::dispatchPlacement(self::ADMIN_KEY, self::placement($this->jug, $this->kyiv, null), 'req-place');

        self::assertSame([], self::storedStock($this->jug));
        $facts = self::completePlacement($page);
        self::assertPlacementSuccess('req-place');
        self::assertCount(1, $facts);
        self::assertInstanceOf(DbSyncCreatedSignalData::class, $facts[0]);
        $fact = DbSyncCreatedSignalData::fromJson($facts[0]->toJson());

        $stock = self::storedStock($this->jug);
        self::assertCount(1, $stock);
        self::assertSame($this->kyiv, (int)$stock[0][EntityProductWarehouseStock::warehouse_id]);
        self::assertSame(1, (int)$stock[0][EntityProductWarehouseStock::units]);
        self::assertSame((string)$stock[0][EntityProductWarehouseStock::id], $fact->idString);
        self::assertSame(EcommerceShopDbContext::productWarehouseStocks, $fact->collectionKey);
        self::assertSame(self::ADMIN_KEY, $fact->origin);
        self::assertSame('req-place', $fact->originRequestId);
        self::deliver(SourceChange::dbCreated($fact->collectionKey, $fact->idString, $fact->row, $fact->origin, $fact->originRequestId));

        $response = self::takeBrowserPayload(SignalTypeConstants::PAGE_RESPONSE, self::VISITOR_KEY);
        self::assertInstanceOf(PageResponseSignalData::class, $response);
        self::assertSame(AdminProductPage::PAGE, $response->pageKey);
        self::assertSame(self::stockField(1, 1, $this->kyiv), $this->cardOf($response)[AdminProductBrowserData::FIELD_STOCK]);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /** A move preserves the stock row and redraws the card and both open warehouse windows. */
    public function testMovingAPiecePreservesItsRowAndRedrawsBothWarehouseWindows(): void
    {
        $this->subscribe(self::ADMIN_KEY, $this->vase);
        self::openWarehouseWindow(self::VISITOR_KEY, $this->lviv, 2, 13);
        self::openWarehouseWindow(self::WAREHOUSE_WINDOW_KEY, $this->odesa, 0, 0);
        $original = self::storedStock($this->vase);
        $page = self::dispatchPlacement(self::ADMIN_KEY, self::placement($this->vase, $this->odesa, $this->lviv), 'req-move');

        $facts = self::completePlacement($page);
        self::assertPlacementSuccess('req-move');
        self::assertCount(1, $facts);
        self::assertInstanceOf(DbSyncUpdatedSignalData::class, $facts[0]);
        $fact = DbSyncUpdatedSignalData::fromJson($facts[0]->toJson());
        self::assertSame((string)$original[0][EntityProductWarehouseStock::id], $fact->idString);
        self::assertSame(EcommerceShopDbContext::productWarehouseStocks, $fact->collectionKey);
        self::assertSame($this->odesa, $fact->row[EntityProductWarehouseStock::warehouse_id]);
        self::assertSame($this->lviv, $fact->previous[EntityProductWarehouseStock::warehouse_id]);

        $stock = self::storedStock($this->vase);
        self::assertCount(1, $stock);
        self::assertSame($original[0][EntityProductWarehouseStock::id], $stock[0][EntityProductWarehouseStock::id]);
        self::assertSame($this->odesa, (int)$stock[0][EntityProductWarehouseStock::warehouse_id]);
        self::assertSame(1, (int)$stock[0][EntityProductWarehouseStock::units]);
        self::deliver(SourceChange::dbUpdated(
            $fact->collectionKey,
            $fact->idString,
            $fact->row,
            $fact->origin,
            $fact->originRequestId,
            previous: $fact->previous,
        ));
        $this->assertMoveDeliveries();
    }

    /** A retry after success is a no-op, and the same stale view cannot move the piece to a different warehouse. */
    public function testRepeatingAMoveIsHarmlessButMovingFromAStaleWarehouseIsRefused(): void
    {
        $payload = self::placement($this->vase, $this->odesa, $this->lviv);
        $page = self::dispatchPlacement(self::ADMIN_KEY, $payload, 'req-original-move');
        self::assertCount(1, self::completePlacement($page));
        self::assertPlacementSuccess('req-original-move');
        $placed = self::storedStock($this->vase);
        self::assertCount(1, $placed);
        self::assertSame($this->odesa, (int)$placed[0][EntityProductWarehouseStock::warehouse_id]);

        $page = self::dispatchPlacement(self::ADMIN_KEY, $payload, 'req-retry-move');
        self::assertSame([], self::completePlacement($page));
        self::assertPlacementSuccess('req-retry-move');
        self::assertSame($placed, self::storedStock($this->vase));

        $page = self::dispatchPlacement(self::ADMIN_KEY, self::placement($this->vase, $this->kyiv, $this->lviv), 'req-stale-move');
        self::assertSame([], self::completePlacement($page));
        self::assertSame(
            'The piece changed warehouses elsewhere. Review the current warehouse and try again.',
            self::placementError(self::ADMIN_KEY, 'req-stale-move')->reason,
        );
        self::assertSame($placed, self::storedStock($this->vase));
    }

    /** The owner refuses a missing warehouse or a non-piece, and an incomplete payload stays at the page boundary. */
    public function testInvalidPlacementsAreRefusedWithoutStockChanges(): void
    {
        $unknownProduct = $this->jug + 1000;
        foreach ([
            [$this->vase, $this->odesa + 1000, $this->lviv, 'This warehouse no longer exists'],
            [$this->bulbs, $this->odesa, null, 'A stock item is not moved: set its units in each warehouse'],
            [$unknownProduct, $this->odesa, null, 'This product does not exist'],
        ] as [$productId, $warehouseId, $expectedWarehouseId, $reason]) {
            $before = self::storedStock($productId);
            $page = self::dispatchPlacement(
                self::ADMIN_KEY,
                self::placement($productId, $warehouseId, $expectedWarehouseId),
                'req-refused-placement',
            );

            self::assertSame([], self::completePlacement($page));
            self::assertSame($reason, self::placementError(self::ADMIN_KEY, 'req-refused-placement')->reason);
            self::assertSame($before, self::storedStock($productId));
        }

        self::dispatchPlacement(self::ADMIN_KEY, [
            ObjectProductWarehouseStock::productId => $this->jug,
            ObjectProductWarehouseStock::warehouseId => $this->kyiv,
        ], 'req-incomplete-placement');
        self::assertSame(
            'Payload carries no integer or null under key expectedWarehouseId',
            self::placementError(self::ADMIN_KEY, 'req-incomplete-placement')->reason,
        );
        self::assertSame([], self::storedStock($this->jug));

        self::dispatchPlacement(self::ADMIN_KEY, self::placement($this->jug, $this->kyiv, null), 'req-malformed-ipc-placement');
        $request = self::takeHandoverFrame(Hilos::$sr, EcommerceShopSignalConstants::STOCK_PLACE_UNIQUE);
        self::assertInstanceOf(AgentSignalData::class, $request->data);
        $payload = $request->data->data->toArray();
        unset($payload[ProductWarehousePlaceActionDTO::expectedWarehouseId]);
        try {
            Hilos::$sr->createAgentSignalPayloadDTO(
                $request->signalName->getName(),
                new AgentSignalData(new SignalData($payload), $request->data->receiptId),
            );
            self::fail('The stock request must carry the expected warehouse, even when it is null.');
        } catch (InvalidAgentSignalPayloadException $refusal) {
            self::assertSame('Payload carries no integer or null under key expectedWarehouseId', $refusal->getPrevious()?->getMessage());
        }
        self::assertSame([], self::storedStock($this->jug));
    }

    /** A viewer can read the card but cannot forward a placement, and the same action is forbidden with view mode off. */
    public function testThePlacementGateRefusesBothTheViewerAndTheNonAdminOutsideViewMode(): void
    {
        $card = $this->cardOf($this->subscribe(self::VISITOR_KEY, $this->vase));
        self::assertSame($this->vase, $card[AdminProductBrowserData::productId]);
        self::assertSame(self::stockField(1, 1, $this->lviv), $card[AdminProductBrowserData::FIELD_STOCK]);
        $before = self::storedStock($this->vase);
        $payload = self::placement($this->vase, $this->kyiv, $this->lviv);

        self::dispatchPlacement(self::VISITOR_KEY, $payload, 'req-viewer-placement');
        self::assertSame(
            ActionViewModeException::ERROR_CODE,
            self::placementError(self::VISITOR_KEY, 'req-viewer-placement')->errorCode,
        );
        self::assertSame($before, self::storedStock($this->vase));

        self::unattended(static function (): void {
            Hilos::$rt->hilosAdminViewModeRuntime?->actions->set(false);
        });
        self::dispatchPlacement(self::VISITOR_KEY, $payload, 'req-forbidden-placement');
        self::assertSame(
            ActionForbiddenException::ERROR_CODE,
            self::placementError(self::VISITOR_KEY, 'req-forbidden-placement')->errorCode,
        );
        self::assertSame($before, self::storedStock($this->vase));
    }

    /**
     * @param int $productId Piece to place
     * @param int $warehouseId Requested destination
     * @param ?int $expectedWarehouseId Last warehouse seen on the card
     * @return array<string, int|null> Placement sent by the browser
     */
    private static function placement(int $productId, int $warehouseId, ?int $expectedWarehouseId): array
    {
        return [
            ObjectProductWarehouseStock::productId => $productId,
            ObjectProductWarehouseStock::warehouseId => $warehouseId,
            ProductWarehousePlaceActionDTO::expectedWarehouseId => $expectedWarehouseId,
        ];
    }

    /**
     * @param string $acceptKey Connection making the request
     * @param array<string, int|null> Payload sent by the browser
     * @param string $requestId Correlation of the tracked action
     * @return PageSignalRouter Products process to receive the writer's reply
     */
    private static function dispatchPlacement(string $acceptKey, array $payload, string $requestId): PageSignalRouter
    {
        $library = new ProductsLibraryAgent();
        $page = new PageSignalRouter(
            new HilosPageFactory($library, Hilos::class),
            new ActionRouteConfig(Hilos::getPageActionRoutes()),
            new SignalRouteConfig(Hilos::getPageSignalRoutes()),
        );
        $action = new WebSocketActionSignalDTO($acceptKey, EcommerceShopSignalConstants::PRODUCT_WAREHOUSE_PLACE, $payload, $requestId);
        ExecutionContext::run(
            new ExecutionFrame(agentId: $library->getId(), acceptKey: $acceptKey),
            static fn () => $page->dispatchAction(WebSocketActionSignalDTO::fromJson($action->toJson()), 'websocket'),
        );

        return $page;
    }

    /**
     * Shuttles queued frames between the two handlers without starting a worker or committing the fixture.
     * The observer on the stock router captures the ORM facts before the rollback root holds them.
     *
     * @param PageSignalRouter $page Products process that dispatched the action
     * @return list<DbSyncSignalDataInterface> Facts produced by the stock handler
     */
    private static function completePlacement(PageSignalRouter $page): array
    {
        $router = Hilos::$sr;
        $request = self::takeHandoverFrame($router, EcommerceShopSignalConstants::STOCK_PLACE_UNIQUE);
        self::assertInstanceOf(AgentSignalData::class, $request->data);
        $data = $router->createAgentSignalPayloadDTO($request->signalName->getName(), $request->data);
        $ask = $data->data;
        self::assertInstanceOf(ProductWarehousePlaceSignalData::class, $ask);

        $stockRouter = new class extends SignalRouter {
            /** @var list<DbSyncSignalDataInterface> Facts scheduled by the stock ORM */
            public array $facts = [];

            /**
             * @param string $signalName DB signal name
             * @param DbSyncSignalDataInterface $signalData ORM announcement
             * @param ?int $connectionIndex Connection holding the write
             */
            public function queueDbSyncSignal(
                string $signalName,
                DbSyncSignalDataInterface $signalData,
                ?int $connectionIndex = null,
            ): void {
                $this->facts[] = $signalData;
                parent::queueDbSyncSignal($signalName, $signalData, $connectionIndex);
            }
        };
        $stock = new StockLibraryAgent();
        Hilos::$sr = $stockRouter;
        try {
            ExecutionContext::run(
                new ExecutionFrame(agentId: $stock->getId()),
                static fn () => ExecutionContext::withOrigin(
                    $ask->acceptKey,
                    $ask->requestId,
                    static fn () => $stock->onSignalAgent(
                        $data,
                        SignalSource::describe($request->signalSource),
                        $request->signalName->getName(),
                    ),
                ),
            );
            $answer = self::takeHandoverFrame($stockRouter, EcommerceShopSignalConstants::ADMIN_PRODUCT_WAREHOUSE_PLACE_DONE);
        } finally {
            Hilos::$sr = $router;
        }

        self::assertInstanceOf(AgentSignalData::class, $answer->data);
        ExecutionContext::run(
            new ExecutionFrame(agentId: ProductsLibraryAgent::AGENT_TYPE),
            static fn () => $page->dispatchAgentSignal($answer->data, SignalSource::describe($answer->signalSource), $answer->signalName->getName()),
        );

        return $stockRouter->facts;
    }

    /**
     * The receiver's registry selects the inner DTO. Leave the envelope intact and remove the
     * inner class hint so a missing route cannot pass using the sender's PHP class name.
     *
     * @param SignalRouter $router Sender's queue
     * @param string $name Expected handover signal
     * @return SignalDTO Transported envelope
     */
    private static function takeHandoverFrame(SignalRouter $router, string $name): SignalDTO
    {
        $signal = $router->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertSame($name, $signal->signalName->getName());
        self::assertSame(SignalTypeConstants::AGENT_SIGNAL, $signal->signalType->getType());
        self::assertNull($router->getNextQueuedSignal(), 'Only the handover frame may leave this process.');
        $wire = $signal->toArray();
        unset($wire[SignalPayloadConstants::FIELD_DATA][SignalPayloadConstants::FIELD_DATA_TYPE]);

        return SignalDTO::fromJson(json_encode($wire, JSON_THROW_ON_ERROR));
    }

    /**
     * @param string $name Expected signal name
     * @param string $acceptKey Expected target connection
     * @return SignalDataInterface Browser payload
     */
    private static function takeBrowserPayload(string $name, string $acceptKey): SignalDataInterface
    {
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertSame($name, $signal->signalName->getName());
        $frame = SignalDTO::fromJson($signal->toJson());
        self::assertInstanceOf(WebSocketSignalData::class, $frame->data);
        self::assertSame($acceptKey, $frame->data->targetAcceptKey);

        return $frame->data->data;
    }

    /** @param string $requestId Correlation to echo to the acting administrator */
    private static function assertPlacementSuccess(string $requestId): void
    {
        $reply = self::takeBrowserPayload(SignalConstants::ACTION_SUCCESS, self::ADMIN_KEY);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $reply);
        self::assertSame([
            SignalPayloadConstants::FIELD_ACTION => EcommerceShopSignalConstants::PRODUCT_WAREHOUSE_PLACE,
            SignalPayloadConstants::FIELD_REQUEST_ID => $requestId,
        ], $reply->toArray());
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * @param string $acceptKey Connection owed the refusal
     * @param string $requestId Correlation to echo
     * @return PageActionErrorSignalData Tracked refusal
     */
    private static function placementError(string $acceptKey, string $requestId): PageActionErrorSignalData
    {
        $error = self::takeBrowserPayload(SignalConstants::ACTION_ERROR, $acceptKey);
        self::assertInstanceOf(PageActionErrorSignalData::class, $error);
        self::assertSame($requestId, $error->requestId);
        self::assertSame(EcommerceShopSignalConstants::PRODUCT_WAREHOUSE_PLACE, $error->action);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());

        return $error;
    }

    /**
     * @param string $acceptKey Connection opening a warehouse window
     * @param int $warehouseId Only warehouse the window shows
     * @param int $products Expected initial product count
     * @param int $units Expected initial unit count
     */
    private static function openWarehouseWindow(string $acceptKey, int $warehouseId, int $products, int $units): void
    {
        $windows = [EcommerceShopTableContext::warehouses => new TableWindowDescriptorDTO(
            filter: [TableConstants::FILTER_KEY_SEARCH => self::warehouseName($warehouseId)],
        )];
        Hilos::$sr->subscribeToPage(
            AdminWarehousesPage::PAGE,
            new WebSocketPageSubscribeSignalDTO($acceptKey, AdminWarehousesPage::PAGE, [], $windows),
        );
        Hilos::$sr->reportTableWindows($acceptKey, $windows);
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), static function () use ($acceptKey): void {
            new AdminWarehousesPage(new WarehousesLibraryAgent())->onSubscribe($acceptKey, new PageRouteParams([]));
        });

        $response = self::takeBrowserPayload(SignalTypeConstants::PAGE_RESPONSE, $acceptKey);
        self::assertInstanceOf(PageResponseSignalData::class, $response);
        self::assertSame(AdminWarehousesPage::PAGE, $response->pageKey);
        $rows = $response->payload->toArray()[PagePayload::windows][EcommerceShopTableContext::warehouses][TableWindowSignalData::rows];
        self::assertCount(1, $rows);
        self::assertWarehouseCounts($rows[0], $warehouseId, $products, $units);
    }

    /** The card and both table windows can be delivered in either order. */
    private function assertMoveDeliveries(): void
    {
        $deliveries = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $frame = SignalDTO::fromJson($signal->toJson());
            self::assertInstanceOf(WebSocketSignalData::class, $frame->data);
            $acceptKey = $frame->data->targetAcceptKey;
            self::assertArrayNotHasKey($acceptKey, $deliveries);
            $deliveries[$acceptKey] = $frame;
        }
        self::assertCount(3, $deliveries);

        self::assertArrayHasKey(self::ADMIN_KEY, $deliveries);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $deliveries[self::ADMIN_KEY]->signalName->getName());
        $response = $deliveries[self::ADMIN_KEY]->data->data;
        self::assertInstanceOf(PageResponseSignalData::class, $response);
        self::assertSame(AdminProductPage::PAGE, $response->pageKey);
        self::assertSame(self::stockField(1, 1, $this->odesa), $this->cardOf($response)[AdminProductBrowserData::FIELD_STOCK]);
        foreach ([
            [self::VISITOR_KEY, $this->lviv, 1, 12],
            [self::WAREHOUSE_WINDOW_KEY, $this->odesa, 1, 1],
        ] as [$acceptKey, $warehouseId, $products, $units]) {
            self::assertArrayHasKey($acceptKey, $deliveries);
            self::assertSame(SignalTypeConstants::TABLE_VIEWPORT_DELTA, $deliveries[$acceptKey]->signalName->getName());
            $delta = $deliveries[$acceptKey]->data->data;
            self::assertInstanceOf(TableViewportDeltaDTO::class, $delta);
            self::assertSame(AdminWarehousesPage::PAGE, $delta->page);
            self::assertSame(EcommerceShopTableContext::warehouses, $delta->tableKey);
            self::assertSame(TableViewportDeltaDTO::KIND_ROW_UPDATED, $delta->kind);
            self::assertNotNull($delta->row);
            self::assertWarehouseCounts($delta->row, $warehouseId, $products, $units);
        }
    }

    /**
     * @param array<string, mixed> $row Row received in a window or delta
     * @param int $warehouseId Warehouse the row names
     * @param int $products Number of distinct products
     * @param int $units Number of units
     */
    private static function assertWarehouseCounts(array $row, int $warehouseId, int $products, int $units): void
    {
        self::assertSame((string)$warehouseId, (string)$row[PagePayload::rowKey]);
        $slot = $row[PagePayload::slots][WarehousesTable::ROW_SLOT];
        self::assertSame($warehouseId, $slot[WarehouseTableRow::id]);
        self::assertSame($products, $slot[WarehouseTableRow::productCount]);
        self::assertSame($units, $slot[WarehouseTableRow::unitCount]);
    }

    /**
     * @param int $productId Product whose persisted stock to read
     * @return list<array<string, mixed>> Stock rows of the product
     */
    private static function storedStock(int $productId): array
    {
        return Database::sql(
            'SELECT id, warehouse_id, units FROM product_warehouse_stock WHERE product_id = ? ORDER BY id',
            [$productId],
        )->rows();
    }

    /**
     * @param int $units Units in all warehouses
     * @param int $warehouses Warehouses keeping a unit
     * @param ?int $warehouseId Warehouse of a placed unique piece
     * @return array<string, int|null> The stock field of the card
     */
    private static function stockField(int $units, int $warehouses, ?int $warehouseId): array
    {
        return [
            AdminProductBrowserData::STOCK_UNITS => $units,
            AdminProductBrowserData::STOCK_WAREHOUSES => $warehouses,
            AdminProductBrowserData::STOCK_WAREHOUSE_ID => $warehouseId,
        ];
    }

    /**
     * Writes a person past the users library and drops a verdict an earlier case of this process may have
     * remembered for the same id.
     *
     * @param string $name Name of the person
     * @param bool $admin Whether the person is an administrator
     * @return int Id of the new person
     */
    private static function person(string $name, bool $admin): int
    {
        Database::sql('INSERT INTO hilos_user (name, admin, block) VALUES (?, ?, 0)', [$name, (int)$admin]);
        $userId = Database::lastInsertId();
        AccountStandingResolver::forget($userId);

        return $userId;
    }

    /**
     * Subscribes one connection to the page of a product and reads the one page answer.
     *
     * @param string $acceptKey Subscribing connection
     * @param int $productId Product the page is opened for
     * @return PageResponseSignalData The one page answer
     */
    private function subscribe(string $acceptKey, int $productId): PageResponseSignalData
    {
        return $this->subscribeTo($acceptKey, (string)$productId);
    }

    /**
     * Subscribes one connection to the page under a raw address and reads the one page answer.
     *
     * @param string $acceptKey Subscribing connection
     * @param string $productId Raw product id route param
     * @return PageResponseSignalData The one page answer
     */
    private function subscribeTo(string $acceptKey, string $productId): PageResponseSignalData
    {
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
        $routeParams = ['productId' => $productId];
        Hilos::$sr->subscribeToPage(
            AdminProductPage::PAGE,
            new WebSocketPageSubscribeSignalDTO($acceptKey, AdminProductPage::PAGE, $routeParams),
        );
        ExecutionContext::run(new ExecutionFrame(acceptKey: $acceptKey), static function () use ($acceptKey, $routeParams): void {
            new AdminProductPage(new ProductsLibraryAgent())->onSubscribe($acceptKey, new PageRouteParams($routeParams));
        });

        $responses = $this->drainResponses($acceptKey);
        self::assertCount(1, $responses);

        return $responses[0];
    }

    /**
     * @param string $acceptKey Connection the answers are addressed to
     * @return list<PageResponseSignalData> Page answers queued for the connection since the last drain
     */
    private function drainResponses(string $acceptKey): array
    {
        $responses = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if (
                $signal->signalName->getName() !== SignalTypeConstants::PAGE_RESPONSE
                || !$signal->data instanceof WebSocketSignalData
                || $signal->data->targetAcceptKey !== $acceptKey
                || !$signal->data->data instanceof PageResponseSignalData
            ) {
                continue;
            }
            self::assertSame(AdminProductPage::PAGE, $signal->data->data->pageKey);
            $responses[] = $signal->data->data;
        }

        return $responses;
    }

    /**
     * @param string $acceptKey Connection the answers are addressed to
     * @return list<array<string, mixed>> Cards of the live answers queued for the connection
     */
    private function sentCards(string $acceptKey): array
    {
        $cards = [];
        foreach ($this->drainResponses($acceptKey) as $response) {
            $payload = $response->payload->toArray();
            if (isset($payload[PagePayload::data][AdminProductBrowserData::DATA])) {
                $cards[] = $payload[PagePayload::data][AdminProductBrowserData::DATA];
            }
        }

        return $cards;
    }

    /**
     * @param string $acceptKey Connection the answers are addressed to
     * @param string $list List key to read
     * @return list<list<array<string, mixed>>> Items of the list, one entry per live answer carrying it
     */
    private function sentListItems(string $acceptKey, string $list): array
    {
        $items = [];
        foreach ($this->drainResponses($acceptKey) as $response) {
            $payload = $response->payload->toArray();
            if (isset($payload[PagePayload::lists][$list])) {
                $items[] = $payload[PagePayload::lists][$list][PagePayload::items];
            }
        }

        return $items;
    }

    /**
     * @param PageResponseSignalData $response A page answer
     * @return array<string, mixed> The card it carries
     */
    private function cardOf(PageResponseSignalData $response): array
    {
        return $response->payload->toArray()[PagePayload::data][AdminProductBrowserData::DATA];
    }

    /** @return string Version of the initial bulbs fixture from its stored catalog fields */
    private function bulbsEditVersion(): string
    {
        return hash('sha256', json_encode([
            $this->bulbs, $this->bulbsName, $this->categoryId, ProductKind::STOCK->value, 41000,
            'Product of the case', $this->pictureFileOf($this->bulbs), 1795132800123,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param int $productId Product id
     * @return int Picture file of the stored product
     */
    private function pictureFileOf(int $productId): int
    {
        return (int)Database::sql('SELECT picture_file_id FROM product WHERE id = ?', [$productId])->field('picture_file_id');
    }

    /**
     * @param int $warehouseId Warehouse id
     * @return string Name of the stored warehouse
     */
    private static function warehouseName(int $warehouseId): string
    {
        return (string)Database::sql('SELECT name FROM warehouse WHERE id = ?', [$warehouseId])->field('name');
    }

    /**
     * @param int $productId Product id
     * @param int $warehouseId Warehouse id
     * @return int Id of the stock row of the product in the warehouse
     */
    private static function stockRowId(int $productId, int $warehouseId): int
    {
        return (int)Database::sql(
            'SELECT id FROM product_warehouse_stock WHERE product_id = ? AND warehouse_id = ?',
            [$productId, $warehouseId],
        )->field('id');
    }

    /**
     * @param int $productId Product id
     * @param int $warehouseId Warehouse id
     * @return array<string, int> The stored stock row, in the keys its Object announces it in
     */
    private static function announcedStock(int $productId, int $warehouseId): array
    {
        $stored = Database::sql(
            'SELECT * FROM product_warehouse_stock WHERE product_id = ? AND warehouse_id = ?',
            [$productId, $warehouseId],
        )->rows()[0];

        return [
            ObjectProductWarehouseStock::id => (int)$stored[EntityProductWarehouseStock::id],
            ObjectProductWarehouseStock::productId => (int)$stored[EntityProductWarehouseStock::product_id],
            ObjectProductWarehouseStock::warehouseId => (int)$stored[EntityProductWarehouseStock::warehouse_id],
            ObjectProductWarehouseStock::units => (int)$stored[EntityProductWarehouseStock::units],
        ];
    }

    /**
     * Applies a row written past the ORM to what this process holds, as a worker does, then hands its fact to the browser.
     *
     * @param string $collectionKey Collection of the row
     * @param string $table Table of the row
     * @param int $id Id of the row
     */
    private static function deliverUpdate(string $collectionKey, string $table, int $id): void
    {
        $row = Database::sql("SELECT * FROM {$table} WHERE id = ?", [$id])->rows()[0];
        DbSyncApplicator::applyUpdated(new DbSyncUpdatedSignalData($collectionKey, (string)$id, $row));
        self::deliver(SourceChange::dbUpdated($collectionKey, (string)$id, $row));
    }

    /**
     * Hands one fact to the browser and flushes it.
     *
     * @param SourceChange $change Fact of a source
     */
    private static function deliver(SourceChange $change): void
    {
        Hilos::$browser->record($change);
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
    }

    /**
     * @param list<array<string, mixed>> $items List items
     * @param int $id Expected item key
     * @param string $slot Source slot to read
     * @return array<string, mixed> Fields of the matching slot
     */
    private static function slotFor(array $items, int $id, string $slot): array
    {
        foreach ($items as $item) {
            if ((string)$item[PagePayload::itemKey] === (string)$id) {
                return $item[PagePayload::slots][$slot];
            }
        }
        self::fail("No {$slot} item for {$id}");
    }
}
