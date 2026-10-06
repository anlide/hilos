<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\LogicException;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\SecondFactorTrusts as ObjectSecondFactorTrusts;
use Hilos\Database\View\Item\SecondFactorTrust;

/**
 * SecondFactorTrusts Db collection - "don't ask again on this device" (HIL-494).
 *
 * Read-facing representation of the framework-owned hilos_second_factor_trust table; the
 * one question asked of it is {@see isTrusted()}.
 *
 * @extends DbCollection<SecondFactorTrust, ObjectSecondFactorTrusts>
 */
class SecondFactorTrusts extends DbCollection
{
    public const string DB_ITEM_CLASS = SecondFactorTrust::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectSecondFactorTrusts::class;

    /**
     * Reads the merge's two sets under a nonwaiting row lock and drops stale wrappers.
     * Called only inside the merge transaction, before any ordinary reads.
     *
     * @param int $survivorId Surviving account
     * @param int $loserId Folded account
     * @throws DatabaseException When a set is busy or its rows cannot be locked
     * @throws LogicException When the collection class constants are not configured
     */
    public function lockForMerge(int $survivorId, int $loserId): void
    {
        $this->objectCollection->lockForMerge($survivorId, $loserId);
        $this->clearCache();
    }

    /**
     * Whether a browser is trusted for a person right now.
     *
     * @param int $sessionId Session row of the browser
     * @param int $userId Person asking to be let in
     * @param int $allowedDays Current policy limit in days
     * @return bool True while a trust of the pair has not run out
     * @throws DatabaseException When the lookup fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function isTrusted(int $sessionId, int $userId, int $allowedDays): bool
    {
        return $this->objectCollection->isTrusted($sessionId, $userId, $allowedDays);
    }
}
