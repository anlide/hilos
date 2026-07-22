<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

/**
 * PasswordPolicy - the chat demo's password rules, shared across auth surfaces.
 *
 * Lifted from a private MainPage const (HIL-162/164) so the profile add/change
 * password flow (HIL-402) enforces the same minimum length as registration and
 * password reset without duplicating the value.
 */
final class PasswordPolicy
{
    /** @var int Minimum accepted password length, in characters */
    public const int MIN_LENGTH = 8;
}
