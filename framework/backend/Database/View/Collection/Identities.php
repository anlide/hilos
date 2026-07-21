<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
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
 * auth leaves; the register leaf (HIL-164) adds {@see createPasswordIdentity()},
 * delegating the hash-at-rest insert to the object collection.
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
     * Creates a `password`-type identity for a user with a freshly hashed secret.
     *
     * Register write path of the identity layer (HIL-164): delegates the hash-at-rest
     * insert to the object collection's {@see ObjectIdentities::createPasswordIdentity()}
     * primitive and returns the read-facing view item for the new row. The secret is
     * minted and stored entirely inside the object layer and never crosses this
     * read-facing boundary, mirroring the delegation in {@see findByIdentity()}.
     *
     * @param int $userId Owning user id
     * @param string $identifier Normalized identifier (lowercased email)
     * @param string $plainSecret Plaintext password to hash and store
     * @return Identity The created identity's read-facing Db item
     * @throws EmptyValueException When identifier or secret is empty
     * @throws DuplicateValueException When an identity already exists for (password, identifier)
     * @throws DatabaseException On database error while creating the identity
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     */
    public function createPasswordIdentity(int $userId, string $identifier, string $plainSecret): Identity
    {
        $objectIdentity = $this->objectCollection->createPasswordIdentity($userId, $identifier, $plainSecret);

        $id = $objectIdentity->id;
        if ($id === null) {
            throw new DatabaseException('Identity insert did not assign an id');
        }

        /** @var ?Identity $identity */
        $identity = $this->getItemForKey($id);
        if ($identity === null) {
            throw new DatabaseException('Created identity is not available on the read-facing collection');
        }

        return $identity;
    }

    /**
     * Creates a verified `oauth`-type identity for a user (HIL-281).
     *
     * External-login write path of the identity layer: delegates the
     * secret-less, verified insert to the object collection's
     * {@see ObjectIdentities::createOauthIdentity()} primitive and returns the
     * read-facing view item for the new row. The identifier is the canonical
     * `provider:subject` pair; account resolution keys strictly on
     * (provider, subject).
     *
     * @param int $userId Owning user id
     * @param string $provider Provider key, e.g. 'oauth:github'
     * @param string $subject Provider-immutable account subject id
     * @return Identity The created identity's read-facing Db item
     * @throws EmptyValueException When provider or subject is empty
     * @throws DuplicateValueException When an identity already exists for (oauth, identifier)
     * @throws DatabaseException On database error while creating the identity
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     */
    public function createOauthIdentity(int $userId, string $provider, string $subject): Identity
    {
        $objectIdentity = $this->objectCollection->createOauthIdentity($userId, $provider, $subject);

        $id = $objectIdentity->id;
        if ($id === null) {
            throw new DatabaseException('Identity insert did not assign an id');
        }

        /** @var ?Identity $identity */
        $identity = $this->getItemForKey($id);
        if ($identity === null) {
            throw new DatabaseException('Created identity is not available on the read-facing collection');
        }

        return $identity;
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
