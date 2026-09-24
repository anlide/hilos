<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

use Hilos\Constants\TimeConstants;
use Hilos\Hilos;
use Hilos\HilosException;

/**
 * How long a removal of a person's second factor waits, from their own choice and the bounds (HIL-494).
 *
 * The person's choice is kept in two parts ({@see SecondFactorSettings} per person): the wait
 * in force, and a shorter one parked until the moment the wait in force when it was asked for
 * runs out. Lengthening applies at once, shortening only after the current wait - otherwise a
 * stolen session would shorten the wait to a day and ask for the removal at once.
 *
 * Pure: every input is passed in, so the rule reads the same in the profile, in the request and
 * in a test.
 */
final readonly class SecondFactorResetWait
{
    /**
     * @param ?int $days The person's wait in force, or null for the administrator's default
     * @param ?int $pendingDays A shorter wait parked, or null when none is
     * @param ?int $pendingFromSec Moment the parked wait takes over (Unix seconds), or null
     */
    public function __construct(
        public ?int $days,
        public ?int $pendingDays,
        public ?int $pendingFromSec,
    ) {
    }

    /**
     * Reads a person's stored choice.
     *
     * @param int $userId Person
     * @return self The choice as stored, or the administrator's default when the person made none
     * @throws HilosException When the lookup fails
     */
    public static function of(int $userId): self
    {
        $setting = Hilos::$db->secondFactorSettings[$userId];
        $from = $setting?->pendingResetWaitFrom;

        return new self(
            $setting?->resetWaitDays,
            $setting?->pendingResetWaitDays,
            $from === null ? null : (int)strtotime($from),
        );
    }

    /**
     * The wait in force now, in days, held within the administrator's bounds.
     *
     * @param SecondFactorPolicy $policy Administrator's bounds and default
     * @param int $nowSec Current moment (Unix seconds)
     * @return int Days a removal asked for now waits
     */
    public function effectiveDays(SecondFactorPolicy $policy, int $nowSec): int
    {
        $days = $this->pendingDays !== null && $this->pendingFromSec !== null && $this->pendingFromSec <= $nowSec
            ? $this->pendingDays
            : ($this->days ?? $policy->resetWaitDefaultDays);

        return max(
            SecondFactorSettings::RESET_WAIT_FLOOR_DAYS,
            min(max($days, $policy->resetWaitMinDays), $policy->resetWaitMaxDays),
        );
    }

    /**
     * The choice after the person asks for a new wait.
     *
     * Longer or equal applies at once and drops whatever was parked; shorter is parked until the
     * wait in force now would have run out.
     *
     * @param int $requestedDays Wait the person asked for, already within the bounds
     * @param SecondFactorPolicy $policy Administrator's bounds and default
     * @param int $nowSec Current moment (Unix seconds)
     * @return self The choice to store
     */
    public function withRequested(int $requestedDays, SecondFactorPolicy $policy, int $nowSec): self
    {
        $current = $this->effectiveDays($policy, $nowSec);
        if ($requestedDays >= $current) {
            return new self($requestedDays, null, null);
        }

        return new self($current, $requestedDays, $nowSec + $current * TimeConstants::SECONDS_PER_DAY);
    }
}
