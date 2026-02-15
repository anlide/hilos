<?php

namespace Hilos\Exception\Hilos\Database\TruthSource;

use Hilos\Exception\Database\TruthSource\WriteNotAllowedException as BaseWriteNotAllowedException;

/**
 * Exception: write operation not allowed (no truth source registered for Db layer).
 */
class WriteNotAllowedException extends BaseWriteNotAllowedException
{
}
