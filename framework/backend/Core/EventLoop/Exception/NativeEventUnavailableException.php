<?php

declare(strict_types=1);

namespace Hilos\Core\EventLoop\Exception;

/**
 * The loaded ext-event build lacks a capability required by the event loop.
 */
final class NativeEventUnavailableException extends EventLoopException
{
}
