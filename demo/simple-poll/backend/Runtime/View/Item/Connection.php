<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Runtime\View\Item;

use Demo\SimplePoll\Runtime\State\Item\Connection as StateConnection;
use Hilos\Runtime\View\Item\HilosConnection;

/**
 * Read-only runtime item for one connection state row.
 *
 * Nothing of this demo's own: the row is the framework presence stage whole, so
 * the base {@see HilosConnection} exposes every field and the per-connection
 * write actions. The class exists because a runtime collection is seen through a
 * concrete item, not because there is anything left to say here.
 *
 * @extends HilosConnection<StateConnection>
 */
final class Connection extends HilosConnection
{
}
