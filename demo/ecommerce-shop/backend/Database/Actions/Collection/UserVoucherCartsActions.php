<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions\Collection;

use Demo\EcommerceShop\Database\Object\Collection\UserVoucherCarts as ObjectUserVoucherCarts;
use Demo\EcommerceShop\Database\Object\Item\UserVoucherCart as ObjectUserVoucherCart;
use Demo\EcommerceShop\Database\View\Item\UserVoucherCart;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\ExternalWriterException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\Database\Actions\Exception\DuplicateIdException;
use Hilos\Database\Actions\Exception\TableNameUndeterminedException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\HilosException;

/**
 * @extends DbActions<UserVoucherCart, ObjectUserVoucherCarts>
 * @property-read ObjectUserVoucherCarts $objectCollection
 */
final class UserVoucherCartsActions extends DbActions
{
    /**
     * Saves the cart's first voucher binding.
     *
     * @param int $userId Cart owner ID
     * @param int $voucherId Voucher ID
     * @throws CreateNotAllowedException When this process may not create the binding
     * @throws ExternalWriterException When the collection has an external writer
     * @throws WriteNotAllowedException When the save is refused by the write guard
     * @throws DatabaseException When the insert, binding or reload fails
     * @throws SourceChangeSubscriberException When a change subscriber fails
     * @throws InvalidArgumentException When the DB sync signal cannot be queued
     * @throws ObjectGetIdStringNotImplementedException When the binding has no usable key
     * @throws DuplicateIdException When the collection already holds the key
     * @throws TableNameUndeterminedException When the collection table is unknown
     * @throws LogicException When the collection Entity is unavailable for reload
     * @throws HilosException When the collection refuses a reload after database replacement
     */
    public function create(int $userId, int $voucherId): void
    {
        $this->ensureCanCreateInSet((string)$userId);

        $binding = ObjectUserVoucherCart::create();
        $binding->userId = $userId;
        $binding->voucherId = $voucherId;
        $binding->sync();
        $this->addObjectToCollection($binding);
    }
}
