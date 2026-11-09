<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountErasure\Exception;

use Hilos\HilosException;

/** @internal Rolls back participant work that encountered an equal saved result. */
final class AccountErasureResultRepeatedException extends HilosException
{
}
