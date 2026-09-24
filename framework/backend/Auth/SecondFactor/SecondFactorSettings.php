<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;

/**
 * SecondFactorSettings - the administrator's six settings of the second factor (HIL-494).
 *
 * Who must use a second factor, how long a browser may be trusted to skip it, how many
 * backup codes a set holds, and the bounds of the wait before a removal: the default a
 * person starts on and the floor and ceiling a person's own choice stays between. Each key
 * names its rule ({@see SecondFactorSettingsCatalog}), and the three wait keys also hold
 * each other: 1 <= minimum <= default <= maximum <= 365.
 *
 * The floor of the wait is hard even for an administrator: a zero-day wait would make a
 * removal instant, and the second factor would protect against nothing a mailbox can do.
 * The ceiling exists so that no account can be made unrecoverable for good.
 */
final class SecondFactorSettings
{
    /** Setting key: who must use a second factor (one of the REQUIRED_* values). */
    public const string REQUIRED_KEY = 'auth.second_factor.required';

    /** Setting key: days a browser may be trusted to skip the step; 0 offers no trust at all. */
    public const string TRUST_DAYS_KEY = 'auth.second_factor.trust_days';

    /** Setting key: backup codes in one issued set. */
    public const string BACKUP_CODES_KEY = 'auth.second_factor.backup_codes';

    /** Setting key: the removal wait a person starts on, in days. */
    public const string RESET_WAIT_DEFAULT_DAYS_KEY = 'auth.second_factor.reset_wait_default_days';

    /** Setting key: the shortest removal wait a person may choose, in days. */
    public const string RESET_WAIT_MIN_DAYS_KEY = 'auth.second_factor.reset_wait_min_days';

    /** Setting key: the longest removal wait a person may choose, in days. */
    public const string RESET_WAIT_MAX_DAYS_KEY = 'auth.second_factor.reset_wait_max_days';

    /** Every key of the group, in the order the administration screen lists them. */
    public const array KEYS = [
        self::REQUIRED_KEY,
        self::TRUST_DAYS_KEY,
        self::BACKUP_CODES_KEY,
        self::RESET_WAIT_DEFAULT_DAYS_KEY,
        self::RESET_WAIT_MIN_DAYS_KEY,
        self::RESET_WAIT_MAX_DAYS_KEY,
    ];

    /** Required value: nobody has to use a second factor. */
    public const string REQUIRED_NONE = 'none';

    /** Required value: administrators have to. */
    public const string REQUIRED_ADMINS = 'admins';

    /** Required value: everybody has to. */
    public const string REQUIRED_EVERYONE = 'everyone';

    /** The values the required key accepts. */
    public const array REQUIRED_VALUES = [self::REQUIRED_NONE, self::REQUIRED_ADMINS, self::REQUIRED_EVERYONE];

    /** Default days a trusted browser skips the step. */
    public const int DEFAULT_TRUST_DAYS = 30;

    /** Longest trust an administrator may set, in days. */
    public const int TRUST_DAYS_MAX = 365;

    /** Default backup codes in one set. */
    public const int DEFAULT_BACKUP_CODES = 10;

    /** Fewest backup codes a set may hold. */
    public const int BACKUP_CODES_MIN = 1;

    /** Most backup codes a set may hold. */
    public const int BACKUP_CODES_MAX = 20;

    /** Default removal wait a person starts on, in days. */
    public const int DEFAULT_RESET_WAIT_DAYS = 8;

    /** Default shortest removal wait a person may choose, in days. */
    public const int DEFAULT_RESET_WAIT_MIN_DAYS = 1;

    /** Default longest removal wait a person may choose, in days. */
    public const int DEFAULT_RESET_WAIT_MAX_DAYS = 30;

    /** Hard floor of every removal wait, in days - no setting goes below it. */
    public const int RESET_WAIT_FLOOR_DAYS = 1;

    /** Hard ceiling of every removal wait, in days - no setting goes above it. */
    public const int RESET_WAIT_CEILING_DAYS = 365;

    /**
     * Reads a setting value as a whole number, the way the integer write paths store it.
     *
     * @param mixed $value Value about to be written
     * @return ?int The whole number, or null when the value is not one
     */
    public static function wholeNumber(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int)$value;
        }

        return null;
    }

    /**
     * Reads one of the integer keys of the group as it stands now.
     *
     * The three wait bounds judge each other by this. A bound that cannot be read - no settings
     * accessor carries the key, or the stored value cannot be read as a number - answers the
     * fallback, the value that refuses nothing on its side: the check is a courtesy to the
     * administrator, and a write whose database cannot be read fails at the write anyway.
     *
     * @param string $key Setting key of the group
     * @param int $fallback Value to judge by when the key cannot be read
     * @return int The stored value, the catalog default when none is stored, or the fallback
     */
    public static function storedDays(string $key, int $fallback): int
    {
        $settings = Hilos::$setting;
        if ($settings === null || !isset($settings[$key])) {
            return $fallback;
        }

        try {
            return $settings[$key]->int();
        } catch (SettingException | DatabaseException) {
            return $fallback;
        }
    }
}
