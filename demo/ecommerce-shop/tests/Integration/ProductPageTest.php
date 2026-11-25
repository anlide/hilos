<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Closure;
use Demo\EcommerceShop\Agents\GuestCartsLibraryAgent;
use Demo\EcommerceShop\Agents\Hilos\UserAgent;
use Demo\EcommerceShop\Agents\ProductAgent;
use Demo\EcommerceShop\Agents\ProductsLibraryAgent;
use Demo\EcommerceShop\Browser\Data\AdminProductBrowserData;
use Demo\EcommerceShop\Browser\EcommerceShopBrowserList;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Actions\CatalogSlug;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\DTO\ProductStockTotalsCollection;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Category as EntityCategory;
use Demo\EcommerceShop\Database\Entity\Item\Product as EntityProduct;
use Demo\EcommerceShop\Database\Entity\Item\ProductSlug as EntityProductSlug;
use Demo\EcommerceShop\Database\Entity\Item\ProductWarehouseStock as EntityProductWarehouseStock;
use Demo\EcommerceShop\Database\Entity\Item\SessionProductCart as EntitySessionProductCart;
use Demo\EcommerceShop\Database\Entity\Item\UserProductCart as EntityUserProductCart;
use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock as ObjectProductWarehouseStock;
use Demo\EcommerceShop\Database\View\Collection\Products;
use Demo\EcommerceShop\Database\View\Collection\ProductSlugs;
use Demo\EcommerceShop\Database\View\Collection\ProductWarehouseStocks;
use Demo\EcommerceShop\Database\View\Item\Product;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\AdminProductPage;
use Demo\EcommerceShop\Pages\AdminProductStockPage;
use Demo\EcommerceShop\Pages\DTO\AdminProductPageSubscribeParams;
use Demo\EcommerceShop\Pages\DTO\ProductPageSubscribeParams;
use Demo\EcommerceShop\Pages\ProductPage;
use Demo\EcommerceShop\Runtime\State\Item\ProductViewerCount;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Demo\EcommerceShop\ShopFront\ProductPageAddresses;
use Demo\EcommerceShop\Tables\ProductStock\ProductStockTable;
use Generator;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PageMovedSignalData;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\PageErrorMessages;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\Context\DbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Item\DbItem;
use Hilos\Files\HilosFiles;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketTableRowFocusSignalDTO;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Testing\IntegrationTest;
use PHPUnit\Framework\Attributes\TestWith;
use ReflectionProperty;

/** Mounts a real worker and two identified connections over the catalog's rollback scope. */
abstract class ProductPageEnvironment extends StockLibraryEnvironment
{
    use ProductPageWorkerFixture;

    /** Mounts the worker after the stock library has claimed its collections. */
    protected function prepareIntegrationEnvironment(): void
    {
        parent::prepareIntegrationEnvironment();
        $this->mountProductPageWorker();
    }

    /** Releases the worker before restoring the stock library environment. */
    protected function releaseIntegrationEnvironment(): void
    {
        try {
            $this->releaseProductPageWorker();
        } finally {
            parent::releaseIntegrationEnvironment();
        }
    }

    /**
     * Starts the agent through the worker so the reader is registered from its declaration.
     *
     * @param Product $product Product address served by the agent
     */
    protected function startProductReader(Product $product): void
    {
        $agentId = new ProductAgent($product->slug)->getId();
        $this->deliver(new AgentStartDTO($agentId));
        foreach ([EcommerceShopDbContext::sessionProductCarts, EcommerceShopDbContext::userProductCarts] as $collection) {
            self::assertContains(SourceConsumer::agent($agentId), SourceInterestRegistry::consumersOf(SourceChange::KIND_DB, $collection));
        }
    }

    /**
     * @param int $productId Product whose cart reading fails
     * @param WorkerDTO ...$messages Applied facts handled before one flush
     */
    protected function deliverWithCartCountFailure(int $productId, WorkerDTO ...$messages): void
    {
        $this->observeCartCountReads(fn () => $this->deliver(...$messages), $productId);
    }

    /**
     * Flushes the database recovery fact WorkerManager records after the first successful query.
     *
     * @param WorkerDTO ...$messages Earlier work in the same flush
     */
    protected function deliverDatabaseRecovery(WorkerDTO ...$messages): void
    {
        $this->flushReaderAfter(function () use ($messages): void {
            foreach ($messages as $message) {
                $this->worker->handleDaemonMessage($message);
            }
            Hilos::$browser->recordDatabaseBack();
        });
    }

    /** @param int $id Product key whose presence is checked without loading it */
    protected function readerHoldsProduct(int $id): bool
    {
        return isset($this->reader->mountedObjectCollection(EcommerceShopDbContext::products)[$id]);
    }

    /** @param int $id Stock row key whose presence is checked without loading it */
    protected function readerHoldsStock(int $id): bool
    {
        return isset($this->reader->mountedObjectCollection(EcommerceShopDbContext::productWarehouseStocks)[$id]);
    }

    /**
     * Fails one stock read and restores the real View before a later attempt can use it.
     *
     * @param WorkerDbSyncUpdatedMessageDTO $message Applied stock update
     * @param bool $duringAddressLookup Whether to fail before the stock key can identify its product
     * @param bool $structured Whether the page produces a structured subscription error
     * @param int $containedFailures Failures caught by the browser publisher outside the page router
     */
    protected function deliverWithStockReadFailure(
        WorkerDbSyncUpdatedMessageDTO $message,
        bool $duringAddressLookup = false,
        bool $structured = false,
        int $containedFailures = 0,
    ): void {
        $views = new ReflectionProperty(DbContext::class, '_dbItemCollections');
        $original = $views->getValue($this->reader);
        $fail = function () use ($views, $original, $structured): never {
            $views->setValue($this->reader, $original);
            if ($structured) {
                throw new PageInternalErrorException('Stock read failed while building the page answer');
            }
            throw new DatabaseException('Stock read failed during live delivery');
        };
        $failingView = new class ($original[EcommerceShopDbContext::productWarehouseStocks], $fail, $duringAddressLookup) extends DbCollection {
            public function __construct(
                private readonly ProductWarehouseStocks $original,
                private readonly Closure $fail,
                private readonly bool $duringAddressLookup,
            ) {
            }

            public function productIdAtStockKey(int $stockId): ?int
            {
                if ($this->duringAddressLookup) {
                    ($this->fail)();
                }
                return $this->original->productIdAtStockKey($stockId);
            }

            public function storedUnitsOfProduct(int $productId): int
            {
                ($this->fail)();
            }

            public function totalsOfProducts(int ...$productIds): ProductStockTotalsCollection
            {
                ($this->fail)();
            }
        };
        $views->setValue($this->reader, array_replace($original, [EcommerceShopDbContext::productWarehouseStocks => $failingView]));
        try {
            $this->flushReaderAfter(fn () => $this->worker->handleDaemonMessage($message), $containedFailures);
            self::assertSame($original, $views->getValue($this->reader));
        } finally {
            $views->setValue($this->reader, $original);
        }
    }

    /**
     * Fails the card's keyed product lookup as the worker handles a category fact.
     *
     * @param WorkerDbSyncUpdatedMessageDTO $message Applied catalog update
     * @param ?Closure(): void $beforeFailure Change that takes place during the keyed read
     */
    protected function deliverWithProductLookupFailure(WorkerDbSyncUpdatedMessageDTO $message, ?Closure $beforeFailure = null): void
    {
        $views = new ReflectionProperty(DbContext::class, '_dbItemCollections');
        $original = $views->getValue($this->reader);
        $failingView = new class ($beforeFailure) extends DbCollection {
            public function __construct(private readonly ?Closure $beforeFailure)
            {
            }

            /** @return Generator<int, Product> Fixture rows, if the read succeeds */
            public function storedProducts(string $column, int|string ...$values): Generator
            {
                $this->beforeFailure?->__invoke();
                throw new DatabaseException('Product lookup failed during catalog fan-out');
            }
        };
        $views->setValue($this->reader, array_replace($original, [EcommerceShopDbContext::products => $failingView]));
        try {
            $this->deliver($message);
        } finally {
            $views->setValue($this->reader, $original);
        }
    }


}

/**
 * The shop worker answers the storefront's product route and applies catalog facts to the
 * reader's ORM context before its product agents re-answer local subscriptions.
 */
#[IntegrationTest]
final class ProductPageTest extends ProductPageEnvironment
{
    private int $warehouse;
    private Product $stock;
    private Product $other;
    private Product $unplaced;

    /** The complete reply counts saved guest and account lines, independent of their quantities and other products. */
    public function testFullProductReplyCountsOnlyCartsContainingTheProduct(): void
    {
        $productId = $this->stock->id;
        $otherId = $this->other->id;
        Hilos::$db->productWarehouseStocks->actions->setUnits($productId, $this->warehouse, 8);
        Hilos::$db->productWarehouseStocks->actions->setUnits($otherId, $this->warehouse, 8);
        $otherSessionId = self::createGuestCartSession();
        self::asFixtureOwner(new GuestCartsLibraryAgent(), function () use ($productId, $otherId, $otherSessionId): void {
            Hilos::$db->sessionProductCarts->actions->add($this->guestCartSessionId, 0, $productId);
            Hilos::$db->sessionProductCarts->actions->setQuantity($this->guestCartSessionId, 0, $productId, 3);
            Hilos::$db->sessionProductCarts->actions->add($otherSessionId, 0, $otherId);
        });
        $userId = Hilos::$rt->connections[self::VISITOR]->userId;
        self::assertNotNull($userId);
        self::asFixtureOwner(new UserAgent((string) $userId), static function () use ($userId, $productId): void {
            Hilos::$db->userProductCarts->actions->add($userId, $productId);
            Hilos::$db->userProductCarts->actions->setQuantity($userId, $productId, 4);
        });

        $this->startProductReader($this->stock);
        $this->subscribe(self::GUEST, $this->stock->slug);
        $response = $this->takeFrame(self::GUEST)->data;
        self::assertInstanceOf(PageResponseSignalData::class, $response);
        self::assertSame(ProductPage::PAGE, $response->pageKey);
        self::assertArrayHasKey(ProductPage::DATA_PRODUCT, $response->payload->data);
        self::assertSame([
            'productId' => $productId,
            'slug' => $this->stock->slug,
            'cartCount' => 2,
        ], $response->payload->data['productCarts']);
    }

    /** Only membership changes re-send the count, and a move takes precedence over a late cart fact. */
    public function testCartMembershipFactsSendOnlyTheCurrentProductCount(): void
    {
        $productId = $this->stock->id;
        $slug = $this->stock->slug;
        Hilos::$db->productWarehouseStocks->actions->setUnits($productId, $this->warehouse, 8);
        $this->subscribe(self::GUEST, $slug);
        $this->takeCard(self::GUEST);
        $this->subscribe(self::VISITOR, $this->other->slug);
        $this->takeCard(self::VISITOR);

        $created = $this->addGuestCartLine($productId);
        $row = $created->signalData->row;
        $lineId = $created->signalData->idString;
        $key = EcommerceShopDbContext::sessionProductCarts;

        $this->deliver($created, $created);
        $this->assertCartCountFrame($productId, $slug, 1);

        self::asFixtureOwner(
            new GuestCartsLibraryAgent(),
            fn () => Hilos::$db->sessionProductCarts->actions->setQuantity($this->guestCartSessionId, 0, $productId, 3),
        );
        $this->deliver(new WorkerDbSyncUpdatedMessageDTO(
            new DbSyncUpdatedSignalData($key, $lineId, [EntitySessionProductCart::quantity => 3]),
        ));
        self::assertSame([], $this->takeFrames());

        self::asFixtureOwner(
            new GuestCartsLibraryAgent(),
            fn () => Hilos::$db->sessionProductCarts->actions->remove($this->guestCartSessionId, 0, $productId),
        );
        $row[EntitySessionProductCart::quantity] = 3;
        $deleted = new WorkerDbSyncDeletedMessageDTO(new DbSyncDeletedSignalData($key, $lineId, $row));
        $this->deliver($deleted);
        $this->assertCartCountFrame($productId, $slug, 0);

        $this->deliver($deleted, self::productPriceFact($productId, 15_500));
        $full = $this->takeFrame(self::GUEST)->data;
        self::assertInstanceOf(PageResponseSignalData::class, $full);
        self::assertSame(15_500, $full->payload->data[ProductPage::DATA_PRODUCT]['price']);
        self::assertSame(0, $full->payload->data['productCarts']['cartCount']);

        $otherId = $this->other->id;
        Hilos::$db->productWarehouseStocks->actions->setUnits($otherId, $this->warehouse, 8);
        $this->deliverWithCartCountFailure(
            $productId,
            $deleted,
            $this->addGuestCartLine($otherId),
        );
        $frames = $this->takeFrames();
        self::assertCount(2, $frames);
        $byConnection = array_column($frames, 'data', 'targetAcceptKey');
        $refusal = $byConnection[self::GUEST];
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $refusal);
        self::assertSame(HttpConstants::HTTP_INTERNAL_ERROR, $refusal->httpCode);
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
        $otherResponse = $byConnection[self::VISITOR];
        self::assertInstanceOf(PageResponseSignalData::class, $otherResponse);
        self::assertSame(['productCarts' => [
            'productId' => $otherId,
            'slug' => $this->other->slug,
            'cartCount' => 1,
        ]], $otherResponse->payload->data);

        $this->deliver($deleted);
        $recovered = $this->takeFrame(self::GUEST)->data;
        self::assertInstanceOf(PageResponseSignalData::class, $recovered);
        self::assertArrayHasKey(ProductPage::DATA_PRODUCT, $recovered->payload->data);
        self::assertSame(0, $recovered->payload->data['productCarts']['cartCount']);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));

        $newSlug = 'cart-count-new-address';
        Database::sql('UPDATE product SET slug = ? WHERE id = ?', [$newSlug, $productId]);
        $this->deliver($deleted, new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
            EcommerceShopDbContext::products,
            (string) $productId,
            [EntityProduct::slug => $newSlug],
            previous: [EntityProduct::slug => $slug],
        )));
        $this->assertMove($slug, $newSlug);
        self::assertNull(Hilos::$sr->pageResource(self::GUEST));
    }

    /** A cart fact retries only the matching storefront address after the first count failed. */
    public function testCartFactRecoversTheFirstStorefrontAnswer(): void
    {
        foreach ([self::GUEST => $this->stock, self::VISITOR => $this->other] as $acceptKey => $product) {
            Hilos::$db->productWarehouseStocks->actions->setUnits($product->id, $this->warehouse, 8);
            $this->observeCartCountReads(fn () => $this->subscribe($acceptKey, $product->slug), $product->id);
            $this->takeRefusal($acceptKey, HttpConstants::HTTP_INTERNAL_ERROR);
            self::assertNull(Hilos::$sr->pageResource($acceptKey));
            self::assertTrue(Hilos::$sr->hasPageDeliveryFailure($acceptKey));
        }

        $productId = $this->stock->id;
        $this->deliver($this->addGuestCartLine($productId));
        $response = $this->takeFrame(self::GUEST)->data;
        self::assertInstanceOf(PageResponseSignalData::class, $response);
        self::assertSame(ProductPage::PAGE, $response->pageKey);
        self::assertArrayHasKey(ProductPage::DATA_PRODUCT, $response->payload->data);
        self::assertSame([
            'productId' => $productId,
            'slug' => $this->stock->slug,
            'cartCount' => 1,
        ], $response->payload->data[ProductPage::DATA_PRODUCT_CARTS]);
        self::assertSame((string) $productId, Hilos::$sr->pageResource(self::GUEST)?->idString);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::VISITOR));
    }

    /**
     * A cart fact can recover a former address before the reader applies the rename.
     *
     * @param TableMutationType $mutation Cart membership fact preceding the rename
     */
    #[TestWith([TableMutationType::Create])]
    #[TestWith([TableMutationType::Delete])]
    #[TestWith([TableMutationType::Clear])]
    public function testCartFactMovesInitiallyRefusedFormerAddress(TableMutationType $mutation): void
    {
        $productId = $this->stock->id;
        $oldSlug = $this->stock->slug;
        Hilos::$db->productWarehouseStocks->actions->setUnits($productId, $this->warehouse, 8);
        Hilos::$db->productWarehouseStocks->actions->setUnits($this->other->id, $this->warehouse, 8);
        $this->warmProduct($productId);
        $this->observeCartCountReads(fn () => $this->subscribe(self::GUEST, $oldSlug), $productId);
        $this->takeRefusal(self::GUEST, HttpConstants::HTTP_INTERNAL_ERROR);
        self::assertNull(Hilos::$sr->pageResource(self::GUEST));
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));

        $rename = $this->renameProduct($this->stock);
        $newSlug = $this->stock->slug;
        self::assertSame($oldSlug, $this->warmProduct($productId)->slug);
        self::assertSame($newSlug, Database::sql('SELECT slug FROM product WHERE id = ?', [$productId])->field('slug'));
        $this->deliver($this->addGuestCartLine($this->other->id));
        self::assertSame([], $this->takeFrames());

        $reads = $this->observeCartCountReads(function () use ($mutation, $productId): void {
            switch ($mutation) {
                case TableMutationType::Clear:
                    Hilos::$browser->record(SourceChange::dbCleared(EcommerceShopDbContext::userProductCarts));
                    $this->deliver();
                    break;
                case TableMutationType::Delete:
                    $this->deliver(new WorkerDbSyncDeletedMessageDTO(new DbSyncDeletedSignalData(
                        EcommerceShopDbContext::userProductCarts,
                        (string) $this->other->id,
                        [EntityUserProductCart::product_id => $productId],
                    )));
                    break;
                default:
                    $this->deliver($this->addGuestCartLine($productId));
            }
        });
        $this->assertMove($oldSlug, $newSlug);
        self::assertSame([[], []], array_values($reads));
        self::assertNull(Hilos::$sr->pageResource(self::GUEST));
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));

        $this->subscribe(self::GUEST, $oldSlug);
        $this->assertMove($oldSlug, $newSlug);
        $this->subscribe(self::GUEST, $newSlug);
        $frame = $this->takeFrame(self::GUEST);
        self::assertSame($newSlug, self::card($frame)[EntityProduct::slug]);
        self::assertSame([
            'productId' => $productId,
            'slug' => $newSlug,
            'cartCount' => $mutation === TableMutationType::Create ? 1 : 0,
        ], $frame->data->payload->data[ProductPage::DATA_PRODUCT_CARTS]);

        Hilos::$browser->record(SourceChange::dbCleared(EcommerceShopDbContext::userProductCarts));
        $this->deliver($rename);
        $frames = $this->takeFrames();
        self::assertCount(1, $frames);
        self::assertSame($newSlug, self::card($frames[0])[EntityProduct::slug]);
        self::assertSame(
            $mutation === TableMutationType::Create ? 1 : 0,
            $frames[0]->data->payload->data[ProductPage::DATA_PRODUCT_CARTS]['cartCount'],
        );
    }

    /**
     * The visibility check sees the new placement when a stock or category fact reaches the old address.
     *
     * @param bool $categoryFact Whether the category update reaches the worker before the stock sync
     */
    #[TestWith([false])]
    #[TestWith([true])]
    public function testPlacementRecoversTheFormerAddress(bool $categoryFact): void
    {
        $productId = $this->unplaced->id;
        $oldSlug = $this->unplaced->slug;
        $this->subscribe(self::GUEST, $oldSlug);
        $this->takeRefusal(self::GUEST, HttpConstants::HTTP_NOT_FOUND);
        $this->deliver($this->renameProduct($this->unplaced));
        self::assertSame([], $this->takeFrames());

        self::stock($productId, $this->warehouse, 1);
        if ($categoryFact) {
            $this->deliver(self::renameCategory($this->unplaced->categoryId, 'Freshly placed'));
        } else {
            $row = Database::sql('SELECT * FROM product_warehouse_stock WHERE product_id = ?', [$productId])->rows()[0];
            $this->deliver(new WorkerDbSyncCreatedMessageDTO(new DbSyncCreatedSignalData(
                EcommerceShopDbContext::productWarehouseStocks,
                (string) $row[EntityProductWarehouseStock::id],
                $row,
            )));
        }
        $this->assertMove($oldSlug, $this->unplaced->slug);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
    }

    /**
     * A catalog fact or database recovery can finish the obsolete address without a cart fact.
     *
     * @param bool $catalogFirst Whether the catalog sync precedes the database recovery
     */
    #[TestWith([true])]
    #[TestWith([false])]
    public function testCatalogAndDatabaseRecoveryMoveTheFormerAddress(bool $catalogFirst): void
    {
        $oldSlug = $this->stock->slug;
        $this->warmProduct($this->stock->id);
        $this->observeCartCountReads(fn () => $this->subscribe(self::GUEST, $oldSlug), $this->stock->id);
        $this->takeRefusal(self::GUEST, HttpConstants::HTTP_INTERNAL_ERROR);
        $rename = $this->renameProduct($this->stock);
        if ($catalogFirst) {
            $this->deliver($rename);
        } else {
            $this->deliverDatabaseRecovery();
        }
        $this->assertMove($oldSlug, $this->stock->slug);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
        if ($catalogFirst) {
            $this->deliverDatabaseRecovery();
        } else {
            $this->deliver($rename);
        }
        Hilos::$browser->record(SourceChange::dbCleared(EcommerceShopDbContext::userProductCarts));
        $this->deliver();
        self::assertSame([], $this->takeFrames());
    }

    /** A current storefront slug takes precedence over an exact historic match for another product. */
    public function testCartFactKeepsCurrentNumericAddressAheadOfHistory(): void
    {
        $slug = '1';
        $productId = $this->other->id;
        Database::sql('UPDATE product SET slug = ? WHERE id = ?', [$slug, $productId]);
        Database::sql('INSERT INTO product_slug (slug, product_id) VALUES (?, ?)', [$slug, $this->stock->id]);
        Hilos::$db->productWarehouseStocks->actions->setUnits($productId, $this->warehouse, 8);
        Hilos::$db->productWarehouseStocks->actions->setUnits($this->stock->id, $this->warehouse, 8);
        $this->observeCartCountReads(fn () => $this->subscribe(self::GUEST, $slug), $productId);
        $this->takeRefusal(self::GUEST, HttpConstants::HTTP_INTERNAL_ERROR);

        $fact = $this->addGuestCartLine($this->stock->id);
        $reads = $this->observeCartCountReads(fn () => $this->deliver($fact));
        self::assertSame([], $this->takeFrames());
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
        self::assertSame([
            EcommerceShopDbContext::sessionProductCarts => [],
            EcommerceShopDbContext::userProductCarts => [],
        ], $reads);

        $this->deliver($this->addGuestCartLine($productId));
        $frame = $this->takeFrame(self::GUEST);
        self::assertSame($slug, self::card($frame)[EntityProduct::slug]);
        self::assertSame([
            'productId' => $productId,
            'slug' => $slug,
            'cartCount' => 1,
        ], $frame->data->payload->data[ProductPage::DATA_PRODUCT_CARTS]);
    }

    /**
     * One oversized batch is split and a failing part leaves the readable addresses and Overview deliverable.
     *
     * @param string $stage Address lookup that refuses the batch
     */
    #[TestWith(['current'])]
    #[TestWith(['history'])]
    #[TestWith(['overlap'])]
    public function testCartAddressBatchesKeepIndependentAnswers(string $stage): void
    {
        $badSlug = $this->stock->slug;
        $goodSlug = $this->other->slug;
        foreach ([self::GUEST => $this->stock, self::VISITOR => $this->other] as $acceptKey => $product) {
            Hilos::$db->productWarehouseStocks->actions->setUnits($product->id, $this->warehouse, 1);
            $this->observeCartCountReads(fn () => $this->subscribe($acceptKey, $product->slug), $product->id);
            $this->takeRefusal($acceptKey, HttpConstants::HTTP_INTERNAL_ERROR);
            $this->renameProduct($product);
        }
        Hilos::$db->productWarehouseStocks->actions->place($this->unplaced->id, $this->warehouse);
        ExecutionContext::run(new ExecutionFrame(), static function (): void {
            Hilos::$rt->connections->actions->register(self::OTHER_GUEST, null, sessionId: 1);
        });
        $this->subscribe(self::OTHER_GUEST, $this->unplaced->slug);
        $this->takeCard(self::OTHER_GUEST);
        $this->openAdminPage(AdminProductPage::PAGE);
        $this->takeFrame(self::ADMIN);

        $facts = [
            $this->addGuestCartLine($this->stock->id),
            $this->addGuestCartLine($this->other->id),
            $this->addGuestCartLine($this->unplaced->id),
        ];
        if ($stage !== 'overlap') {
            Hilos::$browser->record(SourceChange::dbCleared(EcommerceShopDbContext::userProductCarts));
        }

        $lookups = [];
        $check = static function (string $column, array $values) use ($stage, $badSlug, $goodSlug, &$lookups): void {
            if ($column !== ($stage === 'history' ? EntityProductSlug::slug : EntityProduct::slug)) {
                return;
            }
            $lookups[] = $values;
            if (in_array($badSlug, $values, true) && in_array($goodSlug, $values, true)) {
                throw new DatabaseException('The encoded query exceeds the packet limit', 1153);
            }
            if ($values === [$badSlug]) {
                throw new DatabaseException('Only this address batch is unavailable');
            }
        };
        $this->withAddressReadCheck(
            $stage === 'history' ? EcommerceShopDbContext::productSlugs : EcommerceShopDbContext::products,
            $check,
            fn () => $this->deliver(...$facts),
        );

        $frames = $this->takeFrames();
        self::assertEqualsCanonicalizing([self::ADMIN, self::OTHER_GUEST, self::VISITOR], array_column($frames, 'targetAcceptKey'));
        $byConnection = array_column($frames, 'data', 'targetAcceptKey');
        $move = $byConnection[self::VISITOR];
        self::assertInstanceOf(PageMovedSignalData::class, $move);
        self::assertSame(['slug' => $goodSlug], $move->params);
        self::assertSame(['slug' => $this->other->slug], $move->targetParams);
        self::assertSame(1, $byConnection[self::ADMIN]->payload->data[AdminProductPage::DATA_PRODUCT_CARTS]['cartCount']);
        self::assertSame(1, $byConnection[self::OTHER_GUEST]->payload->data[ProductPage::DATA_PRODUCT_CARTS]['cartCount']);
        self::assertContains([$badSlug, $goodSlug], $lookups);
        self::assertContains([$badSlug], $lookups);
        self::assertContains([$goodSlug], $lookups);
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::VISITOR));

        Hilos::$browser->record(SourceChange::dbCleared(EcommerceShopDbContext::userProductCarts));
        $this->deliver();
        $moves = array_values(array_filter($this->takeFrames(), static fn ($frame): bool => $frame->data instanceof PageMovedSignalData));
        self::assertCount(1, $moves);
        self::assertSame(self::GUEST, $moves[0]->targetAcceptKey);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
    }

    /** A refused history source leaves exact current addresses readable in both batches. */
    public function testCartClearRecoversCurrentAddressesAcrossAHistoryRefusal(): void
    {
        Hilos::$db->productWarehouseStocks->actions->setUnits($this->stock->id, $this->warehouse, 1);
        Hilos::$db->productWarehouseStocks->actions->setUnits($this->other->id, $this->warehouse, 1);
        Hilos::$db->productWarehouseStocks->actions->place($this->unplaced->id, $this->warehouse);
        $former = $this->unplaced->slug;
        $this->renameProduct($this->unplaced);
        $this->observeCartCountReads(fn () => $this->subscribe(self::GUEST, $this->stock->slug), $this->stock->id);
        $this->takeRefusal(self::GUEST, HttpConstants::HTTP_INTERNAL_ERROR);

        // Open the former address while its history source is unavailable.
        $this->withAddressReadCheck(
            EcommerceShopDbContext::productSlugs,
            static fn () => throw new DbCollectionNotReadableException('History is unreadable'),
            fn () => $this->subscribe(self::VISITOR, $former),
        );
        $this->takeRefusal(self::VISITOR, HttpConstants::HTTP_INTERNAL_ERROR);
        $fillers = [];
        try {
            foreach (range(1, 30) as $number) {
                $acceptKey = 'product-address-batch-' . $number;
                ExecutionContext::run(new ExecutionFrame(), static function () use ($acceptKey): void {
                    Hilos::$rt->connections->actions->register($acceptKey, null, sessionId: 1);
                });
                $fillers[] = $acceptKey;
                $this->subscribe($acceptKey, 'unknown-' . $number);
                $this->takeRefusal($acceptKey, HttpConstants::HTTP_NOT_FOUND);
            }
            ExecutionContext::run(new ExecutionFrame(), static function (): void {
                Hilos::$rt->connections->actions->register(self::OTHER_GUEST, null, sessionId: 1);
            });
            $this->observeCartCountReads(fn () => $this->subscribe(self::OTHER_GUEST, $this->other->slug), $this->other->id);
            $this->takeRefusal(self::OTHER_GUEST, HttpConstants::HTTP_INTERNAL_ERROR);

            $historyReads = [];
            $this->withAddressReadCheck(
                EcommerceShopDbContext::productSlugs,
                static function (string $column, array $values) use (&$historyReads): never {
                    $historyReads[] = $values;
                    throw new DbCollectionNotReadableException('History is unreadable');
                },
                function (): void {
                    Hilos::$browser->record(SourceChange::dbCleared(EcommerceShopDbContext::userProductCarts));
                    $this->deliver();
                },
            );
            self::assertCount(1, $historyReads);
            self::assertContains($former, $historyReads[0]);
            self::assertNotContains($this->stock->slug, $historyReads[0]);
            $frames = $this->takeFrames();
            self::assertEqualsCanonicalizing([self::GUEST, self::OTHER_GUEST], array_column($frames, 'targetAcceptKey'));
            $frames = array_column($frames, null, 'targetAcceptKey');
            self::assertSame($this->stock->id, self::card($frames[self::GUEST])['id']);
            self::assertSame($this->other->id, self::card($frames[self::OTHER_GUEST])['id']);
            self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::VISITOR));
            self::assertNull(Hilos::$sr->pageResource(self::VISITOR));

            Hilos::$browser->record(SourceChange::dbCleared(EcommerceShopDbContext::userProductCarts));
            $this->deliver();
            $frames = $this->takeFrames();
            self::assertEqualsCanonicalizing([self::GUEST, self::VISITOR, self::OTHER_GUEST], array_column($frames, 'targetAcceptKey'));
            $frames = array_column($frames, 'data', 'targetAcceptKey');
            $move = $frames[self::VISITOR];
            self::assertInstanceOf(PageMovedSignalData::class, $move);
            self::assertSame(['slug' => $this->unplaced->slug], $move->targetParams);
            self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::VISITOR));
        } finally {
            ExecutionContext::run(new ExecutionFrame(), static function () use ($fillers): void {
                foreach ($fillers as $acceptKey) {
                    Hilos::$rt->connections[$acceptKey]?->actions->unregister();
                    SourceInterestRegistry::releaseConsumer(SourceConsumer::page($acceptKey));
                }
            });
        }
    }

    /**
     * @param TableMutationType $mutation Targeted cart membership fact
     * @param string $phase Address lookup that is refused
     */
    #[TestWith([TableMutationType::Create, 'current'])]
    #[TestWith([TableMutationType::Create, 'history'])]
    #[TestWith([TableMutationType::Delete, 'overlap'])]
    public function testTargetedCartFactsRespectTheRefusedAddressPhase(TableMutationType $mutation, string $phase): void
    {
        $former = $this->other->slug;
        $current = $this->stock->slug;
        $this->observeCartCountReads(fn () => $this->subscribe(self::GUEST, $current), $this->stock->id);
        $this->takeRefusal(self::GUEST, HttpConstants::HTTP_INTERNAL_ERROR);
        $this->observeCartCountReads(fn () => $this->subscribe(self::VISITOR, $former), $this->other->id);
        $this->takeRefusal(self::VISITOR, HttpConstants::HTTP_INTERNAL_ERROR);
        $this->renameProduct($this->other);
        $facts = function () use ($mutation): void {
            foreach ([$this->stock->id, $this->other->id] as $id) {
                $row = [EntityUserProductCart::product_id => $id];
                Hilos::$browser->record($mutation === TableMutationType::Create
                    ? SourceChange::dbCreated(EcommerceShopDbContext::userProductCarts, (string) $id, $row)
                    : SourceChange::dbDeleted(EcommerceShopDbContext::userProductCarts, (string) $id, $row));
            }
            $this->deliver();
        };
        $currentRead = false;
        $historyRead = false;
        $overlapRead = false;
        $this->withAddressReadCheck(
            EcommerceShopDbContext::products,
            static function (string $column, array $values) use ($phase, $former, &$currentRead, &$overlapRead): void {
                if ($column === EntityProduct::id) {
                    $currentRead = true;
                    if ($phase === 'current') {
                        throw new DbCollectionNotReadableException('Products are unreadable');
                    }
                } elseif ($values === [$former]) {
                    $overlapRead = true;
                    if ($phase === 'overlap') {
                        throw new DbCollectionNotReadableException('Products cannot confirm the former address');
                    }
                }
            },
            function () use ($phase, $facts, &$historyRead): void {
                $this->withAddressReadCheck(
                    EcommerceShopDbContext::productSlugs,
                    static function () use ($phase, &$historyRead): void {
                        $historyRead = true;
                        if ($phase === 'history') {
                            throw new DbCollectionNotReadableException('History is unreadable');
                        }
                    },
                    $facts,
                );
            },
        );
        self::assertTrue($currentRead);
        self::assertSame($phase !== 'current', $historyRead);
        self::assertSame($phase === 'overlap', $overlapRead);
        if ($phase === 'current') {
            self::assertSame([], $this->takeFrames());
            self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
        } else {
            self::assertSame($this->stock->id, $this->takeCard(self::GUEST)['id']);
        }
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::VISITOR));
        self::assertNull(Hilos::$sr->pageResource(self::VISITOR));

        $facts();
        $frames = array_column($this->takeFrames(), null, 'targetAcceptKey');
        self::assertInstanceOf(PageMovedSignalData::class, $frames[self::VISITOR]->data);
        self::assertSame(['slug' => $this->other->slug], $frames[self::VISITOR]->data->targetParams);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::VISITOR));
    }

    /** The address batches bound both short keys and the encoded length of the longest usable routes. */
    public function testAddressLookupBatchesBoundKeyCountAndSize(): void
    {
        $short = [];
        $long = [];
        for ($index = 0; $index < 34; $index++) {
            $short[] = 'known-missing-' . $index;
            $suffix = "'" . $index;
            $long[] = str_repeat('🌷', CatalogSlug::MAX_LENGTH - strlen($suffix)) . $suffix;
        }
        $overlong = str_repeat('a', CatalogSlug::MAX_LENGTH + 1);
        foreach ([$short, array_slice($long, 0, 12)] as $slugs) {
            $queries = [];
            $keys = [...$slugs, $slugs[0], $overlong];
            $this->withAddressReadCheck(
                EcommerceShopDbContext::products,
                static function (string $column, array $values) use (&$queries): void {
                    self::assertSame(EntityProduct::slug, $column);
                    $queries[] = $values;
                },
                fn () => $this->flushReaderAfter(static function () use ($keys): void {
                    self::assertSame([], (new ProductPageAddresses())->matchingProducts(array_combine($keys, $keys), null));
                }),
            );
            self::assertCount(2, $queries);
            self::assertSame($slugs, array_merge(...$queries));
        }
    }

    /**
     * @param TableMutationType $mutation Cart fact selecting the unbound debts
     */
    #[TestWith([TableMutationType::Create])]
    #[TestWith([TableMutationType::Delete])]
    #[TestWith([TableMutationType::Clear])]
    public function testUnavailableCartAddressSourceLeavesDebtsForTheNextFact(TableMutationType $mutation): void
    {
        foreach ([self::GUEST => $this->stock, self::VISITOR => $this->other] as $acceptKey => $product) {
            $this->observeCartCountReads(fn () => $this->subscribe($acceptKey, $product->slug), $product->id);
            $this->takeRefusal($acceptKey, HttpConstants::HTTP_INTERNAL_ERROR);
        }

        $productIds = [$this->stock->id, $this->other->id, ...range($this->unplaced->id + 1, $this->unplaced->id + 34)];
        $record = static function () use ($mutation, $productIds): void {
            if ($mutation === TableMutationType::Clear) {
                Hilos::$browser->record(SourceChange::dbCleared(EcommerceShopDbContext::userProductCarts));
                return;
            }
            foreach ($productIds as $productId) {
                $row = [EntityUserProductCart::product_id => $productId];
                Hilos::$browser->record($mutation === TableMutationType::Create
                    ? SourceChange::dbCreated(EcommerceShopDbContext::userProductCarts, (string) $productId, $row)
                    : SourceChange::dbDeleted(EcommerceShopDbContext::userProductCarts, (string) $productId, $row));
            }
        };

        $calls = [];
        $unavailable = static function (string $column, array $values) use (&$calls): never {
            $calls[] = [$column, $values];
            throw new DatabaseConnectionException('The address source is unavailable');
        };
        $this->withAddressReadCheck(EcommerceShopDbContext::productSlugs, $unavailable, function () use ($unavailable, $record): void {
            $this->withAddressReadCheck(EcommerceShopDbContext::products, $unavailable, function () use ($record): void {
                $record();
                self::assertSame([[], []], array_values($this->observeCartCountReads(fn () => $this->deliver())));
            });
        });
        self::assertCount(1, $calls);
        self::assertSame($mutation === TableMutationType::Clear ? EntityProduct::slug : EntityProduct::id, $calls[0][0]);
        self::assertSame([], $this->takeFrames());
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::VISITOR));

        $record();
        $reads = $this->observeCartCountReads(fn () => $this->deliver());
        $frames = $this->takeFrames();
        self::assertCount(2, $frames);
        $cards = array_map(self::card(...), array_column($frames, null, 'targetAcceptKey'));
        self::assertSame($this->stock->id, $cards[self::GUEST]['id']);
        self::assertSame($this->other->id, $cards[self::VISITOR]['id']);
        foreach ($reads as $ids) {
            self::assertEqualsCanonicalizing([$this->stock->id, $this->other->id], $ids);
        }
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::VISITOR));
    }

    /** One lost catalog read also stops later address and Overview cart reads until another fact. */
    public function testUnavailableCatalogSourceStopsLaterCartDeliveryInTheFlush(): void
    {
        Hilos::$db->productWarehouseStocks->actions->setUnits($this->stock->id, $this->warehouse, 1);
        foreach ([self::GUEST => $this->stock, self::VISITOR => $this->other] as $acceptKey => $product) {
            $this->subscribe($acceptKey, $product->slug);
            $this->takeCard($acceptKey);
        }
        $this->observeCartCountReads(fn () => $this->openAdminPage(AdminProductPage::PAGE), $this->stock->id);
        $overview = $this->takeFrame(self::ADMIN)->data;
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $overview);
        self::assertSame(AdminProductPage::PAGE, $overview->page);
        self::assertSame(HttpConstants::HTTP_INTERNAL_ERROR, $overview->httpCode);
        $cartFact = $this->addGuestCartLine($this->stock->id);
        $categoryFact = self::renameCategory($this->stock->categoryId, 'Spring bulbs');

        $calls = 0;
        $this->withAddressReadCheck(
            EcommerceShopDbContext::products,
            static function () use (&$calls): never {
                $calls++;
                throw new DatabaseConnectionException('The catalog source is unavailable');
            },
            function () use ($cartFact, $categoryFact): void {
                $reads = $this->observeCartCountReads(fn () => $this->deliver($categoryFact, $cartFact));
                self::assertSame([[], []], array_values($reads));
            },
        );
        self::assertSame(1, $calls);
        $this->takeRefusal(self::GUEST, HttpConstants::HTTP_INTERNAL_ERROR);
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::ADMIN));
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::VISITOR));

        $reads = $this->observeCartCountReads(fn () => $this->deliver($cartFact));
        self::assertSame([[$this->stock->id], [$this->stock->id]], array_values($reads));
        $frames = $this->takeFrames();
        self::assertEqualsCanonicalizing([self::GUEST, self::ADMIN], array_column($frames, 'targetAcceptKey'));
        $replies = array_column($frames, 'data', 'targetAcceptKey');
        self::assertSame('Spring bulbs', $replies[self::GUEST]->payload->data[ProductPage::DATA_PRODUCT]['categoryName']);
        self::assertSame(1, $replies[self::ADMIN]->payload->data[AdminProductPage::DATA_PRODUCT_CARTS]['cartCount']);
    }

    /** A completed selection batch survives the loss of PRIMARY on the next batch. */
    public function testAddressSelectionRetainsCompletedBatchesAfterConnectionLoss(): void
    {
        $current = $this->stock->slug;
        $unknown = 'not-a-product-address';
        $ids = [$this->stock->id, ...range($this->unplaced->id + 1, $this->unplaced->id + 70)];
        $calls = [];
        $check = static function (string $column, array $values) use (&$calls): void {
            $calls[] = [$column, $values];
            if (count($calls) === 2) {
                throw new DatabaseConnectionException('The remaining address source is unavailable');
            }
        };
        $this->withAddressReadCheck(EcommerceShopDbContext::productSlugs, $check, function () use ($current, $unknown, $ids, $check): void {
            $this->withAddressReadCheck(EcommerceShopDbContext::products, $check, function () use ($current, $unknown, $ids): void {
                $this->flushReaderAfter(function () use ($current, $unknown, $ids): void {
                    $matched = (new ProductPageAddresses())->matchingProducts([$current => $current, $unknown => $unknown], $ids);
                    self::assertSame([$current => $this->stock->id], $matched);
                });
            });
        });

        self::assertCount(2, $calls);
        self::assertSame(EntityProduct::id, $calls[0][0]);
        self::assertSame(EntityProduct::id, $calls[1][0]);
        self::assertContains($this->stock->id, $calls[0][1]);
    }

    /**
     * Overview can publish ready cart data after a loss. A whole answer also needs its browser sources.
     * @param bool $owed Whether the opened Overview already owes a whole answer
     */
    #[TestWith([false])]
    #[TestWith([true])]
    public function testLaterConnectionLossKeepsAnAlreadyConfirmedCountDeliverable(bool $owed): void
    {
        Hilos::$db->productWarehouseStocks->actions->setUnits($this->stock->id, $this->warehouse, 1);
        $this->subscribe(self::GUEST, $this->stock->slug);
        $this->takeCard(self::GUEST);
        $this->openAdminPage(AdminProductPage::PAGE);
        $this->takeFrame(self::ADMIN);
        $resource = Hilos::$sr->pageResource(self::ADMIN);
        self::assertNotNull($resource);
        if ($owed) {
            Hilos::$browser->record(SourceChange::dbCleared(EcommerceShopDbContext::userProductCarts));
            $this->deliverWithCartCountFailure($this->stock->id);
            $refusals = array_column($this->takeFrames(), 'data', 'targetAcceptKey');
            self::assertEqualsCanonicalizing([self::GUEST, self::ADMIN], array_keys($refusals));
            foreach ($refusals as $refusal) {
                self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $refusal);
                self::assertSame(HttpConstants::HTTP_INTERNAL_ERROR, $refusal->httpCode);
            }
            self::assertSame($resource, Hilos::$sr->pageResource(self::ADMIN));
            self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::ADMIN));
        }
        $this->observeCartCountReads(fn () => $this->subscribe(self::VISITOR, $this->other->slug), $this->other->id);
        $this->takeRefusal(self::VISITOR, HttpConstants::HTTP_INTERNAL_ERROR);
        $cartFact = $this->addGuestCartLine($this->stock->id);
        $categoryFact = self::renameCategory($this->stock->categoryId, 'Spring bulbs');

        $lostSlug = $this->other->slug;
        $snapshotReads = [];
        $this->withOverviewListCheck(
            static function (string $key) use (&$snapshotReads): void {
                $snapshotReads[] = $key;
            },
            function () use ($lostSlug, $categoryFact, $cartFact): void {
                $this->withAddressReadCheck(
                    EcommerceShopDbContext::products,
                    static function (string $column, array $values) use ($lostSlug): void {
                        if ($column === EntityProduct::slug && $values === [$lostSlug]) {
                            throw new DatabaseConnectionException('A later address batch lost its connection');
                        }
                    },
                    function () use ($categoryFact, $cartFact): void {
                        $reads = $this->observeCartCountReads(fn () => $this->deliver($categoryFact, $cartFact));
                        self::assertSame([[$this->stock->id], [$this->stock->id]], array_values($reads));
                    },
                );
            },
        );
        self::assertSame([], $snapshotReads);

        $cartFrames = [];
        foreach ($this->takeFrames() as $frame) {
            self::assertInstanceOf(PageResponseSignalData::class, $frame->data);
            if (isset($frame->data->payload->data[ProductPage::DATA_PRODUCT_CARTS])) {
                $cartFrames[] = $frame;
            } else {
                self::assertSame(self::ADMIN, $frame->targetAcceptKey);
                self::assertSame([], $frame->data->payload->data);
                self::assertSame([EcommerceShopBrowserList::ADMIN_PRODUCT_CATEGORIES], array_keys($frame->data->payload->lists));
            }
        }
        self::assertEqualsCanonicalizing($owed ? [self::GUEST] : [self::GUEST, self::ADMIN], array_column($cartFrames, 'targetAcceptKey'));
        $replies = array_column($cartFrames, 'data', 'targetAcceptKey');
        self::assertSame(1, $replies[self::GUEST]->payload->data[ProductPage::DATA_PRODUCT_CARTS]['cartCount']);
        if ($owed) {
            self::assertSame($resource, Hilos::$sr->pageResource(self::ADMIN));
            self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::ADMIN));
            $this->deliver($cartFact);
            $frames = $this->takeFrames();
            self::assertEqualsCanonicalizing([self::GUEST, self::ADMIN], array_column($frames, 'targetAcceptKey'));
            $frames = array_column($frames, 'data', 'targetAcceptKey');
            $recovered = $frames[self::ADMIN];
            self::assertInstanceOf(PageResponseSignalData::class, $recovered);
            self::assertSame(1, $recovered->payload->data[AdminProductPage::DATA_PRODUCT_CARTS]['cartCount']);
            self::assertArrayHasKey(EcommerceShopBrowserList::ADMIN_PRODUCT_CATEGORIES, $recovered->payload->lists);
            self::assertArrayHasKey(EcommerceShopBrowserList::ADMIN_PRODUCT_WAREHOUSES, $recovered->payload->lists);
            self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::ADMIN));
        } else {
            self::assertSame(1, $replies[self::ADMIN]->payload->data[AdminProductPage::DATA_PRODUCT_CARTS]['cartCount']);
        }
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::VISITOR));
    }

    /**
     * @param string $source Change that attempts the first browser read
     */
    #[TestWith(['product'])]
    #[TestWith(['stock'])]
    #[TestWith(['viewers'])]
    public function testAmbientOverviewLossStopsAStorefrontRecoveryInTheSameFlush(string $source): void
    {
        $this->openAdminPage(AdminProductPage::PAGE);
        $this->takeFrame(self::ADMIN);
        $this->observeCartCountReads(fn () => $this->subscribe(self::GUEST, $this->other->slug), $this->other->id);
        $this->takeRefusal(self::GUEST, HttpConstants::HTTP_INTERNAL_ERROR);
        $fact = match ($source) {
            'product' => SourceChange::dbUpdated(EcommerceShopDbContext::products, (string) $this->stock->id, [
                EntityProduct::name => $this->stock->name,
            ]),
            'stock' => SourceChange::dbUpdated(EcommerceShopDbContext::productWarehouseStocks, '1', [
                EntityProductWarehouseStock::product_id => $this->stock->id,
            ]),
            'viewers' => SourceChange::rtUpdated(EcommerceShopRtContext::productViewerCounts, $this->stock->slug, [
                ProductViewerCount::productId => $this->stock->id,
            ]),
        };
        $failure = new DatabaseConnectionException('The Overview stock totals lost PRIMARY');
        $totals = [];
        $reads = [];
        $this->withOverviewStockCheck(
            static function (array $ids) use (&$totals, $failure): never {
                $totals[] = $ids;
                throw $failure;
            },
            function () use ($fact, &$totals, &$reads): void {
                $this->withAddressReadCheck(
                    EcommerceShopDbContext::products,
                    static function (string $column, array $values) use (&$totals, &$reads): void {
                        if ($totals !== []) {
                            $reads[] = [$column, $values];
                        }
                    },
                    fn () => $this->flushReaderAfter(static function () use ($fact): void {
                        Hilos::$browser->record($fact);
                        Hilos::$browser->recordDatabaseBack();
                    }, containedFailures: 1),
                );
            },
        );
        self::assertSame([[$this->stock->id]], $totals);
        self::assertSame([], $reads);
        $refusal = $this->takeFrame(self::ADMIN)->data;
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $refusal);
        self::assertSame(AdminProductPage::PAGE, $refusal->page);
        self::assertSame(HttpConstants::HTTP_INTERNAL_ERROR, $refusal->httpCode);
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::ADMIN));
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));

        $this->deliverDatabaseRecovery();
        $frames = $this->takeFrames();
        self::assertEqualsCanonicalizing([self::GUEST, self::ADMIN], array_column($frames, 'targetAcceptKey'));
        $frames = array_column($frames, null, 'targetAcceptKey');
        self::assertSame($this->other->id, self::card($frames[self::GUEST])['id']);
        $recovered = $frames[self::ADMIN]->data;
        self::assertInstanceOf(PageResponseSignalData::class, $recovered);
        self::assertArrayHasKey(AdminProductBrowserData::DATA, $recovered->payload->data);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::ADMIN));
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
    }

    /** A later cold list key reaches the ORM boundary while a healthy link could still answer the test query. */
    public function testProjectLossStopsAColdOverviewListRead(): void
    {
        $this->openAdminPage(AdminProductPage::PAGE);
        $this->takeFrame(self::ADMIN);
        $this->subscribe(self::GUEST, 'unknown-address');
        $this->takeRefusal(self::GUEST, HttpConstants::HTTP_NOT_FOUND);
        $categoryId = self::category('Cold category');
        self::assertFalse(isset($this->reader->mountedObjectCollection(EcommerceShopDbContext::categories)[$categoryId]));
        $loss = new DatabaseConnectionException('The catalog lookup lost its connection');
        $refused = [];
        $this->withOverviewListCheck(
            static fn (string $key) => self::fail('An ambient list change should not rebuild the snapshot'),
            function () use ($categoryId, $loss): void {
                $this->withAddressReadCheck(
                    EcommerceShopDbContext::products,
                    static fn () => throw $loss,
                    fn () => $this->flushReaderAfter(static function () use ($categoryId): void {
                        Hilos::$browser->record(SourceChange::dbCleared(EcommerceShopDbContext::userProductCarts));
                        Hilos::$browser->record(SourceChange::dbCreated(EcommerceShopDbContext::categories, (string) $categoryId, [
                            EntityCategory::id => $categoryId,
                        ]));
                    }, containedFailures: 1),
                );
            },
            static function (string $key, mixed $offset, DatabaseException $failure) use (&$refused): void {
                $refused[] = [$key, (int) $offset, $failure];
            },
        );
        self::assertSame([[EcommerceShopDbContext::categories, $categoryId, $loss]], $refused);
        self::assertFalse(isset($this->reader->mountedObjectCollection(EcommerceShopDbContext::categories)[$categoryId]));
        $frames = $this->takeFrames();
        self::assertSame([self::ADMIN, self::ADMIN], array_column($frames, 'targetAcceptKey'));
        self::assertInstanceOf(PageResponseSignalData::class, $frames[0]->data);
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $frames[1]->data);
        self::assertSame(HttpConstants::HTTP_INTERNAL_ERROR, $frames[1]->data->httpCode);

        $this->deliverDatabaseRecovery();
        $recovered = $this->takeFrame(self::ADMIN)->data;
        self::assertInstanceOf(PageResponseSignalData::class, $recovered);
        self::assertArrayHasKey(EcommerceShopBrowserList::ADMIN_PRODUCT_CATEGORIES, $recovered->payload->lists);
    }

    /**
     * @param Closure(list<int>): void $check Observation at the stock totals
     * @param Closure(): void $step Worker delivery under observation
     */
    private function withOverviewStockCheck(Closure $check, Closure $step): void
    {
        $views = new ReflectionProperty(DbContext::class, '_dbItemCollections');
        $original = $views->getValue($this->reader);
        $key = EcommerceShopDbContext::productWarehouseStocks;
        $checked = new class ($original[$key], $check) extends DbCollection {
            public const string DB_ITEM_CLASS = ProductWarehouseStocks::DB_ITEM_CLASS;
            public const string OBJECT_COLLECTION_CLASS = ProductWarehouseStocks::OBJECT_COLLECTION_CLASS;

            public function __construct(private readonly ProductWarehouseStocks $original, private readonly Closure $check)
            {
            }

            public function getObjectCollection(): ?Objects
            {
                return $this->original->getObjectCollection();
            }

            public function totalsOfProducts(int ...$productIds): ProductStockTotalsCollection
            {
                ($this->check)($productIds);
                return $this->original->totalsOfProducts(...$productIds);
            }
        };
        $views->setValue($this->reader, array_replace($original, [$key => $checked]));
        try {
            $step();
        } finally {
            $views->setValue($this->reader, $original);
        }
    }

    /**
     * @param Closure(string): void $check Observation at a list snapshot query
     * @param Closure(): void $step Worker delivery under observation
     * @param ?Closure(string, mixed, DatabaseException): void $lookupFailed Observation at a failed keyed list read
     */
    private function withOverviewListCheck(Closure $check, Closure $step, ?Closure $lookupFailed = null): void
    {
        $views = new ReflectionProperty(DbContext::class, '_dbItemCollections');
        $original = $views->getValue($this->reader);
        $checked = $original;
        foreach ([EcommerceShopDbContext::categories, EcommerceShopDbContext::warehouses] as $key) {
            $checked[$key] = new class ($original[$key], $key, $check, $lookupFailed) extends DbCollection {
                public function __construct(
                    private readonly DbCollection $original,
                    private readonly string $key,
                    private readonly Closure $check,
                    private readonly ?Closure $lookupFailed,
                ) {
                }

                public function offsetExists(mixed $offset): bool
                {
                    try {
                        return isset($this->original[$offset]);
                    } catch (DatabaseException $failure) {
                        $this->lookupFailed?->__invoke($this->key, $offset, $failure);
                        throw $failure;
                    }
                }

                public function offsetGet(mixed $offset): DbItem
                {
                    return $this->original[$offset];
                }

                public function getObjectCollection(): ?Objects
                {
                    return $this->original->getObjectCollection();
                }

                public function queryPageItems(TableQueryDTO $query): array
                {
                    ($this->check)($this->key);
                    return $this->original->queryPageItems($query);
                }
            };
        }
        $views->setValue($this->reader, $checked);
        try {
            $step();
        } finally {
            $views->setValue($this->reader, $original);
        }
    }

    /**
     * @param string $collection Product or address-history View slot
     * @param Closure(string, list<int|string>): void $check Failure or observation at a real lookup
     * @param Closure(): void $step Worker work exercising the lookup
     */
    private function withAddressReadCheck(string $collection, Closure $check, Closure $step): void
    {
        $views = new ReflectionProperty(DbContext::class, '_dbItemCollections');
        $original = $views->getValue($this->reader);
        $checked = new class ($original[$collection], $check) extends DbCollection {
            public function __construct(private readonly Products|ProductSlugs $original, private readonly Closure $check)
            {
            }

            public function offsetExists(mixed $offset): bool
            {
                return isset($this->original[$offset]);
            }

            public function offsetGet(mixed $offset): DbItem
            {
                return $this->original[$offset];
            }

            public function getObjectCollection(): ?Objects
            {
                return $this->original->getObjectCollection();
            }

            /** @return Generator<int, Product> Stored products by ID */
            public function storedProducts(string $column, int|string ...$values): Generator
            {
                ($this->check)($column, $values);
                return $this->original->storedProducts($column, ...$values);
            }

            /** @return array<int|string, int> Stored addresses by exact slug */
            public function storedAddresses(string $column, int|string ...$values): array
            {
                ($this->check)($column, $values);
                return $this->original->storedAddresses($column, ...$values);
            }
        };
        $views->setValue($this->reader, array_replace($original, [$collection => $checked]));
        try {
            $step();
        } finally {
            $views->setValue($this->reader, $original);
        }
    }

    /**
     * A cart fact costs the same address reads with one unknown slug or multiple refused tabs.
     *
     * @param bool $clear Whether to measure a collection clear
     */
    #[TestWith([false])]
    #[TestWith([true])]
    public function testCartAddressLookupCountIsIndependentOfRefusedTabs(bool $clear): void
    {
        $productId = $this->stock->id;
        $slug = $this->stock->slug;
        Hilos::$db->productWarehouseStocks->actions->setUnits($productId, $this->warehouse, 8);
        $this->subscribe(self::GUEST, $slug);
        $this->takeCard(self::GUEST);

        $fact = $this->addGuestCartLine($productId);
        $flush = function () use ($clear, $fact): void {
            if ($clear) {
                Hilos::$browser->record(SourceChange::dbCleared(EcommerceShopDbContext::userProductCarts));
                $this->deliver();
            } else {
                $this->deliver($fact);
            }
        };
        $queries = self::selectCount();
        $baselineReads = $this->observeCartCountReads($flush);
        $baselineQueries = self::selectCount() - $queries;
        $this->assertCartCountFrame($productId, $slug, 1);

        $unknown = 'no-such-product';
        $this->subscribe(self::VISITOR, $unknown);
        $this->takeRefusal(self::VISITOR, HttpConstants::HTTP_NOT_FOUND);
        $queries = self::selectCount();
        $singleReads = $this->observeCartCountReads($flush);
        $singleQueries = self::selectCount() - $queries;
        $this->assertCartCountFrame($productId, $slug, 1);

        foreach ([self::OTHER_GUEST => $unknown, self::THIRD_GUEST => '0'] as $acceptKey => $address) {
            ExecutionContext::run(new ExecutionFrame(), static function () use ($acceptKey): void {
                Hilos::$rt->connections->actions->register($acceptKey, null, sessionId: 1);
            });
            $this->subscribe($acceptKey, $address);
            $this->takeRefusal($acceptKey, HttpConstants::HTTP_NOT_FOUND);
        }
        $queries = self::selectCount();
        $multipleReads = $this->observeCartCountReads($flush);
        $multipleQueries = self::selectCount() - $queries;
        $this->assertCartCountFrame($productId, $slug, 1);
        self::assertSame([
            EcommerceShopDbContext::sessionProductCarts => [$productId],
            EcommerceShopDbContext::userProductCarts => [$productId],
        ], $baselineReads);
        self::assertSame($baselineReads, $singleReads);
        self::assertSame($baselineReads, $multipleReads);
        foreach ([self::VISITOR, self::OTHER_GUEST, self::THIRD_GUEST] as $acceptKey) {
            self::assertTrue(Hilos::$sr->hasPageDeliveryFailure($acceptKey));
        }
        self::assertSame($singleQueries - $baselineQueries, $multipleQueries - $baselineQueries);
        // Each address SELECT also incurs the rollback scope's SQL-mode SELECT.
        self::assertLessThanOrEqual(4, $multipleQueries - $baselineQueries);
    }

    /** A matching historic address can still be refused by the storefront's visibility check. */
    public function testCartClearKeepsTheFormerAddressOfAnUnplacedProductRefused(): void
    {
        $slug = 'former-unplaced-product';
        Database::sql('INSERT INTO product_slug (slug, product_id) VALUES (?, ?)', [$slug, $this->unplaced->id]);
        $this->subscribe(self::GUEST, $slug);
        $this->takeRefusal(self::GUEST, HttpConstants::HTTP_NOT_FOUND);

        Hilos::$browser->record(SourceChange::dbCleared(EcommerceShopDbContext::userProductCarts));
        $reads = $this->observeCartCountReads(fn () => $this->deliver());
        self::assertSame([], $this->takeFrames());
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
        self::assertSame([
            EcommerceShopDbContext::sessionProductCarts => [],
            EcommerceShopDbContext::userProductCarts => [],
        ], $reads);
    }

    /** Overview recovers a first failed count with no storefront subscription in the worker. */
    public function testCartFactRecoversTheFirstOverviewAnswer(): void
    {
        $productId = $this->stock->id;
        Hilos::$db->productWarehouseStocks->actions->setUnits($productId, $this->warehouse, 8);
        Hilos::$db->productWarehouseStocks->actions->setUnits($this->other->id, $this->warehouse, 8);
        $this->observeCartCountReads(fn () => $this->openAdminPage(AdminProductPage::PAGE), $productId);
        $refusal = $this->takeFrame(self::ADMIN)->data;
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $refusal);
        self::assertSame(AdminProductPage::PAGE, $refusal->page);
        self::assertSame(HttpConstants::HTTP_INTERNAL_ERROR, $refusal->httpCode);
        self::assertNull(Hilos::$sr->pageResource(self::ADMIN));
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::ADMIN));

        $this->deliver($this->addGuestCartLine($this->other->id));
        self::assertSame([], $this->takeFrames());
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::ADMIN));

        $this->deliver($this->addGuestCartLine($productId));
        $response = $this->takeFrame(self::ADMIN)->data;
        self::assertInstanceOf(PageResponseSignalData::class, $response);
        self::assertSame(AdminProductPage::PAGE, $response->pageKey);
        self::assertArrayHasKey(AdminProductBrowserData::DATA, $response->payload->data);
        self::assertSame(['productId' => $productId, 'cartCount' => 1], $response->payload->data[AdminProductPage::DATA_PRODUCT_CARTS]);
        self::assertSame((string) $productId, Hilos::$sr->pageResource(self::ADMIN)?->idString);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::ADMIN));
    }

    /** A clear also retries the first answer of each current product page. */
    public function testCartClearRecoversUnboundProductPages(): void
    {
        $productId = $this->stock->id;
        $slug = $this->stock->slug;
        Hilos::$db->productWarehouseStocks->actions->setUnits($productId, $this->warehouse, 8);
        $this->observeCartCountReads(function () use ($slug): void {
            $this->subscribe(self::GUEST, $slug);
            $this->openAdminPage(AdminProductPage::PAGE);
        }, $productId);
        $refusals = $this->takeFrames();
        self::assertSame([self::GUEST, self::ADMIN], array_column($refusals, 'targetAcceptKey'));
        foreach ($refusals as $frame) {
            self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $frame->data);
            self::assertSame(HttpConstants::HTTP_INTERNAL_ERROR, $frame->data->httpCode);
            self::assertNull(Hilos::$sr->pageResource($frame->targetAcceptKey));
            self::assertTrue(Hilos::$sr->hasPageDeliveryFailure($frame->targetAcceptKey));
        }

        Hilos::$browser->record(SourceChange::dbCleared(EcommerceShopDbContext::sessionProductCarts));
        $reads = $this->observeCartCountReads(fn () => $this->deliver());
        $frames = $this->takeFrames();
        self::assertSame([self::GUEST, self::ADMIN], array_column($frames, 'targetAcceptKey'));
        self::assertSame($slug, self::card($frames[0])[EntityProduct::slug]);
        $overview = $frames[1]->data;
        self::assertInstanceOf(PageResponseSignalData::class, $overview);
        self::assertSame(AdminProductPage::PAGE, $overview->pageKey);
        self::assertArrayHasKey(AdminProductBrowserData::DATA, $overview->payload->data);
        self::assertSame(['productId' => $productId, 'cartCount' => 0], $overview->payload->data[AdminProductPage::DATA_PRODUCT_CARTS]);
        self::assertSame([
            EcommerceShopDbContext::sessionProductCarts => [$productId],
            EcommerceShopDbContext::userProductCarts => [$productId],
        ], $reads);
    }

    /** The full storefront cards and the Overview delta share the same pair of reads. */
    public function testMixedCartAndCatalogFactsShareOneCountAcrossReplies(): void
    {
        $productId = $this->stock->id;
        $slug = $this->stock->slug;
        Hilos::$db->productWarehouseStocks->actions->setUnits($productId, $this->warehouse, 8);
        foreach ([self::GUEST, self::VISITOR] as $acceptKey) {
            $this->subscribe($acceptKey, $slug);
            $this->takeCard($acceptKey);
        }
        $this->openAdminPage(AdminProductPage::PAGE);
        $this->takeFrame(self::ADMIN);

        $reads = $this->observeCartCountReads(fn () => $this->deliver(
            $this->addGuestCartLine($productId),
            self::productPriceFact($productId, 15_500),
        ));
        $frames = $this->takeFrames();
        self::assertSame([self::GUEST, self::VISITOR, self::ADMIN, self::ADMIN], array_column($frames, 'targetAcceptKey'));
        foreach ([$frames[0], $frames[1]] as $frame) {
            self::assertSame(15_500, self::card($frame)[EntityProduct::price]);
            self::assertSame([
                'productId' => $productId,
                'slug' => $slug,
                'cartCount' => 1,
            ], $frame->data->payload->data[ProductPage::DATA_PRODUCT_CARTS]);
        }
        $overview = $frames[2]->data;
        self::assertInstanceOf(PageResponseSignalData::class, $overview);
        self::assertSame(AdminProductPage::PAGE, $overview->pageKey);
        self::assertSame(new PagePayload(data: [
            AdminProductPage::DATA_PRODUCT_CARTS => ['productId' => $productId, 'cartCount' => 1],
        ])->toArray(), $overview->payload->toArray());
        $overviewCard = $frames[3]->data;
        self::assertInstanceOf(PageResponseSignalData::class, $overviewCard);
        self::assertSame(AdminProductPage::PAGE, $overviewCard->pageKey);
        self::assertSame([AdminProductBrowserData::DATA], array_keys($overviewCard->payload->data));
        self::assertSame(15_500, $overviewCard->payload->data[AdminProductBrowserData::DATA][EntityProduct::price]);
        self::assertSame([
            EcommerceShopDbContext::sessionProductCarts => [$productId],
            EcommerceShopDbContext::userProductCarts => [$productId],
        ], $reads);
    }

    /** Guests and signed-in visitors see the same card, including the category and units over every warehouse. */
    public function testPublicCardAndAvailabilityBoundaries(): void
    {
        $category = Hilos::$db->categories[$this->stock->categoryId];
        foreach ([self::GUEST, self::VISITOR] as $acceptKey) {
            $this->subscribe($acceptKey, $this->stock->slug);
            self::assertSame([
                'id' => $this->stock->id,
                'slug' => $this->stock->slug,
                'name' => $this->stock->name,
                'kind' => 'stock',
                'price' => 12_000,
                'description' => 'Product of the case',
                'pictureUrl' => HilosFiles::downloadPath($this->stock->pictureFileId, CatalogPictureUploadTarget::VARIANT_LG),
                'units' => 0,
                'availability' => 'out_of_stock',
                'categoryName' => $category->name,
                'categorySlug' => $category->slug,
            ], $this->takeCard($acceptKey));
        }

        $secondWarehouse = self::warehouse();
        foreach ([
            [ProductKind::STOCK, 1, 0, 'low'],
            [ProductKind::STOCK, 2, 3, 'low'],
            [ProductKind::STOCK, 3, 3, 'in_stock'],
            [ProductKind::UNIQUE, 1, 0, 'free'],
        ] as [$kind, $first, $second, $availability]) {
            $id = self::product($kind, $category->id);
            self::stock($id, $this->warehouse, $first);
            if ($second > 0) {
                self::stock($id, $secondWarehouse, $second);
            }
            // These rows were added past the writer's ORM. Read their addresses from the fixtures.
            $slug = Database::sql('SELECT slug FROM product WHERE id = ?', [$id])->field(EntityProduct::slug);
            self::assertIsString($slug);
            $this->subscribe(self::GUEST, $slug);
            $card = $this->takeCard(self::GUEST);
            self::assertSame($id, $card['id']);
            self::assertSame($kind->value, $card['kind']);
            self::assertSame($first + $second, $card['units']);
            self::assertSame($availability, $card['availability']);
        }
    }

    /** Exact former addresses move to the product's current address, where it answers with its full card. */
    public function testFormerProductAddressesMoveDirectlyToTheCurrentAddress(): void
    {
        foreach (['former-product-address', 'older-product-address'] as $slug) {
            Database::sql('INSERT INTO product_slug (slug, product_id) VALUES (?, ?)', [$slug, $this->stock->id]);
            $this->subscribe(self::GUEST, $slug);
            $this->assertMove($slug, $this->stock->slug);
            self::assertNull(Hilos::$sr->pageResource(self::GUEST));
        }

        $this->subscribe(self::GUEST, $this->stock->slug);
        $category = Hilos::$db->categories[$this->stock->categoryId];
        self::assertSame([
            'id' => $this->stock->id,
            'slug' => $this->stock->slug,
            'name' => $this->stock->name,
            'kind' => 'stock',
            'price' => 12_000,
            'description' => 'Product of the case',
            'pictureUrl' => HilosFiles::downloadPath($this->stock->pictureFileId, CatalogPictureUploadTarget::VARIANT_LG),
            'units' => 0,
            'availability' => 'out_of_stock',
            'categoryName' => $category->name,
            'categorySlug' => $category->slug,
        ], $this->takeCard(self::GUEST));
        self::assertSame((string) $this->stock->id, Hilos::$sr->pageResource(self::GUEST)?->idString);
    }

    /** Unknown, inexact and unplaced addresses get a 404. Missing and empty slugs use the app fallback. */
    public function testInvalidAddressesReceiveOneOrdinarySubscriptionError(): void
    {
        $formerSlug = 'former-product-address';
        $formerUnplacedSlug = 'former-unplaced-address';
        Database::sql('INSERT INTO product_slug (slug, product_id) VALUES (?, ?)', [$formerSlug, $this->stock->id]);
        Database::sql('INSERT INTO product_slug (slug, product_id) VALUES (?, ?)', [$formerUnplacedSlug, $this->unplaced->id]);

        foreach (['no-such-product', $this->unplaced->slug, $this->stock->slug . ' ', $formerSlug . ' ', $formerUnplacedSlug] as $slug) {
            $this->subscribe(self::GUEST, $slug);
            $refusal = $this->takeRefusal(self::GUEST, HttpConstants::HTTP_NOT_FOUND);
            self::assertSame('not_found', $refusal->errorCode);
            self::assertSame('The page you are looking for does not exist.', $refusal->message);
            self::assertNull(Hilos::$sr->pageResource(self::GUEST));
        }

        foreach ([null, ''] as $slug) {
            $this->subscribe(self::GUEST, $slug);
            $this->takeRefusal(self::GUEST, HttpConstants::HTTP_BAD_REQUEST);
        }
    }

    /** First placement answers the guest's preserved subscription at the exact current address. */
    public function testFirstPlacementAnswersThePreviouslyRefusedProductPage(): void
    {
        $productId = $this->unplaced->id;
        $slug = $this->unplaced->slug;
        $this->subscribe(self::GUEST, $slug);
        $this->takeRefusal(self::GUEST, HttpConstants::HTTP_NOT_FOUND);
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
        self::assertNull(Hilos::$sr->pageResource(self::GUEST));
        $this->subscribe(self::VISITOR, $slug . ' ');
        $this->takeRefusal(self::VISITOR, HttpConstants::HTTP_NOT_FOUND);

        Hilos::$db->productWarehouseStocks->actions->place($productId, $this->warehouse);
        $row = Database::sql('SELECT * FROM product_warehouse_stock WHERE product_id = ?', [$productId])->rows()[0];
        $this->deliver(
            self::productPriceFact($productId, 15_500),
            new WorkerDbSyncCreatedMessageDTO(new DbSyncCreatedSignalData(
                EcommerceShopDbContext::productWarehouseStocks,
                (string) $row[EntityProductWarehouseStock::id],
                $row,
            )),
        );

        $card = $this->takeCard(self::GUEST);
        self::assertSame($productId, $card['id']);
        self::assertSame($slug, $card['slug']);
        self::assertSame(15_500, $card['price']);
        self::assertSame(1, $card['units']);
        self::assertSame('free', $card['availability']);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
        self::assertSame((string) $productId, Hilos::$sr->pageResource(self::GUEST)?->idString);
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::VISITOR));
        self::assertNull(Hilos::$sr->pageResource(self::VISITOR));
    }

    /** A newly created bulk product pays the debt at its own address with the zero-stock card. */
    public function testProductCreationAnswersOnlyTheMatchingUnknownAddress(): void
    {
        $slug = 'new-stock-product';
        $this->subscribe(self::GUEST, $slug);
        $this->takeRefusal(self::GUEST, HttpConstants::HTTP_NOT_FOUND);
        $this->subscribe(self::VISITOR, 'another-unknown-product');
        $this->takeRefusal(self::VISITOR, HttpConstants::HTTP_NOT_FOUND);

        $productId = self::product(ProductKind::STOCK, $this->stock->categoryId);
        Database::sql('UPDATE product SET slug = ? WHERE id = ?', [$slug, $productId]);
        $row = Database::sql('SELECT * FROM product WHERE id = ?', [$productId])->rows()[0];
        $this->deliver(new WorkerDbSyncCreatedMessageDTO(
            new DbSyncCreatedSignalData(EcommerceShopDbContext::products, (string) $productId, $row),
        ));

        $card = $this->takeCard(self::GUEST);
        self::assertSame($productId, $card['id']);
        self::assertSame($slug, $card['slug']);
        self::assertSame(0, $card['units']);
        self::assertSame('out_of_stock', $card['availability']);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::VISITOR));
        self::assertNull(Hilos::$sr->pageResource(self::VISITOR));
    }

    /** A refused numeric address does not hydrate unrelated products during a category update. */
    public function testRefusedNumericAddressKeepsUnrelatedProductsCold(): void
    {
        $this->subscribe(self::GUEST, '0');
        $this->takeRefusal(self::GUEST, HttpConstants::HTTP_NOT_FOUND);
        self::assertFalse($this->readerHoldsProduct($this->stock->id));
        self::assertFalse($this->readerHoldsProduct($this->other->id));

        $this->deliver(self::renameCategory($this->stock->categoryId, 'Spring bulbs'));

        self::assertSame([], $this->takeFrames());
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
        self::assertFalse($this->readerHoldsProduct($this->stock->id));
        self::assertFalse($this->readerHoldsProduct($this->other->id));
    }

    /** An uncertain fact compares against the stock count actually sent in the subscription answer. */
    public function testReconciliationUsesTheDeliveredStockSnapshot(): void
    {
        $views = new ReflectionProperty(DbContext::class, '_dbItemCollections');
        $original = $views->getValue($this->reader);
        $stockView = new class extends DbCollection {
            public int $reads = 0;

            public function __construct()
            {
            }

            public function storedUnitsOfProduct(int $productId): int
            {
                // Each read sees another unit, as when a stock write has committed before its sync arrives.
                return ++$this->reads;
            }
        };
        $views->setValue($this->reader, array_replace($original, [EcommerceShopDbContext::productWarehouseStocks => $stockView]));
        try {
            $this->subscribe(self::GUEST, $this->stock->slug);
        } finally {
            $views->setValue($this->reader, $original);
        }
        $card = $this->takeCard(self::GUEST);
        self::stock($this->stock->id, $this->warehouse, $card['units']);
        self::stock($this->other->id, $this->warehouse, 1);
        $unrelated = self::updateStock(Database::lastInsertId());
        $this->deliverWithStockReadFailure($unrelated, duringAddressLookup: true);
        self::assertSame([], $this->takeFrames());

        $this->deliverDatabaseRecovery();

        self::assertSame([], $this->takeFrames());
        self::assertSame(1, $stockView->reads);
        self::assertSame(1, $card['units']);
    }

    /** A units-only fact for another address leaves the unrelated stock row cold. */
    public function testUnrelatedSparseStockUpdateDoesNotLoadTheStockRow(): void
    {
        $this->subscribe(self::GUEST, $this->stock->slug);
        $this->takeCard(self::GUEST);

        self::stock($this->other->id, $this->warehouse, 1);
        $rowId = Database::lastInsertId();
        self::assertFalse($this->readerHoldsStock($rowId));
        self::assertFalse($this->readerHoldsProduct($this->other->id));
        $this->deliver(self::updateStock($rowId));

        self::assertSame([], $this->takeFrames());
        self::assertFalse($this->readerHoldsStock($rowId));
        self::assertFalse($this->readerHoldsProduct($this->other->id));

        $this->updateProduct($this->other->id, 15_500);
        self::assertSame([], $this->takeFrames());
        self::assertFalse($this->readerHoldsProduct($this->other->id));
    }

    /** The cold stock row costs one keyed read regardless of how many other product addresses are open. */
    public function testUnrelatedSparseStockLookupCountIsIndependentOfOpenAddresses(): void
    {
        $this->subscribe(self::GUEST, $this->stock->slug);
        $this->takeCard(self::GUEST);
        self::stock($this->unplaced->id, $this->warehouse, 1);
        $rowId = Database::lastInsertId();
        $fact = self::updateStock($rowId);
        $queries = self::selectCount();
        $this->deliver($fact);
        // The rollback scope runs one SQL-mode SELECT before the stock SELECT and one before the status read.
        self::assertSame(3, self::selectCount() - $queries);
        self::assertSame([], $this->takeFrames());
        self::assertFalse($this->readerHoldsStock($rowId));

        $this->subscribe(self::VISITOR, $this->other->slug);
        $this->takeCard(self::VISITOR);
        self::stock($this->unplaced->id, self::warehouse(), 1);
        $rowId = Database::lastInsertId();
        $fact = self::updateStock($rowId);
        $queries = self::selectCount();
        $this->deliver($fact, $fact);
        self::assertSame(3, self::selectCount() - $queries);
        self::assertSame([], $this->takeFrames());
        self::assertFalse($this->readerHoldsStock($rowId));
        self::assertFalse($this->readerHoldsProduct($this->unplaced->id));
    }

    /** A failed snapshot for the held stock row leaves a debt only on the matching address. */
    public function testFailedSparseStockDeliveryRecoversWithoutNavigation(): void
    {
        self::stock($this->stock->id, $this->warehouse, 1);
        $rowId = Database::lastInsertId();
        $this->reader->productWarehouseStocks[$rowId];
        $this->subscribe(self::GUEST, $this->stock->slug);
        self::assertSame(1, $this->takeCard(self::GUEST)['units']);
        self::assertTrue($this->readerHoldsStock($rowId));
        $this->subscribe(self::VISITOR, $this->other->slug);
        $this->takeCard(self::VISITOR);

        $this->deliverWithStockReadFailure(self::updateStock($rowId));
        $this->takeRefusal(self::GUEST, HttpConstants::HTTP_INTERNAL_ERROR);
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::VISITOR));

        self::assertSame((string)$this->stock->id, Hilos::$sr->pageResource(self::GUEST)?->idString);
        self::assertNull(ProductPage::viewerSubscriptionResource(self::GUEST, $this->stock->slug));
        $this->deliver(self::renameCategory($this->stock->categoryId, 'Spring bulbs'));
        $card = $this->takeCard(self::GUEST);
        self::assertSame($this->stock->id, $card['id']);
        self::assertSame('Spring bulbs', $card['categoryName']);
        self::assertSame(2, $card['units']);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
    }

    /** A failed live rebuild and a failed silent probe leave the former address able to move. */
    public function testFailedProductDeliveryFollowsItsRenamedAddress(): void
    {
        self::stock($this->stock->id, $this->warehouse, 1);
        $rowId = Database::lastInsertId();
        $oldSlug = $this->stock->slug;
        $this->subscribe(self::GUEST, $oldSlug);
        $this->takeCard(self::GUEST);
        $this->subscribe(self::VISITOR, $this->other->slug);
        $this->takeCard(self::VISITOR);
        self::assertSame(1, Hilos::$rt->productViewerCounts[$oldSlug]?->viewerCount);

        $this->deliverWithStockReadFailure(self::updateStock($rowId));
        $this->takeRefusal(self::GUEST, HttpConstants::HTTP_INTERNAL_ERROR);
        self::assertNull(Hilos::$rt->productViewerCounts[$oldSlug]);
        self::assertNull(ProductPage::viewerSubscriptionResource(self::GUEST, $oldSlug));
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));

        $this->deliverWithStockReadFailure(self::renameCategory($this->stock->categoryId, 'Spring bulbs'), structured: true);
        self::assertSame([], $this->takeFrames());
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
        self::assertNull(Hilos::$rt->productViewerCounts[$oldSlug]);

        $this->deliver($this->renameProduct($this->stock));
        $newSlug = $this->stock->slug;
        $this->assertMove($oldSlug, $newSlug);
        self::assertNull(Hilos::$sr->pageResource(self::GUEST));
        self::assertNull(Hilos::$rt->productViewerCounts[$oldSlug]);
        Hilos::$browser->record(SourceChange::dbCleared(EcommerceShopDbContext::userProductCarts));
        $this->deliver();
        $frame = $this->takeFrame(self::VISITOR)->data;
        self::assertInstanceOf(PageResponseSignalData::class, $frame);
        self::assertSame(['productId' => $this->other->id, 'slug' => $this->other->slug, 'cartCount' => 0],
            $frame->payload->data[ProductPage::DATA_PRODUCT_CARTS]);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));

        $this->openAdminPage(AdminProductPage::PAGE);
        $overview = $this->takeFrame(self::ADMIN)->data;
        self::assertInstanceOf(PageResponseSignalData::class, $overview);
        self::assertSame(0, $overview->payload->data[AdminProductBrowserData::DATA][AdminProductBrowserData::FIELD_VIEWER_COUNT]);

        $this->subscribe(self::GUEST, $newSlug);
        $card = $this->takeFrame(self::GUEST);
        self::assertInstanceOf(PageResponseSignalData::class, $card->data);
        self::assertSame($newSlug, self::card($card)['slug']);
        self::assertSame(2, self::card($card)['units']);
        self::assertSame(
            ['slug' => $newSlug, 'otherViewerCount' => 0],
            $card->data->payload->data[ProductPage::DATA_PRODUCT_VIEWERS],
        );
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
        self::assertNull(Hilos::$rt->productViewerCounts[$oldSlug]);
        $viewers = Hilos::$rt->productViewerCounts[$newSlug];
        self::assertSame($this->stock->id, $viewers?->productId);
        self::assertSame(1, $viewers->viewerCount);
        self::assertSame(1, Hilos::$rt->productViewerCounts[$this->other->slug]?->viewerCount);
        self::assertCount(2, Hilos::$rt->productViewerCounts);

        // The rollback scope holds commit-time announcements. Supply the fact from the row the agent wrote.
        Hilos::$browser->record(SourceChange::rtCreated(EcommerceShopRtContext::productViewerCounts, $newSlug, $viewers->toArray()));
        $this->deliver();
        $overview = $this->takeFrame(self::ADMIN)->data;
        self::assertInstanceOf(PageResponseSignalData::class, $overview);
        self::assertSame(AdminProductPage::PAGE, $overview->pageKey);
        $adminCard = $overview->payload->data[AdminProductBrowserData::DATA];
        self::assertSame($this->stock->id, $adminCard[AdminProductBrowserData::productId]);
        self::assertSame(1, $adminCard[AdminProductBrowserData::FIELD_VIEWER_COUNT]);
    }

    /**
     * A rename recovering a retained product resource still belongs to the ordinary page admission.
     *
     * @param bool $freeze Whether to freeze before the probe, or replace its registration during the read
     */
    #[TestWith([true])]
    #[TestWith([false])]
    public function testRenamedProductDebtRespectsTheCurrentAdmission(bool $freeze): void
    {
        $productId = $this->stock->id;
        $oldSlug = $this->stock->slug;
        $this->subscribe(self::GUEST, $oldSlug);
        $this->takeCard(self::GUEST);
        $this->deliverWithCartCountFailure($productId, self::productPriceFact($productId, 1500));
        $this->takeRefusal(self::GUEST, HttpConstants::HTTP_INTERNAL_ERROR);
        self::assertSame((string) $productId, Hilos::$sr->pageResource(self::GUEST)?->idString);
        $rename = $this->renameProduct($this->stock);

        if ($freeze) {
            $previous = Hilos::$rt->hilosProtectedModeRuntime->toArray();
            $active = array_replace($previous, [ProtectedModeRuntime::phase => ProtectedModeRuntime::PHASE_ACTIVE]);
            Hilos::$rt->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::fromRow($active));
            try {
                self::assertSame([[], []], array_values($this->observeCartCountReads(fn () => $this->deliver($rename))));
                self::assertSame([], $this->takeFrames());
                self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
            } finally {
                Hilos::$rt->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::fromRow($previous));
            }

            $this->deliverDatabaseRecovery();
            $this->assertMove($oldSlug, $this->stock->slug);
            return;
        }

        $neighborSlug = $this->other->slug;
        $this->withAddressReadCheck(
            EcommerceShopDbContext::products,
            static function (string $column, array $values) use ($oldSlug, $neighborSlug): void {
                if ($column === EntityProduct::slug && $values === [$oldSlug]) {
                    Hilos::$sr->subscribeToPage(ProductPage::PAGE, new WebSocketPageSubscribeSignalDTO(
                        self::GUEST,
                        ProductPage::PAGE,
                        [ProductPageSubscribeParams::SLUG => $neighborSlug],
                    ));
                    Hilos::$sr->clearPageDeliveryFailure(self::GUEST);
                    Hilos::$sr->markPageDeliveryFailure(self::GUEST);
                }
            },
            fn () => $this->deliver($rename),
        );
        self::assertSame([], $this->takeFrames());
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
        self::assertSame([ProductPageSubscribeParams::SLUG => $neighborSlug], Hilos::$sr->pageSubscription(self::GUEST)->params);
    }

    /** The first browser 500 leaves a searched Stock window available to the next full answer. */
    public function testFailedStockPublicationRecoversItsSearchedWindow(): void
    {
        $kyiv = self::warehouse('Kyiv');
        $lviv = self::warehouse('Lviv');
        self::stock($this->stock->id, $kyiv, 30);
        $filter = [ProductStockTable::FILTER_PRODUCT => $this->stock->id, TableConstants::FILTER_KEY_SEARCH => 'Kyiv'];
        $this->openAdminPage(AdminProductStockPage::PAGE, [ProductStockTable::TABLE => new TableWindowDescriptorDTO(
            filter: $filter,
            sort: Hilos::$table->productStock->defaultSort(),
            limit: 1,
        )]);
        $initial = $this->takeFrame(self::ADMIN)->data;
        self::assertInstanceOf(PageResponseSignalData::class, $initial);
        $window = $initial->payload->windows[ProductStockTable::TABLE];
        self::assertSame($filter, (array)$window[TableWindowDescriptorDTO::FILTER]);
        self::assertSame([$kyiv], array_column($window[TableWindowSignalData::rows], PagePayload::rowKey));
        $subscription = Hilos::$sr->pageSubscription(self::ADMIN);
        Hilos::$sr->setTableFocus(self::ADMIN, ProductStockTable::TABLE, (string)$kyiv);

        $this->deliverWithStockReadFailure(self::productPriceFact($this->stock->id, 700), containedFailures: 1);
        $failure = $this->takeFrame(self::ADMIN)->data;
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $failure);
        self::assertSame(AdminProductStockPage::PAGE, $failure->page);
        self::assertSame(HttpConstants::HTTP_INTERNAL_ERROR, $failure->httpCode);
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::ADMIN));

        $library = new ProductsLibraryAgent();
        $focus = new DaemonAgentMessageDTO($library->getId(), new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::TABLE_ROW_FOCUS),
            new SignalName(AdminProductStockPage::PAGE),
            new WebSocketTableRowFocusSignalDTO(self::ADMIN, AdminProductStockPage::PAGE, ProductStockTable::TABLE, (string)$lviv),
        ));
        $this->deliver($focus);
        self::assertSame([], $this->takeFrames(includeFocus: true));
        self::assertSame((string)$lviv, Hilos::$sr->getTableFocus(self::ADMIN, ProductStockTable::TABLE));

        $this->updateProduct($this->stock->id, 800);
        $recovered = $this->takeFrame(self::ADMIN)->data;
        self::assertInstanceOf(PageResponseSignalData::class, $recovered);
        $recoveredWindow = $recovered->payload->windows[ProductStockTable::TABLE];
        self::assertSame($filter, (array)$recoveredWindow[TableWindowDescriptorDTO::FILTER]);
        self::assertSame(1, $recoveredWindow[TableWindowSignalData::limit]);
        self::assertSame($window[TableWindowDescriptorDTO::SORT], $recoveredWindow[TableWindowDescriptorDTO::SORT]);
        self::assertSame($window[TableWindowSignalData::rows], $recoveredWindow[TableWindowSignalData::rows]);
        self::assertSame($subscription, Hilos::$sr->pageSubscription(self::ADMIN));
        self::assertSame((string)$lviv, Hilos::$sr->getTableFocus(self::ADMIN, ProductStockTable::TABLE));
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::ADMIN));

        $this->deliver($focus);
        $frames = $this->takeFrames(includeFocus: true);
        self::assertCount(1, $frames);
        self::assertSame(self::ADMIN, $frames[0]->targetAcceptKey);
        $delta = $frames[0]->data;
        self::assertInstanceOf(TableViewportDeltaDTO::class, $delta);
        self::assertSame(TableViewportDeltaDTO::KIND_ROW_REMOVED, $delta->kind);
        self::assertSame(TableViewportDeltaDTO::REASON_LEFT_SET, $delta->reason);
        self::assertSame((string)$lviv, (string)$delta->rowKey);

        $this->deliver(new DaemonAgentMessageDTO($library->getId(), new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_UNSUBSCRIBE),
            new SignalName(AdminProductStockPage::PAGE),
            new WebSocketPageUnsubscribeSignalDTO(self::ADMIN),
        )));
        self::assertNull(Hilos::$sr->getTableViewport(self::ADMIN, ProductStockTable::TABLE));
        self::assertNull(Hilos::$sr->getTableFocus(self::ADMIN, ProductStockTable::TABLE));
    }

    /** A category refresh uses the same number of reads with several unrelated bound products. */
    public function testCategoryLookupCountIsIndependentOfOtherOpenProductsInTheBatch(): void
    {
        $this->subscribe(self::GUEST, $this->stock->slug);
        $this->takeCard(self::GUEST);

        $refresh = function (string $name): int {
            $fact = self::renameCategory($this->stock->categoryId, $name);
            $queries = self::selectCount();
            $reads = $this->observeCartCountReads(fn () => $this->deliver($fact));
            $delta = self::selectCount() - $queries;
            self::assertSame($name, $this->takeCard(self::GUEST)['categoryName']);
            self::assertSame([[$this->stock->id], [$this->stock->id]], array_values($reads));

            return $delta;
        };
        $baseline = $refresh('Spring bulbs');

        $this->subscribe(self::VISITOR, $this->other->slug);
        $this->takeCard(self::VISITOR);
        foreach ([self::OTHER_GUEST, self::THIRD_GUEST] as $acceptKey) {
            $product = $this->warmProduct(self::product(ProductKind::STOCK, $this->other->categoryId));
            ExecutionContext::run(new ExecutionFrame(), static function () use ($acceptKey): void {
                Hilos::$rt->connections->actions->register($acceptKey, null, sessionId: 1);
            });
            $this->subscribe($acceptKey, $product->slug);
            $this->takeCard($acceptKey);
        }

        // Each lookup costs its SELECT and the rollback scope's SQL-mode probe.
        self::assertSame($baseline, $refresh('Summer bulbs'));
    }

    /** A category whose prior membership is known gets an addressed failure and one full recovery. */
    public function testFailedCategoryLookupRefusesOnlyItsConfirmedAddress(): void
    {
        $this->subscribe(self::GUEST, $this->other->slug);
        $this->takeCard(self::GUEST);
        $this->subscribe(self::VISITOR, $this->stock->slug);
        $this->takeCard(self::VISITOR);
        self::assertSame(1, Hilos::$rt->productViewerCounts[$this->stock->slug]?->viewerCount);
        $subscription = Hilos::$sr->pageSubscription(self::VISITOR);

        $this->deliverWithProductLookupFailure(self::renameCategory($this->stock->categoryId, 'Spring bulbs'));
        $this->takeRefusal(self::VISITOR, HttpConstants::HTTP_INTERNAL_ERROR);
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::VISITOR));
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
        self::assertSame($subscription, Hilos::$sr->pageSubscription(self::VISITOR));
        self::assertNull(Hilos::$rt->productViewerCounts[$this->stock->slug]?->viewerCount);

        $this->deliverDatabaseRecovery(self::renameCategory($this->stock->categoryId, 'Summer bulbs'));
        self::assertSame('Summer bulbs', $this->takeCard(self::VISITOR)['categoryName']);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::VISITOR));
        self::assertSame(1, Hilos::$rt->productViewerCounts[$this->stock->slug]?->viewerCount);
        $this->deliverDatabaseRecovery();
        self::assertSame([], $this->takeFrames());
    }

    public function testAKeyedReadFailureCannotFailTheNewProductAddress(): void
    {
        $this->subscribe(self::GUEST, $this->stock->slug);
        $this->takeCard(self::GUEST);
        $this->subscribe(self::VISITOR, $this->other->slug);
        $this->takeCard(self::VISITOR);
        $router = Hilos::$sr;
        $neighbor = $router->pageSubscription(self::VISITOR);
        $resource = $router->pageResource(self::VISITOR);
        $this->deliverWithProductLookupFailure(
            self::productPriceFact($this->stock->id, 1500),
            static function () use ($router, $neighbor, $resource): void {
                $router->subscribeToPage(ProductPage::PAGE, new WebSocketPageSubscribeSignalDTO(self::GUEST, ProductPage::PAGE, $neighbor->params));
                $router->bindPageAgent(self::GUEST, ProductPage::PAGE, $neighbor->agentType, $neighbor->agentIndex);
                $router->bindPageResource(self::GUEST, $resource);
            },
        );
        self::assertSame([], $this->takeFrames());
        self::assertFalse($router->hasPageDeliveryFailure(self::GUEST));
        self::assertFalse($router->hasPageDeliveryFailure(self::VISITOR));
        self::assertSame($resource, $router->pageResource(self::GUEST));

        $this->deliver(self::productPriceFact($this->other->id, 1700));
        $replies = $this->takeFrames();
        self::assertSame([self::GUEST, self::VISITOR], array_column($replies, 'targetAcceptKey'));
        foreach ($replies as $reply) {
            self::assertInstanceOf(PageResponseSignalData::class, $reply->data);
        }
    }

    /** A cold stock key that could not be resolved is retried after recovery, with no unrelated frames. */
    public function testFailedColdStockLookupRetriesOnlyTheAddressItFinds(): void
    {
        $this->subscribe(self::GUEST, $this->stock->slug);
        self::assertSame(0, $this->takeCard(self::GUEST)['units']);
        $this->subscribe(self::VISITOR, $this->other->slug);
        $this->takeCard(self::VISITOR);
        self::stock($this->stock->id, $this->warehouse, 1);
        $rowId = Database::lastInsertId();
        self::assertFalse($this->readerHoldsStock($rowId));

        $this->deliverWithStockReadFailure(self::updateStock($rowId), duringAddressLookup: true);
        self::assertSame([], $this->takeFrames());
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::VISITOR));

        $this->deliverDatabaseRecovery();
        $card = $this->takeCard(self::GUEST);
        self::assertSame($this->stock->id, $card['id']);
        self::assertSame(2, $card['units']);
        $this->deliverDatabaseRecovery();
        self::assertSame([], $this->takeFrames());
    }

    /** Independent recovered warehouse totals can reconcile their cards even when another part still fails. */
    public function testWarehouseReconciliationKeepsTheReadablePart(): void
    {
        $badId = $this->stock->id;
        $goodId = $this->other->id;
        $this->subscribe(self::GUEST, $this->stock->slug);
        $this->takeCard(self::GUEST);
        $this->subscribe(self::VISITOR, $this->other->slug);
        $this->takeCard(self::VISITOR);
        self::stock($badId, $this->warehouse, 1);
        self::stock($goodId, $this->warehouse, 2);
        self::stock($this->unplaced->id, $this->warehouse, 1);
        $this->deliverWithStockReadFailure(self::updateStock(Database::lastInsertId()), duringAddressLookup: true);
        self::assertSame([], $this->takeFrames());

        $views = new ReflectionProperty(DbContext::class, '_dbItemCollections');
        $original = $views->getValue($this->reader);
        $checked = new class ($original[EcommerceShopDbContext::productWarehouseStocks], $badId) extends DbCollection {
            /** @var list<list<int>> */
            public array $batches = [];

            public function __construct(private readonly ProductWarehouseStocks $original, private readonly int $badId)
            {
            }

            /** @return array<int, int> Warehouse units by product */
            public function storedUnitsOfProducts(int ...$ids): array
            {
                $this->batches[] = $ids;
                if (count($ids) > 1) {
                    throw new DatabaseException('The stock batch exceeds the packet limit', 1153);
                }
                if ($ids === [$this->badId]) {
                    throw new DatabaseException('This warehouse total is unavailable');
                }
                return $this->original->storedUnitsOfProducts(...$ids);
            }

            public function storedUnitsOfProduct(int $id): int
            {
                return $this->original->storedUnitsOfProduct($id);
            }
        };
        $views->setValue($this->reader, array_replace($original, [EcommerceShopDbContext::productWarehouseStocks => $checked]));
        try {
            $this->deliverDatabaseRecovery();
        } finally {
            $views->setValue($this->reader, $original);
        }

        self::assertSame([[$badId, $goodId], [$badId], [$goodId]], $checked->batches);
        self::assertSame(2, $this->takeCard(self::VISITOR)['units']);
        $this->deliverDatabaseRecovery();
        self::assertSame(1, $this->takeCard(self::GUEST)['units']);
    }

    /** An uncertain stock fact is reconciled on the next fact for a live subscription only. */
    public function testUnresolvedStockDoesNotFollowAReplacementSubscription(): void
    {
        $this->subscribe(self::GUEST, $this->stock->slug);
        $this->takeCard(self::GUEST);
        $this->subscribe(self::VISITOR, $this->stock->slug);
        $this->takeCard(self::VISITOR);

        for ($index = 0; $index < 3; $index++) {
            self::stock($this->stock->id, self::warehouse(), 1);
            $rowId = Database::lastInsertId();
            $this->deliverWithStockReadFailure(self::updateStock($rowId), duringAddressLookup: true);
            // The next eligible fact may repair the previous round even when
            // its own cold key cannot be read yet.
            $frames = $this->takeFrames();
            self::assertCount($index === 0 ? 0 : 2, $frames);
        }

        $this->subscribe(self::VISITOR, $this->stock->slug);
        self::assertSame(6, $this->takeCard(self::VISITOR)['units']);
        $this->deliver(self::renameCategory($this->other->categoryId, 'Autumn vases'));
        self::assertSame([], $this->takeFrames());
        $this->deliverDatabaseRecovery();
        self::assertSame([], $this->takeFrames());
    }

    /** Multiple source facts publish the last category, price and stock in one full card per tab. */
    public function testMixedCatalogFactsPublishOneFinalCardPerSubscription(): void
    {
        self::stock($this->stock->id, $this->warehouse, 1);
        $rowId = Database::lastInsertId();
        $this->subscribe(self::GUEST, $this->stock->slug);
        $this->takeCard(self::GUEST);
        $this->subscribe(self::VISITOR, $this->stock->slug);
        $this->takeCard(self::VISITOR);

        $this->deliver(
            self::renameCategory($this->stock->categoryId, 'Spring bulbs'),
            self::productPriceFact($this->stock->id, 15_500),
            self::updateStock($rowId),
            self::renameCategory($this->stock->categoryId, 'Summer bulbs'),
        );
        $frames = $this->takeFrames();
        self::assertCount(2, $frames);
        self::assertEqualsCanonicalizing([self::GUEST, self::VISITOR], array_column($frames, 'targetAcceptKey'));
        foreach ($frames as $frame) {
            $card = self::card($frame);
            self::assertSame('Summer bulbs', $card['categoryName']);
            self::assertSame(15_500, $card['price']);
            self::assertSame(2, $card['units']);
        }
    }

    /** Applied product, category and stock facts refresh only the connections still on that product address. */
    public function testLiveCatalogFactsRefreshOnlyTheCurrentProductAddress(): void
    {
        $this->subscribe(self::GUEST, $this->stock->slug);
        $expected = $this->takeCard(self::GUEST);
        $this->subscribe(self::VISITOR, $this->stock->slug);
        self::assertSame($expected, $this->takeCard(self::VISITOR));

        $this->updateProduct($this->stock->id, 15_500);
        $expected['price'] = 15_500;
        $frames = $this->takeFrames();
        self::assertCount(2, $frames);
        self::assertEqualsCanonicalizing([self::GUEST, self::VISITOR], array_column($frames, 'targetAcceptKey'));
        foreach ($frames as $frame) {
            self::assertSame($expected, self::card($frame));
        }

        $this->subscribe(self::VISITOR, $this->other->slug);
        self::assertSame($this->other->id, $this->takeCard(self::VISITOR)['id']);
        $this->updateProduct($this->other->id, 20_000);
        self::assertSame(20_000, $this->takeCard(self::VISITOR)['price']);

        $categoryId = $this->stock->categoryId;
        Database::sql('UPDATE category SET name = ?, slug = ? WHERE id = ?', ['Spring flowers', 'spring-flowers', $categoryId]);
        $this->deliver(new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
            EcommerceShopDbContext::categories,
            (string) $categoryId,
            [EntityCategory::name => 'Spring flowers', EntityCategory::slug => 'spring-flowers'],
        )));
        $expected['categoryName'] = 'Spring flowers';
        $expected['categorySlug'] = 'spring-flowers';
        self::assertSame($expected, $this->takeCard(self::GUEST));

        Hilos::$db->productWarehouseStocks->actions->setUnits($this->stock->id, $this->warehouse, 2);
        $row = Database::sql('SELECT * FROM product_warehouse_stock WHERE product_id = ?', [$this->stock->id])->rows()[0];
        $rowId = (string) $row[EntityProductWarehouseStock::id];
        $this->deliver(new WorkerDbSyncCreatedMessageDTO(
            new DbSyncCreatedSignalData(EcommerceShopDbContext::productWarehouseStocks, $rowId, $row),
        ));
        $expected['units'] = 2;
        $expected['availability'] = 'low';
        self::assertSame($expected, $this->takeCard(self::GUEST));

        Hilos::$db->productWarehouseStocks->actions->setUnits($this->stock->id, $this->warehouse, 6);
        $this->deliver(new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
            EcommerceShopDbContext::productWarehouseStocks,
            $rowId,
            [EntityProductWarehouseStock::units => 6],
        )));
        $expected['units'] = 6;
        $expected['availability'] = 'in_stock';
        self::assertSame($expected, $this->takeCard(self::GUEST));

        Hilos::$db->productWarehouseStocks->actions->setUnits($this->stock->id, $this->warehouse, 0);
        $this->deliver(new WorkerDbSyncDeletedMessageDTO(new DbSyncDeletedSignalData(
            EcommerceShopDbContext::productWarehouseStocks,
            $rowId,
            [ObjectProductWarehouseStock::productId => $this->stock->id],
        )));
        $expected['units'] = 0;
        $expected['availability'] = 'out_of_stock';
        self::assertSame($expected, $this->takeCard(self::GUEST));

        $oldSlug = $this->stock->slug;
        $newSlug = 'renamed-product';
        Database::sql('UPDATE product SET slug = ? WHERE id = ?', [$newSlug, $this->stock->id]);
        $this->deliver(new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
            EcommerceShopDbContext::products,
            (string) $this->stock->id,
            [EntityProduct::slug => $newSlug],
            previous: [EntityProduct::slug => $oldSlug],
        )));
        $this->assertMove($oldSlug, $newSlug);
        self::assertNull(Hilos::$sr->pageResource(self::GUEST));
        self::assertSame((string) $this->other->id, Hilos::$sr->pageResource(self::VISITOR)?->idString);

        $this->subscribe(self::GUEST, $newSlug);
        $expected['slug'] = $newSlug;
        self::assertSame($expected, $this->takeCard(self::GUEST));
    }

    /** The registered product resource gives its open page the ordinary deletion refusal. */
    public function testDeletingAnOpenProductRefusesOnlyItsSubscription(): void
    {
        $this->subscribe(self::GUEST, $this->stock->slug);
        $this->takeCard(self::GUEST);
        $this->subscribe(self::VISITOR, $this->other->slug);
        $this->takeCard(self::VISITOR);
        $resource = Hilos::$sr->pageResource(self::GUEST);
        self::assertSame(EcommerceShopDbContext::products, $resource?->collectionKey);
        self::assertSame((string) $this->stock->id, $resource->idString);

        $category = self::renameCategory($this->stock->categoryId, 'Spring bulbs');
        Database::sql('DELETE FROM product WHERE id = ?', [$this->stock->id]);
        $this->deliverDatabaseRecovery(
            $category,
            new WorkerDbSyncDeletedMessageDTO(
                new DbSyncDeletedSignalData(EcommerceShopDbContext::products, (string) $this->stock->id),
            ),
        );

        $refusal = $this->takeRefusal(self::GUEST, HttpConstants::HTTP_NOT_FOUND);
        self::assertSame(PageErrorMessages::RESOURCE_DELETED_MESSAGE, $refusal->transitionMessage);
        self::assertNull(Hilos::$sr->pageResource(self::GUEST));
        self::assertSame((string) $this->other->id, Hilos::$sr->pageResource(self::VISITOR)?->idString);
    }

    /** Adds stock products in different categories and an unplaced piece, then identifies the two connections. */
    protected function setUpInTransaction(): void
    {
        parent::setUpInTransaction();
        $this->warehouse = self::warehouse();
        $stock = self::product(ProductKind::STOCK, self::category('Bulbs'));
        $other = self::product(ProductKind::STOCK, self::category('Vases'));
        $unplaced = self::product(ProductKind::UNIQUE);
        $this->stock = Hilos::$db->products[$stock];
        $this->other = Hilos::$db->products[$other];
        $this->unplaced = Hilos::$db->products[$unplaced];
        $this->guestCartSessionId = self::createGuestCartSession();

        Database::sql('INSERT INTO hilos_user (name, admin, block) VALUES (?, 0, 0)', ['Product visitor']);
        $visitorId = Database::lastInsertId();
        ExecutionContext::run(new ExecutionFrame(), static function () use ($visitorId): void {
            Hilos::$rt->connections->actions->register(self::GUEST, null, sessionId: 1);
            Hilos::$rt->connections->actions->register(self::VISITOR, $visitorId);
        });
    }

    /**
     * @param string $page Admin page backed by the products library
     * @param array<string, TableWindowDescriptorDTO> $windows Windows held by the browser
     */
    private function openAdminPage(string $page, array $windows = []): void
    {
        Database::sql('INSERT INTO hilos_user (name, admin, block) VALUES (?, 1, 0)', ['Product admin']);
        $adminId = Database::lastInsertId();
        ExecutionContext::run(new ExecutionFrame(), static function () use ($adminId): void {
            Hilos::$rt->connections->actions->register(self::ADMIN, $adminId);
        });
        $library = new ProductsLibraryAgent();
        $this->agents->addAgent($library->getId(), $library);
        $this->deliver(new DaemonAgentMessageDTO($library->getId(), new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName($page),
            new WebSocketPageSubscribeSignalDTO(
                self::ADMIN,
                $page,
                [AdminProductPageSubscribeParams::PRODUCT_ID => (string)$this->stock->id],
                $windows,
            ),
        )));
    }

    /**
     * @param Product $product Product to rename through its owner
     * @return WorkerDbSyncUpdatedMessageDTO Catalog fact the caller may delay
     */
    private function renameProduct(Product $product): WorkerDbSyncUpdatedMessageDTO
    {
        $library = new ProductsLibraryAgent();
        $oldSlug = $product->slug;
        self::asFixtureOwner($library, static function () use ($library, $product, $oldSlug): void {
            $library->onStart();
            Hilos::$db->productSlugs->actions->record($oldSlug, $product->id);
            $product->actions->update(
                'Renamed ' . $product->name,
                $product->categoryId,
                $product->kind,
                $product->price,
                $product->description,
                null,
            );
        });

        return new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
            EcommerceShopDbContext::products,
            (string) $product->id,
            [EntityProduct::slug => $product->slug, EntityProduct::name => $product->name],
            previous: [EntityProduct::slug => $oldSlug],
        ));
    }

    /** Asserts that the only frame is the addressed data-only cart count. */
    private function assertCartCountFrame(int $productId, string $slug, int $count): void
    {
        $response = $this->takeFrame(self::GUEST)->data;
        self::assertInstanceOf(PageResponseSignalData::class, $response);
        self::assertSame(ProductPage::PAGE, $response->pageKey);
        self::assertSame(new PagePayload(data: [
            'productCarts' => ['productId' => $productId, 'slug' => $slug, 'cartCount' => $count],
        ])->toArray(), $response->payload->toArray());
    }

    /**
     * @param int $productId Product whose price changes
     * @param int $price New price in kopecks
     */
    private function updateProduct(int $productId, int $price): void
    {
        $this->deliver(self::productPriceFact($productId, $price));
    }

    /**
     * @param int $productId Product updated in the store
     * @param int $price New price in kopecks
     * @return WorkerDbSyncUpdatedMessageDTO Applied change the reader receives
     */
    private static function productPriceFact(int $productId, int $price): WorkerDbSyncUpdatedMessageDTO
    {
        Database::sql('UPDATE product SET price = ? WHERE id = ?', [$price, $productId]);

        return new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
            EcommerceShopDbContext::products,
            (string) $productId,
            [EntityProduct::price => $price],
        ));
    }

    /**
     * @param int $categoryId Category updated in the store
     * @param string $name New category name
     * @return WorkerDbSyncUpdatedMessageDTO Applied change the reader receives
     */
    private static function renameCategory(int $categoryId, string $name): WorkerDbSyncUpdatedMessageDTO
    {
        Database::sql('UPDATE category SET name = ? WHERE id = ?', [$name, $categoryId]);

        return new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
            EcommerceShopDbContext::categories,
            (string) $categoryId,
            [EntityCategory::name => $name],
        ));
    }

    /** @return int SELECT statements executed on the integration connection */
    private static function selectCount(): int
    {
        return (int) Database::sql("SHOW SESSION STATUS LIKE 'Com_select'")->field('Value');
    }

    /**
     * @param int $rowId Stock row holding one unit before the update
     * @return WorkerDbSyncUpdatedMessageDTO The units-only fact for the new count
     */
    private static function updateStock(int $rowId): WorkerDbSyncUpdatedMessageDTO
    {
        Database::sql('UPDATE product_warehouse_stock SET units = ? WHERE id = ?', [2, $rowId]);

        return new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
            EcommerceShopDbContext::productWarehouseStocks,
            (string) $rowId,
            [EntityProductWarehouseStock::units => 2],
            previous: [EntityProductWarehouseStock::units => 1],
        ));
    }

    /**
     * @param string $acceptKey Connection awaiting the single product reply
     * @return array<string, mixed> Card from the reply
     */
    private function takeCard(string $acceptKey): array
    {
        return self::card($this->takeFrame($acceptKey));
    }

    /**
     * @param WebSocketSignalData $frame Addressed page reply
     * @return array<string, mixed> Card from the reply
     */
    private static function card(WebSocketSignalData $frame): array
    {
        self::assertInstanceOf(PageResponseSignalData::class, $frame->data);
        self::assertSame(ProductPage::PAGE, $frame->data->pageKey);

        return $frame->data->payload->data['product'];
    }

    /**
     * @param string $acceptKey Connection awaiting a refusal
     * @param int $httpCode Expected status code
     * @return PageSubscriptionErrorSignalData The matching refusal
     */
    private function takeRefusal(string $acceptKey, int $httpCode): PageSubscriptionErrorSignalData
    {
        $frame = $this->takeFrame($acceptKey);
        self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $frame->data);
        self::assertSame(ProductPage::PAGE, $frame->data->page);
        self::assertSame($httpCode, $frame->data->httpCode);

        return $frame->data;
    }


}
