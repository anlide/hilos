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

    /** Two-step verification after a good credential and before the session upgrade. */
    public const string SECOND_FACTOR = 'second_factor';

    /** Choosing a new password (recovery). */
    public const string SET_PASSWORD = 'set_password';

    /** Parked while an icon method's ceremony runs. */
    public const string EXTERNAL = 'external';

    /** The terminal screen with a Continue action. */
    public const string DONE = 'done';
}
