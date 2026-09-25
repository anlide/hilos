<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\StepUps as ObjectStepUps;
use Hilos\Database\View\Item\StepUp;

/**
 * StepUps Db collection - operation-level confirmations (HIL-495).
 *
 * @extends DbCollection<StepUp, ObjectStepUps>
 */
final class StepUps extends DbCollection
{
    public const string DB_ITEM_CLASS = StepUp::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectStepUps::class;

    /**
     * @param string $tokenHash Hash of the browser session token
     * @param int $userId Person asking to enter the operation
     * @param string $operation Declared operation key
     * @return bool Whether the matching confirmation has not expired
     * @throws DatabaseException When the lookup fails
     * @throws InvalidArgumentException When the entity query is invalid
     */
    public function isConfirmed(string $tokenHash, int $userId, string $operation): bool
    {
        return $this->objectCollection->isConfirmed($tokenHash, $userId, $operation);
    }
}
