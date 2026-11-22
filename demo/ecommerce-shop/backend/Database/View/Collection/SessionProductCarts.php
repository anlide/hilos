<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\View\Collection;

use Demo\EcommerceShop\Database\Actions\Collection\SessionProductCartsActions;
use Demo\EcommerceShop\Database\Object\Collection\SessionProductCarts as ObjectSessionProductCarts;
use Demo\EcommerceShop\Database\View\Item\SessionProductCart;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\NamedDatabaseException;
use Hilos\Database\View\Collection\DbCollection;

/**
 * SessionProductCarts - Db collection of SessionProductCart items: the cart lines of visitors
 * without an account, one per product a browser session put in its cart.
 *
 * @extends DbCollection<SessionProductCart, ObjectSessionProductCarts>
 * @method ObjectSessionProductCarts|null getObjectCollection()
 * @method SessionProductCart|null current()
 * @method SessionProductCart|null first()
 * @method SessionProductCart|null last()
 * @method SessionProductCart offsetGet(mixed $offset)
 * @property-read SessionProductCartsActions $actions Writes for the guest carts library
 */
final class SessionProductCarts extends DbCollection
{
    public const string DB_ITEM_CLASS = SessionProductCart::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectSessionProductCarts::class;

    /**
     * @return array{int, int} Greatest product line ID and voucher binding session ID, zero for an empty table
     * @throws NamedDatabaseException When a table or its database cannot be resolved
     * @throws DatabaseException When the query fails
     */
    public function cartRecoveryHorizons(): array
    {
        return $this->objectCollection->cartRecoveryHorizons();
    }

    /**
     * @param int $afterLineId Exclusive lower bound for product line IDs
     * @param int $throughLineId Inclusive upper bound for product line IDs
     * @param int $afterVoucherSessionId Exclusive lower bound for voucher binding session IDs
     * @param int $throughVoucherSessionId Inclusive upper bound for voucher binding session IDs
     * @param int $limit Maximum number of rows per table
     * @return array{array<int, bool>, array<int, bool>} Ordered absence flags by product line ID and voucher binding session ID
     * @throws NamedDatabaseException When a table or its database cannot be resolved
     * @throws DatabaseException When a page query or its parameters are refused
     */
    public function cartRowAbsencesAfter(
        int $afterLineId,
        int $throughLineId,
        int $afterVoucherSessionId,
        int $throughVoucherSessionId,
        int $limit,
    ): array {
        return $this->objectCollection->cartRowAbsencesAfter(
            $afterLineId,
            $throughLineId,
            $afterVoucherSessionId,
            $throughVoucherSessionId,
            $limit,
        );
    }
}
