<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Closure;
use Demo\EcommerceShop\Agents\EcommerceShopAgent;
use Demo\EcommerceShop\Agents\ProductAgent;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Core\Daemon\EcommerceShopWorkerManager;
use Demo\EcommerceShop\Database\DTO\ProductStockTotals;
use Demo\EcommerceShop\Database\DTO\ProductStockTotalsCollection;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Category as EntityCategory;
use Demo\EcommerceShop\Database\Entity\Item\Product as EntityProduct;
use Demo\EcommerceShop\Database\Entity\Item\ProductWarehouseStock as EntityProductWarehouseStock;
use Demo\EcommerceShop\Database\Object\Item\ProductWarehouseStock as ObjectProductWarehouseStock;
use Demo\EcommerceShop\Database\View\Collection\ProductWarehouseStocks;
use Demo\EcommerceShop\Database\View\Item\Product;
use Demo\EcommerceShop\Files\CatalogPictureUploadTarget;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\DTO\ProductPageSubscribeParams;
use Demo\EcommerceShop\Pages\ProductPage;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PageMovedSignalData;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\PageErrorMessages;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Context\DbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Files\HilosFiles;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Testing\IntegrationTest;
use Hilos\TruthSource\RtTruthSourceRegistry;
use ReflectionProperty;

/** Mounts a real worker and two identified connections over the catalog's rollback scope. */
abstract class ProductPageEnvironment extends StockLibraryEnvironment
{
    protected const string GUEST = 'product-page-guest';
    protected const string VISITOR = 'product-page-visitor';
    private const string CONNECTIONS_OWNER = 'product-page-test';

    protected AgentManager $agents;
    protected EcommerceShopDbContext $reader;
    private EcommerceShopWorkerManager $worker;
    private ?SignalRouter $previousRouter = null;
    private ?BrowserContext $previousBrowser = null;
    private bool $claimedConnections = false;

    /** Mounts a production worker and a separate ORM reader without opening a daemon link. */
    protected function prepareIntegrationEnvironment(): void
    {
        $this->previousRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        parent::prepareIntegrationEnvironment();
        $this->reader = new EcommerceShopDbContext();
        $this->reader->configure();
        Hilos::initBrowser();
        $this->worker = new EcommerceShopWorkerManager(1);
        $this->agents = new ReflectionProperty(WorkerManager::class, 'agentManager')->getValue($this->worker);
        Hilos::$browser->bindPageResender($this->worker);
        RtTruthSourceRegistry::register(EcommerceShopRtContext::connections, TruthSourceKeys::all(), self::CONNECTIONS_OWNER);
        $this->claimedConnections = true;
    }

    /** Releases connections and restores the facades used outside the case. */
    protected function releaseIntegrationEnvironment(): void
    {
        try {
            if ($this->claimedConnections) {
                ExecutionContext::run(new ExecutionFrame(), static function (): void {
                    foreach ([self::GUEST, self::VISITOR] as $acceptKey) {
                        Hilos::$rt->connections[$acceptKey]?->actions->unregister();
                        SourceInterestRegistry::releaseConsumer(SourceConsumer::page($acceptKey));
                    }
                });
                RtTruthSourceRegistry::unregisterAgent(self::CONNECTIONS_OWNER);
            }
        } finally {
            Hilos::$sr = $this->previousRouter;
            if ($this->previousBrowser === null) {
                Hilos::resetBrowser();
            } else {
                Hilos::initBrowser($this->previousBrowser);
            }
            parent::releaseIntegrationEnvironment();
        }
    }

    /** @param WorkerDTO ...$messages Addressed subscriptions or applied facts handled before one flush */
    protected function deliver(WorkerDTO ...$messages): void
    {
        $this->flushReaderAfter(function () use ($messages): void {
            foreach ($messages as $message) {
                $this->worker->handleDaemonMessage($message);
            }
        });
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
     */
    protected function deliverWithStockReadFailure(WorkerDbSyncUpdatedMessageDTO $message, bool $duringAddressLookup = false): void
    {
        $views = new ReflectionProperty(DbContext::class, '_dbItemCollections');
        $original = $views->getValue($this->reader);
        $fail = function () use ($views, $original): never {
            $views->setValue($this->reader, $original);
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

            public function totalsOfProducts(int ...$productIds): ProductStockTotalsCollection
            {
                ($this->fail)();
            }
        };
        $views->setValue($this->reader, array_replace($original, [EcommerceShopDbContext::productWarehouseStocks => $failingView]));
        try {
            $this->deliver($message);
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

            public function offsetExists(mixed $offset): bool
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

    /** @param Closure $step Incoming worker work before the browser flush */
    private function flushReaderAfter(Closure $step): void
    {
        $writer = Hilos::$db;
        Hilos::$db = $this->reader;
        try {
            ExecutionContext::run(new ExecutionFrame(), static function () use ($step): void {
                $step();
                self::assertSame([], Hilos::$browser->flushToSignalRouter());
            });
        } finally {
            Hilos::$db = $writer;
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
        $stockView = new class ($this->warehouse) extends DbCollection {
            public int $reads = 0;

            public function __construct(private readonly int $warehouse)
            {
            }

            public function totalsOfProducts(int ...$productIds): ProductStockTotalsCollection
            {
                // Each read sees another unit, as when a stock write has committed before its sync arrives.
                return new ProductStockTotalsCollection([
                    $productIds[0] => new ProductStockTotals(++$this->reads, $this->warehouse, 1),
                ]);
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
        $this->subscribe(self::GUEST, $this->stock->slug);
        self::assertSame(1, $this->takeCard(self::GUEST)['units']);
        self::assertTrue($this->readerHoldsStock($rowId));
        $this->subscribe(self::VISITOR, $this->other->slug);
        $this->takeCard(self::VISITOR);

        $this->deliverWithStockReadFailure(self::updateStock($rowId));
        $this->takeRefusal(self::GUEST, HttpConstants::HTTP_INTERNAL_ERROR);
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::VISITOR));

        self::assertNull(Hilos::$sr->pageResource(self::GUEST));
        $this->deliver(self::renameCategory($this->stock->categoryId, 'Spring bulbs'));
        $card = $this->takeCard(self::GUEST);
        self::assertSame($this->stock->id, $card['id']);
        self::assertSame('Spring bulbs', $card['categoryName']);
        self::assertSame(2, $card['units']);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
    }

    /** A category whose prior membership is known gets an addressed failure and one full recovery. */
    public function testFailedCategoryLookupRefusesOnlyItsConfirmedAddress(): void
    {
        $this->subscribe(self::GUEST, $this->stock->slug);
        $this->takeCard(self::GUEST);
        $this->subscribe(self::VISITOR, $this->other->slug);
        $this->takeCard(self::VISITOR);

        $this->deliverWithProductLookupFailure(self::renameCategory($this->stock->categoryId, 'Spring bulbs'));
        $this->takeRefusal(self::GUEST, HttpConstants::HTTP_INTERNAL_ERROR);
        self::assertTrue(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::VISITOR));

        $this->deliverDatabaseRecovery(self::renameCategory($this->stock->categoryId, 'Summer bulbs'));
        self::assertSame('Summer bulbs', $this->takeCard(self::GUEST)['categoryName']);
        self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
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

        Database::sql('INSERT INTO hilos_user (name, admin, block) VALUES (?, 0, 0)', ['Product visitor']);
        $visitorId = Database::lastInsertId();
        ExecutionContext::run(new ExecutionFrame(), static function () use ($visitorId): void {
            Hilos::$rt->connections->actions->register(self::GUEST, null);
            Hilos::$rt->connections->actions->register(self::VISITOR, $visitorId);
        });
    }

    /**
     * @param string $acceptKey Connection opening or replacing a page
     * @param ?string $slug Product address, or null when the param is missing
     */
    private function subscribe(string $acceptKey, ?string $slug): void
    {
        $agent = $slug === null || $slug === '' ? new EcommerceShopAgent() : new ProductAgent($slug);
        $agentId = $agent->getId();
        if (!$this->agents->hasAgent($agentId)) {
            $this->agents->addAgent($agentId, $agent);
        }
        $params = $slug === null ? [] : [ProductPageSubscribeParams::SLUG => $slug];
        $this->deliver(new DaemonAgentMessageDTO($agentId, new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
            new SignalName(ProductPage::PAGE),
            new WebSocketPageSubscribeSignalDTO($acceptKey, ProductPage::PAGE, $params),
        )));
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

    /**
     * @param string $oldSlug Requested or held product address
     * @param string $newSlug Current product address
     */
    private function assertMove(string $oldSlug, string $newSlug): void
    {
        $move = $this->takeFrame(self::GUEST)->data;
        self::assertInstanceOf(PageMovedSignalData::class, $move);
        self::assertSame(ProductPage::PAGE, $move->page);
        self::assertSame([ProductPageSubscribeParams::SLUG => $oldSlug], $move->params);
        self::assertSame(ProductPage::PAGE, $move->targetPage);
        self::assertSame([ProductPageSubscribeParams::SLUG => $newSlug], $move->targetParams);
    }

    /**
     * @param string $acceptKey Connection awaiting the only page frame
     * @return WebSocketSignalData Page frame addressed to that connection
     */
    private function takeFrame(string $acceptKey): WebSocketSignalData
    {
        $frames = $this->takeFrames();
        self::assertCount(1, $frames);
        self::assertSame($acceptKey, $frames[0]->targetAcceptKey);

        return $frames[0];
    }

    /** @return list<WebSocketSignalData> Page responses, refusals and moves for any local connection */
    private function takeFrames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if (
                $signal->data instanceof WebSocketSignalData
                && ($signal->data->data instanceof PageResponseSignalData
                    || $signal->data->data instanceof PageSubscriptionErrorSignalData
                    || $signal->data->data instanceof PageMovedSignalData)
            ) {
                $frames[] = $signal->data;
            }
        }

        return $frames;
    }
}
