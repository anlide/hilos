<?php

namespace Demo\Chat\Hilos\Database\Item;

use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Hilos\Hilos\Database\Item\DbItem;

/**
 * Bot Db item - high-level abstraction with lazy loading and relationships.
 *
 * @extends DbItem<ObjectBot>
 */
final class Bot extends DbItem
{
    public function __construct(ObjectBot &$objectBot)
    {
        parent::__construct($objectBot);
    }

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

    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false, bool $toFrontend = false): array
    {
        $data = [];
        if ($withId) {
            $data[ObjectBot::id] = $this->_object->id;
        }
        $data[ObjectBot::name] = $this->_object->name;
        $data[ObjectBot::description] = $this->_object->description;
        $data[ObjectBot::style] = $this->_object->style;
        $data[ObjectBot::topics] = $this->_object->topics;
        $data[ObjectBot::personality] = $this->_object->personality;
        $data[ObjectBot::active] = $this->_object->active;
        return $data;
    }
}
