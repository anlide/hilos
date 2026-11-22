<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\ProductsLibraryAgent;
use Demo\EcommerceShop\Browser\Data\AdminProductBrowserData;
use Demo\EcommerceShop\Browser\EcommerceShopBrowserList;
use Demo\EcommerceShop\Constants\PageConstants;
use Demo\EcommerceShop\Constants\ProductKind;
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
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\PageForbiddenException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Core\Page\PageErrorMessages;
use Hilos\Core\Page\PageResender;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Database\DbSyncApplicator;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Files\HilosFiles;
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
        });
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

        Database::sql('UPDATE product SET name = ?, price = ? WHERE id = ?', ['Renamed bulbs', 52000, $this->bulbs]);
        self::deliverUpdate(EcommerceShopDbContext::products, 'product', $this->bulbs);
        $cards = $this->sentCards(self::ADMIN_KEY);
        self::assertCount(1, $cards);
        self::assertSame('Renamed bulbs', $cards[0]['name']);
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
