<?php

declare(strict_types=1);

namespace Demo\Chat\Agents;

use Demo\Chat\Runtime\View\Item\Connection;

/** Handshake result shared by pending replies for the same socket and target identity. */
final class SessionStateVisit
{
    public bool $ended = false;
    public ?Connection $connection = null;
}
