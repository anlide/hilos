<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Agents\Hilos;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\DTO\AccountCartSendSignalData;
use Demo\EcommerceShop\Core\Router\DTO\CartAddProductDoneSignalData;
use Demo\EcommerceShop\Core\Router\DTO\CartAddProductSignalData;
use Demo\EcommerceShop\Core\Router\DTO\CartStateSignalData;
use Demo\EcommerceShop\Database\Actions\CartLineRules;
use Demo\EcommerceShop\Database\Actions\CartVoucherRules;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\UserProductCart;
use Demo\EcommerceShop\Database\Entity\Item\UserVoucherCart;
use Demo\EcommerceShop\Database\View\Item\Voucher;
use Demo\EcommerceShop\Groups\CartGroup;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Runtime\View\Context\EcommerceShopRtContext;
use Hilos\Auth\AccountOperation\Exception\AccountErasingException;
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
use Hilos\Database\Exception\Transaction\AnnouncementFailedException;
use Hilos\Database\Exception\Transaction\NestedTransactionRefusedException;
use Hilos\Database\Exception\Transaction\TransactionNotOpenException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\Exception\View\CollectionNotManualException;
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
 * Owns the person's product lines and voucher binding, and erases both in its assigned part
 * of account erasure. It reads the cart on demand and sends the whole cart to the person's
 * tabs and devices through {@see CartGroup}.
 */
final class UserAgent extends AbstractUserAgent
{
    /**
     * @var array<string, list<TruthSourceOperation>> The person's framework sets, cart lines and voucher binding
     */
    public const array OWNS_DB_SET = [
        ...parent::OWNS_DB_SET,
        EcommerceShopDbContext::userProductCarts => TruthSourceOperation::ALL,
        EcommerceShopDbContext::userVoucherCarts => TruthSourceOperation::ALL,
    ];

    /**
     * @var list<string> Framework reads plus the catalog entries and stock checked by cart operations
     */
    public const array READS_DB = [
        ...parent::READS_DB,
        EcommerceShopDbContext::products,
        EcommerceShopDbContext::productWarehouseStocks,
        EcommerceShopDbContext::vouchers,
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
    ];

    /**
     * Tells the person's tabs their cart and handles storefront additions. Inherited frames keep their own protocol.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Full sender identity, passed to the inherited handler
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is unknown to this agent
     * @throws InvalidAgentSignalPayloadException When the payload differs from its declaration
     * @throws AgentException When the frame names another person
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

            $line = Hilos::$db->userProductCarts[$lineId] ?? null;
            if ($line === null || $line->userId !== $userId) {
                throw new ValidationException(CartLineRules::LINE_UNAVAILABLE);
            }

            $line->actions->step($step);
            $this->publishCart($userId);
        });
    }

    /**
     * Assigns an active voucher to this person's cart, even when it has no product lines.
     * Interface actions will call this entry point once wired.
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
     * @throws HilosException When the binding collection refuses a reload or the cart snapshot cannot be read
     */
    public function applyVoucher(string $code): void
    {
        $userId = (int)$this->getIndex();
        AddressablePerson::require($userId);

        $voucherId = CartVoucherRules::voucherForCode($code)->id
            ?? throw new LogicException('A catalog voucher must have an ID');
        $binding = Hilos::$db->userVoucherCarts[$userId] ?? null;
        $previousVoucherId = $binding?->voucherId;
        if ($binding === null) {
            Hilos::$db->userVoucherCarts->actions->create($userId, $voucherId);
        } else {
            $binding->actions->setVoucher($voucherId);
        }

        if ($previousVoucherId !== $voucherId) {
            $this->publishCart($userId);
        }
    }

    /**
     * Clears this person's voucher slot if it is occupied. Interface actions will call this entry point once wired.
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
     * @throws HilosException When the cart snapshot cannot be read
     */
    public function removeVoucher(): void
    {
        $userId = (int)$this->getIndex();
        AddressablePerson::require($userId);

        $binding = Hilos::$db->userVoucherCarts[$userId] ?? null;
        if ($binding === null) {
            return;
        }

        $binding->actions->remove();
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
        $binding = Hilos::$db->userVoucherCarts[(int)$this->getIndex()] ?? null;

        return $binding === null ? null : Hilos::$db->vouchers[$binding->voucherId];
    }

    /**
     * Deletes the person's product lines and voucher binding inside the assigned erasure step.
     * If any cart rows were removed, the person's tabs learn the cart is empty once the erasure commits.
     *
     * @param int $userId Person whose account is being erased
     * @return AccountErasure Deleted rows by cart table, with no registry files
     * @throws WriteNotAllowedException When this process may not remove a cart row
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

        return new AccountErasure($deletedRows, []);
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
     * Sends the person's whole cart to every tab and device of the person.
     *
     * A tab added to the person's group receives the current cart. Its lines are
     * read in the order they were added. The snapshot leaves after the commit.
     *
     * @param int $userId Person whose cart is told
     * @throws InvalidArgumentException When the frame cannot be named or queued outside a transaction
     * @throws HilosException When the collection refuses the read
     */
    private function publishCart(int $userId): void
    {
        $lines = [];
        foreach (Hilos::$db->userProductCarts->whereColumnIs(UserProductCart::user_id, $userId) as $line) {
            $lines[$line->id] = [CartStateSignalData::PRODUCT_ID => $line->productId, CartStateSignalData::QUANTITY => $line->quantity];
        }
        ksort($lines);

        $frame = new CartStateSignalData(
            $userId,
            array_values($lines),
            (Hilos::$db->userVoucherCarts[$userId] ?? null)?->voucherId,
        );
        Database::afterCommit(fn () => $this->sendToGroup(EcommerceShopSignalConstants::CART_STATE, CartGroup::forUser($userId), $frame));
    }
}
