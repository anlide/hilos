<?php

declare(strict_types=1);

namespace Hilos\Auth\StepUp;

/**
 * Framework step-up operation keys (HIL-495).
 */
final class StepUpOperationKey
{
    public const string CHANGE_PASSWORD = 'change_password';
    public const string CHANGE_EMAIL = 'change_email';
    public const string DELETE_ACCOUNT = 'delete_account';
}
