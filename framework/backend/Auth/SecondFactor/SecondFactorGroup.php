<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

use Hilos\Constants\HilosSignalConstants;

/**
 * SecondFactorGroup - the per-person WebSocket group the profile's second-factor section listens on (HIL-494).
 *
 * The person's connections that show the section are addressed by group name, the way the
 * notification center addresses a recipient: the connection joins when it subscribes to the
 * profile's security page, and every write to the person's second factor - from any tab, any
 * browser, or the removal sweep - is fanned here as the section's new state
 * ({@see HilosSignalConstants::HILOS_SECOND_FACTOR_STATE}).
 */
final class SecondFactorGroup
{
    /** Name the group answers to, and the head of every full name. */
    public const string NAME = 'hilos_second_factor';

    /** Group-name prefix; the person's user id is appended. */
    public const string PREFIX = self::NAME . ':';

    /**
     * Builds the group name of a person.
     *
     * @param int $userId Person whose section it is
     * @return string Group name the person's connections join
     */
    public static function forUser(int $userId): string
    {
        return self::PREFIX . $userId;
    }
}
