<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
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
     * @throws InvalidArgumentException When the entity query is invalid
     */
    public function findByCode(string $code): ?ObjectCountry
    {
        if ($code === '') {
            return null;
        }

        $entity = static::entityClass()::get([EntityCountry::code => $code])->first();
        if ($entity?->id === null) {
            return null;
        }

        if (!isset($this->objects[$entity->id])) {
            $this->hydrate($entity->id, static::OBJECT_CLASS::fromEntity($entity));
        }

        return $this->objects[$entity->id];
    }
}
