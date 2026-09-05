<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\VerifierCircleMembers as EntityVerifierCircleMembers;
use Hilos\Database\Entity\Item\VerifierCircleMember as EntityVerifierCircleMember;
use Hilos\Database\Object\Item\VerifierCircleMember as ObjectVerifierCircleMember;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlSortDirection;

/**
 * VerifierCircleMembers object collection - the verifier circle of a restore (HIL-643).
 *
 * Reads only. The circle is named by identity pairs and is small by nature - it is the
 * handful of people an operator expects to check the system once it comes back - so the
 * two questions asked of it are "is this pair already in" and "who is in", and both are
 * answered here. Writes go through the collection and item actions.
 *
 * @extends Objects<ObjectVerifierCircleMember>
 * @method ObjectVerifierCircleMember|null current()
 * @method ObjectVerifierCircleMember|null first()
 * @method ObjectVerifierCircleMember|null last()
 * @method ObjectVerifierCircleMember|null get(int|string $key)
 * @method ObjectVerifierCircleMember|null offsetGet(mixed $offset)
 */
final class VerifierCircleMembers extends Objects
{
    public const string OBJECT_CLASS = ObjectVerifierCircleMember::class;
    public const string ENTITY_COLLECTION_CLASS = EntityVerifierCircleMembers::class;
    public const string COLLECTION_KEY = HilosDbContext::verifierCircle;

    /**
     * Finds the circle member named by an identity pair.
     *
     * The pair is UNIQUE, so this returns at most one member. The caller normalizes the
     * identifier the way the identity layer does (lowercased email, E.164 phone) before
     * asking: the pair stored here is the one copied off a confirmed identity.
     *
     * @param string $type Identity type (see IdentityType)
     * @param string $identifier Normalized identifier for the type
     * @return ?ObjectVerifierCircleMember The member, or null when the pair is not in the circle
     * @throws DatabaseException When the lookup query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function findByIdentity(string $type, string $identifier): ?ObjectVerifierCircleMember
    {
        if ($type === '' || $identifier === '') {
            return null;
        }

        $entity = EntityVerifierCircleMember::get([
            EntityVerifierCircleMember::identity_type => $type,
            EntityVerifierCircleMember::identifier => $identifier,
        ])->first();

        if ($entity === null || $entity->id === null) {
            return null;
        }

        if (!isset($this->objects[$entity->id])) {
            $this->hydrate($entity->id, ObjectVerifierCircleMember::fromEntity($entity));
        }

        return $this->objects[$entity->id];
    }

    /**
     * Lists the whole circle, oldest membership first.
     *
     * Ordered by the primary key rather than by address, so the list a screen draws and the
     * list the freeze intersects the hall with are the same list in the same order however
     * many rows share a stamp.
     *
     * @return list<ObjectVerifierCircleMember> Every named member, empty when the circle is empty
     * @throws DatabaseException When the lookup query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function listAll(): array
    {
        $entities = EntityVerifierCircleMember::get(
            [],
            [],
            [EntityVerifierCircleMember::id => SqlSortDirection::ASC],
        );

        $members = [];
        foreach ($entities as $entity) {
            $id = $entity->id;
            if ($id === null) {
                continue;
            }
            if (!isset($this->objects[$id])) {
                $this->hydrate($id, ObjectVerifierCircleMember::fromEntity($entity));
            }
            $members[] = $this->objects[$id];
        }

        return $members;
    }
}
