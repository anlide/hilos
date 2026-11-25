<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Agents\Hilos;

use Closure;
use Demo\EcommerceShop\Agents\GuestCartsLibraryAgent;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\DTO\AccountCartSendSignalData;
use Demo\EcommerceShop\Core\Router\DTO\CartAddProductDoneSignalData;
use Demo\EcommerceShop\Core\Router\DTO\CartAddProductSignalData;
use Demo\EcommerceShop\Core\Router\DTO\CartStateSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartTransferClearedSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartTransferOfferSignalData;
use Demo\EcommerceShop\Core\Router\DTO\GuestCartTransferReceivedSignalData;
use Demo\EcommerceShop\Core\Router\DTO\ShopAccountMergeCartWakeSignalData;
use Demo\EcommerceShop\Database\Actions\CartLineRules;
use Demo\EcommerceShop\Database\Actions\CartVoucherRules;
use Demo\EcommerceShop\Database\Actions\Collection\SessionProductCartsActions;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\ShopGuestCartTransfer;
use Demo\EcommerceShop\Database\Entity\Item\UserProductCart;
use Demo\EcommerceShop\Database\Entity\Item\UserVoucherCart;
use Demo\EcommerceShop\Database\View\Item\Voucher;
use Demo\EcommerceShop\Groups\CartGroup;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Hilos\Auth\AccountOperation\AccountMergeAccess;
use Hilos\Auth\AccountOperation\AccountMergePart;
use Hilos\Auth\AccountOperation\AccountMergePersonalJoin;
use Hilos\Auth\AccountOperation\AccountMergeStage;
use Hilos\Auth\AccountOperation\Exception\AccountErasingException;
use Hilos\Auth\AccountOperation\Exception\AccountMergeGiveWaitsException;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Core\Action\ActionRefusal;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\ExternalWriterException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\DuplicateIdException;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Actions\Exception\TableNameUndeterminedException;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\DatabaseRuntimeException;
use Hilos\Database\Exception\NamedDatabaseException;
use Hilos\Database\Exception\TestRollbackRefusedException;
use Hilos\Database\Exception\Transaction\AnnouncementFailedException;
use Hilos\Database\Exception\Transaction\NestedTransactionRefusedException;
use Hilos\Database\Exception\Transaction\TransactionNotOpenException;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\HilosException;
use Hilos\Runtime\RtStaleness;
use Hilos\Users\AccountErasure;
use Hilos\Users\AddressablePerson;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\WiringRefusal;
use Throwable;

/**
 * Person agent bound to the ecommerce-shop project.
 *
 * Owns the person's product lines, voucher binding and guest cart transfers. It erases them in its
 * assigned part of account erasure and hands over the personal cart in an account merge. It reads
 * the cart on demand and sends the whole cart to the person's tabs and devices through {@see CartGroup}.
 */
final class UserAgent extends AbstractUserAgent implements AccountMergePersonalJoin
{
    /**
     * @var array<string, list<TruthSourceOperation>> The person's framework sets, cart lines, voucher binding and transfers
     */
    public const array OWNS_DB_SET = [
        ...parent::OWNS_DB_SET,
        EcommerceShopDbContext::userProductCarts => TruthSourceOperation::ALL,
        EcommerceShopDbContext::userVoucherCarts => TruthSourceOperation::ALL,
        EcommerceShopDbContext::shopGuestCartTransfers => [TruthSourceOperation::Update, TruthSourceOperation::Remove],
    ];

    /**
     * @var list<string> Framework reads plus catalog entries, stock and the saved guest cart sources
     */
    public const array READS_DB = [
        ...parent::READS_DB,
        EcommerceShopDbContext::products,
        EcommerceShopDbContext::productWarehouseStocks,
        EcommerceShopDbContext::vouchers,
        EcommerceShopDbContext::sessionProductCarts,
        EcommerceShopDbContext::sessionVoucherCarts,
    ];

    public const array READS_RT = [
        ...parent::READS_RT,
        EcommerceShopRtContext::connections,
    ];

    /** @var array<string, mixed> The framework's frames and the requests for this person's cart */
    public const array AGENT_SIGNALS = [
        ...parent::AGENT_SIGNALS,
        EcommerceShopSignalConstants::ACCOUNT_CART_SEND => [
            AgentSignalConfigKey::INDEX_FIELD => AccountCartSendSignalData::userId,
            AgentSignalConfigKey::DTO => AccountCartSendSignalData::class,
        ],
        EcommerceShopSignalConstants::ACCOUNT_CART_ADD_PRODUCT => [
            AgentSignalConfigKey::INDEX_FIELD => AccountCartSendSignalData::userId,
            AgentSignalConfigKey::DTO => CartAddProductSignalData::class,
        ],
        EcommerceShopSignalConstants::GUEST_CART_TRANSFER_OFFER => [
            AgentSignalConfigKey::INDEX_FIELD => GuestCartTransferOfferSignalData::userId,
            AgentSignalConfigKey::DTO => GuestCartTransferOfferSignalData::class,
        ],
        EcommerceShopSignalConstants::GUEST_CART_TRANSFER_CLEARED => [
            AgentSignalConfigKey::INDEX_FIELD => GuestCartTransferClearedSignalData::userId,
            AgentSignalConfigKey::DTO => GuestCartTransferClearedSignalData::class,
        ],
    ];

    /**
     * Tells the person's tabs their cart and handles storefront additions and guest transfers.
     * Inherited frames keep their own protocol.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Full sender identity
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is unknown to this agent
     * @throws InvalidAgentSignalPayloadException When the payload differs from its declaration
     * @throws AgentException When a transfer fails, a frame names another person or a transfer comes from another sender
     * @throws InvalidArgumentException When an answer cannot be named or queued
     * @throws InvalidFormatException When a cart add, renewal or account operation answer is malformed
     * @throws DatabaseException When the folded-account lookup fails
     * @throws LogicException When the folded-account collection cannot be read
     */
    public function onSignalAgent(AgentSignalData $data, string $sender, string $name): void
    {
        switch ($name) {
            case EcommerceShopSignalConstants::ACCOUNT_CART_SEND:
                $send = $data->data;
                if (!$send instanceof AccountCartSendSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, AccountCartSendSignalData::class, $send);
                }

                $this->refuseAnotherPerson($send->userId, $name);
                $this->answerCartAsk($send->userId);

                return;

            case EcommerceShopSignalConstants::ACCOUNT_CART_ADD_PRODUCT:
                $ask = $data->data;
                if (!$ask instanceof CartAddProductSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, CartAddProductSignalData::class, $ask);
                }

                $this->addProductToCart($ask);

                return;

            case EcommerceShopSignalConstants::GUEST_CART_TRANSFER_OFFER:
                $offer = $data->data;
                if (!$offer instanceof GuestCartTransferOfferSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, GuestCartTransferOfferSignalData::class, $offer);
                }

                $this->requireGuestCartTransferSender($sender);
                $this->refuseAnotherPerson($offer->userId, $name);
                $this->receiveGuestCart($offer);

                return;

            case EcommerceShopSignalConstants::GUEST_CART_TRANSFER_CLEARED:
                $cleared = $data->data;
                if (!$cleared instanceof GuestCartTransferClearedSignalData) {
                    throw new InvalidAgentSignalPayloadException($name, GuestCartTransferClearedSignalData::class, $cleared);
                }

                $this->requireGuestCartTransferSender($sender);
                $this->refuseAnotherPerson($cleared->userId, $name);
                $this->finishGuestCartTransfer($cleared);

                return;

            default:
                parent::onSignalAgent($data, $sender, $name);

                return;
        }
    }

    /**
     * Steps an existing line of this person's cart and publishes the committed cart.
     *
     * @param int $sessionId Browser session invoking the step
     * @param int $lineId Primary key of the saved line
     * @param int $step One unit up or down
     * @throws ValidationException When the session no longer belongs to the person, the line cannot be addressed,
     *     or the product rules refuse the step
     * @throws InvalidArgumentException When the direction, database read or sync signal is invalid
     * @throws LogicException When a configured collection cannot provide its item class
     * @throws DatabaseException When a read, write or commit fails
     * @throws ObjectGetIdStringNotImplementedException When a cart or stock row cannot provide its key
     * @throws CollectionNotManualException When a stock selection refuses a matched row
     * @throws ItemNotFoundException When a keyed row disappears during its read
     * @throws ObjectCollectionNullException When the line has no backing collection
     * @throws WriteNotAllowedException When this process cannot update the line
     * @throws CreateNotAllowedException When the write guard refuses a new row
     * @throws SourceChangeSubscriberException When a DB change subscriber fails
     * @throws NamedDatabaseException When the primary database is not configured
     * @throws NestedTransactionRefusedException When the primary database already has an application transaction
     * @throws TransactionNotOpenException When the level is no longer open at commit
     * @throws DatabaseConnectionException When the commit cannot reach the database
     * @throws DatabaseRuntimeException When the database refuses the commit
     * @throws AnnouncementFailedException When a commit announcement fails outside a handler
     * @throws HilosException When the configured cart sources cannot be read
     * @throws Throwable When the transaction propagates another failure from the cart operation
     */
    public function stepProductLine(int $sessionId, int $lineId, int $step): void
    {
        $userId = (int)$this->getIndex();
        Database::transaction(DatabaseNames::PRIMARY, function () use ($sessionId, $userId, $lineId, $step): void {
            if (!Hilos::$db->sessions->isStoredActiveForUser($sessionId, $userId)) {
                throw new ValidationException(CartLineRules::LINE_UNAVAILABLE);
            }

            try {
                AddressablePerson::require($userId);
            } catch (ValidationException $failure) {
                throw new ValidationException(CartLineRules::LINE_UNAVAILABLE, previous: $failure);
            }

            $line = Hilos::$db->userProductCarts->lockCartLine($userId, $lineId);
            if ($line === null) {
                throw new ValidationException(CartLineRules::LINE_UNAVAILABLE);
            }

            $line->actions->step($step);
            $this->publishCart($userId);
        });
    }

    /**
     * Sets the exact quantity of a saved line and publishes the cart when the quantity changes.
     *
     * @param int $sessionId Browser session confirmed by the cart page
     * @param int $lineId Primary key of the saved line
     * @param string $quantity Original quantity text from the form
     * @param int $expectedQuantity Saved quantity the edit started from
     * @throws ValidationException When the cart line cannot be addressed or the quantity is refused
     * @throws InvalidArgumentException When a database read or sync signal is invalid
     * @throws LogicException When a configured collection cannot provide its item class
     * @throws DatabaseException When a read, write or commit fails
     * @throws ObjectGetIdStringNotImplementedException When a cart or stock row cannot provide its key
     * @throws CollectionNotManualException When a stock selection refuses a matched row
     * @throws ItemNotFoundException When a keyed row disappears during its read
     * @throws ObjectCollectionNullException When the line has no backing collection
     * @throws WriteNotAllowedException When this process cannot update the line
     * @throws CreateNotAllowedException When the write guard refuses a new row
     * @throws SourceChangeSubscriberException When a DB change subscriber fails
     * @throws NamedDatabaseException When the primary database is not configured
     * @throws NestedTransactionRefusedException When the primary database already has an application transaction
     * @throws TransactionNotOpenException When the level is no longer open at commit
     * @throws DatabaseConnectionException When the commit cannot reach the database
     * @throws DatabaseRuntimeException When the database refuses the commit
     * @throws AnnouncementFailedException When a commit announcement fails outside a handler
     * @throws HilosException When the configured cart sources cannot be read
     * @throws Throwable When the transaction propagates another failure from the cart operation
     */
    public function setProductLineQuantity(int $sessionId, int $lineId, string $quantity, int $expectedQuantity): void
    {
        $userId = (int)$this->getIndex();
        Database::transaction(DatabaseNames::PRIMARY, function () use ($sessionId, $userId, $lineId, $quantity, $expectedQuantity): void {
            try {
                $sessionActive = Hilos::$db->sessions->lockStoredActiveSession($sessionId, $userId);
            } catch (DatabaseException $failure) {
                throw new ValidationException(CartLineRules::LINE_UNAVAILABLE, previous: $failure);
            }
            if (!$sessionActive) {
                throw new ValidationException(CartLineRules::LINE_UNAVAILABLE);
            }

            try {
                AddressablePerson::require($userId);
            } catch (ValidationException $failure) {
                throw new ValidationException(CartLineRules::LINE_UNAVAILABLE, previous: $failure);
            }

            $line = Hilos::$db->userProductCarts->lockCartLine($userId, $lineId);
            if ($line === null) {
                throw new ValidationException(CartLineRules::LINE_UNAVAILABLE);
            }

            $line->actions->setQuantityFrom($expectedQuantity, $quantity);
            if ($line->quantity !== $expectedQuantity) {
                $this->publishCart($userId);
            }
        });
    }

    /**
     * Selects an active voucher and supersedes earlier guest choices in the same transaction.
     *
     * @param string $code Voucher code as entered
     * @throws EmptyValueException When the trimmed code is empty
     * @throws ValueTooLongException When the code exceeds the catalog limit
     * @throws ValidationException When the person was merged or the code is malformed, unknown or inactive
     * @throws AccountErasingException When the person's erasure has begun
     * @throws ItemNotFoundForUpdateException When the person is missing or the binding is no longer persisted
     * @throws LogicException When a collection is misconfigured or the matched voucher lacks an ID
     * @throws InvalidArgumentException When a read uses an invalid column or object or DB sync cannot be queued
     * @throws DatabaseException When a read or write fails
     * @throws ItemNotFoundException When the binding disappears during a keyed read
     * @throws ObjectGetIdStringNotImplementedException When a voucher or binding cannot be keyed
     * @throws CollectionNotManualException When the selection cannot accept the matched voucher
     * @throws WriteNotAllowedException When this process may not change the binding
     * @throws CreateNotAllowedException When this process may not create the binding
     * @throws ExternalWriterException When the binding collection has an external writer
     * @throws ObjectCollectionNullException When the binding has no backing collection
     * @throws SourceChangeSubscriberException When a binding change subscriber fails
     * @throws DuplicateIdException When the collection already holds the binding key
     * @throws TableNameUndeterminedException When the binding table is unknown
     * @throws NamedDatabaseException When the primary database cannot be resolved
     * @throws TestRollbackRefusedException When a test rollback scope is closing
     * @throws NestedTransactionRefusedException When a transaction cannot be opened
     * @throws TransactionNotOpenException When the transaction fails or loses its level
     * @throws DatabaseConnectionException When the commit cannot reach the database
     * @throws DatabaseRuntimeException When the commit fails
     * @throws AnnouncementFailedException When releasing a committed announcement fails outside a handler
     * @throws HilosException When the binding, pending transfers or the cart snapshot cannot be read or saved
     * @throws Throwable When the transaction propagates another failure from the cart operation
     */
    public function applyVoucher(string $code): void
    {
        $userId = (int)$this->getIndex();
        AddressablePerson::require($userId);

        CartVoucherRules::applyVoucher($code, function (int $voucherId) use ($userId): void {
            $previousVoucherId = $this->saveCartVoucher($userId, $voucherId);
            $this->supersedePendingGuestVouchers($userId);
            if ($previousVoucherId !== $voucherId) {
                $this->publishCart($userId);
            }
        });
    }

    /**
     * Clears the voucher slot and supersedes pending guest choices at the caller's PRIMARY boundary when present.
     *
     * @throws ValidationException When the person was merged
     * @throws AccountErasingException When the person's erasure has begun
     * @throws ItemNotFoundForUpdateException When the person is missing
     * @throws LogicException When a collection is misconfigured
     * @throws InvalidArgumentException When a loaded object does not match its collection or DB sync cannot be queued
     * @throws DatabaseException When a read or delete fails
     * @throws ItemNotFoundException When the binding disappears during a keyed read
     * @throws WriteNotAllowedException When this process may not remove the binding
     * @throws CreateNotAllowedException When the ORM refuses a new row at its write door
     * @throws ObjectCollectionNullException When the binding has no backing collection
     * @throws ObjectGetIdStringNotImplementedException When the binding cannot be keyed
     * @throws SourceChangeSubscriberException When a binding change subscriber fails
     * @throws NamedDatabaseException When the primary database cannot be resolved
     * @throws TestRollbackRefusedException When a test rollback scope is closing
     * @throws TransactionNotOpenException When the transaction fails or loses its level
     * @throws DatabaseConnectionException When the commit cannot reach the database
     * @throws DatabaseRuntimeException When the commit fails
     * @throws AnnouncementFailedException When releasing a committed announcement fails outside a handler
     * @throws HilosException When pending transfers or the cart snapshot cannot be read or updated
     * @throws Throwable When the transaction propagates another failure from the cart operation
     */
    public function removeVoucher(): void
    {
        $userId = (int)$this->getIndex();
        SessionProductCartsActions::inPrimaryTransaction(function () use ($userId): void {
            AddressablePerson::require($userId);

            $binding = Hilos::$db->userVoucherCarts->storedSlot($userId);
            $binding?->actions->remove();
            $this->supersedePendingGuestVouchers($userId);
            if ($binding !== null) {
                $this->publishCart($userId);
            }
        });
    }

    /**
     * Removes a line from this person's cart inside the caller's journaled transaction.
     *
     * @param int $lineId Product line primary key
     * @throws ValidationException When the person is no longer addressable or the line is unavailable to them
     * @throws LogicException When a collection is misconfigured
     * @throws InvalidArgumentException When a loaded object does not match its collection or a signal cannot be queued
     * @throws DatabaseException When a read or delete fails
     * @throws ItemNotFoundException When the line disappears during a keyed read
     * @throws PropertyNotFoundException When the line does not expose a required property
     * @throws ActionsClassException When the line's actions cannot be resolved
     * @throws WriteNotAllowedException When this process may not remove the line from its owner's set
     * @throws CreateNotAllowedException When the ORM refuses a new row at its write door
     * @throws ObjectCollectionNullException When the line has no owning collection
     * @throws ObjectGetIdStringNotImplementedException When the line has no usable key
     * @throws SourceChangeSubscriberException When a change subscriber fails
     * @throws HilosException When the cart snapshot cannot be read
     */
    public function removeProductLine(int $lineId): void
    {
        $userId = (int)$this->getIndex();
        try {
            AddressablePerson::require($userId);
        } catch (ValidationException $e) {
            throw new ValidationException(CartLineRules::LINE_UNAVAILABLE, previous: $e);
        }

        $line = Hilos::$db->userProductCarts->lockCartLine($userId, $lineId);
        if ($line === null) {
            throw new ValidationException(CartLineRules::LINE_UNAVAILABLE);
        }

        $line->actions->remove();
        $this->publishCart($userId);
    }

    /**
     * Reads the selected voucher by its saved ID for the cart snapshot.
     *
     * @return ?Voucher Current catalog voucher, including inactive vouchers, or null for an empty slot
     * @throws LogicException When a collection is misconfigured
     * @throws InvalidArgumentException When a loaded object does not match its collection
     * @throws DatabaseException When the binding or catalog read fails
     * @throws ItemNotFoundException When a saved voucher ID is missing or the binding disappears during a keyed read
     */
    public function cartVoucher(): ?Voucher
    {
        $voucherId = Hilos::$db->userVoucherCarts->storedVoucherId((int)$this->getIndex());

        return $voucherId === null ? null : Hilos::$db->vouchers[$voucherId];
    }

    /**
     * Reads the loser's saved cart after Give has closed it to further changes.
     *
     * @param AccountMergePart $part Merge whose personal give belongs to this agent
     * @return array{lines: list<array{productId: int, quantity: int}>, voucherId: ?int} The loser's personal cart
     * @throws DatabaseException When the stored cart cannot be read
     * @throws InvalidArgumentException When the stored cart query is invalid
     */
    public function personalRowsToGive(AccountMergePart $part): array
    {
        return [
            CartStateSignalData::LINES => $this->storedProductLines($part->loserUserId),
            CartStateSignalData::VOUCHER_ID => Hilos::$db->userVoucherCarts->storedVoucherId($part->loserUserId),
        ];
    }

    /**
     * Joins the cart in the participant's transaction, which already holds the operation lock.
     *
     * @param AccountMergePart $part Merge whose personal receive belongs to this agent
     * @param array<string, mixed> $given The loser's cart as received by the personal handover
     * @return array<string, int> Counts of incoming rows by cart table
     * @throws InvalidFormatException When the personal cart snapshot is malformed
     * @throws ValidationException When a product or a combined quantity is invalid
     * @throws ItemNotFoundForUpdateException When the survivor no longer exists
     * @throws DatabaseException When the account lock, cart read or line write fails
     * @throws CreateNotAllowedException When the agent may not create a line in this cart
     * @throws WriteNotAllowedException When the agent may not change a line in this cart
     * @throws LogicException When a cart collection is misconfigured
     * @throws InvalidArgumentException When a cart object or announcement is invalid
     * @throws ObjectGetIdStringNotImplementedException When a matched line cannot be keyed
     * @throws CollectionNotManualException When the collection refuses a matched line
     * @throws SourceChangeSubscriberException When a store announcement subscriber fails
     * @throws HilosException When the cart or its pending voucher choices cannot be read, written or published
     */
    public function joinPersonalRows(AccountMergePart $part, array $given): array
    {
        if (count($given) !== 2 || !isset($given[CartStateSignalData::LINES]) || !array_key_exists(CartStateSignalData::VOUCHER_ID, $given)) {
            throw new InvalidFormatException('The personal cart handover requires lines and a voucher slot.');
        }
        $voucherId = $given[CartStateSignalData::VOUCHER_ID];
        if ($voucherId !== null && (!is_int($voucherId) || $voucherId <= 0)) {
            throw new InvalidFormatException('The personal cart handover carries an invalid voucher ID.');
        }
        $quantities = $this->personalCartQuantities($given[CartStateSignalData::LINES]);

        $userId = $part->survivorUserId;
        if ($voucherId !== null) {
            Hilos::$db->users->lockStoredAccount($userId);
        }
        $joined = [];
        foreach ($quantities as $productId => $quantity) {
            Hilos::$db->userProductCarts->actions->mergeLine($userId, $productId, $quantity);
        }
        if ($quantities !== []) {
            $joined[UserProductCart::_table] = count($quantities);
        }
        if ($voucherId !== null) {
            $this->saveCartVoucher($userId, $voucherId);
            $this->supersedePendingGuestVouchers($userId);
            $joined[UserVoucherCart::_table] = 1;
        }
        if ($joined !== []) {
            $this->publishCart($userId);
        }

        return $joined;
    }

    /**
     * Deletes the loser's own personal cart inside the participant step, after the receipt was saved.
     *
     * @param AccountMergePart $part Merge whose personal give belongs to this agent
     * @return array<string, int> Counts of deleted rows by cart table
     * @throws WriteNotAllowedException When this process may not remove a cart row
     * @throws CreateNotAllowedException When the ORM refuses a new row at its write door
     * @throws LogicException When a cart collection is misconfigured
     * @throws InvalidArgumentException When a cart object or announcement is invalid
     * @throws ItemNotFoundException When the binding disappears during a keyed read
     * @throws ObjectCollectionNullException When the binding has no backing collection
     * @throws ObjectGetIdStringNotImplementedException When a matched item cannot be keyed
     * @throws CollectionNotManualException When the collection refuses a matched line
     * @throws DatabaseException When a cart read or delete fails
     * @throws SourceChangeSubscriberException When a store announcement subscriber fails
     * @throws HilosException When the cart cannot be cleared or published
     */
    public function deleteGivenPersonalRows(AccountMergePart $part): array
    {
        return $this->deleteCartRows($part->loserUserId);
    }

    /**
     * Deletes the person's product lines, voucher binding and guest cart transfers inside the assigned erasure step.
     * If any cart rows were removed, the person's tabs learn the cart is empty once the erasure commits.
     *
     * @param int $userId Person whose account is being erased
     * @return AccountErasure Deleted rows by table, with no registry files
     * @throws WriteNotAllowedException When this process may not remove a cart row or transfer
     * @throws CreateNotAllowedException When the ORM refuses a new row at its write door
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object does not match its collection or an announcement cannot be queued
     * @throws ItemNotFoundException When the binding disappears during a keyed read
     * @throws ObjectCollectionNullException When the binding has no backing collection
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     * @throws DatabaseException When the rows cannot be read, or the database refuses the delete
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When a cart deletion or the snapshot read fails
     */
    protected function eraseProjectChildren(int $userId): AccountErasure
    {
        $deletedRows = $this->deleteCartRows($userId);

        $removedTransfers = 0;
        foreach (Hilos::$db->shopGuestCartTransfers->whereColumnIs(ShopGuestCartTransfer::user_id, $userId) as $transfer) {
            $transfer->actions->remove();
            $removedTransfers++;
        }
        if ($removedTransfers > 0) {
            $deletedRows[ShopGuestCartTransfer::_table] = $removedTransfers;
        }

        return new AccountErasure($deletedRows, []);
    }

    /**
     * Validates every incoming line before the caller can apply any of them.
     *
     * @param mixed $lines The lines field of the personal cart snapshot
     * @return array<int, int> Quantities by unique product ID, in handover order
     * @throws InvalidFormatException When the list, a line, or its product uniqueness is invalid
     */
    private function personalCartQuantities(mixed $lines): array
    {
        if (!is_array($lines) || !array_is_list($lines)) {
            throw new InvalidFormatException('The personal cart handover requires a list of lines.');
        }
        $quantities = [];
        foreach ($lines as $line) {
            if (!is_array($line) || count($line) !== 2 || !isset($line[CartStateSignalData::PRODUCT_ID], $line[CartStateSignalData::QUANTITY])) {
                throw new InvalidFormatException('A personal cart line requires only a product ID and quantity.');
            }
            $productId = $line[CartStateSignalData::PRODUCT_ID];
            $quantity = $line[CartStateSignalData::QUANTITY];
            if (!is_int($productId) || $productId <= 0 || !is_int($quantity)
                || $quantity < CartLineRules::QUANTITY_MIN || $quantity > CartLineRules::STORED_ACCOUNT_QUANTITY_MAX) {
                throw new InvalidFormatException('A personal cart line carries an invalid product ID or quantity.');
            }
            if (isset($quantities[$productId])) {
                throw new InvalidFormatException('A personal cart handover can carry only one line per product.');
            }
            $quantities[$productId] = $quantity;
        }

        return $quantities;
    }

    /**
     * Clears the person's product lines and voucher slot and publishes the empty cart after commit.
     *
     * @param int $userId Owner of the cart being cleared
     * @return array<string, int> Counts of deleted rows by cart table
     * @throws WriteNotAllowedException When this process may not remove a cart row
     * @throws CreateNotAllowedException When the ORM refuses a new row at its write door
     * @throws LogicException When a cart collection is misconfigured
     * @throws InvalidArgumentException When a cart object or announcement is invalid
     * @throws ItemNotFoundException When the binding disappears during a keyed read
     * @throws ObjectCollectionNullException When the binding has no backing collection
     * @throws ObjectGetIdStringNotImplementedException When a matched item cannot be keyed
     * @throws CollectionNotManualException When the collection refuses a matched line
     * @throws DatabaseException When a cart read or delete fails
     * @throws SourceChangeSubscriberException When a store announcement subscriber fails
     * @throws HilosException When the cart cannot be cleared or published
     */
    private function deleteCartRows(int $userId): array
    {
        $deletedRows = [];
        $lines = Hilos::$db->userProductCarts->actions->deleteForUser($userId);
        if ($lines > 0) {
            $deletedRows[UserProductCart::_table] = $lines;
        }

        $binding = Hilos::$db->userVoucherCarts[$userId] ?? null;
        if ($binding !== null) {
            $binding->actions->remove();
            $deletedRows[UserVoucherCart::_table] = 1;
        }

        if ($deletedRows !== []) {
            $this->publishCart($userId);
        }

        return $deletedRows;
    }

    /**
     * Tells the person's tabs the cart on the connections agent's ask; a failure stays in this agent's log.
     *
     * Nobody waits on the ask: the tab that went without the cart learns it with the next change.
     *
     * @param int $userId Person whose cart is told
     */
    private function answerCartAsk(int $userId): void
    {
        try {
            $this->publishCart($userId);
        } catch (WiringRefusal $e) {
            $this->logAgentError("Cart of user #{$userId} not told, refused by wiring: {$e->getMessage()}");
        } catch (HilosException $e) {
            $this->logAgentError("Cart of user #{$userId} not told: {$e->getMessage()}");
        }
    }

    /**
     * Writes the original person's cart and hands back the committed outcome.
     *
     * @param CartAddProductSignalData $ask Product and acting session from the storefront
     * @throws InvalidFormatException When the outcome has no original product address
     * @throws InvalidArgumentException When the outcome cannot be queued
     */
    private function addProductToCart(CartAddProductSignalData $ask): void
    {
        $refusal = null;
        try {
            $userId = $ask->userId;
            $connection = Hilos::$rt?->sessionConnectionsSource()?->get($ask->acceptKey);
            if (
                $userId === null || $connection?->sessionId !== $ask->sessionId || $connection->userId !== $userId
                || RtStaleness::staleSince(EcommerceShopRtContext::connections, $ask->acceptKey) !== null
            ) {
                throw new ValidationException(CartAddProductSignalData::CART_NOT_READY);
            }
            $this->refuseAnotherPerson($userId, EcommerceShopSignalConstants::ACCOUNT_CART_ADD_PRODUCT);

            Database::transaction(DatabaseNames::PRIMARY, function () use ($ask, $userId): void {
                if (!Hilos::$db->sessions->isStoredBoundToUser($ask->sessionId, $userId)) {
                    throw new ValidationException(CartAddProductSignalData::CART_NOT_READY);
                }

                AddressablePerson::require($userId);
                Hilos::$db->userProductCarts->actions->add($userId, $ask->productId);
                $this->publishCart($userId);
            });
        } catch (WiringRefusal $failure) {
            $refusal = ActionRefusal::fromThrowable($failure);
        } catch (Throwable $failure) {
            $refusal = ActionRefusal::fromThrowable($failure);
            if ($refusal->isInternal()) {
                $this->logAgentError("Account cart add failed for user #{$ask->userId}: {$failure->getMessage()}");
            }
        }

        $this->sendToAgent($ask->replySignal, new CartAddProductDoneSignalData($ask->productSlug, HandoverAnswerSignalData::to($ask, $refusal)));
    }

    /**
     * @param string $sender Full source identity from the dispatcher
     * @throws AgentException When the source is not the guest carts library
     */
    private function requireGuestCartTransferSender(string $sender): void
    {
        if ($sender !== SignalSource::describe(new SignalSource(SignalSource::AGENT, GuestCartsLibraryAgent::AGENT_TYPE))) {
            throw new AgentException('Guest cart transfers require the guest carts library as the sender.');
        }
    }

    /**
     * Selects an already resolved voucher ID at the caller's transaction boundary.
     *
     * @param int $userId Cart owner
     * @param int $voucherId Saved voucher ID
     * @return ?int Previous voucher ID, or null when the slot was empty
     * @throws HilosException When the slot cannot be read or saved
     */
    private function saveCartVoucher(int $userId, int $voucherId): ?int
    {
        $binding = Hilos::$db->userVoucherCarts->storedSlot($userId);
        if ($binding === null) {
            Hilos::$db->userVoucherCarts->actions->create($userId, $voucherId);

            return null;
        }

        $previousVoucherId = $binding->voucherId;
        $binding->actions->setVoucher($voucherId);

        return $previousVoucherId;
    }

    /**
     * @param int $userId Cart owner and recipient of the pending transfers
     * @param ?int $beforeTransferId Exclusive bound for a transfer choice, or null for a manual choice
     * @throws HilosException When a pending transfer cannot be read or updated
     */
    private function supersedePendingGuestVouchers(int $userId, ?int $beforeTransferId = null): void
    {
        $transfers = Hilos::$db->shopGuestCartTransfers;
        foreach ($transfers->lockPendingVoucherTransferIds($userId, $beforeTransferId) as $id) {
            $transfers[$id]->actions->markVoucherSuperseded();
        }
    }

    /**
     * @param AccountMergePart $part Merge whose Give owns the loser
     * @throws AccountMergeGiveWaitsException When assigned guest cart work is still due
     * @throws HilosException When the account or pending assignments cannot be locked
     */
    protected function beforeMergeGive(AccountMergePart $part): void
    {
        Hilos::$db->users->lockStoredAccount($part->loserUserId);
        if (Hilos::$db->shopGuestCartTransfers->hasUnreceivedForUser($part->loserUserId)) {
            throw new AccountMergeGiveWaitsException();
        }
    }

    /**
     * Commits the saved source and its receipt together, or repeats an already committed receipt.
     *
     * @param GuestCartTransferOfferSignalData $offer Offer addressed to this person by the library
     * @throws AgentException When account admission, storage or signal delivery refuses the transfer
     */
    private function receiveGuestCart(GuestCartTransferOfferSignalData $offer): void
    {
        $this->runGuestCartTransfer($offer->transferId, function () use ($offer): void {
            Hilos::$db->users->lockStoredAccount($offer->userId);
            $transfer = Hilos::$db->shopGuestCartTransfers->storedTransferForRecipient($offer->transferId, $offer->userId);
            if ($transfer === null) {
                return;
            }
            if (Hilos::$db->userMerges->isMergedInDatabase($offer->userId)) {
                throw new ValidationException(AbstractSessionsLibraryAgent::MERGED_ACCOUNT_REFUSED_MESSAGE);
            }

            $firstReceipt = !$transfer->received;
            if ($firstReceipt) {
                $quantities = Hilos::$db->sessionProductCarts->storedCartQuantityPage($transfer->sessionId, $transfer->cartEpoch, 0);
                $voucherId = Hilos::$db->sessionVoucherCarts->storedVoucherId($transfer->sessionId, $transfer->cartEpoch);
                if ($quantities === [] && $voucherId === null) {
                    return;
                }

                AddressablePerson::require($offer->userId);
                $reservation = AccountMergeAccess::reservation($offer->userId);
                // Give waits for this receipt. Other reserved accounts and stages still refuse cart changes.
                if ($reservation !== null && ($reservation->loserUserId !== $offer->userId
                    || $reservation->stage !== AccountMergeStage::Go->value)) {
                    throw new ValidationException(AccountMergeAccess::REFUSED_MESSAGE);
                }
                while ($quantities !== []) {
                    foreach ($quantities as $productId => $quantity) {
                        Hilos::$db->userProductCarts->actions->mergeLine($offer->userId, $productId, $quantity);
                    }
                    $quantities = Hilos::$db->sessionProductCarts->storedCartQuantityPage(
                        $transfer->sessionId,
                        $transfer->cartEpoch,
                        $productId,
                    );
                }

                if ($voucherId !== null && !$transfer->voucherSuperseded) {
                    $this->saveCartVoucher($offer->userId, $voucherId);
                    $this->supersedePendingGuestVouchers($offer->userId, $offer->transferId);
                }
                $transfer->actions->markReceived();
                $this->publishCart($offer->userId);
            }

            $receipt = new GuestCartTransferReceivedSignalData($offer->transferId, $offer->userId);
            Database::afterCommit(fn () => $this->sendToAgent(EcommerceShopSignalConstants::GUEST_CART_TRANSFER_RECEIVED, $receipt));
            if ($firstReceipt) {
                Database::afterCommit(fn () => $this->wakeMergeAfterGuestReceipt($offer->userId));
            }
        });
    }

    /**
     * Looks for Give after the receipt has committed and released the account lock.
     *
     * @param int $userId Recipient of the committed guest cart
     * @throws HilosException When the open merge cannot be read or the wake cannot be built or sent
     */
    private function wakeMergeAfterGuestReceipt(int $userId): void
    {
        $operation = Hilos::$db->accountOperations->openMergeOfLoser($userId);
        if ($operation?->id === null || $operation->stage !== AccountMergeStage::Go->value) {
            return;
        }

        $this->sendToAgent(EcommerceShopSignalConstants::SHOP_ACCOUNT_MERGE_CART_WAKE, new ShopAccountMergeCartWakeSignalData($operation->id));
    }

    /**
     * Removes a received transfer only after storage shows that its exact source cart is empty.
     *
     * @param GuestCartTransferClearedSignalData $cleared Clearance addressed to this person by the library
     * @throws AgentException When storage refuses the reads or removal
     */
    private function finishGuestCartTransfer(GuestCartTransferClearedSignalData $cleared): void
    {
        $this->runGuestCartTransfer($cleared->transferId, static function () use ($cleared): void {
            $transfer = Hilos::$db->shopGuestCartTransfers->storedTransferForRecipient($cleared->transferId, $cleared->userId);
            if ($transfer === null || !$transfer->received) {
                return;
            }

            // The library locks source rows before this marker, so read the pair without locking the source.
            if (
                !Hilos::$db->sessionProductCarts->hasCartLine($transfer->sessionId, $transfer->cartEpoch)
                && Hilos::$db->sessionVoucherCarts->storedVoucherId($transfer->sessionId, $transfer->cartEpoch) === null
            ) {
                $transfer->actions->remove();
            }
        });
    }

    /**
     * Rolls back failed transfer work and reports its cause to the agent dispatcher.
     *
     * @param int $transferId Transfer being applied or cleared
     * @param Closure(): void $work Reads and writes belonging to one transaction
     * @throws AgentException When the transfer transaction fails
     */
    private function runGuestCartTransfer(int $transferId, Closure $work): void
    {
        try {
            Database::transaction(DatabaseNames::PRIMARY, $work);
        } catch (Throwable $failure) {
            throw new AgentException("Guest cart transfer #{$transferId} failed: {$failure->getMessage()}", previous: $failure);
        }
    }

    /**
     * Sends the person's whole cart to every tab and device of the person.
     *
     * A tab added to the person's group receives the current cart. Its lines are
     * read in the order they were added. The snapshot leaves after the commit.
     *
     * @param int $userId Person whose cart is told
     * @throws InvalidArgumentException When a cart query or the frame is invalid
     * @throws DatabaseException When the persisted cart cannot be read
     * @throws HilosException When the collection refuses the read
     */
    private function publishCart(int $userId): void
    {
        $frame = new CartStateSignalData(
            $userId,
            $this->storedProductLines($userId),
            Hilos::$db->userVoucherCarts->storedVoucherId($userId),
        );
        Database::afterCommit(fn () => $this->sendToGroup(EcommerceShopSignalConstants::CART_STATE, CartGroup::forUser($userId), $frame));
    }

    /**
     * @param int $userId Owner of the saved cart
     * @return list<array{productId: int, quantity: int}> Product lines in saved order
     * @throws DatabaseException When the lines cannot be read
     * @throws InvalidArgumentException When the stored cart query is invalid
     */
    private function storedProductLines(int $userId): array
    {
        $lines = [];
        foreach (Hilos::$db->userProductCarts->storedCartLines($userId) as [$productId, $quantity]) {
            $lines[] = [CartStateSignalData::PRODUCT_ID => $productId, CartStateSignalData::QUANTITY => $quantity];
        }

        return $lines;
    }
}
