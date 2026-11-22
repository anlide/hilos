<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages;

use Demo\EcommerceShop\Agents\ProductsLibraryAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Constants\PageConstants;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\DTO\AdminProductPageSubscribeParams;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Exception\InvalidPageRouteParamException;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\DatabaseException;
use Hilos\HilosException;

/**
 * One product of the shop under the head of its administration section, addressed by its id
 * (`/hilos/app/product/{productId}`): the page carries the product's card and the categories and
 * warehouses lists the card's names are read from — no actions yet.
 *
 * @property ProductsLibraryAgent $agent
 */
final class AdminProductPage extends AbstractPage
{
    public const string PAGE = PageConstants::ADMIN_PRODUCT;

    public const PageReach REACH = PageReach::ROUTE;

    /**
     * A project's own administrative surface, closed on the server like every
     * other /hilos/* page: the route's `admin: true` marker states surface type
     * to the shell and grants nothing.
     *
     * The level rather than an ACCESS browser guard because this page is served
     * by the products library agent, which owns the products and their addresses
     * and neither users nor connections — the cross-agent guard rule in
     * docs/agents/architecture/page-access-control.md. The level's isAdmin seam
     * runs wherever the page is served, exactly as it does for the framework
     * admin surface.
     */
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::PRODUCTS_LIBRARY;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_ADMIN_PRODUCT,
    ];

    /**
     * Answers a move to another product with the whole page under the new number.
     *
     * @param string $acceptKey Subscribing connection
     * @param PageRouteParams $params Updated route parameters
     * @throws HilosException When the subscription cannot be answered
     */
    final public function onUpdateSubscription(string $acceptKey, PageRouteParams $params): void
    {
        $this->onSubscribe($acceptKey, $params);
    }

    /**
     * Refuses a number that names no product.
     *
     * @param string $acceptKey Subscribing connection, unused
     * @param PageRouteParams $params Raw route parameters
     * @throws MissingPageRouteParamException When the product id is absent or empty
     * @throws InvalidPageRouteParamException When the product id is not a positive integer
     * @throws PageResourceNotFoundException When no product has the id
     * @throws LogicException When the products collection class constants are not configured
     * @throws InvalidArgumentException When a loaded product does not match the collection
     * @throws DatabaseException When the product cannot be looked up
     */
    protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        $address = AdminProductPageSubscribeParams::fromPageRouteParams($params);
        if (!isset(Hilos::$db->products[$address->productId])) {
            throw new PageResourceNotFoundException('Unknown product: ' . $address->productId);
        }
    }
}
