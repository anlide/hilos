<?php

declare(strict_types=1);

namespace Hilos\Auth\StepUp;

/**
 * User-facing step-up refusal text (HIL-495).
 */
final class StepUpMessages
{
    public const string IMPERSONATED = "This is not available while you work in someone else's account";
    public const string EXPIRED = 'Your confirmation has expired. Close this window and start again.';
    public const string NOTHING_TO_CONFIRM_WITH = 'Add a password, an email or a phone to your account to do this';
    public const string UNKNOWN_OPERATION = 'Unknown operation';
    public const string PASSKEY_NOT_CONFIRMED = 'The device key was not confirmed';
}
