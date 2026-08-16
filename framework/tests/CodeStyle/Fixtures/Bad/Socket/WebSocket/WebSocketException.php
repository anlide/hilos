<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad\Socket\WebSocket;

use Hilos\HilosException;

/**
 * Deliberately broken sample: a base a whole branch inherits the marker through,
 * with the marker taken off its own declaration.
 *
 * It sits outside every judged directory on purpose, because the real one does too:
 * the base of the WebSocket family lives beside the frame reader rather than in the
 * exception directory below it. Were the rule to watch only the judged directories,
 * removing this line would silently unmark seven classes at once and no fixture
 * anywhere would notice.
 */
class WebSocketException extends HilosException
{
}
