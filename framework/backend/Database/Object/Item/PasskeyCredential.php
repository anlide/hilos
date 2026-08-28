<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Auth\WebAuthn\AssertionVerifier;
use Hilos\Auth\WebAuthn\Exception\WebAuthnVerificationException;
use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\PasskeyCredential as EntityPasskeyCredential;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * PasskeyCredential object - wraps PasskeyCredential entity.
 *
 * Exposes the credential's fields and the two post-assertion write primitives:
 * {@see updateSignCount()} advances the stored signature counter after a
 * successful login (clone-detection), and {@see touchLastUsed()} stamps last use
 * for passkey management (HIL-404). {@see verifyAssertion()} drives the WebAuthn
 * assertion check against this credential's stored key/counter and, on success,
 * persists the advanced counter and last-used stamp.
 *
 * @extends Object_<EntityPasskeyCredential>
 *
 * @property-read ?int $id
 * @property int $identityId
 * @property int $userId
 * @property string $credentialId
 * @property string $publicKey
 * @property int $algorithm
 * @property-read int $signCount
 * @property ?string $transports
 * @property ?string $aaguid
 * @property string $userHandle
 * @property ?string $label
 * @property-read ?string $lastUsedAt
 * @property string $createdAt
 */
final class PasskeyCredential extends Object_
{
    public const string ENTITY_CLASS = EntityPasskeyCredential::class;
    public const string id = 'id';
    public const string identityId = 'identityId';
    public const string userId = 'userId';
    public const string credentialId = 'credentialId';
    public const string publicKey = 'publicKey';
    public const string algorithm = 'algorithm';
    public const string signCount = 'signCount';
    public const string transports = 'transports';
    public const string aaguid = 'aaguid';
    public const string userHandle = 'userHandle';
    public const string label = 'label';
    public const string lastUsedAt = 'lastUsedAt';
    public const string createdAt = 'createdAt';

    /**
     * Returns the database collection key.
     *
     * @return string Collection key (HilosDbContext::passkeyCredentials)
     */
    protected static function getCollectionKey(): string
    {
        return HilosDbContext::passkeyCredentials;
    }

    /**
     * Magic getter for entity properties.
     *
     * @param string $property Property name (see class @property list)
     * @return mixed Property value
     * @throws DatabaseException When the property is not a known PasskeyCredential field
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::identityId => $this->entity->identity_id,
            self::userId => $this->entity->user_id,
            self::credentialId => $this->entity->credential_id,
            self::publicKey => $this->entity->public_key,
            self::algorithm => $this->entity->algorithm,
            self::signCount => $this->entity->sign_count,
            self::transports => $this->entity->transports,
            self::aaguid => $this->entity->aaguid,
            self::userHandle => $this->entity->user_handle,
            self::label => $this->entity->label,
            self::lastUsedAt => $this->entity->last_used_at,
            self::createdAt => $this->entity->created_at,
            default => parent::__get($property),
        };
    }

    /**
     * Magic setter for entity properties.
     *
     * `signCount` and `lastUsedAt` have no setter here; they are advanced only
     * through {@see updateSignCount()} / {@see touchLastUsed()}, which write with a
     * targeted UPDATE and mirror the value on the loaded entity.
     *
     * @param string $property Name of a settable property (see the class @property list)
     * @param mixed $value Value to set
     * @throws DatabaseException When the property cannot be set on a PasskeyCredential
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::identityId => $this->entity->identity_id = (int)$value,
            self::userId => $this->entity->user_id = (int)$value,
            self::credentialId => $this->entity->credential_id = (string)$value,
            self::publicKey => $this->entity->public_key = (string)$value,
            self::algorithm => $this->entity->algorithm = (int)$value,
            self::transports => $this->entity->transports = $value === null ? null : (string)$value,
            self::aaguid => $this->entity->aaguid = $value === null ? null : (string)$value,
            self::userHandle => $this->entity->user_handle = (string)$value,
            self::label => $this->entity->label = $value === null ? null : (string)$value,
            self::createdAt => $this->entity->created_at = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Advances the stored signature counter after a verified assertion.
     *
     * Clone-detection primitive: WebAuthn requires the authenticator's monotonic
     * signature counter to strictly increase across logins (except the 0/0 case of
     * synced passkeys). Once the assertion verifier accepts a new counter it is
     * persisted here with a targeted UPDATE and mirrored on the loaded entity, so a
     * later comparison reads the advanced value. A no-op for an unpersisted
     * credential.
     *
     * @param int $newCount New signature counter reported by the authenticator
     * @throws DatabaseException When the sign-count update query fails
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     */
    public function updateSignCount(int $newCount): void
    {
        if ($this->entity->id === null) {
            return;
        }

        DbWriteGuard::guardItemWrite(
            static::getCollectionKey(),
            (string)$this->entity->id,
            TruthSourceOperation::Update,
        );

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($newCount));
        $params->add(SqlParam::int($this->entity->id));
        Database::sql(
            'UPDATE `' . EntityPasskeyCredential::_table . '` SET `' . EntityPasskeyCredential::sign_count
                . '` = ? WHERE `' . EntityPasskeyCredential::id . '` = ?',
            $params,
        );

        $this->entity->sign_count = $newCount;
    }

    /**
     * Stamps this credential's last-used time to now.
     *
     * Management primitive seeded for the passkey list (HIL-404): after a
     * successful login the credential's `last_used_at` is set with a targeted
     * UPDATE and mirrored on the loaded entity. A no-op for an unpersisted
     * credential.
     *
     * @throws DatabaseException When the last-used update query fails
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     */
    public function touchLastUsed(): void
    {
        if ($this->entity->id === null) {
            return;
        }

        $now = TimeHelper::getSqlDateTime();

        DbWriteGuard::guardItemWrite(
            static::getCollectionKey(),
            (string)$this->entity->id,
            TruthSourceOperation::Update,
        );

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::string($now));
        $params->add(SqlParam::int($this->entity->id));
        Database::sql(
            'UPDATE `' . EntityPasskeyCredential::_table
                . '` SET `' . EntityPasskeyCredential::last_used_at
                . '` = ? WHERE `' . EntityPasskeyCredential::id . '` = ?',
            $params,
        );

        $this->entity->last_used_at = $now;
    }

    /**
     * Verifies a login assertion against this credential and records the successful use.
     *
     * Runs the WebAuthn assertion check with this credential's stored public key,
     * enrolled algorithm and signature counter; on success it advances the stored
     * counter to the value the authenticator reported (clone-detection baseline)
     * and stamps last use. Any verification failure throws before either write,
     * leaving the credential untouched.
     *
     * @param AssertionVerifier $verifier Configured assertion verifier
     * @param string $expectedChallenge base64url challenge recovered from the signed token
     * @param string $clientDataJson Raw clientDataJSON bytes returned by the client
     * @param string $authenticatorData Raw authenticatorData bytes returned by the client
     * @param string $signature Raw signature bytes returned by the client
     * @throws WebAuthnVerificationException When the assertion fails any client-data, signature or counter check
     * @throws DatabaseException When persisting the advanced counter or last-used stamp fails
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     */
    public function verifyAssertion(
        AssertionVerifier $verifier,
        string $expectedChallenge,
        string $clientDataJson,
        string $authenticatorData,
        string $signature,
    ): void {
        // The column is written only from a PasskeyAlgorithm case, so `from()` cannot
        // miss on a row this framework wrote; a miss would be a corrupted table.
        $newSignCount = $verifier->verify(
            $this->entity->public_key,
            PasskeyAlgorithm::from($this->entity->algorithm),
            $this->entity->sign_count,
            $expectedChallenge,
            $clientDataJson,
            $authenticatorData,
            $signature,
        );

        $this->updateSignCount($newSignCount);
        $this->touchLastUsed();
    }

    /**
     * Converts the credential to an associative array.
     *
     * The `publicKey` and `userHandle` are internal WebAuthn material and are not
     * carried here; the manage surface (HIL-404) shows only the display fields.
     *
     * @return array<string, mixed> Credential data (id, userId, credentialId, transports, aaguid, label, lastUsedAt)
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::credentialId => $this->entity->credential_id,
            self::transports => $this->entity->transports,
            self::aaguid => $this->entity->aaguid,
            self::label => $this->entity->label,
            self::lastUsedAt => $this->entity->last_used_at,
        ];
    }
}
