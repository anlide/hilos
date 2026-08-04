<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\DuplicateValueException;
use Hilos\Core\Exception\EmptyValueException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\PasskeyCredentials as ObjectPasskeyCredentials;
use Hilos\Database\Object\Item\PasskeyCredential as ObjectPasskeyCredential;
use Hilos\Database\View\Item\PasskeyCredential;
use Hilos\Hilos;

/**
 * PasskeyCredentials Db collection.
 *
 * Read-facing representation of the framework-owned hilos_passkey_credential
 * table, and the collection presented on {@see Hilos::$db}->passkeyCredentials.
 * The register/login ceremonies run in the demo action handlers through this
 * represented accessor, so the ceremony primitives are bridged here as thin
 * delegates to the object-layer collection ({@see ObjectPasskeyCredentials}).
 *
 * Unlike {@see Identities}, these bridges return the object-layer items rather
 * than read-facing view items: the ceremony write/verify paths need the
 * credential's post-assertion primitives ({@see ObjectPasskeyCredential::verifyAssertion()},
 * {@see ObjectPasskeyCredential::updateSignCount()}) that only the object item
 * carries. The manage surface (list/remove) built on the view item is HIL-404.
 *
 * @extends DbCollection<PasskeyCredential, ObjectPasskeyCredentials>
 */
final class PasskeyCredentials extends DbCollection
{
    public const string DB_ITEM_CLASS = PasskeyCredential::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectPasskeyCredentials::class;

    /**
     * Stores a passkey credential minted from a verified registration ceremony.
     *
     * Register write path bridged to {@see ObjectPasskeyCredentials::createFromRegistration()};
     * see there for parameter and uniqueness semantics.
     *
     * @param int $identityId Owning `hilos_identity` anchor row id (type=passkey)
     * @param int $userId Owning user id (denormalized for list/resolution)
     * @param string $credentialId Base64url credential id from the authenticator
     * @param string $publicKeyPem Credential public key as PEM
     * @param int $signCount Initial signature counter from registration
     * @param ?string $transports Reported transports (e.g. 'internal,hybrid'), or null
     * @param ?string $aaguid Authenticator AAGUID, or null
     * @param string $userHandle WebAuthn user handle (binary), reused per user
     * @return ObjectPasskeyCredential The stored credential object
     * @throws EmptyValueException When credential id, public key or user handle is empty
     * @throws DuplicateValueException When a credential already exists for this credential id
     * @throws DatabaseException If the insert query fails
     */
    public function createFromRegistration(
        int $identityId,
        int $userId,
        string $credentialId,
        string $publicKeyPem,
        int $signCount,
        ?string $transports,
        ?string $aaguid,
        string $userHandle,
    ): ObjectPasskeyCredential {
        return $this->objectCollection->createFromRegistration(
            $identityId,
            $userId,
            $credentialId,
            $publicKeyPem,
            $signCount,
            $transports,
            $aaguid,
            $userHandle,
        );
    }

    /**
     * Resolves a credential by its base64url credential id.
     *
     * Assertion read path bridged to {@see ObjectPasskeyCredentials::findByCredentialId()}:
     * returns the object item so the login handler can drive
     * {@see ObjectPasskeyCredential::verifyAssertion()} on it. A miss returns null.
     *
     * @param string $credentialId Base64url credential id from the assertion
     * @return ?ObjectPasskeyCredential Credential object or null if not found
     * @throws DatabaseException If the database query fails
     */
    public function findByCredentialId(string $credentialId): ?ObjectPasskeyCredential
    {
        return $this->objectCollection->findByCredentialId($credentialId);
    }

    /**
     * Lists all passkey credentials owned by a user.
     *
     * Bridged to {@see ObjectPasskeyCredentials::listByUser()}; backs the register
     * ceremony's `excludeCredentials`, the login ceremony's `allowCredentials`, and
     * the reuse of a user's existing user handle for a second passkey.
     *
     * @param int $userId Owning user id
     * @return list<ObjectPasskeyCredential> Credential objects for the user (empty when none)
     * @throws DatabaseException If the database query fails
     */
    public function listByUser(int $userId): array
    {
        return $this->objectCollection->listByUser($userId);
    }

    /**
     * Resolves the user id owning a WebAuthn user handle.
     *
     * Bridged to {@see ObjectPasskeyCredentials::findUserByUserHandle()}; the
     * resident-key / usernameless read seeded for HIL-400. A miss resolves to null.
     *
     * @param string $userHandle WebAuthn user handle (binary) from the assertion
     * @return ?int Owning user id, or null when no credential carries the handle
     * @throws DatabaseException If the database query fails
     */
    public function findUserByUserHandle(string $userHandle): ?int
    {
        return $this->objectCollection->findUserByUserHandle($userHandle);
    }
}
