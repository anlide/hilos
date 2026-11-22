<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages;

use Demo\EcommerceShop\Agents\CategoriesLibraryAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Constants\PageConstants;
use Demo\EcommerceShop\Database\Entity\Item\Category as EntityCategory;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\DTO\CategoryPageSubscribeParams;
use Demo\EcommerceShop\Tables\Product\CategoryProductsTable;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Table\Exception\TableNotFoundException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Exception\View\CollectionNotFoundException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\HilosException;

/**
 * The public storefront category and its first products window at `/category/{slug}`.
 *
 * @property CategoriesLibraryAgent $agent The library that holds the current category addresses
 */
final class CategoryPage extends AbstractPage
{
    public const string PAGE = PageConstants::CATEGORY;
    public const PageReach REACH = PageReach::ROUTE;
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::PUBLIC;
    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CATEGORIES_LIBRARY;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_CATEGORY,
    ];

    public const string DATA_CATEGORY = 'category';

    /**
     * Answers updated route params with the category and window under the new address.
     *
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Updated route parameters
     * @throws HilosException When the subscription cannot be answered
     */
    public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        $this->onSubscribe($acceptKey, $params);
    }

    /**
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Raw route parameters
     * @return PagePayload Current category card for the same reply as its products window
     * @throws MissingPageRouteParamException When the slug is absent or empty
     * @throws PageResourceNotFoundException When the slug is not a current category address
     * @throws CollectionNotFoundException When the categories collection is not registered
     * @throws DbCollectionNotReadableException When the categories collection is not readable here
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When a matched object does not match the collection
     * @throws DatabaseException When the category cannot be read
     * @throws ObjectGetIdStringNotImplementedException When a matched object cannot provide its key
     * @throws CollectionNotManualException When the selection cannot accept a matched item
     * @throws TableNotFoundException When the category products table is not registered
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        $address = CategoryPageSubscribeParams::fromPageRouteParams($params);
        $category = Hilos::$db->categories->whereColumnIs(EntityCategory::slug, $address->slug)->first();
        // A database comparison can match even when the route carries extra trailing spaces.
        if ($category === null || $category->slug !== $address->slug) {
            throw new PageResourceNotFoundException('Category not found.');
        }

        $this->scopeProductsWindow($acceptKey, $category->id);

        return new PagePayload(data: [
            self::DATA_CATEGORY => [
                EntityCategory::id => $category->id,
                EntityCategory::slug => $category->slug,
                EntityCategory::name => $category->name,
                EntityCategory::description => $category->description,
            ],
        ]);
    }

    /**
     * Lets the snapshot use a window only when it belongs to the category in the reply.
     *
     * @param string $acceptKey Subscribing connection
     * @param int $categoryId Category id resolved from the route
     * @throws TableNotFoundException When the category products table is not registered
     */
    private function scopeProductsWindow(string $acceptKey, int $categoryId): void
    {
        $router = Hilos::$sr;
        if ($router === null) {
            return;
        }

        $tableKey = CategoryProductsTable::TABLE;
        $reported = $router->takeReportedTableWindows($acceptKey)[$tableKey] ?? null;
        if (($reported?->filter[CategoryProductsTable::FILTER_CATEGORY] ?? null) === $categoryId) {
            $router->reportTableWindows($acceptKey, [$tableKey => $reported]);
            return;
        }

        $held = $router->getTableViewport($acceptKey, $tableKey);
        if (($held?->filter[CategoryProductsTable::FILTER_CATEGORY] ?? null) === $categoryId) {
            return;
        }

        $table = Hilos::$table->categoryProducts;
        $router->setTableViewport($acceptKey, new TableViewportSubscription(
            tableKey: $tableKey,
            filter: [CategoryProductsTable::FILTER_CATEGORY => $categoryId],
            sort: $table->defaultSort(),
            limit: $table->windowSize(),
        ));
        $router->setTableFacets($acceptKey, $tableKey, []);
    }
}
