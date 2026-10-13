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
use Hilos\I18n\DTO\CountryLocaleChoice;

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
     * @param int $languageId Language primary id
     * @return int Number of its locales, regardless of enabled state
     * @throws DatabaseException When the query fails
     */
    public function countForLanguage(int $languageId): int
    {
        return static::entityClass()::count([EntityLocale::language_id => $languageId]);
    }

    /**
     * @param int $languageId Language primary id
     * @return ?bool Whether its countryless locale is enabled; null when absent
     * @throws DatabaseException When the query fails
     */
    public function countrylessEnabled(int $languageId): ?bool
    {
        $locale = static::entityClass()::get('`language_id` = ? AND `country_id` IS NULL', [$languageId])->first();

        return $locale?->enabled;
    }

    /**
     * @param int $countryId Country primary id
     * @return int Number of its locales, regardless of enabled state
     * @throws DatabaseException When the query fails
     */
    public function countForCountry(int $countryId): int
    {
        return static::entityClass()::count([EntityLocale::country_id => $countryId]);
    }

    /**
     * @param int $countryId Country primary id
     * @return list<CountryLocaleChoice> Every locale of the country, on or off, by code
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the entity query is invalid
     */
    public function choicesForCountry(int $countryId): array
    {
        $choices = [];
        foreach (static::entityClass()::get([EntityLocale::country_id => $countryId], [], [EntityLocale::code => 'ASC']) as $locale) {
            $choices[] = new CountryLocaleChoice($locale->code, $locale->enabled);
        }

        return $choices;
    }

    /**
     * @param int $localeId Locale primary id
     * @return ?bool Whether the locale is switched on, or null when it does not exist
     * @throws DatabaseException When the query fails
     * @throws InvalidArgumentException When the entity query is invalid
     */
    public function enabledById(int $localeId): ?bool
    {
        $entity = static::entityClass()::get([EntityLocale::id => $localeId])->first();

        return $entity?->enabled;
    }

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
