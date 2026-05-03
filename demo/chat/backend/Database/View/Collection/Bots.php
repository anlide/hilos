<?php

declare(strict_types=1);

namespace Demo\Chat\Database\View\Collection;

use Demo\Chat\Database\Actions\Collection\BotsActions;
use Demo\Chat\Database\Object\Collection\Bots as ObjectBots;
use Demo\Chat\Database\View\Item\Bot;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Collection\PropertyNotFoundException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\View\Collection\DbCollection;

/**
 * Bots - Db collection of Bot items.
 *
 * @extends DbCollection<Bot, ObjectBots>
 * @method ObjectBots|null getObjectCollection()
 * @method Bot|null current()
 * @method Bot|null first()
 * @method Bot|null last()
 * @method Bot|null offsetGet(mixed $offset)
 * @property-read BotsActions $actions Collection actions (create); items use BotActions
 * @property-read self $activeOnly Bots with active=true
 */
final class Bots extends DbCollection
{
    public const string activeOnly = 'activeOnly';

    public const string DB_ITEM_CLASS = Bot::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectBots::class;

    /**
     * Returns a view collection containing only bots with active=true.
     *
     * @return self Active bots
     * @throws DatabaseException If a filtered bot has no associated database object id
     * @throws CollectionNotManualException If filtered collection cannot accept an item
     * @throws ObjectGetIdStringNotImplementedException If Bot object does not implement getIdString
     */
    private function getActiveOnly(): self
    {
        return $this->filter(static fn (Bot $bot): bool => $bot->active);
    }

    /**
     * Property getter (actions, objectCollection or activeOnly).
     *
     * @param string $name Property name
     * @return BotsActions|ObjectBots|self Property value
     * @throws ActionsClassException If actions class is not set or invalid
     * @throws ObjectCollectionNullException If object collection is not available
     * @throws PropertyNotFoundException If property name is not recognized
     * @throws DatabaseException If a filtered bot has no associated database object id
     * @throws CollectionNotManualException If filtered collection cannot accept an item
     * @throws ObjectGetIdStringNotImplementedException If Bot object does not implement getIdString
     */
    public function __get(string $name): BotsActions|ObjectBots|self
    {
        return match ($name) {
            self::activeOnly => $this->getActiveOnly(),
            default => parent::__get($name),
        };
    }
}
