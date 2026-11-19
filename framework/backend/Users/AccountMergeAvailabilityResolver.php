<?php

declare(strict_types=1);

namespace Hilos\Users;

use Hilos\Auth\AccountOperation\AccountMergePreparation;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Hilos;
use Hilos\HilosException;
use JsonException;

/** Reads durable occupancy through the mounted collections and the existing reservation guard. */
final class AccountMergeAvailabilityResolver
{
    /**
     * @param int $userId Account whose occupancy is requested
     * @return AccountMergeAvailability Current facts, both false when the account is missing
     * @throws HilosException When a durable fact cannot be read or has an invalid shape
     */
    public static function of(int $userId): AccountMergeAvailability
    {
        if (!isset(Hilos::$db->users[$userId])) {
            return new AccountMergeAvailability(false, false);
        }
        try {
            $reserved = AccountMergePreparation::heldBy($userId) !== null;
        } catch (JsonException $failure) {
            throw new InvalidFormatException('The saved merge reservation proof cannot be decoded.', previous: $failure);
        }

        return new AccountMergeAvailability(
            $reserved,
            !isset(Hilos::$db->userMerges[$userId])
                && Hilos::$db->accountDeletions->startedOf($userId) === null
                && Hilos::$db->accountOperations->openErasureCovering($userId) === null
                && !$reserved,
        );
    }
}
