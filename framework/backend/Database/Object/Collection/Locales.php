<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
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
 * @method ObjectLocale|null first()
 * @method ObjectLocale|null last()
 * @method ObjectLocale|null get(int|string $key)
 * @method ObjectLocale|null offsetGet(mixed $offset)
 */
class Locales extends Objects
{
    public const string OBJECT_CLASS = ObjectLocale::class;
    public const string ENTITY_COLLECTION_CLASS = EntityLocales::class;
    public const string COLLECTION_KEY = HilosDbContext::locales;

    /**
     * @param int $languageId Language primary id
     * @return int Number of its locales, regardless of enabled state
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the entity column is invalid
     * @throws LogicException When collection classes are not configured
     */
    public function countForLanguage(int $languageId): int
    {
        return $this->countByColumn(EntityLocale::language_id, $languageId);
    }

    /**
     * @param int $languageId Language primary id
     * @return ?bool Whether its countryless locale is enabled; null when absent
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the entity column is invalid
     * @throws LogicException When collection classes are not configured
     */
    public function countrylessEnabled(int $languageId): ?bool
    {
        foreach ($this->loadByColumn(EntityLocale::language_id, $languageId) as $id) {
            $locale = $this->objects[$id];
            if ($locale->countryId === null) {
                return $locale->enabled;
            }
        }

        return null;
    }

    /**
     * @param string $code Locale code
     * @return ?ObjectLocale Locale or null
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the entity column is invalid
     * @throws LogicException When collection classes are not configured
     */
    public function findByCode(string $code): ?ObjectLocale
    {
        if ($code === '') {
            return null;
        }

        foreach ($this->loadByColumn(EntityLocale::code, $code) as $id) {
            return $this->objects[$id];
        }

        return null;
    }
}
