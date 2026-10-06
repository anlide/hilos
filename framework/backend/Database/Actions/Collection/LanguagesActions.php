<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Object\Collection\Languages as ObjectLanguages;
use Hilos\Database\View\Collection\Languages as DbCollectionLanguages;
use Hilos\Database\View\Item\Language;
use Hilos\HilosException;

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
}
