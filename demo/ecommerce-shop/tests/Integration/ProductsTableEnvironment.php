<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Demo\EcommerceShop\Agents\ProductsLibraryAgent;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * Starts the stock library and the products library as their workers do, then stands in for the products
 * page's process, which is the products library's own and does not hold the stock.
 *
 * Both keep their claims. The stock library's frame stays the current one, so the cases write the
 * stock as it does; a product is written under the products library's frame, through
 * {@see asProductsLibrary()}. The products are held by the products library and keep their promise
 * to read whole, so a case writes its rows past the libraries first and then has the products
 * library declare its set again. The stock is not held, as in the page's process, so it is read by
 * SQL on the product column. The rollback forgets those reads with the rows.
 */
abstract class ProductsTableEnvironment extends StockLibraryEnvironment
{
    private ?ProductsLibraryAgent $products = null;

    /**
     * The stock library starts first, then the products library runs its start under its own frame;
     * what both loaded is forgotten, and the holding of the stock with it, before the first case writes a row.
     */
    protected function prepareIntegrationEnvironment(): void
    {
        parent::prepareIntegrationEnvironment();
        $this->products = new ProductsLibraryAgent();
        OwnershipDeclaration::claimAll($this->products);
        $this->asProductsLibrary($this->products->onStart(...));
        Hilos::$db->forgetLoadedDataForTest();
        Hilos::$db->forgetHoldingForTest(EcommerceShopDbContext::productWarehouseStocks);
    }

    /** Releases the products library's claim, then the stock library's claim and frame. */
    protected function releaseIntegrationEnvironment(): void
    {
        if ($this->products !== null) {
            TruthSourceRegistry::unregisterAgent($this->products->getId());
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->products->getId()));
            $this->products = null;
        }
        parent::releaseIntegrationEnvironment();
    }

    /**
     * Runs a write of the products under the products library's frame, the way its worker runs it.
     *
     * @template T
     * @param callable(): T $write Write of the products
     * @return T What the write returns
     */
    protected function asProductsLibrary(callable $write): mixed
    {
        self::assertNotNull($this->products);

        return ExecutionContext::run(new ExecutionFrame(agentId: $this->products->getId()), $write);
    }

    /**
     * Has the products library read its products and their addresses whole again, after the case's rows past it.
     */
    protected static function holdCatalog(): void
    {
        $products = Hilos::$db->products->getObjectCollection();
        $addresses = Hilos::$db->productSlugs->getObjectCollection();
        self::assertNotNull($products);
        self::assertNotNull($addresses);
        $products->preloadAll();
        $addresses->preloadAll();
    }

    /**
     * Writes a product row past the products library, with the moment of its last edit given.
     *
     * The moment is given, not read from the clock: two products written in the same millisecond
     * would leave their order to chance.
     *
     * @param int $categoryId Category the product is put in
     * @param ProductKind $kind Kind of the product
     * @param string $name Name of the product
     * @param int $price Price in kopecks
     * @param int $changedAt Moment of the last edit, epoch milliseconds
     * @return int Id of the new product
     */
    protected static function productIn(int $categoryId, ProductKind $kind, string $name, int $price, int $changedAt): int
    {
        $slug = 'product-' . RandomHelper::hex(8);
        Database::sql(
            'INSERT INTO product (name, category_id, kind, price, description, picture_file_id, slug, changed_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$name, $categoryId, $kind->value, $price, 'Product of the case', self::pictureFile(), $slug, $changedAt],
        );

        return Database::lastInsertId();
    }
}
