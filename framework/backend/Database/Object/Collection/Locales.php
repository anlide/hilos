<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\Locales as EntityLocales;
use Hilos\Database\Entity\Item\Locale as EntityLocale;
use Hilos\Database\Object\Item\Locale as ObjectLocale;
use Hilos\Database\Object\Objects;

/**
 * Locales loaded whole on first read.
 *
 * @extends Objects<ObjectLocale>
 * @method ObjectLocale|null offsetGet(mixed $offset)
 */
class Locales extends Objects
{
    public const string OBJECT_CLASS = ObjectLocale::class;
    public const string ENTITY_COLLECTION_CLASS = EntityLocales::class;
    public const string COLLECTION_KEY = HilosDbContext::locales;

    /**
     * @param string $code Locale code
     * @return ?ObjectLocale Locale or null
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the entity query is invalid
     */
    public function findByCode(string $code): ?ObjectLocale
    {
        if ($code === '') {
            return null;
        }

        $entity = static::entityClass()::get([EntityLocale::code => $code])->first();
        if ($entity?->id === null) {
            return null;
        }

        if (!isset($this->objects[$entity->id])) {
            $this->hydrate($entity->id, static::OBJECT_CLASS::fromEntity($entity));
        }

        return $this->objects[$entity->id];
    }
}
