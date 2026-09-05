<?php

declare(strict_types=1);

namespace Hilos\Database\Actions\Collection;

use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Database\Actions\Exception\CallbackNotSetException;
use Hilos\Database\Actions\Exception\DuplicateIdException;
use Hilos\Database\Actions\Exception\TableNameUndeterminedException;
use Hilos\Database\Actions\Exception\UnknownLazyStrategyException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\SqlRuntime\DuplicateEntryException;
use Hilos\Database\Object\Collection\VerifierCircleMembers as ObjectVerifierCircleMembers;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\VerifierCircleMember as ObjectVerifierCircleMember;
use Hilos\Database\View\Collection\VerifierCircleMembers as DbCollectionVerifierCircleMembers;
use Hilos\Database\View\Item\VerifierCircleMember;
use Hilos\HilosException;

/**
 * VerifierCircleMembersActions - write operations for the VerifierCircleMembers collection.
 *
 * Collection-level operation: naming one more person as a verifier of the system after a
 * restore (HIL-643). Removing a named one is a write on a row already there and belongs to
 * that item's actions.
 *
 * @extends DbActions<VerifierCircleMember, ObjectVerifierCircleMembers>
 * @property-read DbCollectionVerifierCircleMembers $collection
 * @property-read ObjectVerifierCircleMembers $objectCollection
 */
final class VerifierCircleMembersActions extends DbActions
{
    /**
     * Names one identity pair as a member of the verifier circle.
     *
     * The duplicate is refused by the UNIQUE index rather than by a read before the write:
     * two admin tabs fit between such a read and its insert, and a circle that can hold the
     * same address twice cannot be shown honestly afterwards. The caller passes the pair
     * copied off a confirmed identity, so nothing is normalized here.
     *
     * @param string $identityType Identity type of the named person (see IdentityType)
     * @param string $identifier Normalized identifier for that type
     * @return VerifierCircleMember The created member Db item
     * @throws EmptyValueException When the identity type or the identifier is empty
     * @throws DuplicateValueException When the pair is already in the circle
     * @throws CallbackNotSetException When the collection cannot wrap the created object as a DB item
     * @throws DatabaseException When collection loading or the insert fails
     * @throws DuplicateIdException When the created member id already exists in the collection
     * @throws ObjectGetIdStringNotImplementedException When the created member has no persisted id
     * @throws TableNameUndeterminedException When duplicate-id reporting cannot resolve the table name
     * @throws UnknownLazyStrategyException When the circle collection has an unsupported lazy strategy
     * @throws LogicException When the circle object collection entity class is not configured
     * @throws CreateNotAllowedException When the truth source rejects circle collection creation
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws HilosException When a collection refuses to be re-read from the replaced database
     */
    public function add(string $identityType, string $identifier): VerifierCircleMember
    {
        $this->ensureCanCreate();

        if ($identityType === '' || $identifier === '') {
            throw new EmptyValueException('Verifier circle member identity type and identifier are required');
        }

        $member = ObjectVerifierCircleMember::create();
        $member->identityType = $identityType;
        $member->identifier = $identifier;
        try {
            $member->sync();
        } catch (DuplicateEntryException) {
            throw new DuplicateValueException('address already in the verifier circle');
        }

        $this->addObjectToCollection($member);

        return $this->createDbItemFromObject($member);
    }
}
