<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Agents\Hilos;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Router\DTO\AccountCartSendSignalData;
use Demo\EcommerceShop\Core\Router\DTO\CartStateSignalData;
use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\UserProductCart;
use Demo\EcommerceShop\Groups\CartGroup;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Agent\Exception\AgentUnknownSignalException;
use Hilos\Core\Agent\Exception\InvalidAgentSignalPayloadException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\HilosException;
use Hilos\Users\AccountErasure;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\WiringRefusal;

/**
 * Person agent bound to the ecommerce-shop project.
 *
 * The one writer of its person's cart: it puts a product in, changes its quantity and takes it
 * out, and erases the whole cart in its own part of the erasure of the account, and tells every
 * tab and device of the person the cart after each change ({@see CartGroup}). The lines are read
 * from the database on demand, nothing is loaded at the start.
 */
final class UserAgent extends AbstractUserAgent
{
    /**
     * @var array<string, list<TruthSourceOperation>> The framework's sets of the person, and the person's cart:
     *     every line of it, created here too
     */
    public const array OWNS_DB_SET = [
        ...parent::OWNS_DB_SET,
        EcommerceShopDbContext::userProductCarts => TruthSourceOperation::ALL,
    ];

    /**
     * @var list<string> The framework's reads, and the products and the stock a cart line is checked against: their
     *     own libraries write them, and a cart write only reads the kind of its product and the units its warehouses keep
     */
    public const array READS_DB = [
        ...parent::READS_DB,
        EcommerceShopDbContext::products,
        EcommerceShopDbContext::productWarehouseStocks,
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
     * Deletes the person's cart; an empty cart deletes nothing and names no family.
     *
     * A cart that had lines is told empty to the person's tabs once the erasure commits; an
     * empty one tells nothing.
     *
     * @param int $userId Person whose account is being erased
     * @return AccountErasure The cart lines deleted, under their table; no registry file
     * @throws WriteNotAllowedException When this process may not remove a line of the person's cart
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object does not match its collection
     * @throws ObjectGetIdStringNotImplementedException If a matched item's Object does not implement getIdString()
     * @throws CollectionNotManualException When a matched item is rejected by a non-manual collection
     * @throws DatabaseException When the rows cannot be read, or the database refuses the delete
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When the right, the database or the collection refuses the delete
     */
    protected function eraseProjectChildren(int $userId): AccountErasure
    {
        $lines = Hilos::$db->userProductCarts->actions->deleteForUser($userId);
        if ($lines === 0) {
            return new AccountErasure([], []);
        }

        $this->publishCart($userId);

        return new AccountErasure([UserProductCart::_table => $lines], []);
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
     * Sends the person's whole cart to every tab and device of the person.
     *
     * Every write of this agent to the person's cart ends with this call, and so does a tab put
     * in the person's group. The lines are read now, in the order they were put in, and leave
     * after the commit. The person comes as a parameter: the agent's own is the framework's.
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
