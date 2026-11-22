<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages;

use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Constants\PageConstants;
use Demo\EcommerceShop\Constants\ProductKind;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\DTO\AdminProductPageSubscribeParams;
use Demo\EcommerceShop\Tables\ProductStock\ProductStockTable;
use Hilos\API\Router\Exception\PageSubscriptionMismatchException;
use Hilos\API\Router\Exception\PageSubscriptionNotFoundException;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\Exception\PageSubscriptionException;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Table\Exception\TableNotFoundException;
use Hilos\Database\DatabaseException;
use Hilos\HilosException;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;

/** The units of one stock product by warehouse, under that product's shared head. */
final class AdminProductStockPage extends AbstractPage
{
    public const string PAGE = PageConstants::ADMIN_PRODUCT_STOCK;

    public const PageReach REACH = PageReach::ROUTE;

    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::PRODUCTS_LIBRARY;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_ADMIN_PRODUCT_STOCK,
    ];

    /**
     * @param PageRouteParams $ownerParams Product owner's route params
     * @return bool Whether the product has a Stock tab
     * @throws InvalidPageRouteParamException When the owner's product id is not a positive integer
     * @throws LogicException When the products collection is not configured
     * @throws InvalidArgumentException When a loaded product has the wrong type
     * @throws DatabaseException When the product cannot be read
     * @throws HilosException When the kind of the product cannot be read
     */
    public static function isTabApplicable(PageRouteParams $ownerParams): bool
    {
        $productId = $ownerParams->getPositiveInt(AdminProductPageSubscribeParams::PRODUCT_ID);

        return $productId !== null && self::isStockProduct($productId);
    }

    /**
     * Keep a refused Stock address in the worker mirror so a later debt probe
     * retries that address. The page signal router reports the original refusal.
     *
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params New product address
     * @throws PageInternalErrorException When the page resource declaration is invalid
     * @throws PageSubscriptionException When the Stock address is refused
     * @throws InvalidArgumentException When a response signal cannot be named
     * @throws PageSubscriptionNotFoundException When the connection no longer has a subscription to update
     * @throws PageSubscriptionMismatchException When the subscription no longer names Stock
     * @throws HilosException When the subscription cannot be answered
     */
    public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        try {
            $this->onSubscribe($acceptKey, $params);
        } catch (MissingPageRouteParamException|InvalidPageRouteParamException|PageResourceNotFoundException $refusal) {
            Hilos::$sr?->updatePageSubscription(self::PAGE, new WebSocketPageUpdateSubscriptionSignalDTO(
                acceptKey: $acceptKey,
                page: self::PAGE,
                params: $params->toArray(),
            ));
            throw $refusal;
        }
    }

    /**
     * @param string $acceptKey Connection requesting a window
     * @param TableViewportSubscription $viewport Requested viewport
     * @return bool Whether the requested Stock product matches the connection's page address
     * @throws InvalidPageRouteParamException When the current Stock address is malformed
     * @throws MissingPageRouteParamException When the current Stock address omits the product id
     */
    public function acceptsTableViewport(string $acceptKey, TableViewportSubscription $viewport): bool
    {
        if ($viewport->tableKey !== ProductStockTable::TABLE) {
            return true;
        }

        $subscription = Hilos::$sr?->pageSubscription($acceptKey);
        if ($subscription?->page !== self::PAGE) {
            return false;
        }

        $params = AdminProductPageSubscribeParams::fromPageRouteParams(new PageRouteParams($subscription->stringParams()));

        return ($viewport->filter[ProductStockTable::FILTER_PRODUCT] ?? null) === $params->productId;
    }

    /**
     * @param string $acceptKey Connection whose Stock window and focus are released
     */
    public function onUnsubscribe(string $acceptKey): void
    {
        Hilos::$sr?->forgetTableViewport($acceptKey, ProductStockTable::TABLE);
    }

    /**
     * Refuses unavailable Stock pages and keeps restored windows within the admitted product.
     *
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Raw product address
     * @throws MissingPageRouteParamException When the product id is absent or empty
     * @throws InvalidPageRouteParamException When the product id is not a positive integer
     * @throws PageResourceNotFoundException When the address names no stock product
     * @throws LogicException When the products collection is not configured
     * @throws InvalidArgumentException When a loaded product has the wrong type
     * @throws DatabaseException When the product cannot be read
     * @throws HilosException When the kind of the product cannot be read
     * @throws TableNotFoundException When the stock table is not registered
     */
    protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        $productId = AdminProductPageSubscribeParams::fromPageRouteParams($params)->productId;
        if (!self::isStockProduct($productId)) {
            throw new PageResourceNotFoundException('Stock is unavailable for product ' . $productId);
        }

        $router = Hilos::$sr;
        if ($router === null) {
            return;
        }

        $reported = $router->takeReportedTableWindows($acceptKey);
        if (isset($reported[ProductStockTable::TABLE])
            && ($reported[ProductStockTable::TABLE]->filter[ProductStockTable::FILTER_PRODUCT] ?? null) !== $productId) {
            unset($reported[ProductStockTable::TABLE]);
        }
        if ($reported !== []) {
            $router->reportTableWindows($acceptKey, $reported);
        }

        $held = $router->getTableViewport($acceptKey, ProductStockTable::TABLE);
        if ($held !== null && ($held->filter[ProductStockTable::FILTER_PRODUCT] ?? null) !== $productId) {
            $table = Hilos::$table->productStock;
            $router->setTableViewport($acceptKey, new TableViewportSubscription(
                tableKey: ProductStockTable::TABLE,
                filter: $table->defaultFilter($params),
                sort: $table->defaultSort(),
                limit: $table->windowSize(),
            ));
            $router->clearTableFocus($acceptKey, ProductStockTable::TABLE);
        }
    }

    /**
     * @param int $productId Product's primary key
     * @return bool Whether the key names a stock product
     * @throws LogicException When the products collection is not configured
     * @throws InvalidArgumentException When a loaded product has the wrong type
     * @throws DatabaseException When the product cannot be read
     * @throws HilosException When the kind of the product cannot be read
     */
    private static function isStockProduct(int $productId): bool
    {
        return isset(Hilos::$db->products[$productId]) && Hilos::$db->products[$productId]->kind === ProductKind::STOCK;
    }
}
