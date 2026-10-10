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
use Hilos\I18n\Exception\I18nRowFrozenException;
use Hilos\I18n\MeasurementSystem;
use Hilos\I18n\LocaleTemplates;

/**
 * @extends DbActions<Locale, ObjectLocale>
 * @property-read ObjectLocale $object
 */
class LocaleActions extends DbActions
{
    /**
     * @param string $dateFormat Date template
     * @param string $timeFormat Time template
     * @param string $numberFormat Number template
     * @param string $phoneFormat Phone template
     * @param string $addressFormat Address template
     * @param MeasurementSystem $measurementSystem Unit system
     * @param string $collation Sorting template
     * @throws ItemNotFoundForUpdateException When the row has no persisted id
     * @throws I18nRowFrozenException When the locale is switched on
     * @throws ValidationException When a format is not a known template
     * @throws HilosException When ownership or persistence refuses the write
     */
    public function update(
        string $dateFormat,
        string $timeFormat,
        string $numberFormat,
        string $phoneFormat,
        string $addressFormat,
        MeasurementSystem $measurementSystem,
        string $collation,
    ): void {
        $this->ensureCanWrite();
        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Locale not found for update (id is null)');
        }
        if ($this->object->enabled) {
            throw new I18nRowFrozenException(
                "Locale '{$this->object->code}' is switched on: switch it off, edit it, switch it on",
            );
        }
        LocaleTemplates::refuseUnknown($dateFormat, $timeFormat, $numberFormat, $phoneFormat, $addressFormat, $collation);

        $this->object->dateFormat = $dateFormat;
        $this->object->timeFormat = $timeFormat;
        $this->object->numberFormat = $numberFormat;
        $this->object->phoneFormat = $phoneFormat;
        $this->object->addressFormat = $addressFormat;
        $this->object->measurementSystem = $measurementSystem->value;
        $this->object->collation = $collation;
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

    /** @throws HilosException When ownership or persistence refuses the write */
    public function switchOff(): void
    {
        $this->ensureCanWrite();
        if (!$this->object->enabled) {
            return;
        }
        $this->object->enabled = false;
        $this->object->sync();
    }

    /**
     * @throws ItemNotFoundForDeleteException When the row has no persisted id
     * @throws ObjectCollectionNullException When the item is detached from its collection
     * @throws HilosException When ownership, the database or collection refuses removal
     */
    public function delete(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        if ($this->object->id === null) {
            throw new ItemNotFoundForDeleteException('Locale not found for delete (id is null)');
        }

        $objectCollection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Object collection is null');
        $idString = $this->object->getIdString();
        $this->object->delete();
        unset($objectCollection[$idString]);
        $this->item->getCollection()?->forgetCachedItem($idString);
    }
}
