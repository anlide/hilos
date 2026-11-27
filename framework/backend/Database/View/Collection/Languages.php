<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\LanguagesActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\Exception\View\CollectionNotFoundException;
use Hilos\Database\Object\Collection\Languages as ObjectLanguages;
use Hilos\Database\View\Item\Language;
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Hilos;
use Hilos\I18n\DefaultLanguage;
use Hilos\I18n\DTO\AvailableLanguageRow;
use Hilos\I18n\DTO\AvailableLanguagesSignalData;
use Hilos\I18n\DTO\AvailableLocaleRow;
use Hilos\I18n\DTO\LanguageCardSummary;
use Hilos\I18n\DTO\LanguageOverviewTally;

/**
 * Languages support code strings and primary-id integers as offsets.
 *
 * @extends DbCollection<Language, ObjectLanguages>
 * @property-read LanguagesActions $actions
 * @method ObjectLanguages|null getObjectCollection()
 * @method Language|null first()
 * @method Language|null last()
 */
class Languages extends DbCollection
{
    public const string DB_ITEM_CLASS = Language::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectLanguages::class;

    /**
     * @return LanguageOverviewTally Counts over the full reference and the current default row's name
     * @throws DatabaseException When reading the reference fails
     * @throws ItemNotFoundException When the required default language row is missing
     * @throws InvalidArgumentException When a loaded object has the wrong type
     * @throws LogicException When collection classes are not configured
     * @throws EnvException When the default language environment key is absent
     * @throws EnvInvalidValueException When the configured default code is unknown
     */
    public function overviewTally(): LanguageOverviewTally
    {
        $enabledCount = 0;
        $totalCount = 0;
        foreach ($this->getIterator() as $language) {
            ++$totalCount;
            if ($language->enabled) {
                ++$enabledCount;
            }
        }
        $defaultLanguageCode = DefaultLanguage::code();

        return new LanguageOverviewTally(
            $enabledCount,
            $totalCount,
            $defaultLanguageCode,
            $this[$defaultLanguageCode]->nativeName,
        );
    }

    /**
     * Projects the complete catalog held by the i18n library.
     *
     * @return AvailableLanguagesSignalData Available languages with their available locales
     * @throws DatabaseException When the reference cannot be read
     * @throws InvalidArgumentException When a loaded object has the wrong type
     * @throws LogicException When collection classes are not configured
     * @throws CollectionNotFoundException When a reference collection is missing
     * @throws DbCollectionNotReadableException When a reference collection is unreadable
     * @throws ItemNotFoundException When a locale's required country is missing
     */
    public function available(): AvailableLanguagesSignalData
    {
        $enabledLanguages = [];
        foreach ($this->getIterator() as $language) {
            if ($language->enabled) {
                $enabledLanguages[$language->id] = $language;
            }
        }

        $countryNames = null;
        $localesByLanguage = [];
        foreach (Hilos::$db->locales as $locale) {
            if (!$locale->enabled || !isset($enabledLanguages[$locale->languageId])) {
                continue;
            }

            $countryCode = null;
            $countryName = null;
            if ($locale->countryId !== null) {
                $country = Hilos::$db->countries[$locale->countryId];
                if (!$country->enabled) {
                    continue;
                }
                $countryCode = $country->code;
                $countryNames ??= Hilos::$db->countries->namesInInterfaceLanguage();
                $countryName = $countryNames[$country->id] ?? $countryCode;
            }
            $localesByLanguage[$locale->languageId][] = new AvailableLocaleRow($locale->id, $locale->code, $countryCode, $countryName);
        }

        $languages = [];
        foreach ($enabledLanguages as $language) {
            $locales = $localesByLanguage[$language->id] ?? [];
            usort($locales, static function (AvailableLocaleRow $first, AvailableLocaleRow $second): int {
                if ($first->countryName === null || $second->countryName === null) {
                    return ($first->countryName !== null) <=> ($second->countryName !== null) ?: strcmp($first->code, $second->code);
                }

                return strcasecmp($first->countryName, $second->countryName) ?: strcmp($first->code, $second->code);
            });
            $languages[] = new AvailableLanguageRow($language->id, $language->code, $language->nativeName, $locales);
        }
        usort($languages, static fn (AvailableLanguageRow $first, AvailableLanguageRow $second): int => strcmp($first->code, $second->code));

        return new AvailableLanguagesSignalData($languages);
    }

    /**
     * @param int $languageId Language primary id
     * @return LanguageCardSummary Current read-only card facts
     * @throws DatabaseException When a language or dependent row query fails
     * @throws InvalidArgumentException When the language no longer exists
     * @throws LogicException When collection classes are not configured
     * @throws EnvException When the default language setting is absent
     * @throws EnvInvalidValueException When its configured code is unknown
     */
    public function cardSummary(int $languageId): LanguageCardSummary
    {
        return $this->objectCollection->cardSummary($languageId);
    }

    /**
     * @param mixed $offset Language code or primary id
     * @return bool Whether the language exists
     * @throws DatabaseException When the lookup fails
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When a loaded object has the wrong type
     */
    public function offsetExists(mixed $offset): bool
    {
        if (is_string($offset)) {
            $object = $this->objectCollection->findByCode($offset);
            return $this->getItemForKey($object?->id) !== null;
        }
        if (!is_int($offset)) {
            return false;
        }

        return $this->getItemForKey($offset) !== null;
    }

    /**
     * @param mixed $offset Language code or primary id of a row the caller requires
     * @return Language Language at the code or id
     * @throws ItemNotFoundException When no language holds the code or id, `null` or another unsupported offset included
     * @throws DatabaseException When the lookup fails
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When a loaded object has the wrong type
     */
    public function offsetGet(mixed $offset): Language
    {
        if (is_string($offset)) {
            $object = $this->objectCollection->findByCode($offset);
            return $this->getItemForKey($object?->id) ?? throw new ItemNotFoundException(static::class, $offset);
        }
        if (!is_int($offset)) {
            throw new ItemNotFoundException(static::class, $offset);
        }

        /** @var Language $language */
        $language = parent::offsetGet($offset);
        return $language;
    }
}
