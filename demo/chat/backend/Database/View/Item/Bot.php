<?php

namespace Demo\Chat\Database\View\Item;

use Demo\Chat\Database\Actions\Item\BotActions;
use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;

/**
 * Bot Db item - high-level abstraction with lazy loading and relationships.
 *
 * @extends DbItem<ObjectBot>
 * @method __construct(ObjectBot &$objectBot)
 * @property-read BotActions $actions Item-level write operations
 */
final class Bot extends DbItem
{
    /**
     * Property getter (read-only access).
     *
     * @param string $name Property name
     * @return int|string|bool|BotActions|null Property value or actions
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If actions class is not defined for the collection
     */
    public function __get(string $name): int|string|bool|BotActions|null
    {
        return match ($name) {
            ObjectBot::id => $this->_object->id,
            ObjectBot::name => $this->_object->name,
            ObjectBot::description => $this->_object->description,
            ObjectBot::style => $this->_object->style,
            ObjectBot::topics => $this->_object->topics,
            ObjectBot::personality => $this->_object->personality,
            ObjectBot::active => $this->_object->active,
            ObjectBot::reactionDelayMin => $this->_object->reactionDelayMin,
            ObjectBot::reactionDelayMax => $this->_object->reactionDelayMax,
            ObjectBot::reactionChance => $this->_object->reactionChance,
            ObjectBot::topicMatchRequired => $this->_object->topicMatchRequired,
            ObjectBot::cooldownAfterMessage => $this->_object->cooldownAfterMessage,
            ObjectBot::priority => $this->_object->priority,
            default => parent::__get($name),
        };
    }
}
