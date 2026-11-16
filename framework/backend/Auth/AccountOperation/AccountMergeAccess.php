<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

use Hilos\Core\Exception\ValidationException;
use Hilos\Database\View\Item\AccountOperation;
use Hilos\HilosException;
use JsonException;

/** Access to protection and sign-in follows the saved reservation and the loser's give result. */
final class AccountMergeAccess
{
    public const string REFUSED_MESSAGE = 'This account is being merged. Please try again later.';

    /**
     * @param int $userId Account whose reservation is read
     * @return ?AccountOperation Open merge holding this account's unreleased reservation
     * @throws HilosException When the reservation cannot be read or has an invalid shape
     * @throws JsonException When a saved preparation or release cannot be decoded
     */
    public static function reservation(int $userId): ?AccountOperation
    {
        return AccountMergePreparation::heldBy($userId);
    }

    /**
     * @param int $userId Account whose protection would change
     * @throws ValidationException When a merge holds the account's protection
     * @throws HilosException When the reservation cannot be read or has an invalid shape
     * @throws JsonException When a saved preparation or release cannot be decoded
     */
    public static function requireProtectionChange(int $userId): void
    {
        if (self::reservation($userId) !== null) {
            throw new ValidationException(self::REFUSED_MESSAGE);
        }
    }

    /**
     * @param int $userId Account being signed in
     * @return bool Whether this reserved survivor has the loser's saved give result
     * @throws HilosException When the reservation or merge fact cannot be read or has an invalid shape
     * @throws JsonException When a saved preparation or release cannot be decoded
     */
    public static function blocksSignIn(int $userId): bool
    {
        $operation = self::reservation($userId);

        return $operation !== null && $userId === $operation->survivorUserId && $operation->mergeFact() !== null;
    }

    /**
     * @param int $userId Account being signed in
     * @throws ValidationException When the reserved survivor's protection is not ready after the merge fact
     * @throws HilosException When the reservation or merge fact cannot be read or has an invalid shape
     * @throws JsonException When a saved preparation or release cannot be decoded
     */
    public static function requireSignIn(int $userId): void
    {
        if (self::blocksSignIn($userId)) {
            throw new ValidationException(self::REFUSED_MESSAGE);
        }
    }
}
