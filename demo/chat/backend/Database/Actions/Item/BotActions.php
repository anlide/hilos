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
     * Updates bot fields. Only provided keys are updated.
     *
     * @param array<string, mixed> $data Fields to update (keys: ObjectBot::name, ObjectBot::description, etc.)
     * @throws ItemNotFoundForUpdateException If bot id is null (not persisted)
     * @throws EmptyValueException If name is empty
     * @throws ValueTooShortException If name is too short
     * @throws ValueTooLongException If name exceeds max length
     * @throws HilosException On database error
     */
    public function update(array $data): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForUpdateException('Bot not found for update (id is null)');
        }

        if (array_key_exists(ObjectBot::name, $data)) {
            $name = is_string($data[ObjectBot::name]) ? trim($data[ObjectBot::name]) : '';
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

        if (array_key_exists(ObjectBot::description, $data)) {
            $this->object->description = $data[ObjectBot::description];
        }
        if (array_key_exists(ObjectBot::style, $data)) {
            $this->object->style = $data[ObjectBot::style];
        }
        if (array_key_exists(ObjectBot::topics, $data)) {
            $this->object->topics = $data[ObjectBot::topics];
        }
        if (array_key_exists(ObjectBot::personality, $data)) {
            $this->object->personality = $data[ObjectBot::personality];
        }
        if (array_key_exists(ObjectBot::active, $data)) {
            $this->object->active = $data[ObjectBot::active];
        }
        if (array_key_exists(ObjectBot::reactionDelayMin, $data)) {
            $this->object->reactionDelayMin = (int)$data[ObjectBot::reactionDelayMin];
        }
        if (array_key_exists(ObjectBot::reactionDelayMax, $data)) {
            $this->object->reactionDelayMax = (int)$data[ObjectBot::reactionDelayMax];
        }
        if (array_key_exists(ObjectBot::reactionChance, $data)) {
            $this->object->reactionChance = (int)$data[ObjectBot::reactionChance];
        }
        if (array_key_exists(ObjectBot::topicMatchRequired, $data)) {
            $this->object->topicMatchRequired = (bool)$data[ObjectBot::topicMatchRequired];
        }
        if (array_key_exists(ObjectBot::cooldownAfterMessage, $data)) {
            $this->object->cooldownAfterMessage = (int)$data[ObjectBot::cooldownAfterMessage];
        }
        if (array_key_exists(ObjectBot::priority, $data)) {
            $this->object->priority = (int)$data[ObjectBot::priority];
        }

        $this->object->sync();
    }

    /**
     * Deletes the bot.
     *
     * @throws ItemNotFoundForDeleteException If bot id is null (not persisted)
     * @throws ObjectCollectionNullException If object collection is null
     * @throws HilosException On database error
     */
    public function delete(): void
    {
        $this->ensureCanWrite();

        if ($this->object->id === null) {
            throw new ItemNotFoundForDeleteException('Bot not found for delete (id is null)');
        }

        $objectCollection = $this->getObjectCollection();
        if ($objectCollection === null) {
            throw new ObjectCollectionNullException('Object collection is null');
        }

        $idString = $this->object->getIdString();
        $this->object->delete();
        unset($objectCollection[$idString]);
    }
}
