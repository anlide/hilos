<?php

declare(strict_types=1);

namespace Hilos\Database\Settings\Exception;

use Hilos\Core\Exception\ValidationException;
use Hilos\Database\Settings\Validation\SettingValueRules;

/**
 * Thrown when a setting's catalog rule refuses the value somebody typed (HIL-779).
 *
 * The text this carries is written by the rule for the administrator who is looking at the
 * field - "Value must be an integer of 0 or more" - so it belongs to the
 * {@see ValidationException} family, whose message the wire gate lets through untouched.
 *
 * Its sibling {@see SettingInvalidValueException} stays outside that family and keeps
 * everything else the settings layer raises: a catalog type that cannot convert a stored
 * value, an accessor that is not there, a key that is not declared. Those describe a broken
 * installation rather than an answer to what was typed, and reading them would tell an
 * administrator about the backend's insides instead of about their own input.
 *
 * @see SettingValueRules::assertValid() The one place a rule refuses
 */
class SettingValueRefusedException extends ValidationException
{
}
