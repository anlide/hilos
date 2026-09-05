<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\VerifierCircleMembers as ObjectVerifierCircleMembers;
use Hilos\Database\View\Item\VerifierCircleMember;

/**
 * VerifierCircleMembers Db collection.
 *
 * Read-facing representation of the framework-owned hilos_verifier_circle table
 * (HIL-643). The admin surface and the freeze both read the circle through the object
 * collection's {@see ObjectVerifierCircleMembers::listAll()}; the collection action adds
 * a member and the item action removes one.
 *
 * @extends DbCollection<VerifierCircleMember, ObjectVerifierCircleMembers>
 */
final class VerifierCircleMembers extends DbCollection
{
    public const string DB_ITEM_CLASS = VerifierCircleMember::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectVerifierCircleMembers::class;

    /**
     * Finds the circle member named by an identity pair.
     *
     * @param string $type Identity type (see IdentityType)
     * @param string $identifier Normalized identifier for the type
     * @return ?VerifierCircleMember The member Db item, or null when the pair is not in the circle
     * @throws DatabaseException On database error while resolving the pair
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     */
    public function findByIdentity(string $type, string $identifier): ?VerifierCircleMember
    {
        $objectMember = $this->objectCollection->findByIdentity($type, $identifier);

        /** @var ?VerifierCircleMember $member */
        $member = $this->getItemForKey($objectMember?->id);

        return $member;
    }

    /**
     * Lists the whole circle, oldest membership first.
     *
     * The circle is read whole by everything that reads it at all - the block an operator
     * collects it on, and the freeze intersecting it with the hall - because it is a handful
     * of names rather than a table anybody pages through.
     *
     * @return list<VerifierCircleMember> Every named member, empty when the circle is empty
     * @throws DatabaseException On database error while reading the circle
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     */
    public function listAll(): array
    {
        $members = [];
        foreach ($this->objectCollection->listAll() as $objectMember) {
            /** @var ?VerifierCircleMember $member */
            $member = $this->getItemForKey($objectMember->id);
            if ($member !== null) {
                $members[] = $member;
            }
        }

        return $members;
    }
}
