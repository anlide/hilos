<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Object\Collection;

use Demo\EcommerceShop\Database\EcommerceShopDbContext;
use Demo\EcommerceShop\Database\Entity\Collection\UserProductCarts as EntityUserProductCarts;
use Demo\EcommerceShop\Database\Entity\Item\UserProductCart as EntityUserProductCart;
use Demo\EcommerceShop\Database\Object\Item\UserProductCart as ObjectUserProductCart;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlSortDirection;
use Hilos\HilosException;

/**
 * UserProductCarts - Object collection for the cart lines of people with an account.
 *
 * @extends Objects<ObjectUserProductCart>
 * @method ObjectUserProductCart|null current()
 * @method ObjectUserProductCart|null first()
 * @method ObjectUserProductCart|null last()
 * @method ObjectUserProductCart|null get(int|string $key)
 * @method ObjectUserProductCart|null offsetGet(mixed $offset)
 */
final class UserProductCarts extends Objects
{
    public const string OBJECT_CLASS = ObjectUserProductCart::class;
    public const string ENTITY_COLLECTION_CLASS = EntityUserProductCarts::class;
    public const string COLLECTION_KEY = EcommerceShopDbContext::userProductCarts;

    /**
     * @param int $productId Product primary id
     * @return int Number of account carts containing the product
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the entity column is invalid
     * @throws LogicException When collection classes are not configured
     */
    public function countForProduct(int $productId): int
    {
        return $this->countByColumn(EntityUserProductCart::product_id, $productId);
    }

    /**
     * Reads the cart snapshot in saved line order, including writes whose announcements were lost.
     *
     * @param int $userId Cart owner
     * @return iterable<int, array{int, int}> Product ID and quantity by line ID
     * @throws DatabaseException When the cart rows cannot be read
     * @throws InvalidArgumentException When the entity read cannot be built
     */
    public function storedCartLines(int $userId): iterable
    {
        $rows = static::entityClass()::get( // fresh-sql: a committed cart can differ from the held rows after a lost reply.
            [EntityUserProductCart::user_id => $userId],
            orderBy: [EntityUserProductCart::id => SqlSortDirection::ASC],
        );
        foreach ($rows as $line) {
            yield $line->id => [$line->product_id, $line->quantity];
        }
    }

    /**
     * Checks the cart's owner/product index without walking the held rows or loading new objects.
     *
     * @param int $userId Cart owner
     * @param ?int $productId Product to match, null for any line
     * @return bool Whether the current cart has a matching line
     * @throws DatabaseException When the cart membership cannot be read
     */
    public function hasCartLine(int $userId, ?int $productId = null): bool
    {
        $filters = [EntityUserProductCart::user_id => $userId];
        if ($productId !== null) {
            $filters[EntityUserProductCart::product_id] = $productId;
        }

        return EntityUserProductCart::countUpTo(0, $filters) > 0;
    }

    /**
     * Locks the exact saved line and reconciles the object that a caller will write through.
     *
     * @param int $userId Cart owner
     * @param int $lineId Primary key supplied by the caller
     * @return ?ObjectUserProductCart The current line of this cart, or null when it is absent
     * @throws HilosException When the locking read or reconciliation fails
     */
    public function lockCartLine(int $userId, int $lineId): ?ObjectUserProductCart
    {
        return $this->lockLine('`id` = ? AND `user_id` = ?', [$lineId, $userId], self::primaryTransactionIndex());
    }

    /**
     * Resolves the product key in the set owner's transaction and locks an existing line by ID.
     *
     * The indexed agent owns the set before the transaction starts, so the PRIMARY snapshot sees
     * its predecessor's committed lines and the transaction's own inserts. A miss takes no gap lock.
     *
     * @param int $userId Cart owner
     * @param int $productId Catalog product
     * @return ?ObjectUserProductCart The saved line, including one missing from the mirror
     * @throws DatabaseException When the lookup fails, or the saved match changes before the lock
     * @throws InvalidArgumentException When the entity read cannot be built
     * @throws HilosException When the transaction guard, locking read or reconciliation fails
     */
    public function lockProductLine(int $userId, int $productId): ?ObjectUserProductCart
    {
        $index = self::primaryTransactionIndex();
        $lineId = static::entityClass()::get( // fresh-sql: a committed line may be missing from the mirror.
            [EntityUserProductCart::user_id => $userId, EntityUserProductCart::product_id => $productId],
        )->first()?->id;
        if ($lineId === null) {
            return null;
        }

        return $this->lockLine('`id` = ? AND `user_id` = ? AND `product_id` = ?', [$lineId, $userId, $productId], $index)
            ?? throw new DatabaseException('The saved user cart line changed during the PRIMARY transaction.');
    }

    /**
     * @return int The connection index of the open cart transaction
     * @throws DatabaseException When no cart transaction is open
     */
    private static function primaryTransactionIndex(): int
    {
        $index = EntityUserProductCart::getDatabase()->connectionIndex;
        if (Database::runtimeTransactionLevel($index) === null) {
            throw new DatabaseException('The user cart line lock requires a PRIMARY transaction.');
        }

        return $index;
    }

    /**
     * @param string $predicate Fixed SQL predicate that identifies the row by primary key and verifies its coordinates
     * @param list<int> $keys Bound key values
     * @param int $index Connection holding the open PRIMARY transaction
     * @return ?ObjectUserProductCart The matching line with its durable baseline
     * @throws HilosException When the locking read or reconciliation fails
     */
    private function lockLine(string $predicate, array $keys, int $index): ?ObjectUserProductCart
    {
        $row = Database::sql(
            'SELECT * FROM ' . EntityUserProductCart::getSqlTableName() . ' FORCE INDEX (PRIMARY) WHERE ' . $predicate . ' FOR UPDATE',
            $keys,
            index: $index,
        )->firstRow();

        return $row === null ? null : $this->holdStored(EntityUserProductCart::fromRow($row));
    }

    /**
     * @param EntityUserProductCart $stored Cart line read under its PRIMARY lock
     * @return ObjectUserProductCart The object to use for ORM writes
     * @throws HilosException When the backing database or its rollback scope is unavailable
     */
    private function holdStored(EntityUserProductCart $stored): ObjectUserProductCart
    {
        $held = $this->objects[$stored->id] ?? null;
        if ($held !== null) {
            $held->acceptStored($stored);
            return $held;
        }

        $line = ObjectUserProductCart::fromEntity($stored);
        $this->hydrate($stored->id, $line);

        return $line;
    }
}
