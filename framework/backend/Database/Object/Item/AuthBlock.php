<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\AuthBlock as EntityAuthBlock;
use Hilos\Database\Object\Collection\AuthBlocks as ObjectAuthBlocks;
use Hilos\Database\Object\Collection\AuthBlocks;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * AuthBlock object - wraps AuthBlock entity.
 *
 * Exposes the durable block's fields (HIL-420). Values are written only through
 * the {@see AuthBlocks} upsert/clear
 * primitives; the throttle service owns the ladder and window logic that
 * decides them.
 *
 * @extends Object_<EntityAuthBlock>
 *
 * @property-read ?int $id
 * @property string $scope
 * @property string $identity
 * @property string $action
 * @property int $level
 * @property ?int $blockedUntil
 */
class AuthBlock extends Object_
{
    public const string ENTITY_CLASS = EntityAuthBlock::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectAuthBlocks::class;
    public const string id = 'id';
    public const string scope = 'scope';
    public const string identity = 'identity';
    public const string action = 'action';
    public const string level = 'level';
    public const string blockedUntil = 'blockedUntil';

    /**
     * Stamps a new or changed row before saving. The column has no Object/View
     * reader, so a forgotten application writer would otherwise remain invisible.
     *
     * @throws DatabaseException If database operation fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws ObjectGetIdStringNotImplementedException If getIdString() is not implemented or primary key is null
     * @throws CreateNotAllowedException When no truth source in this process may add a row here
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     */
    public function sync(): void
    {
        if (!$this->entity->isRelated() || $this->getChangedColumns() !== []) {
            $this->entity->updated_at = TimeHelper::nowMs();
        }

        parent::sync();
    }

    /**
     * Magic getter for entity properties.
     *
     * @param string $property Property name (id, scope, identity, action, level, blockedUntil)
     * @return mixed Property value
     * @throws DatabaseException When the property is not a known AuthBlock field
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::scope => $this->entity->scope,
            self::identity => $this->entity->identity,
            self::action => $this->entity->action,
            self::level => $this->entity->level,
            self::blockedUntil => $this->entity->blocked_until,
            default => parent::__get($property),
        };
    }

    /**
     * Magic setter for entity properties.
     *
     * @param string $property Property name (scope, identity, action, level, blockedUntil)
     * @param mixed $value Value to set
     * @throws DatabaseException When the property cannot be set on an AuthBlock
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::scope => $this->entity->scope = (string)$value,
            self::identity => $this->entity->identity = (string)$value,
            self::action => $this->entity->action = (string)$value,
            self::level => $this->entity->level = (int)$value,
            self::blockedUntil => $this->entity->blocked_until = $value === null ? null : (int)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the block to an associative array.
     *
     * @return array<string, mixed> Block data (id, scope, identity, action, level, blockedUntil)
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::scope => $this->entity->scope,
            self::identity => $this->entity->identity,
            self::action => $this->entity->action,
            self::level => $this->entity->level,
            self::blockedUntil => $this->entity->blocked_until,
        ];
    }
}
