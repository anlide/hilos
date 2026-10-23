<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\ChangeLogReceipt as EntityChangeLogReceipt;
use Hilos\Database\Object\Collection\ChangeLogReceipts as ObjectChangeLogReceipts;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;

/**
 * @extends Object_<EntityChangeLogReceipt>
 *
 * @property-read ?int $id
 * @property string $created_at
 * @property ?int $actor_user_id
 * @property ?int $subject_user_id
 * @property ?int $session_id
 * @property string $channel
 * @property string $action
 * @property ?string $agent
 * @property ?string $source
 * @property int $open_handovers
 */
class ChangeLogReceipt extends Object_
{
    public const string ENTITY_CLASS = EntityChangeLogReceipt::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectChangeLogReceipts::class;

    public const string id = EntityChangeLogReceipt::id;
    public const string created_at = EntityChangeLogReceipt::created_at;
    public const string actor_user_id = EntityChangeLogReceipt::actor_user_id;
    public const string subject_user_id = EntityChangeLogReceipt::subject_user_id;
    public const string session_id = EntityChangeLogReceipt::session_id;
    public const string channel = EntityChangeLogReceipt::channel;
    public const string action = EntityChangeLogReceipt::action;
    public const string agent = EntityChangeLogReceipt::agent;
    public const string source = EntityChangeLogReceipt::source;
    public const string open_handovers = EntityChangeLogReceipt::open_handovers;

    /**
     * @return string Global auto-increment id, independent of the physical partition key
     * @throws ObjectGetIdStringNotImplementedException When an unsaved row has no id
     */
    public function getIdString(): string
    {
        return (string)($this->entity->id
            ?? throw new ObjectGetIdStringNotImplementedException('A change log row has no id'));
    }

    /**
     * @param string $property Column name
     * @return mixed Scalar column value
     * @throws DatabaseException When the property does not exist or cannot be accessed
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::created_at => $this->entity->created_at,
            self::actor_user_id => $this->entity->actor_user_id,
            self::subject_user_id => $this->entity->subject_user_id,
            self::session_id => $this->entity->session_id,
            self::channel => $this->entity->channel,
            self::action => $this->entity->action,
            self::agent => $this->entity->agent,
            self::source => $this->entity->source,
            self::open_handovers => $this->entity->open_handovers,
            default => parent::__get($property),
        };
    }

    /**
     * @param string $property Column name
     * @param mixed $value New scalar value
     * @throws DatabaseException When the property cannot be written
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::created_at => $this->entity->created_at = (string)$value,
            self::actor_user_id => $this->entity->actor_user_id = $value === null ? null : (int)$value,
            self::subject_user_id => $this->entity->subject_user_id = $value === null ? null : (int)$value,
            self::session_id => $this->entity->session_id = $value === null ? null : (int)$value,
            self::channel => $this->entity->channel = (string)$value,
            self::action => $this->entity->action = (string)$value,
            self::agent => $this->entity->agent = $value === null ? null : (string)$value,
            self::source => $this->entity->source = $value === null ? null : (string)$value,
            self::open_handovers => $this->entity->open_handovers = (int)$value,
            default => parent::__set($property, $value),
        };
    }
}
