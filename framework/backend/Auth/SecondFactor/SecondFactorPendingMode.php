<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

use Hilos\Database\Actions\Item\SessionActions;

/**
 * The screens a proven sign-in can be held on before the person is let in (HIL-494).
 *
 * Written into the session row by {@see SessionActions::holdPendingSecondFactor()} and read
 * back by the handshake, which serves the step from it - so a reload, a second tab and a
 * restarted daemon land on the same screen. A closed set: the handshake maps each value to
 * a step, and a value it does not know is a step nobody draws.
 */
final class SecondFactorPendingMode
{
    /** The person has a confirmed authenticator and this browser is not trusted: the code step. */
    public const string VERIFY = 'verify';

    /** An administrator requires a second factor the person never enrolled: enrolment on the way in. */
    public const string SETUP = 'setup';

    /**
     * The enrolment on the way in is confirmed and the backup codes are on screen.
     *
     * A reload in this mode no longer shows the codes - they were answered once and are not
     * kept anywhere the handshake reads - so it is served as the ordinary code step: the
     * factor exists now, and the codes stay reachable from the profile.
     */
    public const string SETUP_DONE = 'setup_done';
}
