<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages;

use Demo\EcommerceShop\Agents\VouchersLibraryAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Constants\PageConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;

/**
 * The shop's vouchers under the head of its administration section.
 *
 * The page carries the vouchers table and no actions of its own.
 *
 * @property VouchersLibraryAgent $agent
 */
final class AdminVouchersPage extends AbstractPage
{
    public const string PAGE = PageConstants::ADMIN_VOUCHERS;

    public const PageReach REACH = PageReach::ROUTE;

    /**
     * A project's own administrative surface, closed on the server like every
     * other /hilos/* page: the route's `admin: true` marker states surface type
     * to the shell and grants nothing.
     *
     * The level rather than an ACCESS browser guard because this page is served
     * by the vouchers library agent, which owns the vouchers and their addresses
     * and neither users nor connections — the cross-agent guard rule in
     * docs/agents/architecture/page-access-control.md. The level's isAdmin seam
     * runs wherever the page is served, exactly as it does for the framework
     * admin surface.
     */
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::VOUCHERS_LIBRARY;

    public const array BROWSER = [
        BrowserConfigKey::SIGNAL => EcommerceShopSignalConstants::SUBSCRIPTION_PAGE_ADMIN_VOUCHERS,
    ];
}
