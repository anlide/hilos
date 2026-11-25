<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Collection;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Collection\SessionProductCarts as EntitySessionProductCarts;
use Demo\EcommerceShop\Database\Entity\Item\SessionProductCart as EntitySessionProductCart;
use Demo\EcommerceShop\Database\Entity\Item\SessionVoucherCart as EntitySessionVoucherCart;
use Demo\EcommerceShop\Database\Object\Item\SessionProductCart as ObjectSessionProductCart;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\Session as EntitySession;
use Hilos\Database\Exception\NamedDatabaseException;
use Hilos\Database\Object\Objects;

/**
 * SessionProductCarts - Object collection for the cart lines of visitors without an account.
 *
 * @extends Objects<ObjectSessionProductCart>
 * @method ObjectSessionProductCart|null current()
 * @method ObjectSessionProductCart|null first()
 * @method ObjectSessionProductCart|null last()
 * @method ObjectSessionProductCart|null get(int|string $key)
 * @method ObjectSessionProductCart|null offsetGet(mixed $offset)
 */
final class SessionProductCarts extends Objects
{
    public const string OBJECT_CLASS = ObjectSessionProductCart::class;
    public const string ENTITY_COLLECTION_CLASS = EntitySessionProductCarts::class;
    public const string COLLECTION_KEY = EcommerceShopDbContext::sessionProductCarts;

    /**
     * @param int $productId Product primary id
     * @return int Number of guest carts containing the product
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the entity column is invalid
     * @throws LogicException When collection classes are not configured
     */
    public function countForProduct(int $productId): int
    {
        return $this->countByColumn(EntitySessionProductCart::product_id, $productId);
    }

    private const string CART_KEY = 'cart_key';
    private const string SESSION_MISSING = 'session_missing';
    private const string LAST_LINE_ID = 'last_line_id';
    private const string LAST_VOUCHER_SESSION_ID = 'last_voucher_session_id';

    /**
     * Checks the cart's owner/product index without walking the held rows or loading new objects.
     *
     * @param int $sessionId Cart owner
     * @param ?int $productId Product to match, null for any line
     * @return bool Whether the current cart has a matching line
     * @throws DatabaseException When the cart membership cannot be read
     */
    public function hasCartLine(int $sessionId, ?int $productId = null): bool
    {
        $filters = [EntitySessionProductCart::session_id => $sessionId];
        if ($productId !== null) {
            $filters[EntitySessionProductCart::product_id] = $productId;
        }

        return EntitySessionProductCart::countUpTo(0, $filters) > 0;
    }

    /**
     * Captures the high-water marks once for a recovery pass, using the last primary key of each table.
     *
     * @return array{int, int} Greatest product line ID and voucher binding session ID, zero for an empty table
     * @throws NamedDatabaseException When a table or its database cannot be resolved
     * @throws DatabaseException When the query fails
     */
    public function cartRecoveryHorizons(): array
    {
        $products = EntitySessionProductCart::getSqlTableName();
        $vouchers = EntitySessionVoucherCart::getSqlTableName();
        $lineId = EntitySessionProductCart::id;
        $voucherSessionId = EntitySessionVoucherCart::session_id;
        $lastLine = self::LAST_LINE_ID;
        $lastVoucher = self::LAST_VOUCHER_SESSION_ID;

        $rows = Database::sql(
            "SELECT COALESCE((SELECT MAX(`{$lineId}`) FROM {$products}), 0) AS `{$lastLine}`,
                COALESCE((SELECT MAX(`{$voucherSessionId}`) FROM {$vouchers}), 0) AS `{$lastVoucher}`",
            index: EntitySessionProductCart::getDatabase()->connectionIndex,
        )->rows();

        return [(int)$rows[0][$lastLine], (int)$rows[0][$lastVoucher]];
    }

    /**
     * Reads bounded pages of physical cart rows, including carts that only hold a voucher.
     *
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
        return [
            $this->rowAbsencesAfter(
                EntitySessionProductCart::class,
                EntitySessionProductCart::id,
                EntitySessionProductCart::session_id,
                $afterLineId,
                $throughLineId,
                $limit,
            ),
            $this->rowAbsencesAfter(
                EntitySessionVoucherCart::class,
                EntitySessionVoucherCart::session_id,
                EntitySessionVoucherCart::session_id,
                $afterVoucherSessionId,
                $throughVoucherSessionId,
                $limit,
            ),
        ];
    }

    /**
     * Limits the ordered primary-key range before looking up sessions by their primary key.
     *
     * The held collection's column lookup walks the mirror and cannot bound work by physical rows.
     * Each SQL page visits at most $limit cart rows even if they all belong to a single session.
     *
     * @param class-string<Entity> $cartEntity Cart table represented by the page
     * @param string $keyColumn Cart row's primary key column
     * @param string $sessionColumn Column identifying the owning session
     * @param int $afterId Exclusive lower bound for cart keys
     * @param int $throughId Inclusive upper bound for cart keys
     * @param int $limit Maximum number of cart rows to check
     * @return array<int, bool> Ordered absence flags by cart key
     * @throws NamedDatabaseException When a table or its database cannot be resolved
     * @throws DatabaseException When the page query or its parameters are refused
     */
    private function rowAbsencesAfter(string $cartEntity, string $keyColumn, string $sessionColumn, int $afterId, int $throughId, int $limit): array
    {
        if ($afterId >= $throughId) {
            return [];
        }

        $carts = $cartEntity::getSqlTableName();
        $sessions = EntitySession::getSqlTableName();
        $sessionId = EntitySession::id;
        $cartKey = self::CART_KEY;
        $missing = self::SESSION_MISSING;

        $rows = Database::sql(
            "SELECT cart.`{$cartKey}`, session_row.`{$sessionId}` IS NULL AS `{$missing}`
            FROM (
                SELECT `{$keyColumn}` AS `{$cartKey}`, `{$sessionColumn}` FROM {$carts} FORCE INDEX (PRIMARY)
                WHERE `{$keyColumn}` > ? AND `{$keyColumn}` <= ? ORDER BY `{$keyColumn}` LIMIT ?
            ) AS cart
            LEFT JOIN {$sessions} AS session_row ON session_row.`{$sessionId}` = cart.`{$sessionColumn}`
            ORDER BY cart.`{$cartKey}`",
            [$afterId, $throughId, $limit],
            index: $cartEntity::getDatabase()->connectionIndex,
        )->rows();

        $absences = [];
        foreach ($rows as $row) {
            $absences[(int)$row[$cartKey]] = (bool)$row[$missing];
        }

        return $absences;
    }
}
