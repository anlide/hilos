<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Entity\Item\LanguageName as EntityLanguageName;
use Hilos\Database\Object\Collection\LanguageNames as ObjectLanguageNames;
use Hilos\Database\View\Collection\LanguageNames as DbCollectionLanguageNames;
use Hilos\Database\View\Item\Language;
use Hilos\Database\View\Item\Locale;
use Hilos\Database\View\Item\LanguageName;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * @extends DbActions<LanguageName, ObjectLanguageNames>
 * @property-read DbCollectionLanguageNames $collection
 * @property-read ObjectLanguageNames $objectCollection
 */
class LanguageNamesActions extends DbActions
{
    /**
     * @param Language $language Named language
     * @param Language $inLanguage Writing language
     * @param ?Locale $locale Override locale, or null for base
     * @param string $name Literal name, including an empty string
     * @return LanguageName Locked manual row
     * @throws ValidationException When references or name are invalid
     * @throws HilosException When ownership or persistence refuses the write
     */
    public function createManual(Language $language, Language $inLanguage, ?Locale $locale, string $name): LanguageName
    {
        return $this->createName($language, $inLanguage, $locale, $name, true);
    }

    /**
     * @param Language $language Named language
     * @param Language $inLanguage Writing language
     * @param ?Locale $locale Override locale, or null for base
     * @param string $name Literal name
     * @param bool $locked Manual rows are locked
     * @return LanguageName New name row
     * @throws ValidationException When references or name are invalid
     * @throws HilosException When ownership or persistence refuses the write
     */
    private function createName(Language $language, Language $inLanguage, ?Locale $locale, string $name, bool $locked): LanguageName
    {
        $languageId = $language->id;
        $inLanguageId = $inLanguage->id;
        if ($languageId === null || Hilos::$db->languages[$languageId] === null
            || $inLanguageId === null || Hilos::$db->languages[$inLanguageId] === null) {
            throw new ValidationException('Name references must exist');
        }
        $this->ensureCanCreateInSet((string)$languageId);
        if (mb_strlen($name) > EntityLanguageName::NAME_MAX_CHARS) {
            throw new ValidationException('Name exceeds its column width');
        }
        if ($locale !== null) {
            if ($locale->id === null || Hilos::$db->locales[$locale->id] === null
                || $locale->languageId !== $inLanguageId
                || $this->collection->findBase($languageId, $inLanguageId) === null) {
                throw new ValidationException('Override needs its base and a locale of the writing language');
            }
        }

        $objectClass = $this->objectCollection::OBJECT_CLASS;
        $row = $objectClass::create();
        $row->languageId = $languageId;
        $row->inLanguageId = $inLanguageId;
        $row->localeId = $locale?->id;
        $row->name = $name;
        $row->locked = $locked;
        $row->sync();
        $this->addObjectToCollection($row);
        return $this->createDbItemFromObject($row);
    }
}
