<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\CountryNames as EntityCountryNames;
use Hilos\Database\Entity\Item\CountryName as EntityCountryName;
use Hilos\Database\Object\Item\CountryName as ObjectCountryName;
use Hilos\Database\Object\Objects;

/**
 * Translated country names, loaded as a whole on first read.
 *
 * @extends Objects<ObjectCountryName>
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
     */
    public function hasLockedNameInLanguage(int $languageId): bool
    {
        return static::entityClass()::count([
            EntityCountryName::language_id => $languageId,
            EntityCountryName::locked => true,
        ]) > 0;
    }

    /**
     * @param int $countryId Named country primary id
     * @return bool Whether any name row, base or override, even empty, names it
     * @throws DatabaseException When the query fails
     */
    public function hasForCountry(int $countryId): bool
    {
        return static::entityClass()::count([EntityCountryName::country_id => $countryId]) > 0;
    }

    /**
     * @param int $countryId Named country id
     * @param int $languageId Writing language id
     * @return ?ObjectCountryName Base row, or null
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the query is invalid
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
     */
    public function findOverride(int $countryId, int $languageId, int $localeId): ?ObjectCountryName
    {
        return $this->findOne($countryId, $languageId, $localeId);
    }

    /**
     * @param int $countryId Named country id
     * @param int $languageId Writing language id
     * @return list<ObjectCountryName> Overrides for this base
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the query is invalid
     */
    public function overridesFor(int $countryId, int $languageId): array
    {
        $rows = static::entityClass()::get(
            '`' . EntityCountryName::country_id . '` = ? AND `' . EntityCountryName::language_id . '` = ?'
                . ' AND `' . EntityCountryName::locale_id . '` IS NOT NULL',
            [$countryId, $languageId],
        );
        $names = [];
        foreach ($rows as $row) {
            if ($row->id !== null) {
                if (!isset($this->objects[$row->id])) {
                    $this->hydrate($row->id, static::OBJECT_CLASS::fromEntity($row));
                }
                $names[] = $this->objects[$row->id];
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
     */
    private function findOne(int $countryId, int $languageId, ?int $localeId): ?ObjectCountryName
    {
        $where = '`' . EntityCountryName::country_id . '` = ? AND `' . EntityCountryName::language_id . '` = ?'
            . ' AND `' . EntityCountryName::locale_id . '`' . ($localeId === null ? ' IS NULL' : ' = ?');
        $params = $localeId === null ? [$countryId, $languageId] : [$countryId, $languageId, $localeId];
        $row = static::entityClass()::get($where, $params)->first();
        if ($row?->id === null) {
            return null;
        }
        if (!isset($this->objects[$row->id])) {
            $this->hydrate($row->id, static::OBJECT_CLASS::fromEntity($row));
        }
        return $this->objects[$row->id];
    }
}
