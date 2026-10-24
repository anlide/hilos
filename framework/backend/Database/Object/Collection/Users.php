<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Database\Entity\Collection\Users as EntityUsers;
use Hilos\Database\Entity\Item\User as EntityUser;
use Hilos\Database\Object\Item\User as ObjectUser;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Objects;

/**
 * Users - Object collection for framework people.
 *
 * @extends Objects<ObjectUser>
 * @method ObjectUser|null current()
 * @method ObjectUser|null first()
 * @method ObjectUser|null last()
 * @method ObjectUser|null get(int|string $key)
 * @method ObjectUser|null offsetGet(mixed $offset)
 */
class Users extends Objects
{
    public const string OBJECT_CLASS = ObjectUser::class;
    public const string ENTITY_COLLECTION_CLASS = EntityUsers::class;
    public const string COLLECTION_KEY = HilosDbContext::users;

    private const string DATABASE_REQUIRED_MESSAGE = 'User projections require a database-backed collection';

    /**
     * Projects every current match using SQL collation and literal LIKE semantics,
     * which the shared equality loaders and a partial local mirror cannot express.
     *
     * @param string $substring Literal name substring, including LIKE metacharacters
     * @return list<int> All matching current user ids, without hydrating or retaining users
     * @throws DatabaseException When the collection has no database binding or the query fails
     */
    public function idsMatchingName(string $substring): array
    {
        $binding = $this->getDatabase() ?? throw new DatabaseException(self::DATABASE_REQUIRED_MESSAGE);
        $rows = Database::sql(
            'SELECT `id` FROM ' . $binding->getSqlTableName($this->getTableName()) . " WHERE `name` LIKE ? ESCAPE '!'",
            ['%' . strtr($substring, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%'],
            index: $binding->connectionIndex,
        )->rows();

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int)$row[EntityUser::id];
        }
        return $ids;
    }

    /**
     * Reads a fresh scalar snapshot after renames or deletions outside the local mirror.
     * Key and column loaders preserve warmed Objects, so they cannot supply these names.
     * This projection leaves the ordinary user item cache unchanged.
     *
     * @param list<int> $ids User ids from the selected window; an empty list issues no SQL
     * @return array<int, string> Current names keyed by id, omitting absent users
     * @throws DatabaseException When the collection has no database binding or the query fails
     */
    public function namesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $binding = $this->getDatabase() ?? throw new DatabaseException(self::DATABASE_REQUIRED_MESSAGE);
        $rows = Database::sql(
            'SELECT `id`, `name` FROM ' . $binding->getSqlTableName($this->getTableName())
                . ' WHERE `id` IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
            $ids,
            index: $binding->connectionIndex,
        )->rows();

        $names = [];
        foreach ($rows as $row) {
            $names[(int)$row[EntityUser::id]] = (string)$row[EntityUser::name];
        }
        return $names;
    }
}
