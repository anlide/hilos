<?php

declare(strict_types=1);

namespace Demo\Chat\Database\View\Item;

use Demo\Chat\Database\Actions\Item\UserActions;
use Demo\Chat\Database\Object\Item\User as ObjectUser;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Collection\Connections;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Demo\Chat\Runtime\View\Item\ChatUserState as RuntimeChatUserState;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\User as FrameworkUser;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;

/**
 * User - Db item with high-level abstraction and lazy loading.
 *
 * Stores reference to ObjectUser instance.
 * Object instances are stored in ObjectCollection in Hilos.
 *
 * @method __construct(ObjectUser $objectUser)
 *
 * @property-read ?int $mergedInto Survivor user id this account was merged into, or null when standalone
 * @property-read Connections $connections Connections for this user (online check)
 * @property-read int $onlineSessionCount Number of active online sessions for this user
 * @property-read ?RuntimeChatUserState $chatUserState Per-user chat runtime state
 * @property-read UserActions $actions Actions for write operations on this user
 */
final class User extends FrameworkUser
{
    public const string onlineSessionCount = 'onlineSessionCount';

    /**
     * Property getter (read-only access). Supports lazy loading of related collections.
     *
     * @param string $name Property name
     * @return mixed Property value, actions, or linked runtime items
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws RtActionsStateCollectionNullException If runtime connection state collection is not initialized
     * @throws HilosException Whatever the inherited getter or runtime bridges raise
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectUser::mergedInto => $this->_object->mergedInto,
            ChatRtContext::connections => Hilos::$rt->connections->forUser($this->id),
            self::onlineSessionCount => count($this->connections),
            ChatRtContext::chatUserState => Hilos::$rt->userStates[$this->_object->id],
            default => parent::__get($name),
        };
    }
}
