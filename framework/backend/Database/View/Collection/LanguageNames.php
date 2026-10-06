<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\LanguageNamesActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\LanguageNames as ObjectLanguageNames;
use Hilos\Database\Object\Item\LanguageName as ObjectLanguageName;
use Hilos\Database\View\Item\LanguageName;

/**
 * Translated language names use primary ids as offsets.
 *
 * @extends DbCollection<LanguageName, ObjectLanguageNames>
 * @property-read LanguageNamesActions $actions
 */
class LanguageNames extends DbCollection
{
    public const string DB_ITEM_CLASS = LanguageName::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectLanguageNames::class;

    /**
     * @param int $languageId Named language id
     * @param int $inLanguageId Writing language id
     * @return ?LanguageName Base row, or null
     * @throws DatabaseException When the query fails
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When a loaded object has the wrong type
     */
    public function findBase(int $languageId, int $inLanguageId): ?LanguageName
    {
        return $this->wrap($this->objectCollection->findBase($languageId, $inLanguageId));
    }

    /**
     * @param int $languageId Named language id
     * @param int $inLanguageId Writing language id
     * @param int $localeId Override locale id
     * @return ?LanguageName Override row, or null
     * @throws DatabaseException When the query fails
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When a loaded object has the wrong type
     */
    public function findOverride(int $languageId, int $inLanguageId, int $localeId): ?LanguageName
    {
        return $this->wrap($this->objectCollection->findOverride($languageId, $inLanguageId, $localeId));
    }

    /**
     * @param int $languageId Named language id
     * @param int $inLanguageId Writing language id
     * @return list<LanguageName> Overrides of the base
     * @throws DatabaseException When the query fails
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When a loaded object has the wrong type
     */
    public function overridesFor(int $languageId, int $inLanguageId): array
    {
        $names = [];
        foreach ($this->objectCollection->overridesFor($languageId, $inLanguageId) as $object) {
            $name = $this->wrap($object);
            if ($name !== null) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * @param ?ObjectLanguageName $object Scalar row, if present
     * @return ?LanguageName View row, if present
     * @throws LogicException When collection classes are not configured
     * @throws InvalidArgumentException When a loaded object has the wrong type
     */
    private function wrap(?ObjectLanguageName $object): ?LanguageName
    {
        if ($object?->id === null) {
            return null;
        }
        /** @var ?LanguageName $item */
        $item = $this->getItemForKey($object->id);
        return $item;
    }
}
