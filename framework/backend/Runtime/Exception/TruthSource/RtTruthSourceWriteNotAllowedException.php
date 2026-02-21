<?php

declare(strict_types=1);

namespace Hilos\Runtime\Exception\TruthSource;

use Hilos\Runtime\Exception\RtTruthSourceException;

/**
 * Exception: Write operation not allowed (no truth source registered)
 */
class RtTruthSourceWriteNotAllowedException extends RtTruthSourceException
{
}
