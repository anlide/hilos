<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Database\Entity\Collection\Users as EntityUsers;
use Hilos\Database\Object\Item\User as ObjectUser;
use Hilos\Database\Context\HilosDbContext;
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
}
