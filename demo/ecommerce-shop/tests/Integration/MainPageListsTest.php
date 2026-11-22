<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Browser\EcommerceShopBrowserList;
use Demo\EcommerceShop\Browser\List\MainCategoriesBrowserList;
use Demo\EcommerceShop\Browser\List\MainProductsBrowserList;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Core\Router\EcommerceShopSignalRouter;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Category as EntityCategory;
use Demo\EcommerceShop\Database\Entity\Item\Product as EntityProduct;
use Demo\EcommerceShop\Database\Entity\Item\ProductWarehouseStock as EntityProductWarehouseStock;
use Demo\EcommerceShop\Database\Object\Item\Category as ObjectCategory;
use Demo\EcommerceShop\Database\Object\Item\Product as ObjectProduct;
use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock as ObjectProductWarehouseStock;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\MainPage;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\DbSyncApplicator;
use Hilos\Files\HilosFiles;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Testing\IntegrationTest;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\AccountStandingResolver;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Stands in for the home's process, the shop's own agent, which holds neither the catalog nor the stock:
 * forgets a holding of the catalog an earlier library of this process left, mounts the browser and the
 * shop's router, and claims the connections register the page reads a subscriber from.
 *
 * The runtime writes run outside any agent, as the daemon's do.
 */
abstract class MainPageEnvironment extends ProductStockTableEnvironment
{
    private const string TEST_AGENT = 'main-page-lists-test';

    private ?SignalRouter $previousRouter = null;
    private bool $claimed = false;

    /** Mounts the browser, router and connections claim over the stock environment beneath. */
    protected function prepareIntegrationEnvironment(): void
    {
        $this->previousRouter = Hilos::$sr;
        parent::prepareIntegrationEnvironment();
        Hilos::$db->forgetHoldingForTest(EcommerceShopDbContext::categories, EcommerceShopDbContext::products);
        Hilos::initBrowser();
        Hilos::$sr = new EcommerceShopSignalRouter();
        RtTruthSourceRegistry::register(EcommerceShopRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT);
        $this->claimed = true;
        self::unattended(static function (): void {
            Hilos::$rt->connections->actions->clear();
        });
    }

    /** Forgets the connections if they were claimed, restores the router and the browser, then releases beneath. */
    protected function releaseIntegrationEnvironment(): void
    {
        try {
            if ($this->claimed) {
                $this->claimed = false;
                self::unattended(static function (): void {
                    Hilos::$rt->connections->actions->clear();
                });
                RtTruthSourceRegistry::unregisterAgent(self::TEST_AGENT);
            }
            Hilos::$sr = $this->previousRouter;
            Hilos::initBrowser();
        } finally {
            parent::releaseIntegrationEnvironment();
        }
    }

    /**
     * Runs a runtime write outside any agent, the way the daemon writes.
     *
     * @param callable(): void $write The write
     */
    protected static function unattended(callable $write): void
    {
        ExecutionContext::run(new ExecutionFrame(), $write);
    }
}

/**
 * The two lists of the shop's home over the real schema: the categories as tiles with their tile
 * picture, and the products with their category and whether they are on the shop front; the first
 * answer, and the items a fact of a category, a product or a stock row creates, redraws or removes.
 *
 * The home is served by the shop's own agent, which holds neither the catalog nor the stock: the
 * catalog's rows are written straight into their tables, as another process writes them, and each
 * fact is handed to the browser in the shape the ORM announces it, after the sync a worker applies
 * to the rows it holds. The rollback scope proves reads of written rows, not commit.
 */
#[IntegrationTest]
final class MainPageListsTest extends MainPageEnvironment
{
    private const string GUEST_KEY = 'main-page-guest';
    private const string VISITOR_KEY = 'main-page-visitor';

    /** On an empty catalog, a guest and a signed-in person connected. */
    protected function setUpInTransaction(): void
    {
        parent::setUpInTransaction();
        $visitorId = self::person();
        self::unattended(static function () use ($visitorId): void {
            Hilos::$rt->connections->actions->register(self::GUEST_KEY, null);
            Hilos::$rt->connections->actions->register(self::VISITOR_KEY, $visitorId);
        });
    }

    /**
     * Every category comes with its fields and its tile picture; every product with its category and
     * whether it is on the front: a stock item without stock is, a unique piece is only once it is in a warehouse.
     */
    public function testTheFirstAnswerCarriesEveryCategoryAndEveryProductWithItsShopFront(): void
    {
        $roses = self::category();
        $tulips = self::category();
        $bulk = self::productIn($roses, ProductKind::STOCK);
        $unplaced = self::productIn($roses, ProductKind::UNIQUE);
        $placed = self::productIn($tulips, ProductKind::UNIQUE);
        self::stockInAWarehouseOfItsOwn($placed, 1);

        $lists = $this->snapshot(self::VISITOR_KEY);

        self::assertSame([
            $roses => [EcommerceShopDbContext::categories => self::categorySlot($roses)],
            $tulips => [EcommerceShopDbContext::categories => self::categorySlot($tulips)],
        ], self::itemsByKey($lists[EcommerceShopBrowserList::MAIN_CATEGORIES][PagePayload::items]));
        self::assertStringContainsString(
            CatalogPictureUploadTarget::VARIANT_TILE,
            self::categorySlot($roses)[MainCategoriesBrowserList::TILE_PICTURE],
        );
        self::assertSame([
            $bulk => [EcommerceShopDbContext::products => self::productSlot($bulk, $roses, true)],
            $unplaced => [EcommerceShopDbContext::products => self::productSlot($unplaced, $roses, false)],
            $placed => [
                EcommerceShopDbContext::products => self::productSlot($placed, $tulips, true),
                EcommerceShopDbContext::productWarehouseStocks => [ObjectProductWarehouseStock::productId => $placed],
            ],
        ], self::itemsByKey($lists[EcommerceShopBrowserList::MAIN_PRODUCTS][PagePayload::items]));
    }

    /** A guest, with nobody on the connection, is sent the same first answer as a signed-in person. */
    public function testAGuestIsSentTheSameFirstAnswer(): void
    {
        $roses = self::category();
        self::productIn($roses, ProductKind::STOCK);
        self::stockInAWarehouseOfItsOwn(self::productIn($roses, ProductKind::UNIQUE), 1);

        self::assertSame($this->snapshot(self::VISITOR_KEY), $this->snapshot(self::GUEST_KEY));
    }

    /** A created category enters the categories list as an item of its own. */
    public function testACreatedCategoryEntersTheList(): void
    {
        $this->subscribe();
        $roses = self::category();

        $this->deliver(SourceChange::dbCreated(EcommerceShopDbContext::categories, (string)$roses, self::announcedCategory($roses)));

        $sent = $this->sentLists();
        self::assertSame(
            [$roses => [EcommerceShopDbContext::categories => self::categorySlot($roses)]],
            self::itemsByKey($sent[EcommerceShopBrowserList::MAIN_CATEGORIES][PagePayload::items]),
        );
        self::assertArrayNotHasKey(EcommerceShopBrowserList::MAIN_PRODUCTS, $sent);
    }

    /** A renamed category, its address following its name, is redrawn with both. */
    public function testARenamedCategoryIsRedrawnWithItsNameAndItsAddress(): void
    {
        $roses = self::category();
        $this->subscribe();
        $before = self::announcedCategory($roses);
        $name = 'Red roses ' . RandomHelper::hex(8);
        $slug = 'red-roses-' . RandomHelper::hex(8);

        Database::sql('UPDATE category SET name = ? WHERE id = ?', [$name, $roses]);
        $this->deliverUpdate(
            EcommerceShopDbContext::categories,
            $roses,
            [EntityCategory::name => $name],
            [EntityCategory::name => $before[ObjectCategory::name]],
        );
        Database::sql('UPDATE category SET slug = ? WHERE id = ?', [$slug, $roses]);
        $this->deliverUpdate(
            EcommerceShopDbContext::categories,
            $roses,
            [EntityCategory::slug => $slug],
            [EntityCategory::slug => $before[ObjectCategory::slug]],
        );

        $slots = self::itemsByKey($this->sentLists()[EcommerceShopBrowserList::MAIN_CATEGORIES][PagePayload::items]);
        self::assertSame([$roses => [EcommerceShopDbContext::categories => self::categorySlot($roses)]], $slots);
        self::assertSame($name, $slots[$roses][EcommerceShopDbContext::categories][ObjectCategory::name]);
        self::assertSame($slug, $slots[$roses][EcommerceShopDbContext::categories][ObjectCategory::slug]);
    }

    /** Two categories that swap places are both redrawn at their new positions. */
    public function testTwoCategoriesThatSwapPlacesAreBothRedrawnAtTheirNewPositions(): void
    {
        $roses = self::category();
        $tulips = self::category();
        $rosesPlace = self::storedPosition($roses);
        $tulipsPlace = self::storedPosition($tulips);
        $this->subscribe();

        Database::sql('UPDATE category SET position = ? WHERE id = ?', [$rosesPlace, $tulips]);
        Database::sql('UPDATE category SET position = ? WHERE id = ?', [$tulipsPlace, $roses]);
        $this->deliverUpdate(
            EcommerceShopDbContext::categories,
            $roses,
            [EntityCategory::position => $tulipsPlace],
            [EntityCategory::position => $rosesPlace],
        );
        $this->deliverUpdate(
            EcommerceShopDbContext::categories,
            $tulips,
            [EntityCategory::position => $rosesPlace],
            [EntityCategory::position => $tulipsPlace],
        );

        $slots = self::itemsByKey($this->sentLists()[EcommerceShopBrowserList::MAIN_CATEGORIES][PagePayload::items]);
        self::assertSame($tulipsPlace, $slots[$roses][EcommerceShopDbContext::categories][ObjectCategory::position]);
        self::assertSame($rosesPlace, $slots[$tulips][EcommerceShopDbContext::categories][ObjectCategory::position]);
    }

    /** A deleted category leaves the categories list by its key. */
    public function testADeletedCategoryLeavesTheList(): void
    {
        $gone = self::category();
        $this->subscribe();
        $announced = self::announcedCategory($gone);

        Database::sql('DELETE FROM category_slug WHERE category_id = ?', [$gone]);
        Database::sql('DELETE FROM category WHERE id = ?', [$gone]);
        DbSyncApplicator::applyDeleted(new DbSyncDeletedSignalData(EcommerceShopDbContext::categories, (string)$gone, $announced));
        $this->deliver(SourceChange::dbDeleted(EcommerceShopDbContext::categories, (string)$gone, $announced));

        self::assertSame([$gone], $this->sentLists()[EcommerceShopBrowserList::MAIN_CATEGORIES][PagePayload::deleted]);
    }

    /** A created product enters the products list with its category and its shop front. */
    public function testACreatedProductEntersTheList(): void
    {
        $roses = self::category();
        $this->subscribe();
        $bouquet = self::productIn($roses, ProductKind::STOCK);

        $this->deliver(SourceChange::dbCreated(EcommerceShopDbContext::products, (string)$bouquet, self::announcedProduct($bouquet)));

        $sent = $this->sentLists();
        self::assertSame(
            [$bouquet => [EcommerceShopDbContext::products => self::productSlot($bouquet, $roses, true)]],
            self::itemsByKey($sent[EcommerceShopBrowserList::MAIN_PRODUCTS][PagePayload::items]),
        );
        self::assertArrayNotHasKey(EcommerceShopBrowserList::MAIN_CATEGORIES, $sent);
    }

    /** A product moved to another category is redrawn with the new one. */
    public function testAProductMovedToAnotherCategoryIsRedrawnWithIt(): void
    {
        $roses = self::category();
        $tulips = self::category();
        $moved = self::productIn($roses, ProductKind::UNIQUE);
        $this->subscribe();

        Database::sql('UPDATE product SET category_id = ? WHERE id = ?', [$tulips, $moved]);
        $this->deliverUpdate(
            EcommerceShopDbContext::products,
            $moved,
            [EntityProduct::category_id => $tulips],
            [EntityProduct::category_id => $roses],
        );

        self::assertSame(
            [$moved => [EcommerceShopDbContext::products => self::productSlot($moved, $tulips, false)]],
            self::itemsByKey($this->sentLists()[EcommerceShopBrowserList::MAIN_PRODUCTS][PagePayload::items]),
        );
    }

    /** A product that only changed its name changes no tile, so the products list is sent nothing. */
    public function testARenamedProductSendsTheProductsListNothing(): void
    {
        $roses = self::category();
        $renamed = self::productIn($roses, ProductKind::STOCK);
        $this->subscribe();
        $name = 'Bouquet ' . RandomHelper::hex(8);
        $before = self::announcedProduct($renamed);

        Database::sql('UPDATE product SET name = ? WHERE id = ?', [$name, $renamed]);
        $this->deliverUpdate(
            EcommerceShopDbContext::products,
            $renamed,
            [EntityProduct::name => $name],
            [EntityProduct::name => $before[ObjectProduct::name]],
        );

        self::assertArrayNotHasKey(EcommerceShopBrowserList::MAIN_PRODUCTS, $this->sentLists());
    }

    /** A unique piece laid into a warehouse comes onto the front: its item is redrawn with the stock row beside it. */
    public function testAUniquePieceLaidIntoAWarehouseComesOntoTheFront(): void
    {
        $roses = self::category();
        $piece = self::productIn($roses, ProductKind::UNIQUE);
        $this->subscribe();

        $stockId = self::stockInAWarehouseOfItsOwn($piece, 1);
        $this->deliver(SourceChange::dbCreated(
            EcommerceShopDbContext::productWarehouseStocks,
            (string)$stockId,
            self::announcedStock($stockId),
        ));

        self::assertSame([
            $piece => [
                EcommerceShopDbContext::products => self::productSlot($piece, $roses, true),
                EcommerceShopDbContext::productWarehouseStocks => [ObjectProductWarehouseStock::productId => $piece],
            ],
        ], self::itemsByKey($this->sentLists()[EcommerceShopBrowserList::MAIN_PRODUCTS][PagePayload::items]));
    }

    /** A stock item whose number of units changed stays on the front, so the products list is sent nothing. */
    public function testANewNumberOfUnitsSendsTheProductsListNothing(): void
    {
        $roses = self::category();
        $bulk = self::productIn($roses, ProductKind::STOCK);
        $stockId = self::stockInAWarehouseOfItsOwn($bulk, 3);
        $this->subscribe();

        Database::sql('UPDATE product_warehouse_stock SET units = ? WHERE id = ?', [7, $stockId]);
        $this->deliverUpdate(
            EcommerceShopDbContext::productWarehouseStocks,
            $stockId,
            [EntityProductWarehouseStock::units => 7],
            [EntityProductWarehouseStock::units => 3],
        );

        self::assertArrayNotHasKey(EcommerceShopBrowserList::MAIN_PRODUCTS, $this->sentLists());
    }

    /**
     * Writes a person past the users library and drops a verdict an earlier case of this process may have
     * remembered for the same id.
     *
     * @return int Id of the new person
     */
    private static function person(): int
    {
        Database::sql('INSERT INTO hilos_user (name, admin, block) VALUES (?, 0, 0)', ['Home visitor ' . RandomHelper::hex(8)]);
        $userId = Database::lastInsertId();
        AccountStandingResolver::forget($userId);

        return $userId;
    }

    /**
     * Writes a product row of the given kind into a given category, past the products library.
     *
     * @param int $categoryId Category the product is put in
     * @param ProductKind $kind Kind of the product
     * @return int Id of the new product
     */
    private static function productIn(int $categoryId, ProductKind $kind): int
    {
        $slug = 'product-' . RandomHelper::hex(8);
        Database::sql(
            'INSERT INTO product (name, category_id, kind, price, description, picture_file_id, slug, changed_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$slug, $categoryId, $kind->value, 12_000, 'Product of the case', self::pictureFile(), $slug, 1000],
        );

        return Database::lastInsertId();
    }

    /**
     * Writes a stock row of a product in a warehouse of its own, past the stock library.
     *
     * @param int $productId Product kept
     * @param int $units Units kept
     * @return int Id of the new stock row
     */
    private static function stockInAWarehouseOfItsOwn(int $productId, int $units): int
    {
        Database::sql(
            'INSERT INTO product_warehouse_stock (product_id, warehouse_id, units) VALUES (?, ?, ?)',
            [$productId, self::warehouse(), $units],
        );

        return Database::lastInsertId();
    }

    /**
     * @param int $id Category id
     * @return int Position of the category as stored
     */
    private static function storedPosition(int $id): int
    {
        return (int)Database::sql('SELECT position FROM category WHERE id = ?', [$id])->field(EntityCategory::position);
    }

    /**
     * @param int $categoryId Category id
     * @return array<string, int|string> The stored category row, read past the ORM, in the keys its Object announces it in
     */
    private static function announcedCategory(int $categoryId): array
    {
        $stored = Database::sql('SELECT * FROM category WHERE id = ?', [$categoryId])->rows()[0];

        return [
            ObjectCategory::id => (int)$stored[EntityCategory::id],
            ObjectCategory::name => (string)$stored[EntityCategory::name],
            ObjectCategory::description => (string)$stored[EntityCategory::description],
            ObjectCategory::slug => (string)$stored[EntityCategory::slug],
            ObjectCategory::position => (int)$stored[EntityCategory::position],
            ObjectCategory::pictureFileId => (int)$stored[EntityCategory::picture_file_id],
        ];
    }

    /**
     * @param int $productId Product id
     * @return array<string, int|string> The stored product row, read past the ORM, in the keys its Object announces it in
     */
    private static function announcedProduct(int $productId): array
    {
        $stored = Database::sql('SELECT * FROM product WHERE id = ?', [$productId])->rows()[0];

        return [
            ObjectProduct::id => (int)$stored[EntityProduct::id],
            ObjectProduct::name => (string)$stored[EntityProduct::name],
            ObjectProduct::categoryId => (int)$stored[EntityProduct::category_id],
            ObjectProduct::kind => (string)$stored[EntityProduct::kind],
            ObjectProduct::price => (int)$stored[EntityProduct::price],
            ObjectProduct::description => (string)$stored[EntityProduct::description],
            ObjectProduct::pictureFileId => (int)$stored[EntityProduct::picture_file_id],
            ObjectProduct::slug => (string)$stored[EntityProduct::slug],
            ObjectProduct::changedAt => (int)$stored[EntityProduct::changed_at],
        ];
    }

    /**
     * @param int $stockId Stock row id
     * @return array<string, int> The stored stock row, read past the ORM, in the keys its Object announces it in
     */
    private static function announcedStock(int $stockId): array
    {
        $stored = Database::sql('SELECT * FROM product_warehouse_stock WHERE id = ?', [$stockId])->rows()[0];

        return [
            ObjectProductWarehouseStock::id => (int)$stored[EntityProductWarehouseStock::id],
            ObjectProductWarehouseStock::productId => (int)$stored[EntityProductWarehouseStock::product_id],
            ObjectProductWarehouseStock::warehouseId => (int)$stored[EntityProductWarehouseStock::warehouse_id],
            ObjectProductWarehouseStock::units => (int)$stored[EntityProductWarehouseStock::units],
        ];
    }

    /**
     * @param int $categoryId Category id
     * @return array<string, int|string> The categories slot the home is sent for the stored category
     */
    private static function categorySlot(int $categoryId): array
    {
        $stored = self::announcedCategory($categoryId);

        return [
            ObjectCategory::id => $stored[ObjectCategory::id],
            ObjectCategory::name => $stored[ObjectCategory::name],
            ObjectCategory::description => $stored[ObjectCategory::description],
            ObjectCategory::slug => $stored[ObjectCategory::slug],
            ObjectCategory::position => $stored[ObjectCategory::position],
            MainCategoriesBrowserList::TILE_PICTURE => HilosFiles::downloadPath(
                (int)$stored[ObjectCategory::pictureFileId],
                CatalogPictureUploadTarget::VARIANT_TILE,
            ),
        ];
    }

    /**
     * @param int $productId Product id
     * @param int $categoryId Category the product is expected in
     * @param bool $onShopFront Whether the product is expected on the shop front
     * @return array<string, int|bool> The products slot the home is sent for the product
     */
    private static function productSlot(int $productId, int $categoryId, bool $onShopFront): array
    {
        return [
            ObjectProduct::id => $productId,
            ObjectProduct::categoryId => $categoryId,
            MainProductsBrowserList::ON_SHOP_FRONT => $onShopFront,
        ];
    }

    /**
     * @param string $acceptKey Connection reading the home
     * @return array<string, mixed> Lists section of the home's first answer
     */
    private function snapshot(string $acceptKey): array
    {
        return Hilos::$browser->buildSubscribeSnapshot(MainPage::PAGE, $acceptKey, new PageRouteParams([]))
            ->toArray()[PagePayload::lists];
    }

    /** Subscribes the guest to the home and drops what the subscription queued. */
    private function subscribe(): void
    {
        Hilos::$sr->subscribeToPage(MainPage::PAGE, new WebSocketPageSubscribeSignalDTO(self::GUEST_KEY, MainPage::PAGE, []));
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
        }
    }

    /**
     * Applies an update to the rows this process holds, as a worker does, then hands its fact to the browser.
     *
     * @param string $collectionKey Collection of the row
     * @param int $id Id of the row
     * @param array<string, mixed> $row Changed columns
     * @param array<string, mixed> $previous Their values before
     */
    private function deliverUpdate(string $collectionKey, int $id, array $row, array $previous): void
    {
        DbSyncApplicator::applyUpdated(new DbSyncUpdatedSignalData($collectionKey, (string)$id, $row, previous: $previous));
        $this->deliver(SourceChange::dbUpdated($collectionKey, (string)$id, $row, previous: $previous));
    }

    /**
     * Hands one fact to the browser and flushes it.
     *
     * @param SourceChange $change Fact of a source
     */
    private function deliver(SourceChange $change): void
    {
        Hilos::$browser->record($change);
        self::assertSame([], Hilos::$browser->flushToSignalRouter());
    }

    /**
     * Reads every queued page update the guest was sent and merges its list sections by list.
     *
     * @return array<string, array<string, mixed>> List sections by list key
     */
    private function sentLists(): array
    {
        $lists = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if (
                $signal->signalName->getName() !== SignalTypeConstants::PAGE_RESPONSE
                || !$signal->data instanceof WebSocketSignalData
                || $signal->data->targetAcceptKey !== self::GUEST_KEY
                || !$signal->data->data instanceof PageResponseSignalData
            ) {
                continue;
            }
            self::assertSame(MainPage::PAGE, $signal->data->data->pageKey);
            foreach ($signal->data->data->payload->toArray()[PagePayload::lists] ?? [] as $listKey => $section) {
                $lists[$listKey] = array_merge_recursive($lists[$listKey] ?? [], $section);
            }
        }

        return $lists;
    }

    /**
     * @param list<array<string, mixed>> $items List items of a wire answer
     * @return array<int|string, array<string, mixed>> Slots of each item by its key
     */
    private static function itemsByKey(array $items): array
    {
        $slots = [];
        foreach ($items as $item) {
            $slots[$item[PagePayload::itemKey]] = $item[PagePayload::slots];
        }

        return $slots;
    }
}
