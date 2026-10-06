<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\Exception\ItemNotFoundForDeleteException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Object\Item\Language as ObjectLanguage;
use Hilos\Database\View\Item\Language;
use Hilos\HilosException;
use Hilos\I18n\Exception\I18nRowFrozenException;

/**
 * @extends DbActions<Language, ObjectLanguage>
 * @property-read ObjectLanguage $object
 */
class LanguageActions extends DbActions
{
    /**
     * @param string $nativeName Name in the language itself
     * @param bool $rtl Whether writing runs right to left
     * @throws ItemNotFoundForUpdateException When the row has no persisted id
     * @throws I18nRowFrozenException When the language is switched on
     * @throws ValidationException When the name is malformed
     * @throws HilosException When ownership or persistence refuses the write
     */
    public function update(string $nativeName, bool $rtl): void
    {
        $this->ensureCanWrite();
        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Language not found for update (id is null)');
        }
        if ($this->object->enabled) {
            throw new I18nRowFrozenException(
                "Language '{$this->object->code}' is switched on: switch it off, edit it, switch it on",
            );
        }
        if (trim($nativeName) === '' || mb_strlen($nativeName) > 64) {
            throw new ValidationException('Language native name must have 1..64 characters');
        }

        $this->object->nativeName = $nativeName;
        $this->object->rtl = $rtl;
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
            throw new ItemNotFoundForDeleteException('Language not found for delete (id is null)');
        }

        $objectCollection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Object collection is null');
        $idString = $this->object->getIdString();
        $this->object->delete();
        unset($objectCollection[$idString]);
        $this->item->getCollection()?->forgetCachedItem($idString);
    }
}
