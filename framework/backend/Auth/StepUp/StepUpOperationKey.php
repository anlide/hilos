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

    /** An administrator merging another account into the one on a person's card (HIL-1275). */
    public const string MERGE_ACCOUNTS = 'merge_accounts';

    /** An administrator granting another person administrator rights (HIL-1275). */
    public const string GRANT_ADMIN = 'grant_admin';

    /** An administrator removing another person's administrator rights (HIL-1275). */
    public const string REVOKE_ADMIN = 'revoke_admin';

    /** An administrator blocking another person's account (HIL-1275). */
    public const string BLOCK_ACCOUNT = 'block_account';

    /** An administrator scheduling the deletion of another person's account (HIL-1275). */
    public const string DELETE_OTHER_ACCOUNT = 'delete_other_account';

    /** An administrator starting to act inside another person's account, from the person's card (HIL-1170). */
    public const string IMPERSONATE = 'impersonate';
}
