<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

use Hilos\Database\DatabaseException;
use Hilos\Database\Settings\Exception\SettingException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Users\AdminAudience;

/**
 * The administrator's second-factor settings as they stand, read once for one decision (HIL-494).
 *
 * Built by {@see current()} from the six settings ({@see SecondFactorSettings}); the sign-in
 * gate, the profile section and the reset read the same instance shape, so "who must enrol",
 * "how long a trust lasts" and "how long a removal waits" have one reading each.
 */
final readonly class SecondFactorPolicy
{
    /**
     * @param string $required Who must use a second factor (a SecondFactorSettings::REQUIRED_* value)
     * @param int $trustDays Days a trusted browser skips the step; 0 offers no trust
     * @param int $backupCodes Backup codes in one issued set
     * @param int $resetWaitDefaultDays Removal wait a person starts on, in days
     * @param int $resetWaitMinDays Shortest removal wait a person may choose, in days
     * @param int $resetWaitMaxDays Longest removal wait a person may choose, in days
     */
    public function __construct(
        public string $required,
        public int $trustDays,
        public int $backupCodes,
        public int $resetWaitDefaultDays,
        public int $resetWaitMinDays,
        public int $resetWaitMaxDays,
    ) {
    }

    /**
     * Reads the six settings as they stand now.
     *
     * A project whose catalog carries none of them - no settings accessor, or a catalog that does
     * not fold the fragment in - is on the defaults, which require nobody.
     *
     * @return self The policy in force
     * @throws SettingException When a setting's catalog entry or stored value is invalid
     * @throws DatabaseException When a stored value cannot be read
     */
    public static function current(): self
    {
        $settings = Hilos::$setting;
        if ($settings === null || !isset($settings[SecondFactorSettings::REQUIRED_KEY])) {
            return self::defaults();
        }

        return new self(
            $settings[SecondFactorSettings::REQUIRED_KEY]->string(),
            $settings[SecondFactorSettings::TRUST_DAYS_KEY]->int(),
            $settings[SecondFactorSettings::BACKUP_CODES_KEY]->int(),
            $settings[SecondFactorSettings::RESET_WAIT_DEFAULT_DAYS_KEY]->int(),
            $settings[SecondFactorSettings::RESET_WAIT_MIN_DAYS_KEY]->int(),
            $settings[SecondFactorSettings::RESET_WAIT_MAX_DAYS_KEY]->int(),
        );
    }

    /**
     * The policy an installation starts with - the catalog defaults.
     *
     * @return self Default policy
     */
    public static function defaults(): self
    {
        return new self(
            SecondFactorSettings::REQUIRED_NONE,
            SecondFactorSettings::DEFAULT_TRUST_DAYS,
            SecondFactorSettings::DEFAULT_BACKUP_CODES,
            SecondFactorSettings::DEFAULT_RESET_WAIT_DAYS,
            SecondFactorSettings::DEFAULT_RESET_WAIT_MIN_DAYS,
            SecondFactorSettings::DEFAULT_RESET_WAIT_MAX_DAYS,
        );
    }

    /**
     * Whether this person has to use a second factor.
     *
     * "Administrators" is the project's own answer ({@see AdminAudience}): the framework does
     * not know what makes a user an administrator, and a project that declares nobody requires
     * nobody under that setting.
     *
     * @param int $userId Person asking to be let in
     * @return bool True when the policy requires a second factor of this person
     * @throws HilosException When the project cannot say who its administrators are
     */
    public function requiresFor(int $userId): bool
    {
        return match ($this->required) {
            SecondFactorSettings::REQUIRED_EVERYONE => true,
            SecondFactorSettings::REQUIRED_ADMINS => in_array($userId, Hilos::adminAudienceClass()::all(), true),
            default => false,
        };
    }

    /**
     * Whether the policy requires a second factor of anybody at all.
     *
     * @return bool True unless nobody is required to use one
     */
    public function requiresAnybody(): bool
    {
        return $this->required !== SecondFactorSettings::REQUIRED_NONE;
    }

    /**
     * Days the "don't ask again on this device" checkbox promises, or null when it is not offered.
     *
     * @return ?int Trust days, or null when the administrator offers no trust
     */
    public function trustDeviceDays(): ?int
    {
        return $this->trustDays > 0 ? $this->trustDays : null;
    }
}
