<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Actions\Collection;

use Demo\EcommerceShop\Database\Object\Collection\Sessions as ObjectSessions;
use Hilos\Database\Actions\Collection\SessionsActions as FrameworkSessionsActions;

/**
 * Framework session writes for the shop session chain.
 *
 * @property-read ObjectSessions $objectCollection
 */
final class SessionsActions extends FrameworkSessionsActions
{
}
