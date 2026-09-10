<?php

declare(strict_types=1);

namespace Hilos\Auth;

use Hilos\Auth\Exception\PasswordUnchangedException;
use Hilos\Core\Exception\ValueTooShortException;

/**
 * The password rule every surface of one installation enforces (HIL-622, HIL-654).
 *
 * One rule, and deliberately one: registration, a password reset and the profile's
 * add-password all judge the same secret for the same account, so a minimum that differed
 * between them would only ever mean a password accepted at one door and refused at the
 * next. It moved into the framework with the sign-in commands, which is where the first
 * two of those three now live.
 *
 * The minimum used to be the only thing shared, and the sentence refusing it was typed out
 * again at every door - four of them by the time HIL-654 came to add a second rule. The
 * gate is now the shared thing: {@see assertValid()} is what a password passes before it is
 * written, wherever it was typed, and the next rule (a breached-password list, HIL-650) is
 * one more branch inside it rather than a fifth copy of an if.
 */
final class PasswordPolicy
{
    /** @var int Minimum accepted password length, in characters */
    public const int MIN_LENGTH = 8;

    /**
     * Refuses a password that may not be written, whichever door it arrived at.
     *
     * The order of the two rules is deliberate. Length first, because `password_verify()`
     * is slow on purpose and there is nothing to learn by running it against a secret
     * already known to be refused - and because "too short" is the more useful of the two
     * sentences to a person who typed both mistakes at once.
     *
     * The second rule takes a verdict rather than an identity, and the caller is the one
     * that produces it. `verifyPassword()` lives on both layers of the identity - the
     * object item and the view item over it - with no interface across the pair, and the
     * callers hold different ones: a recovery holds the object layer, the profile holds
     * the view. A bool keeps this a rule instead of making it the place where those two
     * layers have to be reconciled.
     *
     * A door where nothing can match passes `false` and gets the length check alone -
     * registration, and adding a password to an account that has none (HIL-406).
     *
     * WHERE THE CALL GOES matters as much as what it answers, and it is not this class's
     * to enforce: the verdict must be computed only AFTER the caller has proved the right
     * to the operation. A form that judged the new password first would answer "that is
     * already your password" to anybody who guessed it, without ever having to type the
     * current one - which is the account's password handed to whoever asked.
     *
     * @param string $newPassword Plaintext password about to be written
     * @param bool $matchesCurrentPassword Whether that password is the one the account already has
     * @throws ValueTooShortException When the password is shorter than {@see MIN_LENGTH}
     * @throws PasswordUnchangedException When the password is the account's current one
     */
    public static function assertValid(string $newPassword, bool $matchesCurrentPassword): void
    {
        if (strlen($newPassword) < self::MIN_LENGTH) {
            throw new ValueTooShortException('Password must be at least ' . self::MIN_LENGTH . ' characters');
        }

        if ($matchesCurrentPassword) {
            throw new PasswordUnchangedException('That is already your password, choose a different one');
        }
    }
}
