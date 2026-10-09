<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\PasskeyCredentials as EntityPasskeyCredentials;
use Hilos\Database\Entity\Item\PasskeyCredential as EntityPasskeyCredential;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\PasskeyCredential as ObjectPasskeyCredential;
use Hilos\Database\Object\Objects;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * PasskeyCredentials object collection.
 *
 * Persistence primitives of the passkey sidecar (HIL-284): store a credential
 * minted from a verified registration, resolve a credential by its id for an
 * assertion, list a user's credentials, and resolve a user by WebAuthn user
 * handle (the resident-key/usernameless read for HIL-400). Unlike the identity /
 * verification collections there is no secret to split off — the public key is
 * public material — so a credential is written with a single ORM insert.
 *
 * @extends Objects<ObjectPasskeyCredential>
 * @method ObjectPasskeyCredential|null current()
 * @method ObjectPasskeyCredential|null first()
 * @method ObjectPasskeyCredential|null last()
 * @method ObjectPasskeyCredential|null get(int|string $key)
 * @method ObjectPasskeyCredential|null offsetGet(mixed $offset)
 */
class PasskeyCredentials extends Objects
{
    public const string OBJECT_CLASS = ObjectPasskeyCredential::class;
    public const string ENTITY_COLLECTION_CLASS = EntityPasskeyCredentials::class;
    public const string COLLECTION_KEY = HilosDbContext::passkeyCredentials;

    /**
     * Stores a passkey credential minted from a verified registration ceremony.
     *
     * Register write path of the passkey sidecar, called once the attestation
     * verifier has accepted the ceremony and the `hilos_identity` anchor row has
     * been created ({@see Identities::createPasskeyIdentity()}). The public key is
     * a PEM converted once from the authenticator's COSE key, stored next to the
     * algorithm it was enrolled under because the PEM alone cannot name it
     * (HIL-658); `user_handle` is the WebAuthn user handle (one per user, reused
     * across a user's passkeys, passed in by the caller). Uniqueness is per
     * `credential_id`.
     *
     * @param int $identityId Owning `hilos_identity` anchor row id (type=passkey)
     * @param int $userId Owning user id (denormalized for list/resolution)
     * @param string $credentialId Base64url credential id from the authenticator
     * @param string $publicKeyPem Credential public key as PEM
     * @param PasskeyAlgorithm $algorithm Signature suite the ceremony enrolled the key under
     * @param int $signCount Initial signature counter from registration
     * @param ?string $transports Reported transports (e.g. 'internal,hybrid'), or null
     * @param ?string $aaguid Authenticator AAGUID, or null
     * @param string $userHandle WebAuthn user handle (binary), reused per user
     * @param ?string $label Human name for the key — the device it was enrolled on, or null when unknown
     * @return ObjectPasskeyCredential The stored credential object
     * @throws EmptyValueException When credential id, public key or user handle is empty
     * @throws DuplicateValueException When a credential already exists for this credential id
     * @throws DatabaseException If the insert query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function createFromRegistration(
        int $identityId,
        int $userId,
        string $credentialId,
        string $publicKeyPem,
        PasskeyAlgorithm $algorithm,
        int $signCount,
        ?string $transports,
        ?string $aaguid,
        string $userHandle,
        ?string $label,
    ): ObjectPasskeyCredential {
        if ($credentialId === '' || $publicKeyPem === '' || $userHandle === '') {
            throw new EmptyValueException('Passkey credential id, public key and user handle are required');
        }

        if ($this->findByCredentialId($credentialId) !== null) {
            throw new DuplicateValueException('passkey credential already registered');
        }

        $credential = static::OBJECT_CLASS::create();
        $credential->identityId = $identityId;
        $credential->userId = $userId;
        $credential->credentialId = $credentialId;
        $credential->publicKey = $publicKeyPem;
        $credential->algorithm = $algorithm->value;
        // The starting counter rides in the insert: the row is born whole, and a writer that may
        // only create keys has no edit left to make (HIL-1405).
        $credential->signCount = $signCount;
        $credential->transports = $transports;
        $credential->aaguid = $aaguid;
        $credential->userHandle = $userHandle;
        $credential->label = $label;
        // `created_at` is a mapped column, so the insert carries it and the SQL
        // default never applies; stamped here like every other framework row.
        $credential->createdAt = TimeHelper::getSqlDateTime();
        $credential->sync();

        $id = $credential->id;
        if ($id === null) {
            throw new DatabaseException('Passkey credential insert did not assign an id');
        }

        $this[$id] = $credential;

        return $credential;
    }

    /**
     * Resolves a credential by its base64url credential id.
     *
     * Assertion read path: an authenticator's credential id is unique across the
     * table, so this returns at most one credential. A miss lets the login handler
     * answer generically (anti-enumeration).
     *
     * @param string $credentialId Base64url credential id from the assertion
     * @return ?ObjectPasskeyCredential Credential object or null if not found
     * @throws DatabaseException If the database query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function findByCredentialId(string $credentialId): ?ObjectPasskeyCredential
    {
        if ($credentialId === '') {
            return null;
        }

        $entity = static::entityClass()::get([
            EntityPasskeyCredential::credential_id => $credentialId,
        ])->first();

        if ($entity === null || $entity->id === null) {
            return null;
        }

        if (!isset($this->objects[$entity->id])) {
            $this->hydrate($entity->id, static::OBJECT_CLASS::fromEntity($entity));
        }

        return $this->objects[$entity->id];
    }

    /**
     * Lists all passkey credentials owned by a user.
     *
     * Backs the register ceremony's `excludeCredentials`, the login ceremony's
     * `allowCredentials`, and the manage list (HIL-404). Also the source of a
     * user's existing user handle for reuse on a second passkey (read off the
     * first entry).
     *
     * @param int $userId Owning user id
     * @return list<ObjectPasskeyCredential> Credential objects for the user (empty when none)
     * @throws DatabaseException If the database query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function listByUser(int $userId): array
    {
        $entities = static::entityClass()::get([EntityPasskeyCredential::user_id => $userId]);

        $result = [];
        foreach ($entities as $entity) {
            if ($entity->id === null) {
                continue;
            }
            if (!isset($this->objects[$entity->id])) {
                $this->hydrate($entity->id, static::OBJECT_CLASS::fromEntity($entity));
            }
            $result[] = $this->objects[$entity->id];
        }

        return $result;
    }

    /**
     * Deletes the credentials stored for one identity anchor (HIL-722).
     *
     * Unlink cascade: the `hilos_identity` anchor of a passkey and its crypto
     * half are two rows with no foreign key between them, so removing the anchor
     * alone leaves a credential that still signs assertions and still names an
     * account, because the login ceremony resolves the account from the sidecar
     * and never asks the anchor. This is the half that takes the crypto row out,
     * and it runs before the anchor is dropped: an interruption in between has to
     * leave a state that closes the sign-in, not one that opens it.
     *
     * The delete goes through the object so a DB_SYNC_DELETED broadcast takes the
     * row off the profile screen. A sidecar is one row per identity by contract,
     * but every matching row is removed and no count is assumed. An identity with
     * no credential is not an error and does nothing: a non-passkey identity never
     * reaches here, and a repeated unlink has to stay harmless.
     *
     * @param int $identityId Owning `hilos_identity` anchor row id (type=passkey)
     * @throws DatabaseException If the lookup or delete query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function deleteByIdentity(int $identityId): void
    {
        $entities = static::entityClass()::get([EntityPasskeyCredential::identity_id => $identityId]);

        foreach ($entities as $entity) {
            if ($entity->id === null) {
                continue;
            }
            if (!isset($this->objects[$entity->id])) {
                $this->hydrate($entity->id, static::OBJECT_CLASS::fromEntity($entity));
            }

            $this->objects[$entity->id]->delete();
            unset($this[$entity->id]);
        }
    }

    /**
     * Resolves the user id owning a WebAuthn user handle.
     *
     * Resident-key / usernameless read seeded for HIL-400: a discoverable-login
     * assertion carries the user handle instead of an email, so this maps it back
     * to the owning user. The handle is shared across a user's passkeys, so the
     * first matching row's user id is authoritative. A miss resolves to null,
     * letting the caller answer generically.
     *
     * @param string $userHandle WebAuthn user handle (binary) from the assertion
     * @return ?int Owning user id, or null when no credential carries the handle
     * @throws DatabaseException If the database query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function findUserByUserHandle(string $userHandle): ?int
    {
        if ($userHandle === '') {
            return null;
        }

        $entity = static::entityClass()::get([
            EntityPasskeyCredential::user_handle => $userHandle,
        ])->first();

        return $entity?->user_id;
    }

    /**
     * Deletes every device key of a person, by its denormalized user id - the account is being erased (HIL-302).
     *
     * Each row leaves through its object so a delete announcement reaches every reader.
     * A person with none is not an error.
     *
     * @param int $userId Person whose rows to delete
     * @throws DatabaseException When the lookup or a delete fails
     * @throws InvalidArgumentException When the entity query or the queued DB-sync signal is invalid
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function deleteForUser(int $userId): void
    {
        foreach (static::entityClass()::get([EntityPasskeyCredential::user_id => $userId]) as $entity) {
            $id = $entity->id;
            if ($id === null) {
                continue;
            }
            if (!isset($this->objects[$id])) {
                $this->hydrate($id, static::OBJECT_CLASS::fromEntity($entity));
            }
            $this->objects[$id]->delete();
            unset($this[$id]);
        }
    }

    /**
     * Re-points every device key of a merged loser to the survivor (HIL-1132).
     *
     * The pair of {@see Identities::rePointToUser()}: the anchor moves there and its crypto row
     * moves here. There is no duplicate branch, because `credential_id` is unique in the table
     * (`uk_passkey_credential_id`). The user handle is left alone — it lives on the authenticator.
     * A person with none is not an error. This method does not refuse a move onto the same person:
     * the merge core refuses that earlier.
     *
     * Each row leaves through its object so an update announcement reaches every reader.
     *
     * @param int $fromUserId Loser user id whose device keys are absorbed
     * @param int $toUserId Survivor user id that receives the device keys
     * @return int Number of device keys re-pointed to the survivor
     * @throws DatabaseException When the lookup or a move fails
     * @throws InvalidArgumentException When the entity query or the queued DB-sync signal is invalid
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws CreateNotAllowedException When no truth source in this process may add a row here
     * @throws ObjectGetIdStringNotImplementedException When the row's id cannot be named for the write
     */
    public function rePointToUser(int $fromUserId, int $toUserId): int
    {
        $moved = 0;
        foreach (static::entityClass()::get([EntityPasskeyCredential::user_id => $fromUserId]) as $entity) {
            $id = $entity->id;
            if ($id === null) {
                continue;
            }
            if (!isset($this->objects[$id])) {
                $this->hydrate($id, static::OBJECT_CLASS::fromEntity($entity));
            }
            $this->objects[$id]->userId = $toUserId;
            $this->objects[$id]->sync();
            $moved++;
        }

        return $moved;
    }
}
