<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Database\View\Item;

use Demo\SimplePoll\Database\Actions\Item\UserActions;
use Demo\SimplePoll\Database\Object\Item\User as ObjectUser;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;
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
 * @property-read UserActions $actions Actions for write operations on this user
 */
final class User extends DbItem
{
    /**
     * Property getter (read-only access).
     *
     * @param string $name Property name
     * @return bool|int|string|UserActions|null Property value or item actions
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): bool|int|string|UserActions|null
    {
        return match ($name) {
            ObjectUser::id => $this->_object->id,
            ObjectUser::name => $this->_object->name,
            ObjectUser::admin => $this->_object->admin,
            ObjectUser::block => $this->_object->block,
            ObjectUser::lastActivity => $this->_object->lastActivity,
            default => parent::__get($name),
        };
    }
}
