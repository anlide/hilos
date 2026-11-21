<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Agents\Hilos;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Item\UserProductCart;
use Demo\EcommerceShop\Hilos;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\HilosException;
use Hilos\Users\AccountErasure;
use Hilos\Users\Agent\AbstractUserAgent;

/**
 * Person agent bound to the ecommerce-shop project.
 *
 * The one writer of its person's cart: it puts a product in, changes its quantity and takes it
 * out, and erases the whole cart in its own part of the erasure of the account. The lines are read
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

    /**
     * Deletes the person's cart; an empty cart deletes nothing and names no family.
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

        return new AccountErasure($lines === 0 ? [] : [UserProductCart::_table => $lines], []);
    }
}
