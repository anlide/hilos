<?php

declare(strict_types=1);

namespace Hilos\Runtime\Exception;

/** Retryable ordinary admission refusal, raised before any live Runtime write. */
final class RtWriteBackpressureException extends RtBaseException
{
}
