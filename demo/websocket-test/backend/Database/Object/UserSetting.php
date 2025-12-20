<?php

namespace Demo\WebSocketTest\Database\Object;

use Hilos\Database\Object\Object_;
use Demo\WebSocketTest\Database\Entity\UserSetting as EntityUserSetting;

/**
 * UserSetting Object
 * Auto-generated from Entity: Demo\WebSocketTest\Database\Entity\UserSetting
 *
 * @property-read ?int $id
 * @property int $userId
 * @property string $key
 * @property ?string $value
 */
final class UserSetting extends Object_
{
    // Property name constants (camelCase for PHP)
    public const string id = 'id';
    public const string userId = 'userId';
    public const string key = 'key';
    public const string value = 'value';

    protected EntityUserSetting $entity;
    protected EntityUserSetting $entitySync;

    public static function create(): self
    {
        $obj = new self();
        $obj->entity = EntityUserSetting::getEmpty();
        $obj->entitySync = clone $obj->entity;
        return $obj;
    }

    public static function fromEntity(EntityUserSetting $entity): self
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
            self::userId => $this->entity->user_id,
            self::key => $this->entity->key,
            self::value => $this->entity->value,
            default => parent::__get($property),
        };
    }

    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::userId => $this->entity->user_id = (int)$value,
            self::key => $this->entity->key = (string)$value,
            self::value => $this->entity->value = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::key => $this->entity->key,
            self::value => $this->entity->value,
        ];
    }
}
