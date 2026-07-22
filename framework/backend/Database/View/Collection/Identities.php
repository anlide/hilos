<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\ValidationException;
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
     * Resolves the user id owning a verified identity for an email (HIL-283).
     *
     * Passwordless-login read accessor shared with the OAuth email-collision
     * link flow (HIL-282): delegates to the object collection's
     * {@see ObjectIdentities::findUserIdByVerifiedEmail()} primitive, which
     * answers "which existing account owns this verified email?" without
     * disclosing anything to the caller beyond the id (anti-enumeration).
     *
     * @param string $email Lowercased account email
     * @return ?int Owning user id of a verified email identity, or null when none
     * @throws DatabaseException On database error while resolving the identity
     */
    public function findUserIdByVerifiedEmail(string $email): ?int
    {
        return $this->objectCollection->findUserIdByVerifiedEmail($email);
    }

    /**
     * Resolves the user id owning any identity for an email, verified or not (HIL-284).
     *
     * Verification-agnostic sibling of {@see findUserIdByVerifiedEmail()}: delegates
     * to the object collection's {@see ObjectIdentities::findUserIdByEmail()}
     * primitive, for passkey username-first login where the WebAuthn assertion is
     * the proof and the email only scopes allowCredentials. Not for email-proof
     * flows (magic-link / OAuth) — those keep the verified resolver.
     *
     * @param string $email Lowercased account email
     * @return ?int Owning user id of any email identity, or null when none
     * @throws DatabaseException On database error while resolving the identity
     */
    public function findUserIdByEmail(string $email): ?int
    {
        return $this->objectCollection->findUserIdByEmail($email);
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
     * Creates a verified `sms`-type identity for a user (HIL-280).
     *
     * Phone-login write path of the identity layer: delegates the secret-less,
     * verified insert to the object collection's
     * {@see ObjectIdentities::createSmsIdentity()} primitive and returns the
     * read-facing view item for the new row. The identifier is a normalized
     * E.164 phone; uniqueness is per (sms, identifier), mirroring the delegation
     * in {@see createPasswordIdentity()}.
     *
     * @param int $userId Owning user id
     * @param string $identifier Normalized E.164 phone number
     * @return Identity The created identity's read-facing Db item
     * @throws EmptyValueException When identifier is empty
     * @throws DuplicateValueException When an identity already exists for (sms, identifier)
     * @throws DatabaseException On database error while creating the identity
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     */
    public function createSmsIdentity(int $userId, string $identifier): Identity
    {
        $objectIdentity = $this->objectCollection->createSmsIdentity($userId, $identifier);

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
     * Creates a verified `passkey`-type identity anchor for a user (HIL-284).
     *
     * WebAuthn write path of the identity layer: delegates the secret-less,
     * verified insert to the object collection's
     * {@see ObjectIdentities::createPasskeyIdentity()} primitive and returns the
     * read-facing view item for the new row. The identifier is the
     * authenticator's base64url credential id; uniqueness is per
     * (passkey, identifier), mirroring the delegation in
     * {@see createPasswordIdentity()}.
     *
     * @param int $userId Owning user id
     * @param string $credentialId Base64url credential id from the authenticator
     * @return Identity The created identity's read-facing Db item
     * @throws EmptyValueException When credential id is empty
     * @throws DuplicateValueException When an identity already exists for (passkey, identifier)
     * @throws DatabaseException On database error while creating the identity
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     */
    public function createPasskeyIdentity(int $userId, string $credentialId): Identity
    {
        $objectIdentity = $this->objectCollection->createPasskeyIdentity($userId, $credentialId);

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
     * Hard-deletes one of a user's identities (HIL-377).
     *
     * Profile unlink write path: delegates to the object collection's
     * {@see ObjectIdentities::deleteIdentity()} primitive, which owns the
     * ownership and last-identity guards and the (id, user_id)-scoped delete.
     *
     * @param int $userId Owning user id (session user)
     * @param int $identityId Identity id to unlink
     * @throws ValidationException When the identity is not owned by the user, or is their last one
     * @throws DatabaseException On database error while deleting the identity
     */
    public function deleteIdentity(int $userId, int $identityId): void
    {
        $this->objectCollection->deleteIdentity($userId, $identityId);
    }

    /**
     * Re-points every identity owned by a loser user to a survivor user (HIL-378).
     *
     * Account-merge write path: delegates to the object collection's
     * {@see ObjectIdentities::rePointToUser()} primitive, which owns the
     * duplicate-drop guard and the targeted user_id move.
     *
     * @param int $fromUserId Loser user id whose identities are absorbed
     * @param int $toUserId Survivor user id that receives the identities
     * @return int Number of identities re-pointed to the survivor
     * @throws DatabaseException On database error while re-pointing the identities
     */
    public function rePointToUser(int $fromUserId, int $toUserId): int
    {
        return $this->objectCollection->rePointToUser($fromUserId, $toUserId);
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
