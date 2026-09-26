<?php

declare(strict_types=1);

namespace Hilos\Auth\Exception;

use Hilos\Core\Exception\ValidationException;

/**
 * The proposed password is in the framework's list of common breached passwords (HIL-650).
 * Registration and recovery convert this refusal to an ordinary action reply;
 * the profile propagates the validation failure through its action-error channel.
 */
class PasswordTooCommonException extends ValidationException
{
}
