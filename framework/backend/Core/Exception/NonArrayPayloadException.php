<?php

declare(strict_types=1);

namespace Hilos\Core\Exception;

use Hilos\BaseDTO;

/**
 * Exception when a payload string decodes, but into something other than an array:
 * a bare number, a quoted string, the literal `null`.
 *
 * {@see BaseDTO::fromJson()} refuses it here rather than at the parameter of
 * `fromArray()`, so a body of the wrong type is answered by a DTO refusal the read
 * paths already handle instead of a TypeError from the argument boundary.
 */
class NonArrayPayloadException extends InvalidFormatException
{
}
