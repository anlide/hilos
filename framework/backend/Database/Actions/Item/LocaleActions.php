<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\Exception\ItemNotFoundForDeleteException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Object\Item\Locale as ObjectLocale;
use Hilos\Database\View\Item\Locale;
use Hilos\HilosException;
use Hilos\I18n\DefaultLanguage;
use Hilos\I18n\Exception\DefaultLocaleProtectedException;
use Hilos\I18n\DTO\LocaleFormats;
use Hilos\I18n\Exception\I18nRowFrozenException;
use Hilos\I18n\LocaleTemplates;

/**
 * @extends DbActions<Locale, ObjectLocale>
 * @property-read ObjectLocale $object
 */
class LocaleActions extends DbActions
{
    /**
     * @param LocaleFormats $formats New display formats and first weekday
     * @throws ItemNotFoundForUpdateException When the row has no persisted id
     * @throws I18nRowFrozenException When the locale is switched on
     * @throws ValidationException When a format is not a known template
     * @throws HilosException When ownership or persistence refuses the write
     */
    public function update(LocaleFormats $formats): void
    {
        $this->ensureCanWrite();
        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Locale not found for update (id is null)');
        }
        if ($this->object->enabled) {
            throw new I18nRowFrozenException(
                "Locale '{$this->object->code}' is switched on: switch it off, edit it, switch it on",
            );
        }
        LocaleTemplates::refuseUnknown($formats);

        $this->object->dateFormat = $formats->date;
        $this->object->timeFormat = $formats->time;
        $this->object->numberFormat = $formats->number;
        $this->object->phoneFormat = $formats->phone;
        $this->object->addressFormat = $formats->address;
        $this->object->measurementSystem = $formats->measurement->value;
        $this->object->collation = $formats->collation;
        $this->object->firstWeekday = $formats->firstWeekday->value;
        $this->object->sync();
    }

    /** @throws HilosException When ownership or persistence refuses the write */
    public function switchOn(): void
    {
        $this->ensureCanWrite();
        if ($this->object->enabled) {
            return;
        }
        $this->object->enabled = true;
        $this->object->sync();
    }

    /**
     * @throws DefaultLocaleProtectedException When this is the default language's countryless locale
     * @throws HilosException When ownership, environment or persistence refuses the write
     */
    public function switchOff(): void
    {
        $this->ensureCanWrite();
        if ($this->object->countryId === null && $this->object->code === DefaultLanguage::code()) {
            throw new DefaultLocaleProtectedException(
                "Default language locale '{$this->object->code}' cannot be switched off",
            );
        }
        if (!$this->object->enabled) {
            return;
        }
        $this->object->enabled = false;
        $this->object->sync();
    }

    /**
     * @throws ItemNotFoundForDeleteException When the row has no persisted id
     * @throws ObjectCollectionNullException When the item is detached from its collection
     * @throws I18nRowFrozenException When the locale is switched on
     * @throws ValidationException When the locale is a country's default or has name overrides
     * @throws HilosException When ownership, the database or collection refuses removal
     */
    public function delete(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        if ($this->object->id === null) {
            throw new ItemNotFoundForDeleteException('Locale not found for delete (id is null)');
        }

        switch ($this->item->deleteReason()) {
            case Locale::DELETE_ENABLED:
                throw new I18nRowFrozenException(
                    "Locale '{$this->object->code}' is switched on: switch it off before deleting it.",
                );
            case Locale::DELETE_COUNTRY_DEFAULT:
                throw new ValidationException(
                    "Locale '{$this->object->code}' is the country's default: choose another locale for the country first.",
                );
            case Locale::DELETE_NAMES:
                throw new ValidationException(
                    "Locale '{$this->object->code}' has name overrides: remove them before deleting it.",
                );
        }

        $objectCollection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Object collection is null');
        $idString = $this->object->getIdString();
        $this->object->delete();
        unset($objectCollection[$idString]);
        $this->item->getCollection()?->forgetCachedItem($idString);
    }
}
