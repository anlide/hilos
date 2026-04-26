<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Collection;

use Demo\Chat\Database\Object\Collection\Bots as ObjectBots;
use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Demo\Chat\Database\View\Collection\Bots as DbCollectionBots;
use Demo\Chat\Database\View\Item\Bot;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\ValueTooLongException;
use Hilos\Core\Exception\ValueTooShortException;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\HilosException;

/**
 * BotsActions - write operations for Bots collection.
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
     * @param string $name Bot display name
     * @param ?string $description Bot description
     * @param ?string $style Writing style
     * @param ?string $topics Preferred topics
     * @param ?string $personality Personality traits
     * @param bool $active Whether the bot is active
     * @return Bot Created bot Db item
     * @throws EmptyValueException When bot name is empty
     * @throws ValueTooShortException When bot name is too short
     * @throws ValueTooLongException When bot name exceeds maximum length
     * @throws HilosException On database error or truth source check failure
     */
    public function create(
        string $name,
        ?string $description = null,
        ?string $style = null,
        ?string $topics = null,
        ?string $personality = null,
        bool $active = true,
    ): Bot {
        $this->ensureCanCreate();

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

        $bot = ObjectBot::create();
        $bot->name = $name;
        $bot->description = $description;
        $bot->style = $style;
        $bot->topics = $topics;
        $bot->personality = $personality;
        $bot->active = $active;
        $bot->reactionDelayMin = 5;
        $bot->reactionDelayMax = 30;
        $bot->reactionChance = 80;
        $bot->topicMatchRequired = true;
        $bot->cooldownAfterMessage = 60;
        $bot->priority = 0;
        $bot->sync();

        $this->addObjectToCollection($bot);

        return $this->createDbItemFromObject($bot);
    }
}
