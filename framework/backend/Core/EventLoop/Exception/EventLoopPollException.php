<?php

declare(strict_types=1);

namespace Hilos\Core\EventLoop\Exception;

/** The native base failed to poll; owner retirement cannot restore that base. */
final class EventLoopPollException extends EventLoopException
{
}
