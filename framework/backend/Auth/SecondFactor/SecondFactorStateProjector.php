<?php

declare(strict_types=1);

namespace Hilos\Auth\SecondFactor;

use Hilos\Auth\SecondFactor\DTO\SecondFactorStateSignalData;
use Hilos\Constants\TimeConstants;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * Builds the profile's second-factor section of one person (HIL-494).
 *
 * The one place the section is put together, so the security page's first render and every
 * change fanned to the person's group say the same thing: the confirmed apps, how many backup
 * codes are left of the set, whether the administrator requires a factor, the person's removal
 * wait with the bounds around it, and the removal that stands, if any.
 */
final class SecondFactorStateProjector
{
    /**
     * Builds the section.
     *
     * @param int $userId Person whose section it is
     * @return SecondFactorStateSignalData The section
     * @throws HilosException When a lookup or a setting read fails
     */
    public static function stateFor(int $userId): SecondFactorStateSignalData
    {
        $policy = SecondFactorPolicy::current();
        $wait = SecondFactorResetWait::of($userId);
        $now = time();

        $authenticators = [];
        foreach (Hilos::$db->secondFactors->confirmedOf($userId) as $factor) {
            $id = $factor->id;
            if ($id === null) {
                continue;
            }
            $lastUsedAt = $factor->lastUsedAt;
            $authenticators[] = [
                SecondFactorStateSignalData::id => $id,
                SecondFactorStateSignalData::label => $factor->label,
                SecondFactorStateSignalData::createdAt => TimeHelper::sqlToMs($factor->createdAt),
                SecondFactorStateSignalData::lastUsedAt => $lastUsedAt === null ? null : TimeHelper::sqlToMs($lastUsedAt),
            ];
        }

        $codes = Hilos::$db->secondFactorBackupCodes->listByUser($userId);
        $left = count(array_filter($codes, static fn ($code): bool => $code->usedAt === null));
        $reset = Hilos::$db->secondFactorResets->liveOf($userId);
        $pendingActive = $wait->pendingDays !== null && $wait->pendingFromSec !== null && $wait->pendingFromSec > $now;

        return new SecondFactorStateSignalData(
            $authenticators,
            $left,
            count($codes),
            $policy->requiresFor($userId),
            [
                SecondFactorStateSignalData::days => $wait->effectiveDays($policy, $now),
                SecondFactorStateSignalData::pendingDays => $pendingActive ? $wait->pendingDays : null,
                SecondFactorStateSignalData::pendingFrom => $pendingActive
                    ? (int)$wait->pendingFromSec * TimeConstants::MS_PER_SECOND
                    : null,
                SecondFactorStateSignalData::defaultDays => $policy->resetWaitDefaultDays,
                SecondFactorStateSignalData::minDays => $policy->resetWaitMinDays,
                SecondFactorStateSignalData::maxDays => $policy->resetWaitMaxDays,
            ],
            $reset === null ? null : [
                SecondFactorStateSignalData::requestedAt => TimeHelper::sqlToMs($reset->requestedAt),
                SecondFactorStateSignalData::effectiveAt => TimeHelper::sqlToMs($reset->effectiveAt),
            ],
        );
    }
}
