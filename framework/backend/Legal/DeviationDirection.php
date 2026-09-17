<?php

declare(strict_types=1);

namespace Hilos\Legal;

/**
 * DeviationDirection - which way a project's clause departs from the standard one.
 *
 * Declared by the project, never derived: no machine reads two prose texts and tells which is
 * stricter, and the direction is the field a reader trusts most. A looser deviation is worth
 * showing as much as a stricter one - "we keep less than others do" earns trust.
 */
enum DeviationDirection: string
{
    /** The project's clause is harsher on the person than the standard's: more kept, more seen. */
    case STRICTER = 'stricter';

    /** The project's clause is kinder to the person than the standard's: less kept, less seen. */
    case LOOSER = 'looser';
}
