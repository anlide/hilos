<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\Languages as EntityLanguages;
use Hilos\Database\Entity\Item\Language as EntityLanguage;
use Hilos\Database\Object\Item\Language as ObjectLanguage;
use Hilos\Database\Object\Objects;

/**
 * Languages loaded whole on first read.
 *
 * @extends Objects<ObjectLanguage>
 * @method ObjectLanguage|null offsetGet(mixed $offset)
 */
class Languages extends Objects
{
    public const string OBJECT_CLASS = ObjectLanguage::class;
    public const string ENTITY_COLLECTION_CLASS = EntityLanguages::class;
    public const string COLLECTION_KEY = HilosDbContext::languages;

    /**
     * @param string $code Language code
     * @return ?ObjectLanguage Language or null
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the entity query is invalid
     */
    public function findByCode(string $code): ?ObjectLanguage
    {
        if ($code === '') {
            return null;
        }

        $entity = static::entityClass()::get([EntityLanguage::code => $code])->first();
        if ($entity?->id === null) {
            return null;
        }

        if (!isset($this->objects[$entity->id])) {
            $this->hydrate($entity->id, static::OBJECT_CLASS::fromEntity($entity));
        }

        return $this->objects[$entity->id];
    }
}
