<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Collection;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Collection\SessionProductCarts as EntitySessionProductCarts;
use Demo\EcommerceShop\Database\Entity\Item\Session as EntitySession;
use Demo\EcommerceShop\Database\Entity\Item\SessionProductCart as EntitySessionProductCart;
use Demo\EcommerceShop\Database\Entity\Item\SessionVoucherCart as EntitySessionVoucherCart;
use Demo\EcommerceShop\Database\Entity\Item\ShopGuestCartTransfer as EntityShopGuestCartTransfer;
use Demo\EcommerceShop\Database\Object\Item\SessionProductCart as ObjectSessionProductCart;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\NamedDatabaseException;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlSortDirection;
use Hilos\HilosException;

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

    private const string CART_KEY = 'cart_key';
    private const string SESSION_MISSING = 'session_missing';
    private const string LAST_LINE_ID = 'last_line_id';
    private const string LAST_VOUCHER_ID = 'last_voucher_id';
    private const int TRANSFER_LINES_PER_PAGE = 25;

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

    /**
     * Reads the cart snapshot in saved line order, including writes whose announcements were lost.
     *
     * @param int $sessionId Cart owner
     * @param int $cartEpoch Number of the guest cart
     * @return iterable<int, array{int, int}> Product ID and quantity by line ID
     * @throws DatabaseException When the cart rows cannot be read
     * @throws InvalidArgumentException When the entity read cannot be built
     */
    public function storedCartLines(int $sessionId, int $cartEpoch): iterable
    {
        $rows = static::entityClass()::get( // fresh-sql: a committed cart can differ from the held rows after a lost reply.
            [EntitySessionProductCart::session_id => $sessionId, EntitySessionProductCart::cart_epoch => $cartEpoch],
            orderBy: [EntitySessionProductCart::id => SqlSortDirection::ASC],
        );
        foreach ($rows as $line) {
            yield $line->id => [$line->product_id, $line->quantity];
        }
    }

    /**
     * Checks the cart's owner/product index without walking the held rows or loading new objects.
     *
     * @param int $sessionId Cart owner
     * @param int $cartEpoch Number of the guest cart
     * @param ?int $productId Product to match, null for any line
     * @return bool Whether the current cart has a matching line
     * @throws DatabaseException When the cart membership cannot be read
     */
    public function hasCartLine(int $sessionId, int $cartEpoch, ?int $productId = null): bool
    {
        $filters = [
            EntitySessionProductCart::session_id => $sessionId,
            EntitySessionProductCart::cart_epoch => $cartEpoch,
        ];
        if ($productId !== null) {
            $filters[EntitySessionProductCart::product_id] = $productId;
        }

        return EntitySessionProductCart::countUpTo(0, $filters) > 0;
    }

    /**
     * Checks membership using a current read after the caller locked the session.
     *
     * @param int $sessionId Cart owner
     * @param int $cartEpoch Number of the source cart
     * @return bool Whether that exact pair has a saved product line
     * @throws HilosException When the locking read fails
     */
    public function hasLockedCartLine(int $sessionId, int $cartEpoch): bool
    {
        return Database::sql(
            'SELECT `id` FROM ' . EntitySessionProductCart::getSqlTableName()
            . ' WHERE `session_id` = ? AND `cart_epoch` = ? LIMIT 1 FOR UPDATE',
            [$sessionId, $cartEpoch],
            index: EntitySessionProductCart::getDatabase()->connectionIndex,
        )->firstRow() !== null;
    }

    /**
     * Locks a bounded portion in the pair index's order before checking the transfer receipt.
     *
     * @param int $sessionId Cart owner
     * @param int $cartEpoch Number of the source cart
     * @param int $limit Remaining line budget for this attempt
     * @return list<int> Locked line IDs for guarded ORM removal
     * @throws HilosException When the locking read or reconciliation fails
     */
    public function lockCartLineIds(int $sessionId, int $cartEpoch, int $limit): array
    {
        $rows = Database::sql(
            'SELECT * FROM ' . EntitySessionProductCart::getSqlTableName() . ' FORCE INDEX (uk_session_product_cart)'
            . ' WHERE `session_id` = ? AND `cart_epoch` = ? ORDER BY `product_id` LIMIT ? FOR UPDATE',
            [$sessionId, $cartEpoch, $limit],
            index: EntitySessionProductCart::getDatabase()->connectionIndex,
        )->rows();
        $ids = [];
        foreach ($rows as $row) {
            $stored = EntitySessionProductCart::fromRow($row);
            $this->holdStored($stored);
            $ids[] = $stored->id;
        }

        return $ids;
    }

    /**
     * Reads the source quantities from storage since the library's latest writes may not have reached the recipient.
     *
     * @param int $sessionId Source session
     * @param int $cartEpoch Source cart number
     * @return array<int, int> Stored quantities keyed by product ID
     * @throws DatabaseException When the cart rows cannot be read
     * @throws InvalidArgumentException When the entity read cannot be built
     */
    public function storedCartQuantities(int $sessionId, int $cartEpoch): array
    {
        $quantities = [];
        foreach (static::entityClass()::get( // fresh-sql: a pending offer must match the durable source, including quantities.
            [EntitySessionProductCart::session_id => $sessionId, EntitySessionProductCart::cart_epoch => $cartEpoch],
        ) as $line) {
            $quantities[$line->product_id] = $line->quantity;
        }

        return $quantities;
    }

    /**
     * Reads one page from the frozen source, in the order of the pair's unique product index.
     *
     * @param int $sessionId Source session
     * @param int $cartEpoch Source cart number
     * @param int $afterProductId Exclusive product cursor, zero at the start
     * @return array<int, int> Stored quantities in ascending product ID order
     * @throws DatabaseException When the cart rows cannot be read
     * @throws InvalidArgumentException When the entity read cannot be built
     */
    public function storedCartQuantityPage(int $sessionId, int $cartEpoch, int $afterProductId): array
    {
        $quantities = [];
        foreach (static::entityClass()::get( // fresh-sql: the recipient's mirror may lag the frozen source.
            '`session_id` = ? AND `cart_epoch` = ? AND `product_id` > ?',
            [$sessionId, $cartEpoch, $afterProductId],
            [EntitySessionProductCart::product_id => SqlSortDirection::ASC],
            self::TRANSFER_LINES_PER_PAGE,
        ) as $line) {
            $quantities[$line->product_id] = $line->quantity;
        }

        return $quantities;
    }

    /**
     * @param int $lineId Key of a possibly sparse cart fact
     * @return ?array{int, int} Persisted session and epoch, or null when the line is gone
     * @throws HilosException When the keyed read fails
     */
    public function storedLineCoordinate(int $lineId): ?array
    {
        $line = EntitySessionProductCart::getById($lineId);

        return $line === null ? null : [$line->session_id, $line->cart_epoch];
    }

    /**
     * @param list<int> $sessionIds Swept sessions
     * @return array<int, array<int, list<int>>> Physical line IDs by session and epoch
     * @throws HilosException When the stored rows cannot be selected
     */
    public function storedLineIdsByPair(array $sessionIds): array
    {
        $ids = [];
        foreach ($this->readStoredByColumn(EntitySessionProductCart::session_id, ...$sessionIds) as $line) {
            $ids[$line->session_id][$line->cart_epoch][] = $line->id;
        }

        return $ids;
    }

    /**
     * Locks the exact saved line and reconciles the object that a caller will write through.
     *
     * @param int $sessionId Cart owner
     * @param int $cartEpoch Number of the guest cart
     * @param int $lineId Primary key supplied by the caller
     * @return ?ObjectSessionProductCart The current line of this cart, or null when it is absent
     * @throws HilosException When the locking read or reconciliation fails
     */
    public function lockCartLine(int $sessionId, int $cartEpoch, int $lineId): ?ObjectSessionProductCart
    {
        return $this->lockLine('`id` = ? AND `session_id` = ? AND `cart_epoch` = ?', [$lineId, $sessionId, $cartEpoch]);
    }

    /**
     * Locks the row at the cart's unique product key before calculating a mutation.
     *
     * @param int $sessionId Cart owner
     * @param int $cartEpoch Number of the guest cart
     * @param int $productId Catalog product
     * @return ?ObjectSessionProductCart The saved line, including one missing from the mirror
     * @throws HilosException When the locking read or reconciliation fails
     */
    public function lockProductLine(int $sessionId, int $cartEpoch, int $productId): ?ObjectSessionProductCart
    {
        return $this->lockLine('`session_id` = ? AND `cart_epoch` = ? AND `product_id` = ?', [$sessionId, $cartEpoch, $productId]);
    }

    /**
     * Locks the transfer key after the caller has locked the source session and cart line.
     *
     * @param int $sessionId Cart owner
     * @param int $cartEpoch Number of the guest cart
     * @return bool Whether this exact pair has any transfer, pending or received
     * @throws HilosException When the read fails
     */
    public function hasLockedTransfer(int $sessionId, int $cartEpoch): bool
    {
        return Database::sql(
            'SELECT `id` FROM ' . EntityShopGuestCartTransfer::getSqlTableName()
            . ' WHERE `session_id` = ? AND `cart_epoch` = ? FOR UPDATE',
            [$sessionId, $cartEpoch],
            index: EntityShopGuestCartTransfer::getDatabase()->connectionIndex,
        )->firstRow() !== null;
    }

    /**
     * Captures the high-water marks once for a recovery pass, using the last primary key of each table.
     *
     * @return array{int, int} Greatest product line ID and voucher binding ID, zero for an empty table
     * @throws NamedDatabaseException When a table or its database cannot be resolved
     * @throws DatabaseException When the query fails
     */
    public function cartRecoveryHorizons(): array
    {
        $products = EntitySessionProductCart::getSqlTableName();
        $vouchers = EntitySessionVoucherCart::getSqlTableName();
        $lineId = EntitySessionProductCart::id;
        $voucherId = EntitySessionVoucherCart::id;
        $lastLine = self::LAST_LINE_ID;
        $lastVoucher = self::LAST_VOUCHER_ID;

        $rows = Database::sql(
            "SELECT COALESCE((SELECT MAX(`{$lineId}`) FROM {$products}), 0) AS `{$lastLine}`,
                COALESCE((SELECT MAX(`{$voucherId}`) FROM {$vouchers}), 0) AS `{$lastVoucher}`",
            index: EntitySessionProductCart::getDatabase()->connectionIndex,
        )->rows();

        return [(int)$rows[0][$lastLine], (int)$rows[0][$lastVoucher]];
    }

    /**
     * Reads bounded pages of physical cart rows, including carts that only hold a voucher.
     *
     * @param int $afterLineId Exclusive lower bound for product line IDs
     * @param int $throughLineId Inclusive upper bound for product line IDs
     * @param int $afterVoucherId Exclusive lower bound for voucher binding IDs
     * @param int $throughVoucherId Inclusive upper bound for voucher binding IDs
     * @param int $limit Maximum number of rows per table
     * @return array{array<int, bool>, array<int, bool>} Ordered cleanup candidate flags by product line ID and voucher binding ID
     * @throws NamedDatabaseException When a table or its database cannot be resolved
     * @throws DatabaseException When a page query or its parameters are refused
     */
    public function cartRowAbsencesAfter(
        int $afterLineId,
        int $throughLineId,
        int $afterVoucherId,
        int $throughVoucherId,
        int $limit,
    ): array {
        return [
            $this->rowAbsencesAfter(
                EntitySessionProductCart::class,
                $afterLineId,
                $throughLineId,
                $limit,
            ),
            $this->rowAbsencesAfter(
                EntitySessionVoucherCart::class,
                $afterVoucherId,
                $throughVoucherId,
                $limit,
            ),
        ];
    }

    /**
     * @param string $predicate Fixed SQL predicate for a primary or unique key
     * @param list<int> $keys Bound key values
     * @return ?ObjectSessionProductCart The matching line with its durable baseline
     * @throws HilosException When the locking read or reconciliation fails
     */
    private function lockLine(string $predicate, array $keys): ?ObjectSessionProductCart
    {
        $index = EntitySessionProductCart::getDatabase()->connectionIndex;
        if (Database::runtimeTransactionLevel($index) === null) {
            throw new DatabaseException('The session cart line lock requires a PRIMARY transaction.');
        }

        $row = Database::sql(
            'SELECT * FROM ' . EntitySessionProductCart::getSqlTableName() . ' WHERE ' . $predicate . ' FOR UPDATE',
            $keys,
            index: $index,
        )->firstRow();

        return $row === null ? null : $this->holdStored(EntitySessionProductCart::fromRow($row));
    }

    /**
     * @param EntitySessionProductCart $stored Cart line read under its PRIMARY lock
     * @return ObjectSessionProductCart The object to use for ORM writes
     * @throws HilosException When the backing database or its rollback scope is unavailable
     */
    private function holdStored(EntitySessionProductCart $stored): ObjectSessionProductCart
    {
        $held = $this->objects[$stored->id] ?? null;
        if ($held !== null) {
            $held->acceptStored($stored);
            return $held;
        }

        $line = ObjectSessionProductCart::fromEntity($stored);
        $this->hydrate($stored->id, $line);

        return $line;
    }

    /**
     * Limits the ordered primary-key range before looking up sessions by their primary key.
     *
     * The held collection's column lookup walks the mirror and cannot bound work by physical rows.
     * Each SQL page visits at most $limit cart rows even if they all belong to a single session.
     *
     * @param class-string<EntitySessionProductCart|EntitySessionVoucherCart> $cartEntity Cart table represented by the page
     * @param int $afterId Exclusive lower bound for cart keys
     * @param int $throughId Inclusive upper bound for cart keys
     * @param int $limit Maximum number of cart rows to check
     * @return array<int, bool> Ordered absence flags by cart key
     * @throws NamedDatabaseException When a table or its database cannot be resolved
     * @throws DatabaseException When the page query or its parameters are refused
     */
    private function rowAbsencesAfter(string $cartEntity, int $afterId, int $throughId, int $limit): array
    {
        if ($afterId >= $throughId) {
            return [];
        }

        $carts = $cartEntity::getSqlTableName();
        $sessions = EntitySession::getSqlTableName();
        $transfers = EntityShopGuestCartTransfer::getSqlTableName();
        $sessionId = EntitySession::id;
        $sessionEpoch = EntitySession::cart_epoch;
        $keyColumn = $cartEntity::id;
        $sessionColumn = $cartEntity::session_id;
        $epochColumn = $cartEntity::cart_epoch;
        $transferId = EntityShopGuestCartTransfer::id;
        $transferSession = EntityShopGuestCartTransfer::session_id;
        $transferEpoch = EntityShopGuestCartTransfer::cart_epoch;
        $cartKey = self::CART_KEY;
        $missing = self::SESSION_MISSING;

        $rows = Database::sql(
            "SELECT cart.`{$cartKey}`,
                ((session_row.`{$sessionId}` IS NULL OR session_row.`{$sessionEpoch}` <> cart.`{$epochColumn}`)
                    AND transfer.`{$transferId}` IS NULL) AS `{$missing}`
            FROM (
                SELECT `{$keyColumn}` AS `{$cartKey}`, `{$sessionColumn}`, `{$epochColumn}` FROM {$carts} FORCE INDEX (PRIMARY)
                WHERE `{$keyColumn}` > ? AND `{$keyColumn}` <= ? ORDER BY `{$keyColumn}` LIMIT ?
            ) AS cart
            LEFT JOIN {$sessions} AS session_row ON session_row.`{$sessionId}` = cart.`{$sessionColumn}`
            LEFT JOIN {$transfers} AS transfer ON transfer.`{$transferSession}` = cart.`{$sessionColumn}`
                AND transfer.`{$transferEpoch}` = cart.`{$epochColumn}`
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
