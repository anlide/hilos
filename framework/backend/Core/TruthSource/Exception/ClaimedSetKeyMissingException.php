<?php

declare(strict_types=1);

namespace Hilos\Core\TruthSource\Exception;

use Hilos\HilosException;

/**
 * Exception: an agent declared a collection by a set and then named no set key of it.
 *
 * Refused rather than registered, because the width of no rows is already taken - it is the right
 * to create - and a claim that names no set would stay silent until the first foreign write into
 * the rows it was meant to hold.
 */
class ClaimedSetKeyMissingException extends HilosException
{
}
