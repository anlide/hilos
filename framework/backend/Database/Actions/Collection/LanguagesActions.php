<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Object\Collection\Languages as ObjectLanguages;
use Hilos\Database\View\Collection\Languages as DbCollectionLanguages;
use Hilos\Database\View\Item\Language;
use Hilos\HilosException;
use Hilos\I18n\Catalog\BuiltInI18nCatalog;

/**
 * @extends DbActions<Language, ObjectLanguages>
 * @property-read DbCollectionLanguages $collection
 * @property-read ObjectLanguages $objectCollection
 */
class LanguagesActions extends DbActions
{
    /**
     * @param string $code Two lowercase ISO 639-1 letters
     * @param string $nativeName Name in the language itself
     * @param bool $rtl Whether writing runs right to left
     * @return Language New switched-off language
     * @throws ValidationException When the code or name is malformed
     * @throws HilosException When ownership, the database or collection refuses the write
     */
    public function create(string $code, string $nativeName, bool $rtl): Language
    {
        $this->ensureCanCreate();

        if (preg_match('/^[a-z]{2}$/', $code) !== 1) {
            throw new ValidationException('Language code must have two lowercase letters');
        }
        if (trim($nativeName) === '' || mb_strlen($nativeName) > 64) {
            throw new ValidationException('Language native name must have 1..64 characters');
        }

        $objectClass = $this->objectCollection::OBJECT_CLASS;
        $language = $objectClass::create();
        $language->code = $code;
        $language->nativeName = $nativeName;
        $language->rtl = $rtl;
        $language->enabled = false;
        $language->sync();
        $this->addObjectToCollection($language);

        return $this->createDbItemFromObject($language);
    }

    /**
     * Refreshes the switched-off built-in languages from the catalog: the native name and the
     * writing direction. A missing language is not created - a person adds a language - and a
     * switched-on one is left as it is (HIL-1472).
     *
     * The walk is over the catalog, so a language the catalog does not know is never reached. A
     * value already equal to the catalog's is not written. Opens no transaction: the reflow
     * calls it inside its own.
     *
     * @throws ValidationException When a catalog value does not fit the write door
     * @throws HilosException When ownership or persistence refuses a write
     */
    public function refreshFromCatalog(): void
    {
        foreach (BuiltInI18nCatalog::languages() as $definition) {
            $language = $this->collection[$definition->code];
            if ($language === null || $language->enabled
                || ($language->nativeName === $definition->nativeName && $language->rtl === $definition->rtl)) {
                continue;
            }
            $language->actions->update($definition->nativeName, $definition->rtl);
        }
    }
}
