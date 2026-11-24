<?php

declare(strict_types=1);

namespace Demo\Chat\Moderation;

use Hilos\Core\Exception\ValidationException;

/** The stored session no longer authorizes this draft's author. */
final class MessageAuthorChangedException extends ValidationException
{
}
