<?php

namespace Demo\Chat\Database\Object\Item;

use Demo\Chat\Database\Entity\Item\Bot as EntityBot;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Object_;

/**
 * Bot Object
 * Auto-generated from Entity: Demo\Chat\Database\Entity\Item\Bot
 *
 * @extends Object_<EntityBot>
 *
 * @property-read ?int $id
 * @property string $name
 * @property ?string $description
 * @property ?string $style
 * @property ?string $topics
 * @property ?string $personality
 * @property bool $active
 */
final class Bot extends Object_
{
    public const string ENTITY_CLASS = EntityBot::class;

    public const string id = 'id';
    public const string name = 'name';
    public const string description = 'description';
    public const string style = 'style';
    public const string topics = 'topics';
    public const string personality = 'personality';
    public const string active = 'active';

    /**
     * Returns the value of a bot object property by name.
     *
     * @param string $property Property name (id, name, description, style, topics, personality, active)
     * @return mixed Property value or parent method result
     * @throws DatabaseException
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::name => $this->entity->name,
            self::description => $this->entity->description,
            self::style => $this->entity->style,
            self::topics => $this->entity->topics,
            self::personality => $this->entity->personality,
            self::active => $this->entity->active,
            default => parent::__get($property),
        };
    }

    /**
     * Sets the value of a bot object property.
     *
     * @param string $property Property name to set
     * @param mixed $value New value (cast to appropriate type)
     * @throws DatabaseException
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::name => $this->entity->name = (string)$value,
            self::description => $this->entity->description = (string)$value,
            self::style => $this->entity->style = (string)$value,
            self::topics => $this->entity->topics = (string)$value,
            self::personality => $this->entity->personality = (string)$value,
            self::active => $this->entity->active = (bool)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the bot object to an associative array with all fields.
     *
     * @return array<string, mixed> Key => value array
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::name => $this->entity->name,
            self::description => $this->entity->description,
            self::style => $this->entity->style,
            self::topics => $this->entity->topics,
            self::personality => $this->entity->personality,
            self::active => $this->entity->active,
        ];
    }
}
