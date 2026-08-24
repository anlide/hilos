<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\PasskeyCredentials as EntityPasskeyCredentials;
use Hilos\Database\Entity\Item\PasskeyCredential as EntityPasskeyCredential;
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
final class PasskeyCredentials extends Objects
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

        $credential = ObjectPasskeyCredential::create();
        $credential->identityId = $identityId;
        $credential->userId = $userId;
        $credential->credentialId = $credentialId;
        $credential->publicKey = $publicKeyPem;
        $credential->algorithm = $algorithm->value;
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

        if ($signCount > 0) {
            $credential->updateSignCount($signCount);
        }

        $this->objects[$id] = $credential;

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

        $entity = EntityPasskeyCredential::get([
            EntityPasskeyCredential::credential_id => $credentialId,
        ])->first();

        if ($entity === null || $entity->id === null) {
            return null;
        }

        if (!isset($this->objects[$entity->id])) {
            $this->objects[$entity->id] = ObjectPasskeyCredential::fromEntity($entity);
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
        $entities = EntityPasskeyCredential::get([EntityPasskeyCredential::user_id => $userId]);

        $result = [];
        foreach ($entities as $entity) {
            if ($entity->id === null) {
                continue;
            }
            if (!isset($this->objects[$entity->id])) {
                $this->objects[$entity->id] = ObjectPasskeyCredential::fromEntity($entity);
            }
            $result[] = $this->objects[$entity->id];
        }

        return $result;
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

        $entity = EntityPasskeyCredential::get([
            EntityPasskeyCredential::user_handle => $userHandle,
        ])->first();

        return $entity?->user_id;
    }
}
