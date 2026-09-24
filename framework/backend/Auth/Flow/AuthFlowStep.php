<?php

declare(strict_types=1);

namespace Hilos\Auth\Flow;

/**
 * AuthFlowStep - fixed value set for the identifier-first auth flow's step axis.
 *
 * The backend half of the contract the flow machine in `@hilos/core`
 * (`auth/authFlow.ts`, HIL-413) drives the sign-in surface by: a step is a SCREEN,
 * and the backend decides which screen a submit lands on by naming one of these in
 * its {@see AuthFlowOutcome}. The set is fixed here so every method leaf writes into
 * the existing shape instead of inventing a step the views cannot render - the
 * frontend maps these keys to layout and text, and a key it does not know is a blank
 * screen.
 *
 * Mirrors `AuthStep` on the frontend value for value; adding a step means adding it
 * on both sides.
 */
final class AuthFlowStep
{
    /** The single entry field - password and the method row reveal inside it. */
    public const string IDENTIFIER = 'identifier';

    /** The registration terms screen. */
    public const string CONSENT = 'consent';

    /** A one-time code: identifier confirmation, phone sign-in, recovery. */
    public const string CODE = 'code';

    /** A one-time code that ran out: the field is gone and the screen offers a new code. */
    public const string CODE_EXPIRED = 'code_expired';

    /** Two-step verification after a good credential and before the session upgrade. */
    public const string SECOND_FACTOR = 'second_factor';

    /** Enrolling a second factor on the way in, because an administrator requires one (HIL-494). */
    public const string SECOND_FACTOR_SETUP = 'second_factor_setup';

    /** The backup codes of a second factor just enrolled on the way in, before the person is let in (HIL-494). */
    public const string SECOND_FACTOR_CODES = 'second_factor_codes';

    /** Asking for the delayed removal of a second factor nobody can show any more (HIL-494). */
    public const string SECOND_FACTOR_RESET = 'second_factor_reset';

    /** The removal is asked for and will take effect on its date (HIL-494). */
    public const string SECOND_FACTOR_RESET_REQUESTED = 'second_factor_reset_requested';

    /** Choosing a new password (recovery). */
    public const string SET_PASSWORD = 'set_password';

    /** Parked while an icon method's ceremony runs. */
    public const string EXTERNAL = 'external';

    /** The terminal screen with a Continue action. */
    public const string DONE = 'done';
}
