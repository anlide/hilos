<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Item;

use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Demo\Chat\Database\View\Item\Bot;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ItemNotFoundForDeleteException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\Exception\ValueTooShortException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Actions\Item\DbActions;
use Hilos\HilosException;

/**
 * BotActions - write operations for a single Bot item.
 *
 * @extends DbActions<Bot, ObjectBot>
 * @property-read ObjectBot $object
 */
final class BotActions extends DbActions
{
    private const int NAME_MIN_LENGTH = 1;
    private const int NAME_MAX_LENGTH = 255;

    /**
     * Updates bot fields. Null parameters are left unchanged.
     *
     * @param ?string $name Display name
     * @param ?string $description Description
     * @param ?string $style Writing style
     * @param ?string $topics Preferred topics
     * @param ?string $personality Personality traits
     * @param ?bool $active Active flag
     * @throws ItemNotFoundForUpdateException If bot id is null (not persisted)
     * @throws EmptyValueException If name is empty
     * @throws ValueTooShortException If name is too short
     * @throws ValueTooLongException If name exceeds max length
     * @throws HilosException On database error
     */
    public function update(
        ?string $name = null,
        ?string $description = null,
        ?string $style = null,
        ?string $topics = null,
        ?string $personality = null,
        ?bool $active = null,
    ): void {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Bot not found for update (id is null)');
        }

        if ($name !== null) {
            $name = trim($name);
            if ($name === '') {
                throw new EmptyValueException('Bot name cannot be empty');
            }
            if (mb_strlen($name) < self::NAME_MIN_LENGTH) {
                throw new ValueTooShortException('Bot name is too short');
            }
            if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
                throw new ValueTooLongException('Bot name exceeds maximum length of ' . self::NAME_MAX_LENGTH . ' characters');
            }
            $this->object->name = $name;
        }

        if ($description !== null) {
            $this->object->description = $description;
        }
        if ($style !== null) {
            $this->object->style = $style;
        }
        if ($topics !== null) {
            $this->object->topics = $topics;
        }
        if ($personality !== null) {
            $this->object->personality = $personality;
        }
        if ($active !== null) {
            $this->object->active = $active;
        }

        $this->object->sync();
    }

    /**
     * Deletes the bot.
     *
     * @throws ItemNotFoundForDeleteException If bot id is null (not persisted)
     * @throws ObjectCollectionNullException If object collection is null
     * @throws HilosException On database error
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function delete(): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForDeleteException('Bot not found for delete (id is null)');
        }

        $objectCollection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Object collection is null');

        $idString = $this->object->getIdString();
        $this->object->delete();
        unset($objectCollection[$idString]);
    }
}
