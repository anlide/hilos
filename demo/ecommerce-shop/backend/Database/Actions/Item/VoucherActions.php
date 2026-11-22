<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions\Item;

use Demo\EcommerceShop\Database\Actions\VoucherFields;
use Demo\EcommerceShop\Database\Object\Item\Voucher as ObjectVoucher;
use Demo\EcommerceShop\Database\View\Item\Voucher;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Actions\Item\DbActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;

/**
 * @extends DbActions<Voucher, ObjectVoucher>
 * @property-read ObjectVoucher $object
 */
final class VoucherActions extends DbActions
{
    /**
     * Validation runs before any field is assigned. The object restores its saved fields
     * when the database or write guard refuses the update.
     *
     * @param string $code Voucher code as entered
     * @param int $discountPercent Discount as a whole percentage
     * @param bool $active Whether the voucher is active
     * @throws ItemNotFoundForUpdateException When the voucher has no ID
     * @throws EmptyValueException When the code is empty
     * @throws ValueTooLongException When the code is longer than its column
     * @throws ValidationException When the code or percentage is invalid
     * @throws DuplicateValueException When another voucher already has this code
     * @throws WriteNotAllowedException When this process may not edit the voucher
     * @throws CreateNotAllowedException When the object is new and creating it is forbidden
     * @throws DatabaseException When the binding or database update fails
     * @throws ObjectCollectionNullException When the backing collection is missing
     * @throws ObjectGetIdStringNotImplementedException When the object has no usable primary key
     * @throws SourceChangeSubscriberException When a subscriber to the change announcement fails
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     */
    public function update(string $code, int $discountPercent, bool $active): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Voucher not found for update (id is null)');
        }

        $code = VoucherFields::code($code);
        $discountPercent = VoucherFields::discountPercent($discountPercent);
        $this->object->code = $code;
        $this->object->discountPercent = $discountPercent;
        $this->object->active = $active;
        try {
            $this->object->sync();
        } catch (DuplicateEntryException $failure) {
            throw new DuplicateValueException('A voucher with this code already exists', previous: $failure);
        }
    }

    /**
     * Setting the current flag skips the write and its permission check.
     *
     * @param bool $active Whether the voucher is active
     * @throws ItemNotFoundForUpdateException When the voucher has no ID
     * @throws WriteNotAllowedException When this process may not edit the voucher
     * @throws CreateNotAllowedException When the object is new and creating it is forbidden
     * @throws DatabaseException When the binding or database update fails
     * @throws ObjectCollectionNullException When the backing collection is missing
     * @throws ObjectGetIdStringNotImplementedException When the object has no usable primary key
     * @throws SourceChangeSubscriberException When a subscriber to the change announcement fails
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     */
    public function setActive(bool $active): void
    {
        if ($this->object->active === $active) {
            return;
        }

        $this->ensureCanWrite();
        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Voucher not found for update (id is null)');
        }

        $this->object->active = $active;
        $this->object->sync();
    }
}
