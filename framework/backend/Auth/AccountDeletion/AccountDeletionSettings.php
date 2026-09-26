<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountDeletion;

use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;

/**
 * AccountDeletionSettings - the administrator's grace period of account deletion (HIL-302).
 *
 * A person who asks to delete their account waits this many days before the account is
 * erased, and can call it off until then. The moment is fixed when the request is made, so
 * changing the setting moves only the requests made after it.
 */
final class AccountDeletionSettings
{
    /** Setting key: days between a deletion request and the erasure. */
    public const string GRACE_DAYS_KEY = 'auth.account_deletion.grace_days';

    /** Grace period an installation starts on, in days. */
    public const int DEFAULT_GRACE_DAYS = 30;

    /** Shortest grace period an administrator may set, in days - a deletion is never instant. */
    public const int MIN_GRACE_DAYS = 1;

    /** Longest grace period an administrator may set, in days. */
    public const int MAX_GRACE_DAYS = 365;

    /**
     * The grace period a request made now waits out, in days.
     *
     * No settings accessor, or a catalog without the key, answers the default. A stored value
     * is held within the bounds: the rule refuses every write outside them, and a row written
     * past the rule must still not make a deletion instant.
     *
     * @return int Days from the request to the erasure
     * @throws DatabaseException When the stored setting cannot be read
     * @throws SettingException When the setting catalog or value is invalid
     */
    public static function graceDays(): int
    {
        $settings = Hilos::$setting;
        if ($settings === null || !isset($settings[self::GRACE_DAYS_KEY])) {
            return self::DEFAULT_GRACE_DAYS;
        }

        return max(self::MIN_GRACE_DAYS, min(self::MAX_GRACE_DAYS, $settings[self::GRACE_DAYS_KEY]->int()));
    }
}
