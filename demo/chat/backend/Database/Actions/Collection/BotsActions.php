<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Collection;

use Demo\Chat\Database\Object\Collection\Bots as ObjectBots;
use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Demo\Chat\Database\View\Collection\Bots as DbCollectionBots;
use Demo\Chat\Database\View\Item\Bot;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\HilosException;
use RuntimeException;

/**
 * Bots Actions - write operations for Bots collection.
 *
 * @extends DbActions<Bot, ObjectBots>
 * @property-read DbCollectionBots $collection
 * @property-read ObjectBots $objectCollection
 */
final class BotsActions extends DbActions
{
    private const int NAME_MIN_LENGTH = 1;
    private const int NAME_MAX_LENGTH = 255;

    /**
     * Creates a new bot and adds it to the collection.
     *
     * @param array<string, mixed> $data Bot fields (ObjectBot::name, ObjectBot::description, etc.)
     *
     * @return Bot Created bot Db item
     *
     * @throws HilosException On error (invalid data, database error, etc.)
     */
    public function create(array $data): Bot
    {
        $this->ensureCanCreate();

        $name = isset($data[ObjectBot::name]) && is_string($data[ObjectBot::name]) ? trim($data[ObjectBot::name]) : '';
        if ($name === '') {
            throw new RuntimeException('Bot name cannot be empty');
        }
        if (mb_strlen($name) < self::NAME_MIN_LENGTH) {
            throw new RuntimeException('Bot name is too short');
        }
        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new RuntimeException('Bot name exceeds maximum length of ' . self::NAME_MAX_LENGTH . ' characters');
        }

        $bot = ObjectBot::create();
        $bot->name = $data[ObjectBot::name];
        $bot->description = $data[ObjectBot::description] ?? null;
        $bot->style = $data[ObjectBot::style] ?? null;
        $bot->topics = $data[ObjectBot::topics] ?? null;
        $bot->personality = $data[ObjectBot::personality] ?? null;
        $bot->active = $data[ObjectBot::active] ?? true;
        $bot->reactionDelayMin = $data[ObjectBot::reactionDelayMin] ?? 5;
        $bot->reactionDelayMax = $data[ObjectBot::reactionDelayMax] ?? 30;
        $bot->reactionChance = $data[ObjectBot::reactionChance] ?? 80;
        $bot->topicMatchRequired = $data[ObjectBot::topicMatchRequired] ?? true;
        $bot->cooldownAfterMessage = $data[ObjectBot::cooldownAfterMessage] ?? 60;
        $bot->priority = $data[ObjectBot::priority] ?? 0;
        $bot->sync();

        $this->addObjectToCollection($bot);

        return $this->createDbItemFromObject($bot);
    }
}
