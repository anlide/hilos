<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource\Exception;

use Hilos\HilosException;

/**
 * Exception: one collection named by both widths of the same half - whole and by rows.
 *
 * Refused rather than resolved, because the registry keeps one grant per (collection, agent) pair
 * and a repeated registration replaces it: without this refusal one of the two claims would
 * silently eat the other, and which one won would depend on the order of the walk.
 */
class ClaimWidthConflictException extends HilosException
{
}
