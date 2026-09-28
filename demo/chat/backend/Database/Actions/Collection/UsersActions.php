<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Collection;

use Demo\Chat\Database\Object\Collection\Users as ObjectUsers;
use Demo\Chat\Database\View\Collection\Users as DbCollectionUsers;
use Demo\Chat\Database\View\Item\User;
use Hilos\Database\Actions\Collection\UsersActions as FrameworkUsersActions;

/**
 * UsersActions - write operations for Users collection.
 *
 * @method User registerAdmin()
 * @method User createWithName(string $name)
 * @property-read DbCollectionUsers $collection
 * @property-read ObjectUsers $objectCollection
 */
final class UsersActions extends FrameworkUsersActions
{
}
