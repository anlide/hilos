<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\Identities as ObjectIdentities;
use Hilos\Database\Object\Item\Identity as ObjectIdentity;
use Hilos\Database\View\Item\Identity;

/**
 * Identities Db collection.
 *
 * Read-facing API for the framework-owned hilos_identity table. Write actions
 * (create / delete / verify-flip / secret update) are added by the consuming
 * auth leaves, not here.
 *
 * @extends DbCollection<Identity, ObjectIdentities>
 */
final class Identities extends DbCollection
{
    public const string DB_ITEM_CLASS = Identity::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectIdentities::class;

    /**
     * Finds the identity for a (type, identifier) pair.
     *
     * @param string $type Identity type (see IdentityType)
     * @param string $identifier Normalized identifier for the type
     * @return ?Identity Identity Db item or null if not found
     * @throws DatabaseException On database error while resolving the identity
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     */
    public function findByIdentity(string $type, string $identifier): ?Identity
    {
        $objectIdentity = $this->objectCollection->findByIdentity($type, $identifier);

        if ($objectIdentity?->id === null) {
            return null;
        }

        /** @var ?Identity $identity */
        $identity = $this->getItemForKey($objectIdentity->id);
        return $identity;
    }

    /**
     * Lists all identities owned by a user.
     *
     * @param int $userId Owning user id
     * @return list<Identity> Identity Db items for the user (empty when none)
     * @throws DatabaseException On database error while loading the identities
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     */
    public function listByUser(int $userId): array
    {
        $objectIdentities = $this->objectCollection->listByUser($userId);

        $result = [];
        foreach ($objectIdentities as $objectIdentity) {
            if ($objectIdentity->id === null) {
                continue;
            }
            $item = $this->getItemForKey($objectIdentity->id);
            if ($item !== null) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Runs a throwaway password verification to equalize login response time.
     *
     * Anti-enumeration companion of {@see findByIdentity()}: when a login lookup
     * misses, the handler still spends the bcrypt cost so response time does not
     * disclose whether the identifier exists.
     *
     * @param string $plainPassword Submitted plaintext to verify against a dummy hash
     */
    public function verifyDummyPassword(string $plainPassword): void
    {
        ObjectIdentity::verifyDummyPassword($plainPassword);
    }
}
