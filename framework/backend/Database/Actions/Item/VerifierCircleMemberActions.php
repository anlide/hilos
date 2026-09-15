<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Item;

use Hilos\Core\Exception\ItemNotFoundForDeleteException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Exception\ObjectCollectionNullException;
use Hilos\Database\Actions\Exception\UnknownLazyStrategyException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\VerifierCircleMember as ObjectVerifierCircleMember;
use Hilos\Database\View\Item\VerifierCircleMember;

/**
 * VerifierCircleMember Actions - write operations for a single verifier circle member.
 *
 * Item-level operation: delete only (HIL-643). A membership has nothing to update - it is
 * an identity pair and the day it was named - so taking somebody out of the circle is the
 * only write a row already there ever takes.
 *
 * @extends DbActions<VerifierCircleMember, ObjectVerifierCircleMember>
 * @property-read ObjectVerifierCircleMember $object
 */
final class VerifierCircleMemberActions extends DbActions
{
    /**
     * Takes the named person out of the verifier circle.
     *
     * @throws ItemNotFoundForDeleteException When the member object has no persisted id
     * @throws ObjectCollectionNullException When the member action is detached from its object collection
     * @throws ObjectGetIdStringNotImplementedException When the member object cannot expose its id string
     * @throws DatabaseException When collection loading or the delete fails
     * @throws UnknownLazyStrategyException When the circle collection has an unsupported lazy strategy
     * @throws LogicException When the circle object collection entity class is not configured
     * @throws WriteNotAllowedException When the truth source rejects the member delete
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function delete(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);

        if ($this->object->id === null) {
            throw new ItemNotFoundForDeleteException('Verifier circle member not found for delete (id is null)');
        }

        $objectCollection = $this->getObjectCollection()
            ?? throw new ObjectCollectionNullException('Object collection is null');

        $idString = $this->object->getIdString();
        $this->object->delete();
        unset($objectCollection[$idString]);
    }
}
