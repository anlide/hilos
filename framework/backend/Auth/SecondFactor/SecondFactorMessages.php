<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

/**
 * The sentences the second factor puts in front of a person, in one place (HIL-494).
 *
 * Shared by the sign-in step, the enrolment on the way in and the profile section: a wrong
 * code is the same sentence whether the code came from an app or from the backup list, so a
 * refusal never says which of the two was tried.
 */
final class SecondFactorMessages
{
    /** A code that matched nothing - an app code, a backup code, a code already used. */
    public const string INVALID_CODE = 'Invalid code';

    /** A second-factor submit whose sign-in no longer waits on that screen. */
    public const string STEP_EXPIRED = 'Your sign-in step expired, start again';

    /** A first code for an enrolment that was never started or ran out. */
    public const string SETUP_EXPIRED = 'This setup expired, start it again';

    /** A cancel link that names no standing removal - wrong, canceled or carried out. */
    public const string LINK_DEAD = 'This link no longer works';

    /** A removal asked for while one stands; the date it takes effect follows. */
    public const string RESET_ALREADY_REQUESTED = 'Removal is already requested and takes effect on %s';

    /** An administrator requires a second factor, so the last authenticator stays. */
    public const string REQUIRED = 'Your administrator requires two-step verification';

    /** A profile action naming an app the person has not connected. */
    public const string NOT_CONNECTED = 'This app is not connected';

    /** A removal asked for by a person who has no second factor to remove. */
    public const string FACTOR_OFF = 'Two-step verification is off';

    /** A removal wait outside the administrator's bounds; the bounds follow. */
    public const string WAIT_OUT_OF_BOUNDS = 'Choose a wait from %d to %d days';

    /** Name an authenticator starts with until the person gives it one. */
    public const string DEFAULT_LABEL = 'Authenticator app';
}
