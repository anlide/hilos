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
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\DTO\CartRemoveLineActionDTO;
use Demo\EcommerceShop\Pages\DTO\CartSetQuantityActionDTO;
use Demo\EcommerceShop\Pages\DTO\CartStepQuantityActionDTO;
use Demo\EcommerceShop\Pages\DTO\CartVoucherApplyActionDTO;
use Demo\EcommerceShop\Pages\DTO\CartVoucherRemoveActionDTO;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Demo\EcommerceShop\Runtime\View\Item\Connection;
use Hilos\Auth\AccountOperation\Exception\AccountErasingException;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Config\PageAgentIndexKey;
use Hilos\Core\Page\Config\PageAgentIndexSource;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\Exception\PageConflictException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageReach;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\DTO\ActionReplyDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\DatabaseRuntimeException;
use Hilos\Database\Exception\NamedDatabaseException;
use Hilos\Database\Exception\TestRollbackRefusedException;
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
        EcommerceShopSignalConstants::CART_REMOVE_VOUCHER => CartVoucherRemoveActionDTO::class,
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
        HilosDbContext::sessions,
    ];

    public const array READS_RT = [EcommerceShopRtContext::connections];

    public const string DATA_CART = 'cart';

    /** Refusal shared by the cart handler and the shop transport. */
    public const string CART_UNAVAILABLE = 'This cart is no longer available.';

    private const string GUEST_CART_KEY_MESSAGE = 'ecommerce-shop:guest-cart:v1:%d:%d';
    private const string PAGE_SUBSCRIPTION_PROOF_MESSAGE = 'ecommerce-shop:cart-subscription:v1:%d:%s:%s:%s';

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
                $this->handleVoucherAction($acceptKey, $dto);

                return null;

            case EcommerceShopSignalConstants::CART_REMOVE_VOUCHER:
                if (!$dto instanceof CartVoucherRemoveActionDTO) {
                    throw new InvalidActionPayloadException($action, CartVoucherRemoveActionDTO::class, $dto);
                }
                $this->handleVoucherAction($acceptKey, $dto);

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
        if ($sessionId === null) {
            throw new PageConflictException('The cart owner is not available yet');
        }

        $agent = $this->getAgent();
        $sessionToken = $connection->sessionToken;
        $cartEpoch = null;
        if ($userId === null) {
            if ($sessionToken === null || $sessionToken === '') {
                throw new PageConflictException('The cart owner is not available yet');
            }

            $cartEpoch = Hilos::$db->sessions->activeGuestCartEpoch($sessionId, $sessionToken)
                ?? throw new PageConflictException('The cart owner has changed');
            $guestCartKey = hash_hmac('sha256', sprintf(self::GUEST_CART_KEY_MESSAGE, $sessionId, $cartEpoch), $sessionToken);
            if (!$agent instanceof GuestCartsLibraryAgent) {
                throw new PageConflictException('The cart owner is not available at this agent');
            }
            $cart = CartPageSnapshot::forGuest($agent, $sessionId, $cartEpoch, $guestCartKey);
        } else {
            if (!Hilos::$db->sessions->isStoredActiveForUser($sessionId, $userId)) {
                throw new PageConflictException('The cart owner is not available yet');
            }
            if (!$agent instanceof UserAgent) {
                throw new PageConflictException('The cart owner is not available at this agent');
            }
            $cart = CartPageSnapshot::forAccount($agent, $userId);
        }

        $current = $this->requireConnection($acceptKey);
        if ($current->userId !== $userId || $current->sessionId !== $sessionId || $current->sessionToken !== $sessionToken
        ) {
            throw new PageConflictException('The cart owner has changed');
        }
        $bindingIsCurrent = $userId === null
            ? Hilos::$db->sessions->activeGuestCartEpoch($sessionId, $sessionToken) === $cartEpoch
            : Hilos::$db->sessions->isStoredActiveForUser($sessionId, $userId, $sessionToken);
        if (!$bindingIsCurrent) {
            throw new PageConflictException('The cart owner has changed');
        }

        $deliveryId = Hilos::$sr?->confirmedPageAnswerDeliveryId($acceptKey, self::PAGE);

        return new PagePayload(
            data: [self::DATA_CART => $cart->toArray()],
            pageSubscriptionProof: $sessionToken === null || $sessionToken === '' || $deliveryId === null
                ? null : self::pageSubscriptionProof($deliveryId, $sessionId, $sessionToken, $userId, $cartEpoch),
        );
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

        $token = $connection->sessionToken;
        try {
            Database::transaction(DatabaseNames::PRIMARY, static function () use ($agent, $sessionId, $dto, $token): void {
                if ($agent instanceof GuestCartsLibraryAgent && $token !== null) {
                    $agent->removeProductLine($sessionId, $dto->lineId, $token);
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
                $agent->stepProductLine($acceptKey, $sessionId, $dto->lineId, $dto->step);

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
                $agent->setProductLineQuantity($acceptKey, $sessionId, $dto->lineId, $dto->quantity, $dto->expectedQuantity);

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
     * @param CartVoucherApplyActionDTO|CartVoucherRemoveActionDTO $dto Voucher action and displayed cart kind
     * @throws ValidationException When the cart binding changed or the voucher is refused
     * @throws RtActionsStateCollectionNullException When the runtime connection collection is unavailable
     * @throws NamedDatabaseException When the primary database cannot be resolved
     * @throws NestedTransactionRefusedException When another application transaction is open
     * @throws TransactionNotOpenException When the transaction fails or loses its level
     * @throws DatabaseConnectionException When the commit cannot reach the database
     * @throws DatabaseRuntimeException When the commit fails
     * @throws AnnouncementFailedException When releasing a committed announcement fails outside a handler
     * @throws LogicException When the voucher action raises an unexpected failure
     * @throws HilosException When the runtime, database or cart sources cannot complete the action
     */
    private function handleVoucherAction(string $acceptKey, CartVoucherApplyActionDTO|CartVoucherRemoveActionDTO $dto): void
    {
        $connection = $this->requireActionConnection($acceptKey, self::CART_UNAVAILABLE);
        $sessionId = $connection->sessionId;
        $userId = $connection->userId;
        $sessionToken = $connection->sessionToken;
        $guestToken = $userId === null ? $sessionToken : null;
        $ownerKind = $userId === null ? CartStepQuantityActionDTO::GUEST : CartStepQuantityActionDTO::ACCOUNT;
        if ($sessionId === null || $sessionId <= 0 || $dto->ownerKind !== $ownerKind
            || ($userId === null && ($guestToken === null || $guestToken === ''))
            || !Hilos::$sr?->pageSubscription($acceptKey)?->admitsPageAnswer($acceptKey)
        ) {
            throw new ValidationException(self::CART_UNAVAILABLE);
        }

        $agent = $this->getAgent();
        try {
            Database::transaction(DatabaseNames::PRIMARY, function () use ($acceptKey, $agent, $sessionId, $userId, $sessionToken, $dto): void {
                if ($dto instanceof CartVoucherRemoveActionDTO) {
                    if (!$agent instanceof GuestCartsLibraryAgent && !$agent instanceof UserAgent) {
                        throw new ValidationException(self::CART_UNAVAILABLE);
                    }
                    $cartEpoch = $this->requirePageSubscriptionProof($acceptKey, $sessionId, $userId, $sessionToken, $dto);
                    $this->removeExpectedVoucher($agent, $sessionId, $cartEpoch, $dto->expectedVoucherId);

                    return;
                }
                try {
                    $sessionActive = $userId === null
                        ? Hilos::$db->sessions->lockActiveGuestCartEpoch($sessionId, $sessionToken, waitForLock: false) !== null
                        : Hilos::$db->sessions->lockStoredActiveSession($sessionId, $userId);
                } catch (DatabaseException $failure) {
                    throw new ValidationException(self::CART_UNAVAILABLE, previous: $failure);
                }
                if (!$sessionActive) {
                    throw new ValidationException(self::CART_UNAVAILABLE);
                }

                if (!$agent instanceof GuestCartsLibraryAgent && !$agent instanceof UserAgent) {
                    throw new ValidationException(self::CART_UNAVAILABLE);
                }
                if ($agent instanceof GuestCartsLibraryAgent) {
                    $agent->applyVoucher($sessionId, $dto->code);
                } else {
                    $agent->applyVoucher($dto->code);
                }
            });
        } catch (HilosException $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new LogicException('Failed to change the cart voucher', previous: $failure);
        }
    }

    /**
     * @param string $acceptKey Acting socket
     * @param int $sessionId Session selected by the serving cart owner
     * @param ?int $userId Account owning the cart, or null for a guest
     * @param ?string $sessionToken Token from the connection before reading storage
     * @param CartVoucherRemoveActionDTO $dto Original Remove action with its envelope metadata
     * @return ?int Confirmed guest cart epoch, or null for an account cart
     * @throws ValidationException When the subscription proof or stored session no longer confirms this cart
     * @throws HilosException When the runtime or stored guest epoch cannot be read
     */
    private function requirePageSubscriptionProof(
        string $acceptKey,
        int $sessionId,
        ?int $userId,
        ?string $sessionToken,
        CartVoucherRemoveActionDTO $dto,
    ): ?int {
        if ($dto->pageSubscriptionProof === null || $dto->pageSubscriptionDeliveryId === null
            || $sessionToken === null || $sessionToken === '') {
            throw new ValidationException(self::CART_UNAVAILABLE);
        }
        try {
            $cartEpoch = $userId === null
                ? Hilos::$db->sessions->lockActiveGuestCartEpoch($sessionId, $sessionToken, waitForLock: false) : null;
            $sessionActive = $userId === null
                ? $cartEpoch !== null : Hilos::$db->sessions->lockStoredActiveSession($sessionId, $userId, $sessionToken);
        } catch (DatabaseException $failure) {
            throw new ValidationException(self::CART_UNAVAILABLE, previous: $failure);
        }

        $subscription = Hilos::$sr?->pageSubscription($acceptKey);
        $current = $this->requireActionConnection($acceptKey, self::CART_UNAVAILABLE);
        if (!$sessionActive || $subscription?->deliveryId !== $dto->pageSubscriptionDeliveryId || $subscription->admittedIdentity === null
            || !$subscription->admitsPageAnswer($acceptKey)
            || $current->sessionId !== $sessionId || $current->userId !== $userId || $current->sessionToken !== $sessionToken
            || !hash_equals(
                self::pageSubscriptionProof($subscription->deliveryId, $sessionId, $sessionToken, $userId, $cartEpoch),
                $dto->pageSubscriptionProof,
            )
        ) {
            throw new ValidationException(self::CART_UNAVAILABLE);
        }

        return $cartEpoch;
    }

    /**
     * The confirmed delivery ID already includes the socket. The persisted session
     * token is available to its serving owner across nodes and keeps the digest opaque.
     *
     * @param string $deliveryId Confirmed internal subscription incarnation
     * @param int $sessionId Stored session of the socket
     * @param string $sessionToken Token verified against that session
     * @param ?int $userId Account owning the cart, or null for a guest
     * @param ?int $cartEpoch Stored guest generation, or null for an account
     * @return string Opaque proof for this confirmed cart subscription
     */
    private static function pageSubscriptionProof(
        string $deliveryId,
        int $sessionId,
        string $sessionToken,
        ?int $userId,
        ?int $cartEpoch,
    ): string {
        return hash_hmac(
            'sha256',
            sprintf(self::PAGE_SUBSCRIPTION_PROOF_MESSAGE, $sessionId, $userId, $cartEpoch, $deliveryId),
            $sessionToken,
        );
    }

    /**
     * Removes the displayed voucher with the session binding locked by the caller.
     *
     * @param GuestCartsLibraryAgent|UserAgent $agent Confirmed cart owner
     * @param int $sessionId Stored session that keys a guest cart
     * @param ?int $cartEpoch Number of the guest cart confirmed by the proof, or null for an account cart
     * @param int $expectedVoucherId Primary key of the displayed voucher
     * @throws ValidationException When the cart is unavailable, another voucher is applied or the owner refuses removal
     * @throws AccountErasingException When the person's erasure has begun
     * @throws ItemNotFoundForUpdateException When the person is missing
     * @throws LogicException When a collection is misconfigured
     * @throws InvalidArgumentException When a slot query or item is invalid or DB sync cannot be queued
     * @throws DatabaseException When a binding or catalog read, reconciliation, or delete fails
     * @throws ItemNotFoundException When a binding or catalog row disappears during a keyed read
     * @throws WriteNotAllowedException When the owner may not remove the binding
     * @throws CreateNotAllowedException When the ORM refuses a new row at its write door
     * @throws ObjectCollectionNullException When the binding has no backing collection
     * @throws ObjectGetIdStringNotImplementedException When the binding cannot be keyed
     * @throws SourceChangeSubscriberException When a binding change subscriber fails
     * @throws HilosException When the cart snapshot cannot be read
     * @throws NamedDatabaseException When the primary database or a cart table cannot be resolved
     * @throws TestRollbackRefusedException When a test rollback scope is closing
     * @throws NestedTransactionRefusedException When a transaction cannot be opened
     * @throws TransactionNotOpenException When the transaction fails or loses its level
     * @throws DatabaseConnectionException When the commit cannot reach the database
     * @throws DatabaseRuntimeException When the commit fails
     * @throws AnnouncementFailedException When releasing a committed announcement fails outside a handler
     * @throws Throwable When the transaction propagates another failure from the cart operation
     */
    private function removeExpectedVoucher(
        GuestCartsLibraryAgent|UserAgent $agent,
        int $sessionId,
        ?int $cartEpoch,
        int $expectedVoucherId,
    ): void {
        if ($agent instanceof GuestCartsLibraryAgent) {
            if ($cartEpoch === null) {
                throw new ValidationException(self::CART_UNAVAILABLE);
            }
            $voucherId = $agent->storedVoucherId($sessionId, $cartEpoch);
            if ($voucherId === null) {
                return;
            }
        } else {
            $voucher = $agent->cartVoucher();
            if ($voucher === null) {
                return;
            }
            $voucherId = $voucher->id;
        }
        if ($voucherId !== $expectedVoucherId) {
            throw new ValidationException('This voucher is no longer applied to your cart.');
        }

        if ($agent instanceof GuestCartsLibraryAgent) {
            $agent->removeVoucher($sessionId);
        } else {
            $agent->removeVoucher();
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
