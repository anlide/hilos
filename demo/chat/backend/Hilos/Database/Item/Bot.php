<?php

namespace Demo\Chat\Hilos\Database\Item;

use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Hilos\Hilos\Database\Exception\Item\PropertyNotFoundException;
use Hilos\Hilos\Database\Item\DbItem;

/**
 * Bot Db item - high-level abstraction with lazy loading and relationships.
 *
 * @extends DbItem<ObjectBot>
 * @method __construct(ObjectBot &$objectBot)
 */
final class Bot extends DbItem
{
    /**
     * Property getter (read-only access).
     *
     * @param string $name Property name
     * @return int|string|bool|null Property value
     * @throws PropertyNotFoundException If property does not exist
     */
    public function __get(string $name): int|string|bool|null
    {
        return match ($name) {
            ObjectBot::id => $this->_object->id,
            ObjectBot::name => $this->_object->name,
            ObjectBot::description => $this->_object->description,
            ObjectBot::style => $this->_object->style,
            ObjectBot::topics => $this->_object->topics,
            ObjectBot::personality => $this->_object->personality,
            ObjectBot::active => $this->_object->active,
            default => parent::__get($name),
        };
    }
}
