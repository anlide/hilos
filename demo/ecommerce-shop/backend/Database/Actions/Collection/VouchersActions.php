<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions\Collection;

use Demo\EcommerceShop\Database\Actions\VoucherFields;
use Demo\EcommerceShop\Database\Object\Collection\Vouchers as ObjectVouchers;
use Demo\EcommerceShop\Database\Object\Item\Voucher as ObjectVoucher;
use Demo\EcommerceShop\Database\View\Collection\Vouchers as DbCollectionVouchers;
use Demo\EcommerceShop\Database\View\Item\Voucher;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\ExternalWriterException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\Database\Actions\Exception\CallbackNotSetException;
use Hilos\Database\Actions\Exception\DuplicateIdException;
use Hilos\Database\Actions\Exception\TableNameUndeterminedException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\HilosException;

/**
 * @extends DbActions<Voucher, ObjectVouchers>
 * @property-read DbCollectionVouchers $collection
 * @property-read ObjectVouchers $objectCollection
 */
final class VouchersActions extends DbActions
{
    /**
     * The unique index arbitrates concurrent writes for the same normalized code.
     *
     * @param string $code Voucher code as entered
     * @param int $discountPercent Discount as a whole percentage
     * @param bool $active Whether the voucher is active
     * @return Voucher Created voucher
     * @throws EmptyValueException When the code is empty
     * @throws ValueTooLongException When the code is longer than its column
     * @throws ValidationException When the code or percentage is invalid
     * @throws DuplicateValueException When another voucher already has this code
     * @throws CreateNotAllowedException When this process may not add a voucher
     * @throws ExternalWriterException When the collection is configured for an external writer
     * @throws WriteNotAllowedException When the write guard refuses the save
     * @throws CallbackNotSetException When the collection cannot wrap the new object
     * @throws DatabaseException When the insert or collection loading fails
     * @throws SourceChangeSubscriberException When a subscriber to the change announcement fails
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws ObjectGetIdStringNotImplementedException When the object has no usable primary key
     * @throws DuplicateIdException When the collection already holds the assigned key
     * @throws TableNameUndeterminedException When the collection table cannot be determined
     * @throws LogicException When the collection Entity is unavailable for reload
     * @throws HilosException When the collection refuses a reload after database replacement
     */
    public function create(string $code, int $discountPercent, bool $active): Voucher
    {
        $this->ensureCanCreate();

        $code = VoucherFields::code($code);
        $discountPercent = VoucherFields::discountPercent($discountPercent);
        $voucher = ObjectVoucher::create();
        $voucher->code = $code;
        $voucher->discountPercent = $discountPercent;
        $voucher->active = $active;
        try {
            $voucher->sync();
        } catch (DuplicateEntryException $failure) {
            throw new DuplicateValueException('A voucher with this code already exists', previous: $failure);
        }

        $this->addObjectToCollection($voucher);

        return $this->createDbItemFromObject($voucher);
    }
}
