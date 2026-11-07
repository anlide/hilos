<?php

declare(strict_types=1);

namespace Hilos\Database\Exception;

use Hilos\HilosException;

/** An Entity or unmapped-table provider declares inconsistent moment metadata. */
final class InvalidMomentDeclarationException extends HilosException
{
}
