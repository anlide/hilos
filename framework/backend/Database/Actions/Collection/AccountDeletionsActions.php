<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Actions\Exception\CallbackNotSetException;
use Hilos\Database\Actions\Exception\UnknownLazyStrategyException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\AccountDeletions as ObjectAccountDeletions;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\View\Collection\AccountDeletions as DbCollectionAccountDeletions;
use Hilos\Database\View\Item\AccountDeletion;

/**
 * AccountDeletionsActions - write operations for the AccountDeletions collection (HIL-302).
 *
 * Opening a request. Cancelling it and carrying it out are writes on a row already there
 * and belong to that item's actions.
 *
 * @extends DbActions<AccountDeletion, ObjectAccountDeletions>
 * @property-read DbCollectionAccountDeletions $collection
 * @property-read ObjectAccountDeletions $objectCollection
 */
final class AccountDeletionsActions extends DbActions
{
    /**
     * Opens a request to delete a person's account.
     *
     * @param int $userId Person whose account is to be deleted
     * @param string $effectiveAt Moment the account is erased (SQL datetime)
     * @return AccountDeletion The request
     * @throws CreateNotAllowedException When the truth source rejects the insert
     * @throws WriteNotAllowedException Never for a new row; declared by the storing sync
     * @throws UnknownLazyStrategyException When the collection has an unsupported lazy strategy
     * @throws LogicException When the object collection entity class is not configured
     * @throws DatabaseException When the insert fails
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ObjectGetIdStringNotImplementedException If the inserted row has no primary key
     * @throws CallbackNotSetException When the collection cannot wrap the created object as a DB item
     */
    public function request(int $userId, string $effectiveAt): AccountDeletion
    {
        $this->ensureCanCreate();

        return $this->createDbItemFromObject($this->objectCollection->request($userId, $effectiveAt));
    }
}
