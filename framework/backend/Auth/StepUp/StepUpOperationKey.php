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
    public const string EXPORT_DATA = 'export_data';

    /** Connecting an authenticator app to one's own account (HIL-1138). */
    public const string ADD_AUTHENTICATOR_APP = 'add_authenticator_app';

    /** Adding a way in - a password, a phone, a device key, a provider - to one's own account (HIL-1138). */
    public const string ADD_SIGN_IN_METHOD = 'add_sign_in_method';
}
