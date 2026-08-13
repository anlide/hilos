<?php

declare(strict_types=1);

namespace Hilos\Auth\Flow;

/**
 * AuthFlowIntent - fixed value set for the identifier-first auth flow's intent axis.
 *
 * What the flow is trying to do, the other axis of the state the machine in
 * `@hilos/core` (`auth/authFlow.ts`, HIL-413) holds. The backend names it alongside
 * the step in its {@see AuthFlowOutcome} whenever a reply moves the surface - a taken
 * address, for instance, answers with the identifier step under {@see self::LOGIN},
 * which is what turns "this email exists" from an error into a sign-in.
 *
 * Mirrors `AuthIntent` on the frontend value for value.
 */
final class AuthFlowIntent
{
    /** Acting on an existing account: signing in. */
    public const string LOGIN = 'login';

    /** Creating an account. */
    public const string REGISTER = 'register';

    /** Acting on an existing account: recovering access to it. */
    public const string RECOVERY = 'recovery';
}
