<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\Actions\Collection\LanguagesActions;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ItemNotFoundException;
use Hilos\Database\Object\Collection\Languages as ObjectLanguages;
use Hilos\Database\View\Item\Language;
use Hilos\Environment\Exception\EnvException;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\I18n\DTO\LanguageCardSummary;

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
