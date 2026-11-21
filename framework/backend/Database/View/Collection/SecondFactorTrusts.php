<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\SecondFactorTrusts as ObjectSecondFactorTrusts;
use Hilos\Database\View\Item\SecondFactorTrust;
use Hilos\HilosException;

/**
 * SecondFactorTrusts Db collection - "don't ask again on this device" (HIL-494).
 *
 * Read-facing representation of the framework-owned hilos_second_factor_trust table; the
 * one question asked of it is {@see isTrusted()}.
 *
 * @extends DbCollection<SecondFactorTrust, ObjectSecondFactorTrusts>
 * @method ObjectSecondFactorTrusts|null getObjectCollection()
 * @method SecondFactorTrust|null first()
 * @method SecondFactorTrust|null last()
 * @method SecondFactorTrust offsetGet(mixed $offset)
 */
class SecondFactorTrusts extends DbCollection
{
    public const string DB_ITEM_CLASS = SecondFactorTrust::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectSecondFactorTrusts::class;

    /**
     * Whether a browser is trusted for a person right now.
     *
     * @param int $sessionId Session row of the browser
     * @param int $userId Person asking to be let in
     * @param int $allowedDays Current policy limit in days
     * @return bool True while a trust of the pair has not run out and no restriction saved since its grant has ended it
     * @throws DatabaseException When a lookup fails
     * @throws InvalidArgumentException When an entity query is given an invalid order direction
     * @throws HilosException When the trust restrictions cannot be read
     */
    public function isTrusted(int $sessionId, int $userId, int $allowedDays): bool
    {
        return $this->objectCollection->isTrusted($sessionId, $userId, $allowedDays);
    }
}
