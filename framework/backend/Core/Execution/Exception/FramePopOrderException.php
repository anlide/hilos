<?php

declare(strict_types=1);

namespace Hilos\Core\Execution\Exception;

use Hilos\Core\Exception\LogicException;

/**
 * Thrown when an execution frame is popped out of order.
 *
 * Signals a caller bug: a frame token was popped while it was not the current
 * top of the execution stack, i.e. push/pop calls were not balanced.
 */
class FramePopOrderException extends LogicException
{
}
