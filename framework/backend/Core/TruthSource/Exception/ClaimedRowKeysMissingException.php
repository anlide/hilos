<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource\Exception;

use Hilos\HilosException;

/**
 * Exception: an agent declared a collection narrowly and then named no row of it.
 *
 * Refused rather than registered, because a claim of no rows already means something else - it is
 * the right to create - and a collection registered with nobody holding its rows stays silent
 * until the first foreign write an hour later.
 */
class ClaimedRowKeysMissingException extends HilosException
{
}
