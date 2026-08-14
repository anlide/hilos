<?php

declare(strict_types=1);

namespace Hilos\Core\Exception;

use Hilos\BaseDTO;

/**
 * Exception when a payload string does not decode as JSON at all.
 *
 * Told apart from {@see NonArrayPayloadException} by {@see BaseDTO::fromJson()},
 * which asks the decoder for its error rather than reading `null` as a refusal: the
 * literal `null` decodes successfully and is a payload of the wrong shape, not a
 * string that failed to parse.
 */
class InvalidJsonException extends InvalidFormatException
{
}
