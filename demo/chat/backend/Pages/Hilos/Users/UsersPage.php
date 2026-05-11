<?php

declare(strict_types=1);

namespace Demo\Chat\Pages\Hilos\Users;

use Demo\Chat\Pages\AdminUsersPage;
use Hilos\Pages\Users\AbstractHilosUsersPage;

/**
 * UsersPage - Hilos users list page implementation for demo.
 *
 * The initial users table snapshot and table mutation broadcasts are produced
 * by {@see \Demo\Chat\Projection\Page\HilosUsersPageProjection}, mirroring the
 * admin users page projection (see {@see AdminUsersPage}).
 */
final class UsersPage extends AbstractHilosUsersPage
{
}
