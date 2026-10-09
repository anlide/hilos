<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Auth\WebAuthn\AssertionVerifier;
use Hilos\Auth\WebAuthn\Exception\WebAuthnVerificationException;
use Hilos\Auth\WebAuthn\PasskeyAlgorithm;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\PasskeyCredential as EntityPasskeyCredential;
use Hilos\Database\Object\Collection\PasskeyCredentials as ObjectPasskeyCredentials;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * PasskeyCredential object - wraps PasskeyCredential entity.
 *
 * Exposes the credential's fields and a use of the key cut in two (HIL-1405):
 * {@see checkAssertion()} runs the WebAuthn assertion check against the stored
 * key and counter and writes nothing, and {@see recordUse()} - the write of the
 * person's agent - holds the counter rule once more against the stored counter,
 * then advances it ({@see updateSignCount()}, clone-detection) and stamps last
 * use ({@see touchLastUsed()}, passkey management, HIL-404).
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
class PasskeyCredential extends Object_
{
    public const string ENTITY_CLASS = EntityPasskeyCredential::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectPasskeyCredentials::class;
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
     * `signCount` is set only on a row not yet stored - the counter the insert carries
     * (HIL-1405) - and `lastUsedAt` not at all: on a stored row both are advanced only
     * through {@see recordUse()}, which writes with a targeted UPDATE and mirrors the
     * value on the loaded entity.
     *
     * @param string $property Name of a settable property (see the class @property list)
     * @param mixed $value Value to set
     * @throws DatabaseException When the property cannot be set on a PasskeyCredential, or the counter of a stored row is set
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::signCount => $this->entity->id === null
                ? $this->entity->sign_count = (int)$value
                : throw new DatabaseException('A stored passkey counter advances only through recordUse()'),
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
            $this->touchedSetKeys(...),
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
            $this->touchedSetKeys(...),
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
     * Checks an assertion against this credential and answers the counter it reported.
     *
     * The library's half of a use of the key (HIL-1405): the WebAuthn assertion check runs
     * with this credential's stored public key, enrolled algorithm and signature counter, and
     * nothing is written - the counter and the last-used stamp are the person's agent's to write,
     * through {@see recordUse()}, which holds the counter rule again because the hop between the
     * two lets a second assertion pass this check before the first is written.
     *
     * @param AssertionVerifier $verifier Configured assertion verifier
     * @param string $expectedChallenge base64url challenge recovered from the signed token
     * @param string $clientDataJson Raw clientDataJSON bytes returned by the client
     * @param string $authenticatorData Raw authenticatorData bytes returned by the client
     * @param string $signature Raw signature bytes returned by the client
     * @return int The authenticator's new signature counter
     * @throws WebAuthnVerificationException When the assertion fails any client-data, signature or counter check
     */
    public function checkAssertion(
        AssertionVerifier $verifier,
        string $expectedChallenge,
        string $clientDataJson,
        string $authenticatorData,
        string $signature,
    ): int {
        // The column is written only from a PasskeyAlgorithm case, so `from()` cannot
        // miss on a row this framework wrote; a miss would be a corrupted table.
        return $verifier->verify(
            $this->entity->public_key,
            PasskeyAlgorithm::from($this->entity->algorithm),
            $this->entity->sign_count,
            $expectedChallenge,
            $clientDataJson,
            $authenticatorData,
            $signature,
        );
    }

    /**
     * Records a use of the key: the counter it reported, and the time (HIL-1405).
     *
     * The write of the person's agent, after the library's {@see checkAssertion()}. The counter
     * rule is asked once more, against the counter stored now rather than the one the library
     * read: two assertions of a cloned key could both pass that check before either is written,
     * and the agent, as the one writer of the person's keys taking its frames one at a time,
     * is where the second one is caught. The stored counter is read with a targeted query, since
     * a counter written by another process never reaches a copy of the row loaded here. An
     * unchanged counter - the 0/0 pair of a key that never counts - is not written again.
     *
     * @param int $signCount Signature counter the authenticator reported
     * @throws WebAuthnVerificationException When the counter did not advance past the stored one (possible clone)
     * @throws DatabaseException When the counter lookup, the counter update or the last-used update fails
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     */
    public function recordUse(int $signCount): void
    {
        $storedSignCount = $this->storedSignCount();
        AssertionVerifier::assertCounterAdvances($storedSignCount, $signCount);
        if ($signCount !== $storedSignCount) {
            $this->updateSignCount($signCount);
        }

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

    /**
     * @return int Signature counter stored for this credential now, or the loaded one for an unpersisted row
     * @throws DatabaseException When the counter lookup query fails
     */
    private function storedSignCount(): int
    {
        if ($this->entity->id === null) {
            return $this->entity->sign_count;
        }

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($this->entity->id));
        $stored = Database::sql(
            'SELECT `' . EntityPasskeyCredential::sign_count . '` FROM `' . EntityPasskeyCredential::_table
                . '` WHERE `' . EntityPasskeyCredential::id . '` = ?',
            $params,
        )->first()?->first()[EntityPasskeyCredential::sign_count] ?? null;
        if ($stored === null) {
            throw new DatabaseException("Passkey credential {$this->entity->id} is not stored");
        }

        return (int)$stored;
    }
}
