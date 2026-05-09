<?php

declare(strict_types=1);

namespace Hilos\Environment\Exception;

/**
 * Thrown when callers try to mutate read-only environment values through the accessor.
 */
final class EnvMutationNotSupportedException extends EnvException
{
}
