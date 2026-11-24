<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages;

use Demo\EcommerceShop\Agents\GuestCartsLibraryAgent;
use Demo\EcommerceShop\Agents\Hilos\UserAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\PageConstants;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\UserProductCart;
use Demo\EcommerceShop\Database\Object\Item\SessionProductCart;
use Demo\EcommerceShop\Database\View\Item\Voucher;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\DTO\CartPageData;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Demo\EcommerceShop\Runtime\View\Item\Connection;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AgentInterface;
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
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;

/** The public storefront cart, answered by the owner of the connection's cart. */
final class CartPage extends AbstractPage
{
    public const string PAGE = PageConstants::CART;
    public const PageReach REACH = PageReach::ROUTE;
    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::PUBLIC;
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_USER;
    public const array SUBSCRIPTION_AGENT_INDEX = [
        PageAgentIndexKey::SOURCE => PageAgentIndexSource::SESSION_USER_OR_GUEST,
        PageAgentIndexKey::FALLBACK_AGENT_TYPE => AgentType::GUEST_CARTS_LIBRARY,
    ];

    /**
     * Sources for the snapshot and for matching changes while the cart owner restarts.
     * The voucher catalog is read here so its changes reach this worker even when the cart owner runs on another one.
     */
    public const array READS_DB = [
        EcommerceShopDbContext::products,
        EcommerceShopDbContext::productWarehouseStocks,
        EcommerceShopDbContext::userProductCarts,
        EcommerceShopDbContext::sessionProductCarts,
        EcommerceShopDbContext::sessionVoucherCarts,
        EcommerceShopDbContext::userVoucherCarts,
        EcommerceShopDbContext::vouchers,
    ];

    public const array READS_RT = [EcommerceShopRtContext::connections];

    public const string DATA_CART = 'cart';

    /**
     * @param ?int $userId Account behind the connection, or null for a guest
     * @param ?int $sessionId Current session of the connection
     * @param ?string $agentType Serving or subscribed agent type
     * @param ?string $agentIndex Serving or subscribed agent index
     * @return bool Whether this address owns the connection's current cart
     */
    public static function cartOwnerMatches(?int $userId, ?int $sessionId, ?string $agentType, ?string $agentIndex): bool
    {
        return $userId === null
            ? $sessionId > 0 && $agentType === AgentType::GUEST_CARTS_LIBRARY && $agentIndex === null
            : $userId > 0 && $agentType === HilosAgentType::HILOS_USER && $agentIndex === (string) $userId;
    }

    /**
     * @param string $acceptKey Subscribing connection supplied by the router
     * @param PageRouteParams $params Route parameters, unused
     * @return PagePayload A complete cart for the confirmed owner
     * @throws PageConflictException When the connection's identity is unconfirmed or the cart owner changed
     * @throws RtActionsStateCollectionNullException When the runtime connection collection is unavailable
     * @throws InvalidArgumentException When a collection, stock total or picture variant is misconfigured
     * @throws LogicException When the collection classes are not configured or a monetary amount is invalid
     * @throws DatabaseException When a cart, its products, its stock or its voucher cannot be read
     * @throws ItemNotFoundException When the saved voucher is missing or the binding disappears during its keyed read
     * @throws ObjectGetIdStringNotImplementedException When a matched row cannot provide a key
     * @throws CollectionNotManualException When a selection refuses a matched row
     * @throws HilosException When a configured data source refuses a read
     */
    protected function buildPagePayload(string $acceptKey, PageRouteParams $params): PagePayload
    {
        $connection = $this->requireConnection($acceptKey);
        $userId = $connection->userId;
        $sessionId = $connection->sessionId;
        $cartLines = $userId === null
            ? Hilos::$db->sessionProductCarts->whereColumnIs(SessionProductCart::sessionId, $sessionId)
            : Hilos::$db->userProductCarts->whereColumnIs(UserProductCart::user_id, $userId);

        $ordered = [];
        $productIds = [];
        foreach ($cartLines as $line) {
            $ordered[$line->id] = $line;
            $productIds[] = $line->productId;
        }
        ksort($ordered);

        $stock = Hilos::$db->productWarehouseStocks->totalsOfProducts(...$productIds);
        $lines = [];
        foreach ($ordered as $line) {
            $product = Hilos::$db->products[$line->productId] ?? null;
            if ($product === null) {
                // A product can disappear with its cart line while the catalog change is crossing workers.
                continue;
            }

            $lines[] = CartPageData::line($product, $line->quantity, $stock->of($line->productId)->unitCount);
        }
        $cart = new CartPageData($userId, $lines, $this->cartVoucher($sessionId));

        $current = $this->requireConnection($acceptKey);
        if ($current->userId !== $userId || $current->sessionId !== $sessionId) {
            throw new PageConflictException('The cart owner has changed');
        }

        return new PagePayload(data: [self::DATA_CART => $cart->toArray()]);
    }

    /**
     * Asks the confirmed cart owner for its voucher, read by the saved ID so a switched-off voucher still comes back.
     *
     * @param ?int $sessionId Current session of the connection, which keys a guest's voucher
     * @return ?Voucher Current catalog voucher, including an inactive one, or null when none is selected
     * @throws PageConflictException When the serving agent cannot hold a cart
     * @throws LogicException When a voucher collection is misconfigured
     * @throws InvalidArgumentException When a loaded voucher does not match its collection
     * @throws DatabaseException When the binding or catalog read fails
     * @throws ItemNotFoundException When the saved voucher is missing or the binding disappears during its keyed read
     */
    private function cartVoucher(?int $sessionId): ?Voucher
    {
        $agent = $this->getAgent();

        return match (true) {
            $agent instanceof UserAgent => $agent->cartVoucher(),
            $agent instanceof GuestCartsLibraryAgent && $sessionId !== null => $agent->cartVoucher($sessionId),
            default => throw new PageConflictException('The cart owner is not available at this agent'),
        };
    }

    /**
     * @param string $acceptKey Subscribing connection supplied by the router
     * @return Connection Current runtime row that agrees with the serving agent
     * @throws PageConflictException When there is no confirmed cart owner at this agent
     * @throws RtActionsStateCollectionNullException When the runtime connection collection is unavailable
     * @throws HilosException When the runtime context refuses the read
     */
    private function requireConnection(string $acceptKey): Connection
    {
        $connection = Hilos::$rt->connections[$acceptKey];
        $agent = $this->getAgent();
        if ($connection === null || $connection->staleSince() !== null || !$agent instanceof AgentInterface) {
            throw new PageConflictException('The cart owner is not available yet');
        }

        if (!self::cartOwnerMatches($connection->userId, $connection->sessionId, $agent->getType(), $agent->getIndex())) {
            throw new PageConflictException('The cart owner is not available at this agent');
        }

        return $connection;
    }
}
