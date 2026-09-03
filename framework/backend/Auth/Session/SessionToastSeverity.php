<?php

declare(strict_types=1);

namespace Hilos\Auth\Session;

use Hilos\Auth\Session\DTO\RaiseSessionToastSignalData;

/**
 * The four kinds a toast addressed to a session can be (HIL-768).
 *
 * The backend half of a contract that is already written on the frontend: `HilosToastSeverity`
 * in `@hilos/core` (`state/toasts.ts`) names the same four, and a card is drawn from the value
 * rather than from anything derived out of it. So the values here are those four spelled
 * letter for letter - a fifth kind is a card nobody wrote, and a different spelling is a card
 * that never arrives.
 *
 * A backed enum rather than a bag of constants because it stands on the edge: a raise
 * ({@see RaiseSessionToastSignalData}) arrives as a string off the wire, and `tryFrom()` is
 * where an unknown one is turned away instead of being stored and shipped to a browser that
 * cannot draw it.
 */
enum SessionToastSeverity: string
{
    /** Something failed. Cards of this kind carry no countdown and wait for a person. */
    case ERROR = 'error';

    /** Something the person asked for finished. */
    case SUCCESS = 'success';

    /** Something worth saying that is neither of the above. */
    case INFO = 'info';

    /** Something finished, but not the way it was meant to. */
    case WARNING = 'warning';
}
