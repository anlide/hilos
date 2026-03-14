<?php

namespace Hilos\Core\Exception\Process;

use Hilos\Core\Exception\ProcessException;

/**
 * Exception thrown when pipe or stream could not be set to non-blocking mode.
 */
class FailedToSetNonBlockingException extends ProcessException {
}
