<?php

declare(strict_types=1);

namespace Hilos\Auth\Impersonation;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\AbstractUsersLibraryAgent;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Pages\AbstractHilosProfileSignInPage;

/**
 * The actions that touch the sign-in of an account: closed inside a takeover unless the administrator allowed it (HIL-1170).
 *
 * One list of the framework's, not a constant on each owner: signing in is the framework's
 * surface, so a project has nothing to add. What is here is the password, the address, the ways
 * in - adding and removing a password, a phone, a device key or a provider - the second factor
 * whole, and ending the person's other sessions. They come from the users library
 * ({@see AbstractUsersLibraryAgent}), the sessions library ({@see AbstractSessionsLibraryAgent})
 * and the sign-in methods page of the profile ({@see AbstractHilosProfileSignInPage}).
 *
 * Deleting the account and accepting the terms are not here: what a person does with their own
 * account is never done with someone else's hands, so those refuse every takeover whatever the
 * setting says, at their own commands.
 */
final class ImpersonationAccountAccess
{
    /** Names of the actions that touch the sign-in of the account. */
    public const array ACTIONS = [
        HilosSignalConstants::HILOS_LINK_OAUTH_START,
        HilosSignalConstants::HILOS_LINK_OAUTH_AFTER_REAUTH,
        HilosSignalConstants::HILOS_PASSKEY_REGISTER_OPTIONS,
        HilosSignalConstants::HILOS_PASSKEY_REGISTER_CONFIRM,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_CONFIRM,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_REMOVE,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_SHOW,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_RENEW,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_WAIT_SET,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_REQUEST,
        HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_CANCEL,
        HilosSignalConstants::PROFILE_SET_PASSWORD,
        HilosSignalConstants::PROFILE_UNLINK_IDENTITY,
        HilosSignalConstants::PROFILE_ADD_SMS_REQUEST,
        HilosSignalConstants::PROFILE_ADD_SMS_CONFIRM,
        HilosSignalConstants::PROFILE_ADD_PASSWORD_REQUEST,
        HilosSignalConstants::PROFILE_ADD_PASSWORD_CONFIRM,
        HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_REQUEST,
        HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_CONFIRM,
        HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_REQUEST,
        HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_CONFIRM,
        HilosSignalConstants::PROFILE_CHANGE_PASSWORD_OPEN,
        HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_REQUEST,
        HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_CONFIRM,
        HilosSignalConstants::PROFILE_CHANGE_PASSWORD,
        HilosSignalConstants::HILOS_SESSION_END,
        HilosSignalConstants::HILOS_SESSIONS_END_OTHERS,
    ];
}
