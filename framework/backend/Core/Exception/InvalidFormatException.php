<?php

declare(strict_types=1);

namespace Hilos\Core\Exception;

/**
 * Exception when value format is invalid.
 *
 * Carries the marker for the whole `require*` branch of the payload readers and for
 * the two refusals of the JSON reader: whatever shape the wrong value arrived in, the
 * failure is that the input could not be parsed.
 */
class InvalidFormatException extends ValidationException implements MalformedInput
{
}
