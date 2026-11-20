<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages;

use Demo\EcommerceShop\Agents\EcommerceShopAgent;
use Demo\EcommerceShop\Agents\ProductAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\PageConstants;
use Demo\EcommerceShop\Pages\DTO\ProductPageSubscribeParams;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Config\PageAgentIndexKey;
use Hilos\Core\Page\Config\PageAgentIndexSource;
use Hilos\Core\Page\Exception\MissingPageRouteParamException;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;

/**
 * ProductPage - The storefront's product page, one per address (`/product/{slug}`).
 *
 * Served by the agent of its address: the slug names the {@see ProductAgent} instance, which
 * comes up with the first visitor. A subscription without a slug falls back to the shop's app
 * agent and is refused there. The page is open to a guest and answers with an empty reply for
 * now: what it shows of the product arrives with a leaf of its own.
 *
 * @property ProductAgent|EcommerceShopAgent $agent The agent of the address, or the app agent a slug-less subscription falls back to
 */
final class ProductPage extends AbstractPage
{
    public const string PAGE = PageConstants::PRODUCT;

    public const PageReach REACH = PageReach::ROUTE;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::PRODUCT;

    public const array SUBSCRIPTION_AGENT_INDEX = [
        PageAgentIndexKey::SOURCE => PageAgentIndexSource::PARAM,
        PageAgentIndexKey::PARAM => ProductPageSubscribeParams::SLUG,
        PageAgentIndexKey::FALLBACK_AGENT_TYPE => AgentType::ECOMMERCE_SHOP,
    ];

    /**
     * Refuses a subscription that names no address.
     *
     * The agent of an address always has one; the app agent a slug-less subscription falls back
     * to finds none here and answers it 400.
     *
     * @param string $acceptKey WebSocket accept key
     * @param PageRouteParams $params Route params from page subscription
     * @throws MissingPageRouteParamException When the slug is absent or empty
     */
    protected function onSubscribeBeforeResponse(string $acceptKey, PageRouteParams $params): void
    {
        ProductPageSubscribeParams::fromPageRouteParams($params);
    }
}
