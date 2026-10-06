<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\Exception\ItemNotFoundForDeleteException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\CountryName as EntityCountryName;
use Hilos\Database\Object\Item\CountryName as ObjectCountryName;
use Hilos\Database\View\Item\CountryName;
use Hilos\Hilos;
use Hilos\HilosException;
use Throwable;

/**
 * @extends DbActions<CountryName, ObjectCountryName>
 * @property-read ObjectCountryName $object
 */
class CountryNameActions extends DbActions
{
    /**
     * @param string $name Literal name, including an empty string
     * @throws ItemNotFoundForUpdateException When the row has no persisted id
     * @throws ValidationException When the name is too wide
     * @throws HilosException When ownership or persistence refuses the write
     */
    public function edit(string $name): void
    {
        $this->ensureCanWrite();
        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Name has no persisted id');
        }
        if (mb_strlen($name) > EntityCountryName::NAME_MAX_CHARS) {
            throw new ValidationException('Name exceeds its column width');
        }
        $this->object->name = $name;
        $this->object->locked = true;
        $this->object->sync();
    }

    /**
     * @param string $name New catalog name
     * @return bool Whether an unlocked base was refreshed
     * @throws ItemNotFoundForUpdateException When the row has no persisted id
     * @throws ValidationException When the row is an override or name is too wide
     * @throws HilosException When ownership or persistence refuses the write
     */
    public function refreshFromCatalog(string $name): bool
    {
        $this->ensureCanWrite();
        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Name has no persisted id');
        }
        if ($this->object->localeId !== null || mb_strlen($name) > EntityCountryName::NAME_MAX_CHARS) {
            throw new ValidationException('Catalog can refresh only a base name within its column width');
        }
        if ($this->object->locked) {
            return false;
        }
        $this->object->name = $name;
        $this->object->sync();
        return true;
    }

    /**
     * A base and its overrides are removed in one transaction.
     *
     * @throws ItemNotFoundForDeleteException When the row has no persisted id
     * @throws ObjectCollectionNullException When the item is detached
     * @throws HilosException When ownership, lookup or deletion fails
     */
    public function delete(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        if ($this->object->id === null) {
            throw new ItemNotFoundForDeleteException('Name has no persisted id');
        }
        if ($this->object->localeId !== null) {
            $this->deleteOne();
            return;
        }

        Database::transactionStart();
        try {
            foreach (Hilos::$db->countryNames->overridesFor($this->object->countryId, $this->object->languageId) as $override) {
                $override->actions->delete();
            }
            $this->deleteOne();
            Database::transactionCommit();
        } catch (Throwable $failure) {
            try {
                Database::transactionRollback();
            } catch (HilosException) {
                // Keep the failure that prevented the name removal.
            }
            throw $failure;
        }
    }

    /** @throws HilosException When ownership or deletion fails */
    private function deleteOne(): void
    {
        $objectCollection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Object collection is null');
        $idString = $this->object->getIdString();
        $this->object->delete();
        unset($objectCollection[$idString]);
        $this->item->getCollection()?->forgetCachedItem($idString);
    }
}
