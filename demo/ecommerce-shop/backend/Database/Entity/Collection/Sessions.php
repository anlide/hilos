<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Database\Entity\Collection;

use Demo\EcommerceShop\Database\Entity\Item\Session as EntitySession;
use Hilos\Database\Entity\Collection\Sessions as FrameworkSessions;

/**
 * Entity collection for shop sessions.
 *
 * @method EntitySession|null current()
 * @method EntitySession|null offsetGet(mixed $offset)
 */
final class Sessions extends FrameworkSessions
{
    public const string ENTITY_CLASS = EntitySession::class;
}
