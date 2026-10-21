<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\LanguageNames as EntityLanguageNames;
use Hilos\Database\Entity\Item\LanguageName as EntityLanguageName;
use Hilos\Database\Object\Item\LanguageName as ObjectLanguageName;
use Hilos\Database\Object\Objects;
use Hilos\HilosException;

/**
 * Translated language names, loaded as a whole on first read.
 *
 * @extends Objects<ObjectLanguageName>
 * @method ObjectLanguageName|null first()
 * @method ObjectLanguageName|null last()
 * @method ObjectLanguageName|null get(int|string $key)
 * @method ObjectLanguageName|null offsetGet(mixed $offset)
 */
class LanguageNames extends Objects
{
    public const string OBJECT_CLASS = ObjectLanguageName::class;
    public const string ENTITY_COLLECTION_CLASS = EntityLanguageNames::class;
    public const string COLLECTION_KEY = HilosDbContext::languageNames;

    /**
     * @param int $languageId Named language primary id
     * @return int Number of nonempty base names in other languages
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the entity column is invalid
     * @throws LogicException When collection classes are not configured
     */
    public function countBaseNamesForLanguage(int $languageId): int
    {
        $count = 0;
        foreach ($this->loadByColumn(EntityLanguageName::language_id, $languageId) as $id) {
            $name = $this->objects[$id];
            if ($name->inLanguageId !== $languageId && $name->localeId === null && $name->name !== '') {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @param int $languageId Language primary id
     * @return bool Whether a locked name names it or is written in it
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the entity column is invalid
     * @throws LogicException When collection classes are not configured
     */
    public function hasManualNameForLanguage(int $languageId): bool
    {
        foreach ($this->loadByColumn(EntityLanguageName::language_id, $languageId) as $id) {
            if ($this->objects[$id]->locked === true) {
                return true;
            }
        }
        foreach ($this->loadByColumn(EntityLanguageName::in_language_id, $languageId) as $id) {
            if ($this->objects[$id]->locked === true) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param int $languageId Named language id
     * @param int $inLanguageId Writing language id
     * @return ?ObjectLanguageName Base row, or null
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the query is invalid
     * @throws LogicException When collection classes are not configured
     */
    public function findBase(int $languageId, int $inLanguageId): ?ObjectLanguageName
    {
        return $this->findOne($languageId, $inLanguageId, null);
    }

    /**
     * @param int $languageId Named language id
     * @param int $inLanguageId Writing language id
     * @param int $localeId Override locale id
     * @return ?ObjectLanguageName Override row, or null
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the query is invalid
     * @throws LogicException When collection classes are not configured
     */
    public function findOverride(int $languageId, int $inLanguageId, int $localeId): ?ObjectLanguageName
    {
        return $this->findOne($languageId, $inLanguageId, $localeId);
    }

    /**
     * @param int $languageId Named language id
     * @param int $inLanguageId Writing language id
     * @return static Overrides for this base, indexed by primary id
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the query is invalid
     * @throws HilosException When the empty collection refuses direct initialization
     */
    public function overridesFor(int $languageId, int $inLanguageId): static
    {
        $names = static::initEmpty();
        foreach ($this->loadByColumn(EntityLanguageName::language_id, $languageId) as $id) {
            $name = $this->objects[$id];
            if ($name->inLanguageId === $inLanguageId && $name->localeId !== null) {
                $names->hydrate($id, $name);
            }
        }
        return $names;
    }

    /**
     * @param int $languageId Named language id
     * @param int $inLanguageId Writing language id
     * @param ?int $localeId Null for the base, otherwise override locale
     * @return ?ObjectLanguageName Matching row, or null
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the query is invalid
     * @throws LogicException When collection classes are not configured
     */
    private function findOne(int $languageId, int $inLanguageId, ?int $localeId): ?ObjectLanguageName
    {
        foreach ($this->loadByColumn(EntityLanguageName::language_id, $languageId) as $id) {
            $name = $this->objects[$id];
            if ($name->inLanguageId === $inLanguageId && $name->localeId === $localeId) {
                return $name;
            }
        }
        return null;
    }
}
