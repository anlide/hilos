<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages;

use Demo\EcommerceShop\Agents\EcommerceShopAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Constants\PageConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageReach;

/**
 * MainPage - The home page of the ecommerce-shop demo.
 *
 * The shop front of categories: its data is the page's two lists - the categories as tiles and
 * the products the browser counts into them - and both stay live. It declares no actions or
 * signals of its own.
 *
 * @property EcommerceShopAgent $agent
 */
final class MainPage extends AbstractPage
{
    public const string PAGE = PageConstants::MAIN;

    public const PageReach REACH = PageReach::ROUTE;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::ECOMMERCE_SHOP;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_MAIN,
    ];
}
