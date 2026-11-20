<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database;

use Demo\EcommerceShop\Database\Actions\Collection\CategoriesActions;
use Demo\EcommerceShop\Database\Actions\Collection\CategorySlugsActions;
use Demo\EcommerceShop\Database\Actions\Collection\ProductsActions;
use Demo\EcommerceShop\Database\Actions\Collection\ProductSlugsActions;
use Demo\EcommerceShop\Database\Actions\Collection\ProductWarehouseStocksActions;
use Demo\EcommerceShop\Database\Actions\Collection\SessionProductCartsActions;
use Demo\EcommerceShop\Database\Actions\Collection\WarehousesActions;
use Demo\EcommerceShop\Database\Actions\Item\CategoryActions;
use Demo\EcommerceShop\Database\Actions\Item\ProductActions;
use Demo\EcommerceShop\Database\Actions\Item\WarehouseActions;
use Demo\EcommerceShop\Database\Object\Collection\Categories as ObjectCategories;
use Demo\EcommerceShop\Database\Object\Collection\CategorySlugs as ObjectCategorySlugs;
use Demo\EcommerceShop\Database\Object\Collection\Products as ObjectProducts;
use Demo\EcommerceShop\Database\Object\Collection\ProductSlugs as ObjectProductSlugs;
use Demo\EcommerceShop\Database\Object\Collection\ProductWarehouseStocks as ObjectProductWarehouseStocks;
use Demo\EcommerceShop\Database\Object\Collection\SessionProductCarts as ObjectSessionProductCarts;
use Demo\EcommerceShop\Database\Object\Collection\Warehouses as ObjectWarehouses;
use Demo\EcommerceShop\Database\View\Collection\Categories;
use Demo\EcommerceShop\Database\View\Collection\CategorySlugs;
use Demo\EcommerceShop\Database\View\Collection\Products;
use Demo\EcommerceShop\Database\View\Collection\ProductSlugs;
use Demo\EcommerceShop\Database\View\Collection\ProductWarehouseStocks;
use Demo\EcommerceShop\Database\View\Collection\SessionProductCarts;
use Demo\EcommerceShop\Database\View\Collection\Warehouses;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Exception\CollectionAlreadyMountedException;
use Hilos\Database\Exception\FrameworkExtensionException;
use Hilos\Database\Exception\InvalidMountedCollectionException;
use Hilos\Database\Exception\UnboundedCollectionReadException;
use Hilos\Database\Exception\UnknownLazyStrategyException;
use Hilos\Database\Exception\View\ObjectCollectionNotFoundException;
use Hilos\Database\Object\Objects;

/**
 * EcommerceShopDbContext - Database context for the ecommerce-shop demo.
 *
 * People, their sessions and every table of signing in are the framework's. The shop's own
 * tables are its warehouses, the categories of its catalog, its products, every address a
 * category or a product has had, and the stock: the units of each product in each warehouse.
 * The warehouses, the categories and their addresses are a few rows each, read whole by
 * everything that reads them at all, so each of those collections loads its table on its first
 * read in any process, the way the language catalog does. The catalog of products grows, and
 * the stock with it, so the products, their addresses and the stock load lazily by key: the
 * products library holds the products and their addresses whole, the stock library the stock,
 * and a walk over any of them anywhere else is refused. A visitor without an account stays
 * nameless, and the one thing the shop keeps for one is the cart of its browser session: the
 * guest carts grow with the visitors, so they load lazily by key too, and the guest carts
 * library holds them whole.
 *
 * @extends HilosDbContext
 * @property-read Warehouses $warehouses
 * @property-read Categories $categories
 * @property-read CategorySlugs $categorySlugs
 * @property-read Products $products
 * @property-read ProductSlugs $productSlugs
 * @property-read ProductWarehouseStocks $productWarehouseStocks
 * @property-read SessionProductCarts $sessionProductCarts
 */
final class EcommerceShopDbContext extends HilosDbContext
{
    public const string warehouses = 'warehouses';
    public const string categories = 'categories';
    public const string categorySlugs = 'categorySlugs';
    public const string products = 'products';
    public const string productSlugs = 'productSlugs';
    public const string productWarehouseStocks = 'productWarehouseStocks';
    public const string sessionProductCarts = 'sessionProductCarts';

    /**
     * Configures database context with object collections and view representations.
     *
     * @throws FrameworkExtensionException When a framework key is extended by a chain that does not extend it
     * @throws CollectionAlreadyMountedException When a key is represented twice
     * @throws ObjectCollectionNotFoundException When a represented object collection is missing
     * @throws UnknownLazyStrategyException When a collection is mounted under a strategy initDB() does not know
     * @throws InvalidMountedCollectionException When a project collection key is empty or already mounted
     * @throws UnboundedCollectionReadException When a cacheless collection is mounted with a whole-table strategy
     */
    public function configure(): void
    {
        parent::configure();

        $this->mountObjectCollection(ObjectWarehouses::class, Objects::LAZY_STRATEGY_NONE);
        $this->mountObjectCollection(ObjectCategories::class, Objects::LAZY_STRATEGY_NONE);
        $this->mountObjectCollection(ObjectCategorySlugs::class, Objects::LAZY_STRATEGY_NONE);
        $this->mountObjectCollection(ObjectProducts::class, Objects::LAZY_STRATEGY_KEY);
        $this->mountObjectCollection(ObjectProductSlugs::class, Objects::LAZY_STRATEGY_KEY);
        $this->mountObjectCollection(ObjectProductWarehouseStocks::class, Objects::LAZY_STRATEGY_KEY);
        $this->mountObjectCollection(ObjectSessionProductCarts::class, Objects::LAZY_STRATEGY_KEY);

        $this->setRepresent(self::warehouses, Warehouses::class, WarehousesActions::class, WarehouseActions::class);
        $this->setRepresent(self::categories, Categories::class, CategoriesActions::class, CategoryActions::class);
        $this->setRepresent(self::categorySlugs, CategorySlugs::class, CategorySlugsActions::class);
        $this->setRepresent(self::products, Products::class, ProductsActions::class, ProductActions::class);
        $this->setRepresent(self::productSlugs, ProductSlugs::class, ProductSlugsActions::class);
        $this->setRepresent(self::productWarehouseStocks, ProductWarehouseStocks::class, ProductWarehouseStocksActions::class);
        $this->setRepresent(self::sessionProductCarts, SessionProductCarts::class, SessionProductCartsActions::class);
    }
}
