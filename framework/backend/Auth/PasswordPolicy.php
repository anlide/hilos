<?php

declare(strict_types=1);

namespace Hilos\Auth;

/**
 * The password rule every surface of one installation enforces (HIL-622).
 *
 * One value, and deliberately one: registration, a password reset and the profile's
 * add-password all judge the same secret for the same account, so a minimum that differed
 * between them would only ever mean a password accepted at one door and refused at the
 * next. It moved into the framework with the sign-in commands, which is where the first
 * two of those three now live.
 */
final class PasswordPolicy
{
    /** @var int Minimum accepted password length, in characters */
    public const int MIN_LENGTH = 8;
}
