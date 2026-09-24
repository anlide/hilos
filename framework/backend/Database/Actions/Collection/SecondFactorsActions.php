<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\CallbackNotSetException;
use Hilos\Database\Actions\Exception\UnknownLazyStrategyException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\SecondFactors as ObjectSecondFactors;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\View\Collection\SecondFactors as DbCollectionSecondFactors;
use Hilos\Database\View\Item\SecondFactor;

/**
 * SecondFactorsActions - write operations for the SecondFactors collection (HIL-494).
 *
 * Opening an enrolment and switching a person's second factor off whole. Confirming an
 * enrolment, taking a code's step and removing one authenticator are writes on a row
 * already there and belong to that item's actions.
 *
 * @extends DbActions<SecondFactor, ObjectSecondFactors>
 * @property-read DbCollectionSecondFactors $collection
 * @property-read ObjectSecondFactors $objectCollection
 */
final class SecondFactorsActions extends DbActions
{
    /**
     * Opens an enrolment: an unconfirmed authenticator holding a fresh secret.
     *
     * The person's earlier unfinished enrolment is replaced; confirmed authenticators stay.
     *
     * @param int $userId Person enrolling
     * @param string $label Name the authenticator starts with
     * @param string $secret Base32 shared secret
     * @return SecondFactor The unconfirmed authenticator
     * @throws CreateNotAllowedException When the truth source rejects the insert
     * @throws WriteNotAllowedException When the truth source rejects the replacement or the secret write
     * @throws UnknownLazyStrategyException When the collection has an unsupported lazy strategy
     * @throws LogicException When the object collection entity class is not configured
     * @throws DatabaseException When a lookup, the insert or the secret write fails
     * @throws InvalidArgumentException When a query or the queued DB-sync signal is invalid
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ObjectGetIdStringNotImplementedException If the inserted row has no primary key
     * @throws CallbackNotSetException When the collection cannot wrap the created object as a DB item
     */
    public function startEnrolment(int $userId, string $label, string $secret): SecondFactor
    {
        $this->ensureCanCreate();

        return $this->createDbItemFromObject($this->objectCollection->startEnrolment($userId, $label, $secret));
    }

    /**
     * Deletes every authenticator of a person - the second factor switched off whole.
     *
     * @param int $userId Person
     * @throws WriteNotAllowedException When the truth source rejects the delete
     * @throws UnknownLazyStrategyException When the collection has an unsupported lazy strategy
     * @throws LogicException When the object collection entity class is not configured
     * @throws DatabaseException When a lookup or a delete fails
     * @throws InvalidArgumentException When a query or the queued DB-sync signal is invalid
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function deleteForUser(int $userId): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);

        $this->objectCollection->deleteForUser($userId);
    }
}
