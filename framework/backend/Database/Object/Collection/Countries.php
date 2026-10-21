<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\Countries as EntityCountries;
use Hilos\Database\Entity\Item\Country as EntityCountry;
use Hilos\Database\Object\Item\Country as ObjectCountry;
use Hilos\Database\Object\Objects;

/**
 * Countries loaded whole on first read.
 *
 * @extends Objects<ObjectCountry>
 * @method ObjectCountry|null first()
 * @method ObjectCountry|null last()
 * @method ObjectCountry|null get(int|string $key)
 * @method ObjectCountry|null offsetGet(mixed $offset)
 */
class Countries extends Objects
{
    public const string OBJECT_CLASS = ObjectCountry::class;
    public const string ENTITY_COLLECTION_CLASS = EntityCountries::class;
    public const string COLLECTION_KEY = HilosDbContext::countries;

    /**
     * @param string $code Country code
     * @return ?ObjectCountry Country or null
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the entity column is invalid
     * @throws LogicException When collection classes are not configured
     */
    public function findByCode(string $code): ?ObjectCountry
    {
        if ($code === '') {
            return null;
        }

        foreach ($this->loadByColumn(EntityCountry::code, $code) as $id) {
            return $this->objects[$id];
        }

        return null;
    }
}
