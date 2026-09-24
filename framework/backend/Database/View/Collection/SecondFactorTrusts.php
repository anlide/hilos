<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

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
final class SecondFactorTrusts extends DbCollection
{
    public const string DB_ITEM_CLASS = SecondFactorTrust::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectSecondFactorTrusts::class;

    /**
     * Whether a browser is trusted for a person right now.
     *
     * @param int $sessionId Session row of the browser
     * @param int $userId Person asking to be let in
     * @return bool True while a trust of the pair has not run out
     * @throws DatabaseException When the lookup fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function isTrusted(int $sessionId, int $userId): bool
    {
        return $this->objectCollection->isTrusted($sessionId, $userId);
    }
}
