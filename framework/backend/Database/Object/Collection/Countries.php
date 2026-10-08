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
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Hilos;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use Hilos\I18n\DefaultLanguage;
use Hilos\I18n\DTO\CountryCardSummary;

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
     * @param int $countryId Country primary id
     * @return CountryCardSummary Current read-only card facts
     * @throws InvalidArgumentException When the country no longer exists
     * @throws DatabaseException When a country or dependent row query fails
     * @throws LogicException When collection classes are not configured
     * @throws EnvException When the default language setting is absent
     * @throws EnvInvalidValueException When its configured code is unknown
     */
    public function cardSummary(int $countryId): CountryCardSummary
    {
        $country = $this[$countryId];
        if ($country === null) {
            throw new InvalidArgumentException("Country {$countryId} does not exist");
        }

        $defaultLanguageId = Hilos::$db->languages[DefaultLanguage::code()]?->id;
        $name = $defaultLanguageId === null ? null : Hilos::$db->countryNames->findBase($countryId, $defaultLanguageId)?->name;
        return new CountryCardSummary(
            name: $name === '' ? null : $name,
            isOwn: BuiltInI18nCatalog::country($country->code) === null,
            defaultLocaleCode: $country->defaultLocaleId === null ? null : Hilos::$db->locales[$country->defaultLocaleId]?->code,
            hasLocales: Hilos::$db->locales->hasForCountry($countryId),
            hasNames: Hilos::$db->countryNames->hasForCountry($countryId),
        );
    }

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
