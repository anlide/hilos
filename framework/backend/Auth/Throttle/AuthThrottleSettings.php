<?php

declare(strict_types=1);

namespace Hilos\Auth\Throttle;

use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;

/**
 * Reads the live grace the guarded doors give a silent throttle agent (HIL-1280).
 *
 * The one number of the layer that is a setting rather than an environment value: an
 * administrator decides how long the doors may run unguarded, and the decision is in force on
 * every node at once.
 */
final class AuthThrottleSettings
{
    public const string OUTAGE_GRACE_SECONDS_KEY = 'auth.throttle.outage_grace_seconds';
    public const int DEFAULT_OUTAGE_GRACE_SECONDS = 5;

    /**
     * @return int Seconds of silence the guarded doors still run through, zero or more
     * @throws DatabaseException When the stored setting cannot be read
     * @throws SettingException When the catalog or stored value is invalid
     */
    public static function outageGraceSeconds(): int
    {
        $settings = Hilos::$setting;
        if ($settings === null || !isset($settings[self::OUTAGE_GRACE_SECONDS_KEY])) {
            return self::DEFAULT_OUTAGE_GRACE_SECONDS;
        }

        return max(0, $settings[self::OUTAGE_GRACE_SECONDS_KEY]->int());
    }
}
