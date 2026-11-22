<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Agents\Hilos;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\DTO\AccountCartSendSignalData;
use Demo\EcommerceShop\Core\Router\DTO\CartStateSignalData;
use Demo\EcommerceShop\Database\Actions\CartVoucherRules;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\UserProductCart;
use Demo\EcommerceShop\Database\Entity\Item\UserVoucherCart;
use Demo\EcommerceShop\Database\View\Item\Voucher;
use Demo\EcommerceShop\Groups\CartGroup;
use Demo\EcommerceShop\Hilos;
use Hilos\Auth\AccountOperation\Exception\AccountErasingException;
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
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\HilosException;
use Hilos\Users\AccountErasure;
use Hilos\Users\AddressablePerson;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\WiringRefusal;

/**
 * Person agent bound to the ecommerce-shop project.
 *
 * Owns the person's product lines and voucher binding, and erases both in its assigned part
 * of account erasure. It reads the cart on demand and sends its product lines to the person's
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

    /** @var array<string, mixed> The framework's frames, and the ask to tell the person's tabs the cart */
    public const array AGENT_SIGNALS = [
        ...parent::AGENT_SIGNALS,
        EcommerceShopSignalConstants::ACCOUNT_CART_SEND => [
            AgentSignalConfigKey::INDEX_FIELD => AccountCartSendSignalData::userId,
            AgentSignalConfigKey::DTO => AccountCartSendSignalData::class,
        ],
    ];

    /**
     * Tells the person's tabs the cart when asked; the ask is not answered. Inherited frames keep their own protocol.
     *
     * @param AgentSignalData $data Wrapped agent-signal payload
     * @param string $sender Full sender identity, passed to the inherited handler
     * @param string $name Routed agent-signal name
     * @throws AgentUnknownSignalException When the name is unknown to this agent
     * @throws InvalidAgentSignalPayloadException When the payload differs from its declaration
     * @throws AgentException When the frame names another person
     * @throws InvalidArgumentException When an inherited answer cannot be named or queued
     * @throws InvalidFormatException When an inherited renewal or account operation answer is malformed
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

            default:
                parent::onSignalAgent($data, $sender, $name);

                return;
        }
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
     * @throws HilosException When the binding collection refuses a reload after database replacement
     */
    public function applyVoucher(string $code): void
    {
        $userId = (int)$this->getIndex();
        AddressablePerson::require($userId);

        $voucherId = CartVoucherRules::voucherForCode($code)->id
            ?? throw new LogicException('A catalog voucher must have an ID');
        $binding = Hilos::$db->userVoucherCarts[$userId] ?? null;
        if ($binding === null) {
            Hilos::$db->userVoucherCarts->actions->create($userId, $voucherId);

            return;
        }

        $binding->actions->setVoucher($voucherId);
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
     */
    public function removeVoucher(): void
    {
        $userId = (int)$this->getIndex();
        AddressablePerson::require($userId);

        (Hilos::$db->userVoucherCarts[$userId] ?? null)?->actions->remove();
    }

    /**
     * Reads the selected voucher by its saved ID for the cart calculation once that is wired.
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
     * Removing product lines tells the person's tabs about the empty cart once the erasure commits.
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
     * @throws HilosException When the right, the database or the collection refuses the delete
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

        if ($lines > 0) {
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
     * Sends the person's product lines to every tab and device of the person.
     *
     * A tab added to the person's group receives those lines too. They are read now, in the
     * order they were added, and leave after the commit.
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

        $frame = new CartStateSignalData($userId, array_values($lines));
        Database::afterCommit(fn () => $this->sendToGroup(EcommerceShopSignalConstants::CART_STATE, CartGroup::forUser($userId), $frame));
    }
}
