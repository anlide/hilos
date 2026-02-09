<?php

namespace Demo\Chat\Database\Idea;

use Demo\Chat\Database\Object\Bot as ObjectBot;
use Hilos\Database\Idea\IdeaItem;
use RuntimeException;

/**
 * Bot Idea
 * High-level abstraction with lazy loading and relationships
 *
 * Stores reference to ObjectBot instance.
 * Object instances are stored in ObjectCollection in Idea.
 *
 * @extends IdeaItem<ObjectBot>
 *
 * @property-read ?int $idBot Bot ID (primary key)
 * @property-read string $name Bot name
 * @property-read ?string $description Bot description
 * @property-read ?string $style Chat style (e.g., friendly, professional, casual)
 * @property-read ?string $topics JSON array of topics the bot can chat about
 * @property-read ?string $personality JSON object describing bot personality traits
 * @property-read bool $active Whether bot is active
 * @property-read string $createdAt Creation timestamp
 * @property-read string $updatedAt Last update timestamp
 */
final class Bot extends IdeaItem
{
    /**
     * Public constructor - creates IdeaBot from ObjectBot instance
     *
     * @param ObjectBot $objectBot ObjectBot instance (reference)
     */
    public function __construct(ObjectBot &$objectBot)
    {
        parent::__construct($objectBot);
    }

    /**
     * Property getter (read-only access)
     * Provides access to ObjectBot properties through IdeaBot interface.
     *
     * @param string $name Property name
     * @return int|string|bool|null Property value
     * @throws RuntimeException If property does not exist
     */
    public function __get(string $name): int|string|bool|null
    {
        return match ($name) {
            ObjectBot::idBot => $this->_object->idBot,
            ObjectBot::name => $this->_object->name,
            ObjectBot::description => $this->_object->description,
            ObjectBot::style => $this->_object->style,
            ObjectBot::topics => $this->_object->topics,
            ObjectBot::personality => $this->_object->personality,
            ObjectBot::active => $this->_object->active,
            ObjectBot::createdAt => $this->_object->createdAt,
            ObjectBot::updatedAt => $this->_object->updatedAt,

            default => parent::__get($name),
        };
    }

    /**
     * Convert to array representation
     *
     * @param bool $withId Include ID field in result
     * @param bool $idAsIndex Use ID as array key
     * @param bool $withBridges Include bridge/junction table data
     * @param bool $withCalculation Include calculated fields
     * @param bool $toFrontend When true, exclude fields that must not be sent to frontend
     * @return array<string, mixed> Array representation
     */
    public function toArray(bool $withId = true, bool $idAsIndex = true, bool $withBridges = false, bool $withCalculation = false, bool $toFrontend = false): array
    {
        $data = [];

        if ($withId) {
            $data[ObjectBot::idBot] = $this->_object->idBot;
        }

        $data[ObjectBot::name] = $this->_object->name;
        $data[ObjectBot::description] = $this->_object->description;
        $data[ObjectBot::style] = $this->_object->style;
        $data[ObjectBot::topics] = $this->_object->topics;
        $data[ObjectBot::personality] = $this->_object->personality;
        $data[ObjectBot::active] = $this->_object->active;
        $data[ObjectBot::createdAt] = $this->_object->createdAt;
        $data[ObjectBot::updatedAt] = $this->_object->updatedAt;

        return $data;
    }
}
