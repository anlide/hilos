<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\AuthBlock as ObjectAuthBlock;
use Hilos\HilosException;

/**
 * AuthBlock Db item - read-only wrapper around ObjectAuthBlock.
 *
 * @extends DbItem<ObjectAuthBlock>
 * @property-read ?int $id
 * @property-read string $scope
 * @property-read string $identity
 * @property-read string $action
 * @property-read int $level
 * @property-read ?string $blockedUntil
 */
final class AuthBlock extends DbItem
{
    /**
     * Magic getter for auth block properties.
     *
     * @param string $name Property name (id, scope, identity, action, level, blockedUntil)
     * @return mixed Property value
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectAuthBlock::id => $this->_object->id,
            ObjectAuthBlock::scope => $this->_object->scope,
            ObjectAuthBlock::identity => $this->_object->identity,
            ObjectAuthBlock::action => $this->_object->action,
            ObjectAuthBlock::level => $this->_object->level,
            ObjectAuthBlock::blockedUntil => $this->_object->blockedUntil,
            default => parent::__get($name),
        };
    }
}
