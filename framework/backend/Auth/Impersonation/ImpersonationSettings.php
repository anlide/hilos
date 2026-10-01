<?php

declare(strict_types=1);

namespace Hilos\Auth\Impersonation;

use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;

/**
 * ImpersonationSettings - the administrator's seven settings of impersonation (HIL-1170).
 *
 * Whether impersonation exists in the product at all, what may be done inside someone else's
 * account - only look, or act as well - whether the sign-in of that account may be touched,
 * whether the administrator carries their own admin rights inside, and whom it may take over: a
 * blocked person, a frozen person, another administrator.
 *
 * They are judged at two moments, and each setting at one of them. Whether and whom - allowed,
 * blocked, frozen, equal - at the start, by the sessions library for both ways in, the card and
 * the command line; a takeover already under way is not ended by a later switch, the way a
 * sign-in method switched off does not sign anybody out. What may be done inside - the scope,
 * the sign-in and the admin rights - on every action, by the live value.
 *
 * Every default is the behavior the product had before the settings existed, so a project whose
 * catalog does not fold {@see ImpersonationSettingsCatalog} in lives on the defaults: a key the
 * project's catalog lacks reads as its default. A stored value that cannot be read is a failure
 * of the read, not a default.
 */
final class ImpersonationSettings
{
    /** Setting key: whether impersonation exists in the product. */
    public const string ALLOWED_KEY = 'auth.impersonation.allowed';

    /** Setting key: what may be done inside someone else's account (one of the SCOPE_* values). */
    public const string SCOPE_KEY = 'auth.impersonation.scope';

    /** Setting key: whether the sign-in of the account taken over may be touched. */
    public const string ACCOUNT_ACCESS_KEY = 'auth.impersonation.account_access';

    /** Setting key: whether the administrator carries their own admin rights inside. */
    public const string CARRY_ADMIN_KEY = 'auth.impersonation.carry_admin';

    /** Setting key: whether a blocked person may be taken over. */
    public const string BLOCKED_KEY = 'auth.impersonation.blocked';

    /** Setting key: whether a frozen person may be taken over. */
    public const string FROZEN_KEY = 'auth.impersonation.frozen';

    /** Setting key: whether another administrator may be taken over. */
    public const string EQUAL_KEY = 'auth.impersonation.equal';

    /** Every key of the group, in the order the administration screen lists them. */
    public const array KEYS = [
        self::ALLOWED_KEY,
        self::SCOPE_KEY,
        self::ACCOUNT_ACCESS_KEY,
        self::CARRY_ADMIN_KEY,
        self::BLOCKED_KEY,
        self::FROZEN_KEY,
        self::EQUAL_KEY,
    ];

    /** The keys that are a yes or a no, switched on the screen rather than edited in a dialog. */
    public const array SWITCH_KEYS = [
        self::ALLOWED_KEY,
        self::ACCOUNT_ACCESS_KEY,
        self::CARRY_ADMIN_KEY,
        self::BLOCKED_KEY,
        self::FROZEN_KEY,
        self::EQUAL_KEY,
    ];

    /** Scope value: inside someone else's account the administrator only looks. */
    public const string SCOPE_VIEW = 'view';

    /** Scope value: inside someone else's account the administrator looks and acts. */
    public const string SCOPE_ACT = 'act';

    /** The values the scope key accepts. */
    public const array SCOPE_VALUES = [self::SCOPE_VIEW, self::SCOPE_ACT];

    /** Default of the allowed key: impersonation exists. */
    public const bool DEFAULT_ALLOWED = true;

    /** Default of the scope key: look and act. */
    public const string DEFAULT_SCOPE = self::SCOPE_ACT;

    /** Default of the sign-in key: the sign-in of the account taken over stays closed. */
    public const bool DEFAULT_ACCOUNT_ACCESS = false;

    /** Default of the admin-rights key: the administrator sees the product without their own rights. */
    public const bool DEFAULT_CARRY_ADMIN = false;

    /** Default of the blocked key: a blocked person may be taken over, for service work. */
    public const bool DEFAULT_BLOCKED = true;

    /** Default of the frozen key: a frozen person may be taken over. */
    public const bool DEFAULT_FROZEN = true;

    /** Default of the equal key: another administrator may be taken over. */
    public const bool DEFAULT_EQUAL = true;

    /**
     * @return bool Whether impersonation exists in the product
     * @throws DatabaseException When the stored setting cannot be read
     * @throws SettingException When the setting catalog or value is invalid
     */
    public static function isAllowed(): bool
    {
        return self::flag(self::ALLOWED_KEY, self::DEFAULT_ALLOWED);
    }

    /**
     * @return string What may be done inside someone else's account, one of {@see self::SCOPE_VALUES}
     * @throws DatabaseException When the stored setting cannot be read
     * @throws SettingException When the setting catalog or value is invalid
     */
    public static function scope(): string
    {
        if (Hilos::$setting === null || !isset(Hilos::$setting[self::SCOPE_KEY])) {
            return self::DEFAULT_SCOPE;
        }

        return Hilos::$setting[self::SCOPE_KEY]->string();
    }

    /**
     * @return bool Whether inside someone else's account the administrator only looks
     * @throws DatabaseException When the stored setting cannot be read
     * @throws SettingException When the setting catalog or value is invalid
     */
    public static function isViewOnly(): bool
    {
        return self::scope() === self::SCOPE_VIEW;
    }

    /**
     * @return bool Whether the sign-in of the account taken over may be touched
     * @throws DatabaseException When the stored setting cannot be read
     * @throws SettingException When the setting catalog or value is invalid
     */
    public static function allowsAccountAccess(): bool
    {
        return self::flag(self::ACCOUNT_ACCESS_KEY, self::DEFAULT_ACCOUNT_ACCESS);
    }

    /**
     * @return bool Whether the administrator carries their own admin rights inside
     * @throws DatabaseException When the stored setting cannot be read
     * @throws SettingException When the setting catalog or value is invalid
     */
    public static function carriesAdmin(): bool
    {
        return self::flag(self::CARRY_ADMIN_KEY, self::DEFAULT_CARRY_ADMIN);
    }

    /**
     * @return bool Whether a blocked person may be taken over
     * @throws DatabaseException When the stored setting cannot be read
     * @throws SettingException When the setting catalog or value is invalid
     */
    public static function allowsBlocked(): bool
    {
        return self::flag(self::BLOCKED_KEY, self::DEFAULT_BLOCKED);
    }

    /**
     * @return bool Whether a frozen person may be taken over
     * @throws DatabaseException When the stored setting cannot be read
     * @throws SettingException When the setting catalog or value is invalid
     */
    public static function allowsFrozen(): bool
    {
        return self::flag(self::FROZEN_KEY, self::DEFAULT_FROZEN);
    }

    /**
     * Whether another administrator may be taken over.
     *
     * While there are no roles, the admin flag is the only right in the system, so an equal is
     * another administrator; the roles epic (HIL-306) redefines it.
     *
     * @return bool Whether another administrator may be taken over
     * @throws DatabaseException When the stored setting cannot be read
     * @throws SettingException When the setting catalog or value is invalid
     */
    public static function allowsEqual(): bool
    {
        return self::flag(self::EQUAL_KEY, self::DEFAULT_EQUAL);
    }

    /**
     * @param string $key Yes-or-no key of the group
     * @param bool $default Value when the project's catalog lacks the key
     * @return bool The value in force
     * @throws DatabaseException When the stored setting cannot be read
     * @throws SettingException When the setting catalog or value is invalid
     */
    private static function flag(string $key, bool $default): bool
    {
        if (Hilos::$setting === null || !isset(Hilos::$setting[$key])) {
            return $default;
        }

        return Hilos::$setting[$key]->bool();
    }
}
