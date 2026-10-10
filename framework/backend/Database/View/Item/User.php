<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\UserActions;
use Hilos\Database\Object\Item\User as ObjectUser;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\HilosException;

/**
 * User - Db item with high-level abstraction and lazy loading.
 *
 * Stores reference to ObjectUser instance.
 * Object instances are stored in ObjectCollection in Hilos.
 *
 * Holds only durable database fields: presence is merged by the Hilos users
 * table from the runtime presence source, never by this view item.
 *
 * @extends DbItem<ObjectUser>
 * @method __construct(ObjectUser $objectUser)
 *
 * @property-read ?int $id User ID (primary key)
 * @property-read string $name User name
 * @property-read bool $admin Whether the user is a panel admin operator
 * @property-read bool $block Whether the user is blocked from acting
 * @property-read ?string $lastActivity Last activity timestamp
 * @property-read ?string $themePick The person's theme pick: light, dark or system; null when they never picked
 * @property-read UserActions $actions Actions for write operations on this user
 */
class User extends DbItem
{
    /**
     * Property getter (read-only access).
     *
     * @param string $name Property name
     * @return mixed Property value or item actions
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectUser::id => $this->_object->id,
            ObjectUser::name => $this->_object->name,
            ObjectUser::admin => $this->_object->admin,
            ObjectUser::block => $this->_object->block,
            ObjectUser::lastActivity => $this->_object->lastActivity,
            ObjectUser::themePick => $this->_object->themePick,
            default => parent::__get($name),
        };
    }
}
