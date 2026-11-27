<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages;

use Demo\EcommerceShop\Agents\Hilos\UserAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\PageConstants;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Config\PageAgentIndexKey;
use Hilos\Core\Page\Config\PageAgentIndexSource;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\PageConflictException;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;

/** The checkout summary, answered by the owner of the connection's account cart. */
final class CheckoutPage extends AbstractPage
{
    public const string PAGE = PageConstants::CHECKOUT;
    public const PageReach REACH = PageReach::ROUTE;
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::AUTHENTICATED;
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_USER;
    public const array SUBSCRIPTION_AGENT_INDEX = [
        PageAgentIndexKey::SOURCE => PageAgentIndexSource::SESSION_USER,
        PageAgentIndexKey::FALLBACK_AGENT_TYPE => AgentType::GUEST_CARTS_LIBRARY,
    ];

    public const array READS_DB = [
        EcommerceShopDbContext::products,
        EcommerceShopDbContext::productWarehouseStocks,
        EcommerceShopDbContext::userProductCarts,
        EcommerceShopDbContext::userVoucherCarts,
        EcommerceShopDbContext::vouchers,
        HilosDbContext::sessions,
    ];

    public const array READS_RT = [EcommerceShopRtContext::connections];

    public const string DATA_CART = CartPage::DATA_CART;

    /**
     * @param string $acceptKey Subscribing connection supplied by the router
     * @param PageRouteParams $params Route parameters, unused
     * @return PagePayload Complete account cart for the confirmed owner
     * @throws PageConflictException When the serving agent or active session does not confirm the account owner
     * @throws RtActionsStateCollectionNullException When the runtime connection collection is unavailable
     * @throws InvalidArgumentException When a session query, saved row, stock total or picture variant is invalid
     * @throws LogicException When a collection is misconfigured or a monetary amount is invalid
     * @throws DatabaseException When the session, cart, products, stock or voucher cannot be read
     * @throws ItemNotFoundException When the catalog or saved voucher cannot provide its keyed item
     * @throws ObjectGetIdStringNotImplementedException When a stock row cannot provide a key
     * @throws CollectionNotManualException When a stock selection refuses a matched row
     * @throws HilosException When a configured data source refuses a read
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        $connection = Hilos::$rt->connections[$acceptKey];
        $agent = $this->getAgent();
        $userId = $connection?->userId;
        $sessionId = $connection?->sessionId;
        if (
            $connection === null || $connection->staleSince() !== null || $userId === null || $userId <= 0 || $sessionId === null
            || !$agent instanceof UserAgent || $agent->getIndex() !== (string) $userId
            || !Hilos::$db->sessions->isStoredActiveForUser($sessionId, $userId)
        ) {
            throw new PageConflictException('The cart owner is not available yet');
        }

        $sessionToken = $connection->sessionToken;
        $cart = CartPageSnapshot::forAccount($agent, $userId);
        $current = Hilos::$rt->connections[$acceptKey];
        if (
            $current === null || $current->staleSince() !== null || $current->userId !== $userId
            || $current->sessionId !== $sessionId || $current->sessionToken !== $sessionToken
            || !Hilos::$db->sessions->isStoredActiveForUser($sessionId, $userId, $sessionToken)
        ) {
            throw new PageConflictException('The cart owner has changed');
        }

        return new PagePayload(data: [self::DATA_CART => $cart->toArray()]);
    }
}
