<?php

namespace Demo\WebSocketTest\Database\Object;

use Hilos\Database\Object\Object_;
use Demo\WebSocketTest\Database\Entity\Bot as EntityBot;

/**
 * Bot Object
 * Auto-generated from Entity: Demo\WebSocketTest\Database\Entity\Bot
 *
 * @property-read ?int $idBot
 * @property string $name
 * @property ?string $description
 * @property ?string $style
 * @property ?string $topics
 * @property ?string $personality
 * @property bool $active
 * @property string $createdAt
 * @property string $updatedAt
 */
final class Bot extends Object_
{
    // Property name constants (camelCase for PHP)
    public const string idBot = 'idBot';
    public const string name = 'name';
    public const string description = 'description';
    public const string style = 'style';
    public const string topics = 'topics';
    public const string personality = 'personality';
    public const string active = 'active';
    public const string createdAt = 'createdAt';
    public const string updatedAt = 'updatedAt';

    protected EntityBot $entity;
    protected EntityBot $entitySync;

    public static function create(): self
    {
        $obj = new self();
        $obj->entity = EntityBot::getEmpty();
        $obj->entitySync = clone $obj->entity;
        return $obj;
    }

    public static function fromEntity(EntityBot $entity): self
    {
        $obj = new self();
        $obj->entity = $entity;
        $obj->entitySync = clone $entity;
        return $obj;
    }

    public function __get(string $property): mixed
    {
        return match ($property) {
            self::idBot => $this->entity->id_bot,
            self::name => $this->entity->name,
            self::description => $this->entity->description,
            self::style => $this->entity->style,
            self::topics => $this->entity->topics,
            self::personality => $this->entity->personality,
            self::active => $this->entity->active,
            self::createdAt => $this->entity->created_at,
            self::updatedAt => $this->entity->updated_at,
            default => parent::__get($property),
        };
    }

    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::name => $this->entity->name = (string)$value,
            self::description => $this->entity->description = (string)$value,
            self::style => $this->entity->style = (string)$value,
            self::topics => $this->entity->topics = (string)$value,
            self::personality => $this->entity->personality = (string)$value,
            self::active => $this->entity->active = (bool)$value,
            self::createdAt => $this->entity->created_at = (string)$value,
            self::updatedAt => $this->entity->updated_at = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    public function toArray(): array
    {
        return [
            self::idBot => $this->entity->id_bot,
            self::name => $this->entity->name,
            self::description => $this->entity->description,
            self::style => $this->entity->style,
            self::topics => $this->entity->topics,
            self::personality => $this->entity->personality,
            self::active => $this->entity->active,
            self::createdAt => $this->entity->created_at,
            self::updatedAt => $this->entity->updated_at,
        ];
    }
}
