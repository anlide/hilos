<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor\DTO;

use Hilos\Auth\SecondFactor\SecondFactorPolicy;
use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;

/**
 * The administrator's second-factor settings, sent to every connection when they change (HIL-494).
 *
 * The profile section draws its bounds and its "required" note from these, and the code step
 * its trust checkbox, so a change on the administration screen reaches every open surface
 * without a reload - the way a change of sign-in methods does.
 */
final class SecondFactorPolicySignalData extends BaseDTO implements SignalDataInterface
{
    public const string required = 'required';
    public const string trustDays = 'trustDays';
    public const string backupCodes = 'backupCodes';
    public const string resetWaitDefaultDays = 'resetWaitDefaultDays';
    public const string resetWaitMinDays = 'resetWaitMinDays';
    public const string resetWaitMaxDays = 'resetWaitMaxDays';

    /**
     * @param string $required Who must use a second factor (a SecondFactorSettings::REQUIRED_* value)
     * @param int $trustDays Days a trusted browser skips the step; 0 offers no trust
     * @param int $backupCodes Backup codes in one issued set
     * @param int $resetWaitDefaultDays Removal wait a person starts on
     * @param int $resetWaitMinDays Shortest removal wait a person may choose
     * @param int $resetWaitMaxDays Longest removal wait a person may choose
     */
    public function __construct(
        public readonly string $required,
        public readonly int $trustDays,
        public readonly int $backupCodes,
        public readonly int $resetWaitDefaultDays,
        public readonly int $resetWaitMinDays,
        public readonly int $resetWaitMaxDays,
    ) {
    }

    /**
     * Builds the signal from the policy in force.
     *
     * @param SecondFactorPolicy $policy Policy to send
     * @return self Signal payload
     */
    public static function of(SecondFactorPolicy $policy): self
    {
        return new self(
            $policy->required,
            $policy->trustDays,
            $policy->backupCodes,
            $policy->resetWaitDefaultDays,
            $policy->resetWaitMinDays,
            $policy->resetWaitMaxDays,
        );
    }

    /**
     * @return array<string, mixed> Wire form
     */
    public function toArray(): array
    {
        return [
            self::required => $this->required,
            self::trustDays => $this->trustDays,
            self::backupCodes => $this->backupCodes,
            self::resetWaitDefaultDays => $this->resetWaitDefaultDays,
            self::resetWaitMinDays => $this->resetWaitMinDays,
            self::resetWaitMaxDays => $this->resetWaitMaxDays,
        ];
    }

    /**
     * @param array<string, mixed> $data Wire form
     * @return static Restored payload
     * @throws InvalidFormatException When a member is absent or of another type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, self::required),
            self::requireInt($data, self::trustDays),
            self::requireInt($data, self::backupCodes),
            self::requireInt($data, self::resetWaitDefaultDays),
            self::requireInt($data, self::resetWaitMinDays),
            self::requireInt($data, self::resetWaitMaxDays),
        );
    }
}
