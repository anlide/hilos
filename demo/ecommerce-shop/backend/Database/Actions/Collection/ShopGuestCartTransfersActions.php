<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions\Collection;

use Demo\EcommerceShop\Database\Object\Collection\ShopGuestCartTransfers as ObjectShopGuestCartTransfers;
use Demo\EcommerceShop\Database\Object\Item\ShopGuestCartTransfer as ObjectShopGuestCartTransfer;
use Demo\EcommerceShop\Database\View\Item\ShopGuestCartTransfer;
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
 * @extends DbActions<ShopGuestCartTransfer, ObjectShopGuestCartTransfers>
 * @property-read ObjectShopGuestCartTransfers $objectCollection
 */
final class ShopGuestCartTransfersActions extends DbActions
{
    /**
     * Assigns a guest product cart to its recipient account.
     *
     * @param int $sessionId Source browser session ID
     * @param int $cartEpoch Number of the source cart
     * @param int $userId Recipient account ID
     * @throws CreateNotAllowedException When this process may not create the transfer
     * @throws ExternalWriterException When the collection has an external writer
     * @throws WriteNotAllowedException When the save is refused by the write guard
     * @throws DatabaseException When the insert or collection reload fails
     * @throws SourceChangeSubscriberException When a change subscriber fails
     * @throws InvalidArgumentException When the DB sync signal cannot be queued
     * @throws ObjectGetIdStringNotImplementedException When the transfer has no usable key
     * @throws DuplicateIdException When the collection already holds the key
     * @throws TableNameUndeterminedException When the collection table is unknown
     * @throws LogicException When the collection Entity is unavailable for reload
     * @throws HilosException When the collection refuses a reload after database replacement
     */
    public function create(int $sessionId, int $cartEpoch, int $userId): void
    {
        $this->ensureCanCreate();

        $transfer = ObjectShopGuestCartTransfer::create();
        $transfer->sessionId = $sessionId;
        $transfer->cartEpoch = $cartEpoch;
        $transfer->userId = $userId;
        $transfer->received = false;
        $transfer->sync();
        $this->addObjectToCollection($transfer);
    }
}
