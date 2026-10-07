<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\Languages as EntityLanguages;
use Hilos\Database\Entity\Item\Language as EntityLanguage;
use Hilos\Database\Object\Item\Language as ObjectLanguage;
use Hilos\Database\Object\Objects;
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Hilos;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;
use Hilos\I18n\DefaultLanguage;
use Hilos\I18n\DTO\LanguageCardSummary;

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
     * @param int $languageId Language primary id
     * @return LanguageCardSummary Current read-only card facts
     * @throws InvalidArgumentException When the language no longer exists
     * @throws DatabaseException When a language or dependent row query fails
     * @throws LogicException When collection classes are not configured
     * @throws EnvException When the default language setting is absent
     * @throws EnvInvalidValueException When its configured code is unknown
     */
    public function cardSummary(int $languageId): LanguageCardSummary
    {
        $language = $this[$languageId];
        if ($language === null) {
            throw new InvalidArgumentException("Language {$languageId} does not exist");
        }

        $localeCount = Hilos::$db->locales->countForLanguage($languageId);
        return new LanguageCardSummary(
            isDefault: $language->code === DefaultLanguage::code(),
            isOwn: BuiltInI18nCatalog::language($language->code) === null,
            localeCount: $localeCount,
            nameCount: Hilos::$db->languageNames->countBaseNamesForLanguage($languageId),
            hasManualLanguageName: Hilos::$db->languageNames->hasManualNameForLanguage($languageId),
            hasLockedCountryName: Hilos::$db->countryNames->hasLockedNameInLanguage($languageId),
        );
    }

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
