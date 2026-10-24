<?php

declare(strict_types=1);

namespace Hilos\Database\View\Item;

use Hilos\Database\Actions\Item\UserActions;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Item\User as ObjectUser;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;

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
 * @property-read ?int $lastActivity Last activity in epoch milliseconds UTC; null when never recorded
 * @property-read ?string $themePick Light, dark or system; null when there is no personal position
 * @property-read bool $themePickInitialized Whether an account choice was ever saved, including a reset
 * @property-read ?string $timeZone Exact IANA time zone; null for Automatic
 * @property-read ?string $lastBrowserTimeZone Last usable IANA time zone a browser of this person named; null when none has
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
            ObjectUser::lastActivity => $this->_object->lastActivity === null
                ? null
                : TimeHelper::sqlToMs($this->_object->lastActivity),
            ObjectUser::themePick => $this->_object->themePick,
            ObjectUser::themePickInitialized => $this->_object->themePickInitialized,
            ObjectUser::timeZone => $this->_object->timeZone,
            ObjectUser::lastBrowserTimeZone => $this->_object->lastBrowserTimeZone,
            default => parent::__get($name),
        };
    }

    /**
     * Keeps the inherited field selection and serializes activity as epoch milliseconds UTC.
     *
     * @param bool $withId Include ID fields
     * @param bool $idAsIndex Use ID as array index
     * @param bool $withBridges Include bridge data
     * @param bool $withCalculation Include calculated fields
     * @param bool $toFrontend Exclude private fields and always include ID
     * @return array<string, mixed> Read-facing user fields
     * @throws LogicException When a related collection is not configured
     * @throws InvalidArgumentException When a related object type does not match its collection
     * @throws DatabaseException When loading a related row fails
     */
    public function toArray(
        bool $withId = true,
        bool $idAsIndex = true,
        bool $withBridges = false,
        bool $withCalculation = false,
        bool $toFrontend = false,
    ): array {
        $result = parent::toArray($withId, $idAsIndex, $withBridges, $withCalculation, $toFrontend);
        $result[ObjectUser::lastActivity] = $this->lastActivity;

        return $result;
    }
}
