<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Closure;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\Product;
use Demo\EcommerceShop\Database\View\Collection\ProductWarehouseStocks;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\ProductPage;
use Demo\EcommerceShop\ShopFront\ProductCartCount;
use Hilos\Constants\AppEnv;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Database\Context\DbContext;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Throwable;

/** Exercises a real committed catalog change with independent writer and browser-worker connections. */
final class CommittedRenameProductPageTest extends TestCase
{
    use CatalogFixtureRows;
    use ProductPageWorkerFixture;

    private const int CATALOG_WRITER = 2;

    /** A cart fact may reach the reader after the writer commits and before its catalog sync arrives. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCartFactSeesTheCommittedRenameWhileTheWorkerStillHoldsTheOldSlug(): void
    {
        $this->withCatalogProduct(ProductKind::STOCK, function (int $productId, int $warehouse): void {
            self::stock($productId, $warehouse, 1);
            $held = $this->warmProduct($productId);
            $oldSlug = $held->slug;
            $newSlug = 'committed-' . $oldSlug;
            $this->subscribe(self::GUEST, $oldSlug);
            $reply = $this->takeFrame(self::GUEST)->data;
            self::assertInstanceOf(PageResponseSignalData::class, $reply);
            self::assertSame($oldSlug, $reply->payload->data[ProductPage::DATA_PRODUCT][Product::slug]);

            $this->commitRename($productId, $oldSlug, $newSlug);
            self::assertSame($newSlug, Database::sql('SELECT slug FROM product WHERE id = ?', [$productId])->field('slug'));
            self::assertSame($oldSlug, $held->slug);

            $cartFact = $this->addGuestCartLine($productId);
            self::assertSame([[], []], array_values($this->observeCartCountReads(fn () => $this->deliver($cartFact))));
            $this->assertMove($oldSlug, $newSlug);
            self::assertFalse(Hilos::$sr->hasPageDeliveryFailure(self::GUEST));
            self::assertSame($oldSlug, $held->slug);

            $this->subscribe(self::GUEST, $oldSlug);
            $this->assertMove($oldSlug, $newSlug);
            $this->subscribe(self::GUEST, $newSlug);
            $reply = $this->takeFrame(self::GUEST)->data;
            self::assertInstanceOf(PageResponseSignalData::class, $reply);
            self::assertSame($newSlug, $reply->payload->data[ProductPage::DATA_PRODUCT][Product::slug]);
            self::assertSame([
                'productId' => $productId,
                'slug' => $newSlug,
                'cartCount' => 1,
            ], $reply->payload->data[ProductPage::DATA_PRODUCT_CARTS]);
            self::assertSame($oldSlug, $held->slug);

            Hilos::$browser->record(SourceChange::dbCleared(EcommerceShopDbContext::userProductCarts));
            $this->deliver(new WorkerDbSyncUpdatedMessageDTO(new DbSyncUpdatedSignalData(
                EcommerceShopDbContext::products,
                (string) $productId,
                [Product::slug => $newSlug],
                previous: [Product::slug => $oldSlug],
            )));
            $reply = $this->takeFrame(self::GUEST)->data;
            self::assertInstanceOf(PageResponseSignalData::class, $reply);
            self::assertSame($newSlug, $reply->payload->data[ProductPage::DATA_PRODUCT][Product::slug]);
            self::assertSame($newSlug, $held->slug);
        });
    }

    /**
     * @param string $isolation Session default on the browser's connection
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[TestWith(['READ COMMITTED'])]
    #[TestWith(['REPEATABLE READ'])]
    public function testRenameAndPlacementCannotMakeTheOldAddressVisibleMidRead(string $isolation): void
    {
        $this->withCatalogProduct(ProductKind::UNIQUE, function (int $productId, int $warehouse) use ($isolation): void {
            Database::sql('SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation);
            $oldSlug = $this->warmProduct($productId)->slug;
            $newSlug = 'placed-' . $oldSlug;
            Database::sql(
                'INSERT INTO session_product_cart (session_id, product_id, quantity) VALUES (?, ?, 1)',
                [$this->guestCartSessionId, $productId],
            );

            $views = new ReflectionProperty(DbContext::class, '_dbItemCollections');
            $original = $views->getValue($this->reader);
            $stock = new class (
                $original[EcommerceShopDbContext::productWarehouseStocks],
                function () use ($productId, $warehouse, $oldSlug, $newSlug): void {
                    $this->commitRename($productId, $oldSlug, $newSlug);
                    Database::sql(
                        'INSERT INTO product_warehouse_stock (product_id, warehouse_id, units) VALUES (?, ?, 1)',
                        [$productId, $warehouse],
                        index: self::CATALOG_WRITER,
                    );
                },
            ) extends DbCollection {
                public function __construct(private readonly ProductWarehouseStocks $original, private readonly Closure $place)
                {
                }

                public function storedUnitsOfProduct(int $productId): int
                {
                    ($this->place)();

                    return $this->original->storedUnitsOfProduct($productId);
                }
            };
            $views->setValue($this->reader, array_replace($original, [EcommerceShopDbContext::productWarehouseStocks => $stock]));
            try {
                $reads = $this->observeCartCountReads(fn () => $this->subscribe(self::GUEST, $oldSlug));
                $reply = $this->takeFrame(self::GUEST)->data;
                self::assertInstanceOf(PageSubscriptionErrorSignalData::class, $reply);
                self::assertSame(HttpConstants::HTTP_NOT_FOUND, $reply->httpCode);
                self::assertSame([[], []], array_values($reads));
            } finally {
                $views->setValue($this->reader, $original);
            }

            $this->subscribe(self::GUEST, $oldSlug);
            $this->assertMove($oldSlug, $newSlug);
            $reads = $this->observeCartCountReads(fn () => $this->subscribe(self::GUEST, $newSlug));
            $reply = $this->takeFrame(self::GUEST)->data;
            self::assertInstanceOf(PageResponseSignalData::class, $reply);
            self::assertSame(1, $reply->payload->data[ProductPage::DATA_PRODUCT_CARTS]['cartCount']);
            self::assertSame([[$productId], [$productId]], array_values($reads));
            self::assertSame(str_replace(' ', '-', $isolation), Database::sql('SELECT @@tx_isolation AS isolation_level')->field('isolation_level'));
        });
    }

    /** A transfer committed after the first count cannot make one cart count twice. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCartTransferBetweenCountsKeepsTheTotal(): void
    {
        $this->withCatalogProduct(ProductKind::STOCK, function (int $productId, int $warehouse): void {
            self::stock($productId, $warehouse, 1);
            Database::sql('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            Database::sql('INSERT INTO hilos_user (name, admin, block) VALUES (?, 0, 0)', ['Cart transfer recipient']);
            $userId = Database::lastInsertId();
            try {
                $this->addGuestCartLine($productId);
                $this->observeCartCountReads(
                    fn () => $this->flushReaderAfter(static fn () => self::assertSame(1, ProductCartCount::forProduct($productId))),
                    beforeCount: static function (string $collection) use ($productId, $userId): void {
                        if ($collection !== EcommerceShopDbContext::userProductCarts) {
                            return;
                        }
                        Database::transactionStart(self::CATALOG_WRITER);
                        try {
                            Database::sql(
                                'INSERT INTO user_product_cart (user_id, product_id, quantity) VALUES (?, ?, 1)',
                                [$userId, $productId],
                                index: self::CATALOG_WRITER,
                            );
                            Database::sql(
                                'DELETE FROM session_product_cart WHERE product_id = ?',
                                [$productId],
                                index: self::CATALOG_WRITER,
                            );
                            Database::transactionCommit(self::CATALOG_WRITER);
                        } catch (Throwable $failure) {
                            Database::transactionRollbackSilent(self::CATALOG_WRITER);
                            throw $failure;
                        }
                    },
                );
                $this->flushReaderAfter(static fn () => self::assertSame(1, ProductCartCount::forProduct($productId)));
            } finally {
                Database::sql('DELETE FROM user_product_cart WHERE user_id = ?', [$userId]);
                Database::sql('DELETE FROM hilos_user WHERE id = ?', [$userId]);
            }
        });
    }

    /**
     * @param ProductKind $kind Kind of catalog fixture
     * @param Closure(int, int): void $exercise Work with the product and warehouse IDs
     */
    private function withCatalogProduct(ProductKind $kind, Closure $exercise): void
    {
        self::assertSame(AppEnv::TEST->value, Hilos::$env[EnvConstants::APP_ENV]->string());
        Database::initialize();
        $primary = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::configure(self::CATALOG_WRITER, $primary->host, $primary->user, $primary->password,
            $primary->database, $primary->port, $primary->charset);
        Database::connect(self::CATALOG_WRITER);
        $this->mountProductPageWorker();

        $categoryId = self::category();
        $productId = self::product($kind, $categoryId);
        $warehouse = self::warehouse();
        $pictures = Database::sql(
            'SELECT picture_file_id FROM product WHERE id = ? UNION ALL SELECT picture_file_id FROM category WHERE id = ?',
            [$productId, $categoryId],
        )->rows();
        try {
            $this->guestCartSessionId = self::createGuestCartSession();
            ExecutionContext::run(new ExecutionFrame(), static function (): void {
                Hilos::$rt->connections->actions->register(self::GUEST, null, sessionId: 1);
            });
            $exercise($productId, $warehouse);
        } finally {
            try {
                Database::sql('DELETE FROM session_product_cart WHERE product_id = ?', [$productId]);
                if (isset($this->guestCartSessionId)) {
                    Database::sql('DELETE FROM hilos_session WHERE id = ?', [$this->guestCartSessionId]);
                }
                Database::sql('DELETE FROM product_slug WHERE product_id = ?', [$productId]);
                Database::sql('DELETE FROM product_warehouse_stock WHERE product_id = ?', [$productId]);
                Database::sql('DELETE FROM warehouse WHERE id = ?', [$warehouse]);
                Database::sql('DELETE FROM product WHERE id = ?', [$productId]);
                Database::sql('DELETE FROM category WHERE id = ?', [$categoryId]);
                foreach ($pictures as $row) {
                    Database::sql('DELETE FROM hilos_file WHERE id = ?', [$row[Product::picture_file_id]]);
                }
            } finally {
                $this->releaseProductPageWorker();
                Database::close(self::CATALOG_WRITER);
            }
        }
    }

    /**
     * @param int $productId Renamed product
     * @param string $oldSlug Address held by the worker
     * @param string $newSlug Address committed by the writer
     */
    private function commitRename(int $productId, string $oldSlug, string $newSlug): void
    {
        Database::transactionStart(self::CATALOG_WRITER);
        try {
            Database::sql('INSERT INTO product_slug (slug, product_id) VALUES (?, ?), (?, ?)',
                [$oldSlug, $productId, $newSlug, $productId], index: self::CATALOG_WRITER);
            Database::sql('UPDATE product SET slug = ? WHERE id = ?', [$newSlug, $productId], index: self::CATALOG_WRITER);
            self::assertSame($oldSlug, Database::sql('SELECT slug FROM product WHERE id = ?', [$productId])->field('slug'));
            Database::transactionCommit(self::CATALOG_WRITER);
        } catch (Throwable $failure) {
            Database::transactionRollbackSilent(self::CATALOG_WRITER);
            throw $failure;
        }
    }
}
