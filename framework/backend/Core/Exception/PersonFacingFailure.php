<?php

declare(strict_types=1);

namespace Hilos\Core\Exception;

/**
 * Declares that an exception's getMessage() is written for the person who asked.
 *
 * The message may cross the action-refusal boundary as it is. This declaration is
 * independent of the exception family; diagnostic text must never carry the marker.
 */
interface PersonFacingFailure
{
}
