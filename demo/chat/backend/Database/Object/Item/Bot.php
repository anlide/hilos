<?php

namespace Demo\Chat\Database\Object\Item;

use Hilos\Database\Object\Item\Object_;
use Demo\Chat\Database\Entity\Item\Bot as EntityBot;

/**
 * Bot Object
 * Auto-generated from Entity: Demo\Chat\Database\Entity\Item\Bot
 */
final class Bot extends Object_
{
    public const string id = 'id';
    public const string name = 'name';
    public const string description = 'description';
    public const string style = 'style';
    public const string topics = 'topics';
    public const string personality = 'personality';
    public const string active = 'active';

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
