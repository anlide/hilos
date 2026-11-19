<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database;

use Demo\EcommerceShop\Database\Actions\Collection\CategoriesActions;
use Demo\EcommerceShop\Database\Actions\Collection\CategorySlugsActions;
use Demo\EcommerceShop\Database\Actions\Collection\WarehousesActions;
use Demo\EcommerceShop\Database\Actions\Item\CategoryActions;
use Demo\EcommerceShop\Database\Actions\Item\WarehouseActions;
use Demo\EcommerceShop\Database\Object\Collection\Categories as ObjectCategories;
use Demo\EcommerceShop\Database\Object\Collection\CategorySlugs as ObjectCategorySlugs;
use Demo\EcommerceShop\Database\Object\Collection\Warehouses as ObjectWarehouses;
use Demo\EcommerceShop\Database\View\Collection\Categories;
use Demo\EcommerceShop\Database\View\Collection\CategorySlugs;
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
 * tables are its warehouses, the categories of its catalog and every address a category has
 * had: a few rows each, read whole by everything that reads them at all, so each collection
 * loads its table on its first read in any process, the way the language catalog does. A
 * visitor without an account stays nameless, so there is no guest table.
 *
 * @extends HilosDbContext
 * @property-read Warehouses $warehouses
 * @property-read Categories $categories
 * @property-read CategorySlugs $categorySlugs
 */
final class EcommerceShopDbContext extends HilosDbContext
{
    public const string warehouses = 'warehouses';
    public const string categories = 'categories';
    public const string categorySlugs = 'categorySlugs';

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

        $this->setRepresent(self::warehouses, Warehouses::class, WarehousesActions::class, WarehouseActions::class);
        $this->setRepresent(self::categories, Categories::class, CategoriesActions::class, CategoryActions::class);
        $this->setRepresent(self::categorySlugs, CategorySlugs::class, CategorySlugsActions::class);
    }
}
