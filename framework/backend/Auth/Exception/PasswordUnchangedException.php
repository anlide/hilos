<?php

declare(strict_types=1);

namespace Hilos\Auth\Exception;

use Hilos\Auth\PasswordPolicy;
use Hilos\Core\Exception\ValidationException;

/**
 * Thrown when the password offered is the one the account already has (HIL-654).
 *
 * Changing a password is not editing a field, it is revoking a secret, and accepting the
 * old value told a person they had done that when they had not. Its own class, and not the
 * bare {@see ValidationException} the length refusal used to be, for one reason: the
 * recovery door has to tell this refusal from every other the seam can raise, because this
 * is the only one it answers as an ordinary reply of the form rather than as a broken
 * client contract. Nothing else catches it - the profile lets it out through the same
 * action_error channel that carries "Current password is incorrect".
 *
 * A {@see ValidationException} child, so the doors and the tests that catch the family go
 * on catching it and the sentence still reaches the person who typed the password.
 *
 * @see PasswordPolicy::assertValid() Where it is raised
 */
class PasswordUnchangedException extends ValidationException
{
}
