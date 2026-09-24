<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Actions\Exception\UnknownLazyStrategyException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\SecondFactorBackupCode as ObjectSecondFactorBackupCode;
use Hilos\Database\View\Item\SecondFactorBackupCode;

/**
 * SecondFactorBackupCodeActions - write operations for one backup code (HIL-494).
 *
 * @extends DbActions<SecondFactorBackupCode, ObjectSecondFactorBackupCode>
 * @property-read ObjectSecondFactorBackupCode $object
 */
final class SecondFactorBackupCodeActions extends DbActions
{
    /**
     * Burns this code, if nobody burned it first.
     *
     * @return bool True when this call burned the code, false when it was used already
     * @throws ObjectCollectionNullException When the action is detached from its object collection
     * @throws ObjectGetIdStringNotImplementedException When the code row cannot expose its id string
     * @throws UnknownLazyStrategyException When the collection has an unsupported lazy strategy
     * @throws LogicException When the object collection entity class is not configured
     * @throws WriteNotAllowedException When the truth source rejects the update
     * @throws CreateNotAllowedException Never for a persisted row; declared by the re-announcing sync
     * @throws DatabaseException When the update, the row count or the re-announcement fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     */
    public function spend(): bool
    {
        $this->ensureCanWrite();

        return $this->object->spend();
    }
}
