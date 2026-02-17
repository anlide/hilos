<?php

namespace Hilos\Hilos\TruthSource\Exception;

use Hilos\Core\TruthSource\Exception\WriteNotAllowedException as BaseWriteNotAllowedException;

/**
 * Exception: write operation not allowed (no truth source registered for Db layer).
 */
class WriteNotAllowedException extends BaseWriteNotAllowedException
{
}
