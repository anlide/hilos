<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Pages;

use Demo\EcommerceShop\Agents\GuestCartsLibraryAgent;
use Demo\EcommerceShop\Agents\Hilos\UserAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Constants\PageConstants;
use Demo\EcommerceShop\Database\Actions\CartLineRules;
use Demo\EcommerceShop\Database\Database;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\UserProductCart;
use Demo\EcommerceShop\Database\Object\Item\SessionProductCart;
use Demo\EcommerceShop\Database\View\Item\Voucher;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\DTO\CartPageData;
use Demo\EcommerceShop\Pages\DTO\CartRemoveLineActionDTO;
use Demo\EcommerceShop\Pages\DTO\CartSetQuantityActionDTO;
use Demo\EcommerceShop\Pages\DTO\CartStepQuantityActionDTO;
use Demo\EcommerceShop\Pages\DTO\CartVoucherApplyActionDTO;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Demo\EcommerceShop\Runtime\View\Item\Connection;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Config\PageAgentIndexKey;
use Hilos\Core\Page\Config\PageAgentIndexSource;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\PageConflictException;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\DatabaseRuntimeException;
use Hilos\Database\Exception\NamedDatabaseException;
use Hilos\Database\Exception\Transaction\AnnouncementFailedException;
use Hilos\Database\Exception\Transaction\NestedTransactionRefusedException;
use Hilos\Database\Exception\Transaction\TransactionNotOpenException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Throwable;

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

    public const array ACTIONS = [
        EcommerceShopSignalConstants::CART_REMOVE_LINE => CartRemoveLineActionDTO::class,
        EcommerceShopSignalConstants::CART_STEP_QUANTITY => CartStepQuantityActionDTO::class,
        EcommerceShopSignalConstants::CART_SET_QUANTITY => CartSetQuantityActionDTO::class,
        EcommerceShopSignalConstants::CART_APPLY_VOUCHER => CartVoucherApplyActionDTO::class,
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

    private const string GUEST_CART_KEY_MESSAGE = 'ecommerce-shop:guest-cart:v1:%d:0';
    private const string CART_UNAVAILABLE = 'This cart is no longer available.';

    /**
     * @param string $acceptKey Acting connection
     * @param string $action Action from the WebSocket envelope
     * @param ActionPayloadDTO $dto Parsed action payload
     * @return ?ActionReplyDTO No result body on success
     * @throws AgentUnknownActionException When the action does not belong to this page
     * @throws InvalidActionPayloadException When the payload does not match the action
     * @throws ValidationException When the owner binding, line, requested quantity or voucher is refused
     * @throws RtActionsStateCollectionNullException When the runtime connection collection is unavailable
     * @throws NamedDatabaseException When the primary database cannot be resolved
     * @throws NestedTransactionRefusedException When another application transaction is open
     * @throws TransactionNotOpenException When the transaction fails or loses its level
     * @throws DatabaseConnectionException When the commit cannot reach the database
     * @throws DatabaseRuntimeException When the commit fails
     * @throws AnnouncementFailedException When releasing a committed announcement fails outside a handler
     * @throws LogicException When the cart operation raises an unexpected error
     * @throws HilosException When the runtime read or cart write fails
     */
    public function onAction(string $acceptKey, string $action, ActionPayloadDTO $dto): ?ActionReplyDTO
    {
        switch ($action) {
            case EcommerceShopSignalConstants::CART_REMOVE_LINE:
                if (!$dto instanceof CartRemoveLineActionDTO) {
                    throw new InvalidActionPayloadException($action, CartRemoveLineActionDTO::class, $dto);
                }
                $this->handleRemoveLine($acceptKey, $dto);

                return null;

            case EcommerceShopSignalConstants::CART_STEP_QUANTITY:
                if (!$dto instanceof CartStepQuantityActionDTO) {
                    throw new InvalidActionPayloadException($action, CartStepQuantityActionDTO::class, $dto);
                }
                $this->handleStepQuantity($acceptKey, $dto);

                return null;

            case EcommerceShopSignalConstants::CART_SET_QUANTITY:
                if (!$dto instanceof CartSetQuantityActionDTO) {
                    throw new InvalidActionPayloadException($action, CartSetQuantityActionDTO::class, $dto);
                }
                $this->handleSetQuantity($acceptKey, $dto);

                return null;

            case EcommerceShopSignalConstants::CART_APPLY_VOUCHER:
                if (!$dto instanceof CartVoucherApplyActionDTO) {
                    throw new InvalidActionPayloadException($action, CartVoucherApplyActionDTO::class, $dto);
                }
                $this->handleApplyVoucher($acceptKey, $dto);

                return null;

            default:
                throw new AgentUnknownActionException(sprintf('Unknown action %s', $action));
        }
    }

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
     * @throws PageConflictException When the active session does not confirm the connection's cart owner
     * @throws RtActionsStateCollectionNullException When the runtime connection collection is unavailable
     * @throws InvalidArgumentException When a session query, collection, stock total or picture variant is invalid
     * @throws LogicException When a line has no ID, the collection classes are not configured or a monetary amount is invalid
     * @throws DatabaseException When the session, cart, products, stock or voucher cannot be read
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
        $sessionToken = $userId === null ? $connection->sessionToken : null;
        if (!$this->isActiveCartBinding($sessionId, $userId, $sessionToken)) {
            throw new PageConflictException('The cart owner is not available yet');
        }

        $guestCartKey = $sessionToken === null
            ? null
            : hash_hmac('sha256', sprintf(self::GUEST_CART_KEY_MESSAGE, $sessionId), $sessionToken);
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

            $lines[] = CartPageData::line(
                $line->id ?? throw new LogicException('A saved cart line must have an ID'),
                $product,
                $line->quantity,
                $stock->of($line->productId)->unitCount,
            );
        }
        $cart = new CartPageData($userId, $guestCartKey, $lines, $this->cartVoucher($sessionId));
        $payload = new PagePayload(data: [self::DATA_CART => $cart->toArray()]);

        $current = $this->requireConnection($acceptKey);
        if (
            $current->userId !== $userId || $current->sessionId !== $sessionId
            || ($userId === null && $current->sessionToken !== $sessionToken)
            || !$this->isActiveCartBinding($sessionId, $userId, $sessionToken)
        ) {
            throw new PageConflictException('The cart owner has changed');
        }

        return $payload;
    }

    /**
     * @param string $acceptKey Acting connection
     * @param CartRemoveLineActionDTO $dto Saved line and kind of cart displayed
     * @throws ValidationException When the owner binding or line is unavailable
     * @throws RtActionsStateCollectionNullException When the runtime connection collection is unavailable
     * @throws NamedDatabaseException When the primary database cannot be resolved
     * @throws NestedTransactionRefusedException When another application transaction is open
     * @throws TransactionNotOpenException When the transaction fails or loses its level
     * @throws DatabaseConnectionException When the commit cannot reach the database
     * @throws DatabaseRuntimeException When the commit fails
     * @throws AnnouncementFailedException When releasing a committed announcement fails outside a handler
     * @throws LogicException When the cart operation raises an unexpected error
     * @throws HilosException When the runtime read or cart write fails
     */
    private function handleRemoveLine(string $acceptKey, CartRemoveLineActionDTO $dto): void
    {
        try {
            $connection = $this->requireConnection($acceptKey);
        } catch (PageConflictException $e) {
            throw new ValidationException(CartLineRules::LINE_UNAVAILABLE, previous: $e);
        }

        $agent = $this->getAgent();
        $subscription = Hilos::$sr?->pageSubscription($acceptKey);
        $sessionId = $connection->sessionId;
        if ($subscription?->page !== self::PAGE
            || $subscription->agentType !== $agent->getType()
            || $subscription->agentIndex !== $agent->getIndex()
            || !$subscription->admitsPageAnswer($acceptKey)
            || $sessionId === null || $sessionId <= 0
        ) {
            throw new ValidationException(CartLineRules::LINE_UNAVAILABLE);
        }

        $ownerKind = $connection->userId === null ? CartRemoveLineActionDTO::GUEST : CartRemoveLineActionDTO::ACCOUNT;
        if ($dto->ownerKind !== $ownerKind) {
            throw new ValidationException(CartLineRules::LINE_UNAVAILABLE);
        }

        try {
            Database::transaction(DatabaseNames::PRIMARY, static function () use ($agent, $sessionId, $dto): void {
                if ($agent instanceof GuestCartsLibraryAgent) {
                    $agent->removeProductLine($sessionId, $dto->lineId);
                } elseif ($agent instanceof UserAgent) {
                    if (!Hilos::$db->sessions->isStoredActiveForUser($sessionId, (int)$agent->getIndex())) {
                        throw new ValidationException(CartLineRules::LINE_UNAVAILABLE);
                    }
                    $agent->removeProductLine($dto->lineId);
                } else {
                    throw new ValidationException(CartLineRules::LINE_UNAVAILABLE);
                }
            });
        } catch (HilosException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new LogicException('Unexpected cart removal failure', previous: $e);
        }
    }

    /**
     * @param string $acceptKey Connection invoking the action
     * @param CartStepQuantityActionDTO $dto Target and direction
     * @throws ValidationException When ownership changed or the cart refuses the requested step
     * @throws RtActionsStateCollectionNullException When the runtime connection collection is unavailable
     * @throws LogicException When the cart operation raises an unexpected failure
     * @throws HilosException When the runtime, database or cart sources cannot complete the action
     */
    private function handleStepQuantity(string $acceptKey, CartStepQuantityActionDTO $dto): void
    {
        $sessionId = $this->requireActionConnection($acceptKey)->sessionId;
        if ($sessionId === null || $sessionId <= 0) {
            throw new ValidationException(CartLineRules::LINE_UNAVAILABLE);
        }

        $agent = $this->getAgent();
        try {
            if ($agent instanceof GuestCartsLibraryAgent && $dto->ownerKind === CartStepQuantityActionDTO::GUEST) {
                $agent->stepProductLine($sessionId, $dto->lineId, $dto->step);

                return;
            }
            if ($agent instanceof UserAgent && $dto->ownerKind === CartStepQuantityActionDTO::ACCOUNT) {
                $agent->stepProductLine($sessionId, $dto->lineId, $dto->step);

                return;
            }
        } catch (HilosException $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new LogicException('Failed to step the cart line', previous: $failure);
        }

        throw new ValidationException(CartLineRules::LINE_UNAVAILABLE);
    }

    /**
     * @param string $acceptKey Connection invoking the action
     * @param CartSetQuantityActionDTO $dto Target, original quantity text and saved edit base
     * @throws ValidationException When ownership changed, the edit is stale or the requested quantity is refused
     * @throws RtActionsStateCollectionNullException When the runtime connection collection is unavailable
     * @throws LogicException When the cart operation raises an unexpected failure
     * @throws HilosException When the runtime, database or cart sources cannot complete the action
     */
    private function handleSetQuantity(string $acceptKey, CartSetQuantityActionDTO $dto): void
    {
        $connection = $this->requireActionConnection($acceptKey);
        $sessionId = $connection->sessionId;
        if ($sessionId === null || $sessionId <= 0
            || !Hilos::$sr?->pageSubscription($acceptKey)?->admitsPageAnswer($acceptKey)
        ) {
            throw new ValidationException(CartLineRules::LINE_UNAVAILABLE);
        }

        $agent = $this->getAgent();
        try {
            if ($agent instanceof GuestCartsLibraryAgent && $dto->ownerKind === CartStepQuantityActionDTO::GUEST) {
                $agent->setProductLineQuantity($sessionId, $dto->lineId, $dto->quantity, $dto->expectedQuantity);

                return;
            }
            if ($agent instanceof UserAgent && $dto->ownerKind === CartStepQuantityActionDTO::ACCOUNT) {
                $agent->setProductLineQuantity($sessionId, $dto->lineId, $dto->quantity, $dto->expectedQuantity);

                return;
            }
        } catch (HilosException $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new LogicException('Failed to set the cart line quantity', previous: $failure);
        }

        throw new ValidationException(CartLineRules::LINE_UNAVAILABLE);
    }

    /**
     * @param string $acceptKey Connection invoking the action
     * @param CartVoucherApplyActionDTO $dto Entered code and displayed cart kind
     * @throws ValidationException When the cart binding changed or the voucher is refused
     * @throws RtActionsStateCollectionNullException When the runtime connection collection is unavailable
     * @throws NamedDatabaseException When the primary database cannot be resolved
     * @throws NestedTransactionRefusedException When another application transaction is open
     * @throws TransactionNotOpenException When the transaction fails or loses its level
     * @throws DatabaseConnectionException When the commit cannot reach the database
     * @throws DatabaseRuntimeException When the commit fails
     * @throws AnnouncementFailedException When releasing a committed announcement fails outside a handler
     * @throws LogicException When voucher application raises an unexpected failure
     * @throws HilosException When the runtime, database or cart sources cannot complete the action
     */
    private function handleApplyVoucher(string $acceptKey, CartVoucherApplyActionDTO $dto): void
    {
        $connection = $this->requireActionConnection($acceptKey, self::CART_UNAVAILABLE);
        $sessionId = $connection->sessionId;
        $userId = $connection->userId;
        $guestToken = $userId === null ? $connection->sessionToken : null;
        $ownerKind = $userId === null ? CartStepQuantityActionDTO::GUEST : CartStepQuantityActionDTO::ACCOUNT;
        if ($sessionId === null || $sessionId <= 0 || $dto->ownerKind !== $ownerKind
            || ($userId === null && ($guestToken === null || $guestToken === ''))
            || !Hilos::$sr?->pageSubscription($acceptKey)?->admitsPageAnswer($acceptKey)
        ) {
            throw new ValidationException(self::CART_UNAVAILABLE);
        }

        $agent = $this->getAgent();
        try {
            Database::transaction(DatabaseNames::PRIMARY, static function () use ($agent, $sessionId, $userId, $guestToken, $dto): void {
                try {
                    $sessionActive = Hilos::$db->sessions->lockStoredActiveSession($sessionId, $userId, $guestToken);
                } catch (DatabaseException $failure) {
                    throw new ValidationException(self::CART_UNAVAILABLE, previous: $failure);
                }
                if (!$sessionActive) {
                    throw new ValidationException(self::CART_UNAVAILABLE);
                }

                if ($agent instanceof GuestCartsLibraryAgent) {
                    $agent->applyVoucher($sessionId, $dto->code);
                } elseif ($agent instanceof UserAgent) {
                    $agent->applyVoucher($dto->code);
                } else {
                    throw new ValidationException(self::CART_UNAVAILABLE);
                }
            });
        } catch (HilosException $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new LogicException('Failed to apply the cart voucher', previous: $failure);
        }
    }

    /**
     * @param string $acceptKey Connection invoking the action
     * @param string $unavailableReason Public refusal when the cart binding has changed
     * @return Connection Current connection whose active cart subscription agrees with the serving agent
     * @throws ValidationException When the connection or its cart subscription no longer belongs to the agent
     * @throws RtActionsStateCollectionNullException When the runtime connection collection is unavailable
     * @throws HilosException When the runtime context refuses the read
     */
    private function requireActionConnection(string $acceptKey, string $unavailableReason = CartLineRules::LINE_UNAVAILABLE): Connection
    {
        try {
            $connection = $this->requireConnection($acceptKey);
        } catch (PageConflictException $failure) {
            throw new ValidationException($unavailableReason, previous: $failure);
        }

        $agent = $this->getAgent();
        $subscription = Hilos::$sr?->pageSubscription($acceptKey);
        if (!$agent instanceof AgentInterface || $subscription?->page !== self::PAGE
            || $subscription->agentType !== $agent->getType() || $subscription->agentIndex !== $agent->getIndex()
        ) {
            throw new ValidationException($unavailableReason);
        }

        return $connection;
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

    /**
     * @param ?int $sessionId Session ID from the current runtime binding
     * @param ?int $userId Bound account ID, or null for a guest
     * @param ?string $sessionToken Guest cookie token from the same binding
     * @return bool Whether storage confirms this exact active cart binding
     * @throws DatabaseException When the persisted session cannot be read
     * @throws InvalidArgumentException When the entity query is invalid
     */
    private function isActiveCartBinding(?int $sessionId, ?int $userId, ?string $sessionToken): bool
    {
        if ($sessionId === null) {
            return false;
        }
        if ($userId !== null) {
            return Hilos::$db->sessions->isStoredActiveForUser($sessionId, $userId);
        }

        return $sessionToken !== null && $sessionToken !== ''
            && Hilos::$db->sessions->isStoredActiveGuest($sessionId, $sessionToken);
    }
}
