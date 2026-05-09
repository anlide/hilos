<?php

declare(strict_types=1);

namespace Hilos\Environment\Exception;

/**
 * Thrown when an environment key is read through the wrong typed accessor.
 */
final class EnvTypeMismatchException extends EnvException
{
}
