<?php

namespace Demo\Chat\Database\Object\Item;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Entity\Item\Bot as EntityBot;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\Object_;

/**
 * Bot - Object wrapper for bot entity.
 *
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
 * @property int $reactionDelayMin
 * @property int $reactionDelayMax
 * @property int $reactionChance
 * @property bool $topicMatchRequired
 * @property int $cooldownAfterMessage
 * @property int $priority
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
    public const string reactionDelayMin = 'reactionDelayMin';
    public const string reactionDelayMax = 'reactionDelayMax';
    public const string reactionChance = 'reactionChance';
    public const string topicMatchRequired = 'topicMatchRequired';
    public const string cooldownAfterMessage = 'cooldownAfterMessage';
    public const string priority = 'priority';

    /**
     * Return collection key used for DbChatContext lookup.
     *
     * @return string Collection key (DbChatContext::bots)
     */
    protected static function getCollectionKey(): string
    {
        return DbChatContext::bots;
    }

    /**
     * Returns the value of a bot object property by name.
     *
     * @param string $property Property name (id, name, description, style, topics, personality, active, reactionDelayMin, ...)
     * @return mixed Property value or parent method result
     * @throws DatabaseException If entity or collection access fails
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
            self::reactionDelayMin => $this->entity->reaction_delay_min,
            self::reactionDelayMax => $this->entity->reaction_delay_max,
            self::reactionChance => $this->entity->reaction_chance,
            self::topicMatchRequired => $this->entity->topic_match_required,
            self::cooldownAfterMessage => $this->entity->cooldown_after_message,
            self::priority => $this->entity->priority,
            default => parent::__get($property),
        };
    }

    /**
     * Sets the value of a bot object property.
     *
     * @param string $property Property name to set
     * @param mixed $value New value (cast to appropriate type)
     * @throws DatabaseException If entity or collection access fails
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
            self::reactionDelayMin => $this->entity->reaction_delay_min = (int)$value,
            self::reactionDelayMax => $this->entity->reaction_delay_max = (int)$value,
            self::reactionChance => $this->entity->reaction_chance = (int)$value,
            self::topicMatchRequired => $this->entity->topic_match_required = (bool)$value,
            self::cooldownAfterMessage => $this->entity->cooldown_after_message = (int)$value,
            self::priority => $this->entity->priority = (int)$value,
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
            self::reactionDelayMin => $this->entity->reaction_delay_min,
            self::reactionDelayMax => $this->entity->reaction_delay_max,
            self::reactionChance => $this->entity->reaction_chance,
            self::topicMatchRequired => $this->entity->topic_match_required,
            self::cooldownAfterMessage => $this->entity->cooldown_after_message,
            self::priority => $this->entity->priority,
        ];
    }
}
