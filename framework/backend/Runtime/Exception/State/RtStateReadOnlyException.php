<?php

declare(strict_types=1);

namespace Hilos\Runtime\Exception\State;

use Hilos\Runtime\Exception\RtStateException;

/**
 * Exception: attempt to write RtState from outside.
 */
class RtStateReadOnlyException extends RtStateException
{
}
