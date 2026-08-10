<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Runtime\View\Actions\Item;

use Demo\SimpleTodo\Runtime\View\Item\Connection as RuntimeConnection;
use Hilos\Runtime\View\Actions\Item\HilosConnectionActions;

/**
 * Write operations for a single connection (RtItem).
 *
 * Nothing of this demo's own: closing a socket and re-pointing its user are the
 * framework's own writes, and this demo makes no others.
 *
 * @extends HilosConnectionActions<RuntimeConnection>
 */
final class ConnectionActions extends HilosConnectionActions
{
}
