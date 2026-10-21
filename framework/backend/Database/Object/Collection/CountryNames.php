<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\CountryNames as EntityCountryNames;
use Hilos\Database\Entity\Item\CountryName as EntityCountryName;
use Hilos\Database\Object\Item\CountryName as ObjectCountryName;
use Hilos\Database\Object\Objects;
use Hilos\HilosException;

/**
 * Translated country names, loaded as a whole on first read.
 *
 * @extends Objects<ObjectCountryName>
 * @method ObjectCountryName|null first()
 * @method ObjectCountryName|null last()
 * @method ObjectCountryName|null get(int|string $key)
 * @method ObjectCountryName|null offsetGet(mixed $offset)
 */
class CountryNames extends Objects
{
    public const string OBJECT_CLASS = ObjectCountryName::class;
    public const string ENTITY_COLLECTION_CLASS = EntityCountryNames::class;
    public const string COLLECTION_KEY = HilosDbContext::countryNames;

    /**
     * @param int $languageId Writing language primary id
     * @return bool Whether a locked country name is written in it
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the entity column is invalid
     * @throws LogicException When collection classes are not configured
     */
    public function hasLockedNameInLanguage(int $languageId): bool
    {
        foreach ($this->loadByColumn(EntityCountryName::language_id, $languageId) as $id) {
            if ($this->objects[$id]->locked === true) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param int $languageId Writing language primary id
     * @return static All unlocked base names written in the language, indexed by primary id
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the query is invalid
     * @throws HilosException When the empty collection refuses direct initialization
     */
    public function unlockedBaseNamesInLanguage(int $languageId): static
    {
        $names = static::initEmpty();
        foreach ($this->loadByColumn(EntityCountryName::language_id, $languageId) as $id) {
            $name = $this->objects[$id];
            if ($name->locked === false && $name->localeId === null) {
                $names->hydrate($id, $name);
            }
        }
        return $names;
    }

    /**
     * @param int $countryId Named country primary id
     * @return bool Whether any name row, base or override, even empty, names it
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the entity column is invalid
     * @throws LogicException When collection classes are not configured
     */
    public function hasForCountry(int $countryId): bool
    {
        return $this->countByColumn(EntityCountryName::country_id, $countryId) > 0;
    }

    /**
     * @param int $countryId Named country primary id
     * @return int Number of nonempty base names in every language
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the entity column is invalid
     * @throws LogicException When collection classes are not configured
     */
    public function countBaseNamesForCountry(int $countryId): int
    {
        $count = 0;
        foreach ($this->loadByColumn(EntityCountryName::country_id, $countryId) as $id) {
            $name = $this->objects[$id];
            if ($name->localeId === null && $name->name !== '') {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @param int $countryId Named country id
     * @param int $languageId Writing language id
     * @return ?ObjectCountryName Base row, or null
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the query is invalid
     * @throws LogicException When collection classes are not configured
     */
    public function findBase(int $countryId, int $languageId): ?ObjectCountryName
    {
        return $this->findOne($countryId, $languageId, null);
    }

    /**
     * @param int $countryId Named country id
     * @param int $languageId Writing language id
     * @param int $localeId Override locale id
     * @return ?ObjectCountryName Override row, or null
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the query is invalid
     * @throws LogicException When collection classes are not configured
     */
    public function findOverride(int $countryId, int $languageId, int $localeId): ?ObjectCountryName
    {
        return $this->findOne($countryId, $languageId, $localeId);
    }

    /**
     * @param int $countryId Named country id
     * @param int $languageId Writing language id
     * @return static Overrides for this base, indexed by primary id
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the query is invalid
     * @throws HilosException When the empty collection refuses direct initialization
     */
    public function overridesFor(int $countryId, int $languageId): static
    {
        $names = static::initEmpty();
        foreach ($this->loadByColumn(EntityCountryName::country_id, $countryId) as $id) {
            $name = $this->objects[$id];
            if ($name->languageId === $languageId && $name->localeId !== null) {
                $names->hydrate($id, $name);
            }
        }
        return $names;
    }

    /**
     * @param int $countryId Named country id
     * @param int $languageId Writing language id
     * @param ?int $localeId Null for the base, otherwise override locale
     * @return ?ObjectCountryName Matching row, or null
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the query is invalid
     * @throws LogicException When collection classes are not configured
     */
    private function findOne(int $countryId, int $languageId, ?int $localeId): ?ObjectCountryName
    {
        foreach ($this->loadByColumn(EntityCountryName::country_id, $countryId) as $id) {
            $name = $this->objects[$id];
            if ($name->languageId === $languageId && $name->localeId === $localeId) {
                return $name;
            }
        }
        return null;
    }
}
