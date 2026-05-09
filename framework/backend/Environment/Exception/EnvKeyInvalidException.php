<?php

declare(strict_types=1);

namespace Hilos\Environment\Exception;

/**
 * Thrown when an environment key is not a non-empty string or EnvConstants case.
 */
final class EnvKeyInvalidException extends EnvException
{
}
