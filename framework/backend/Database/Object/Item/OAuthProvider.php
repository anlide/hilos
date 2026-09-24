<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\OAuthProvider as EntityOAuthProvider;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;

/**
 * OAuthProvider object - wraps OAuthProvider entity.
 *
 * Exposes the provider's non-secret fields and the secret primitives of the
 * provider layer (HIL-286). The `client_secret` value is never exposed as a
 * property, in toArray(), or over the DB sync wire: it is read and written with
 * targeted queries here, the way the identity layer handles a password hash,
 * while the fact of its write travels as an empty diff.
 *
 * Whether a secret is set is remembered on the object (HIL-1080), because a provider's
 * readiness is asked on every handshake: a write in this process sets the answer, a write
 * in another process or node forgets it through the empty diff
 * ({@see applyDbSyncUnmappedUpdate()}), and the next question reads it again. The secret's
 * value is never remembered.
 *
 * @extends Object_<EntityOAuthProvider>
 *
 * @property-read ?int $id
 * @property string $providerKey
 * @property ?string $clientId
 * @property ?string $scope
 */
final class OAuthProvider extends Object_
{
    public const string ENTITY_CLASS = EntityOAuthProvider::class;
    public const string id = 'id';
    public const string providerKey = 'providerKey';
    public const string clientId = 'clientId';
    public const string scope = 'scope';

    /** Remembered answer of {@see hasClientSecret()}; null when never asked or forgotten. */
    private ?bool $clientSecretSet = null;

    /**
     * Returns the database collection key.
     *
     * @return string Collection key (HilosDbContext::oauthProviders)
     */
    protected static function getCollectionKey(): string
    {
        return HilosDbContext::oauthProviders;
    }

    /**
     * Magic getter for entity properties.
     *
     * @param string $property Property name (id, providerKey, clientId, scope)
     * @return mixed Property value
     * @throws DatabaseException When the property is not a known OAuthProvider field
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::providerKey => $this->entity->provider_key,
            self::clientId => $this->entity->client_id,
            self::scope => $this->entity->scope,
            default => parent::__get($property),
        };
    }

    /**
     * Magic setter for entity properties.
     *
     * The `client_secret` column has no setter here; it is written only through
     * {@see writeClientSecret()}.
     *
     * @param string $property Property name (providerKey, clientId, scope)
     * @param mixed $value Value to set
     * @throws DatabaseException When the property cannot be set on an OAuthProvider
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::providerKey => $this->entity->provider_key = (string)$value,
            self::clientId => $this->entity->client_id = is_scalar($value) ? (string)$value : null,
            self::scope => $this->entity->scope = is_scalar($value) ? (string)$value : null,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Reports whether this provider row carries a non-empty client secret.
     *
     * The set/not-set state is all that leaves the layer for the admin surface: the
     * secret is read with a targeted query and only the boolean is returned. False for
     * an unpersisted row.
     *
     * The answer is remembered on the object: a write of the secret in this process sets
     * it, and one in another process forgets it through the empty diff that announces it
     * ({@see applyDbSyncUnmappedUpdate()}), so only the first question after either reads
     * the row.
     *
     * @return bool True when the stored secret is a non-empty string
     * @throws DatabaseException When the secret lookup query fails
     */
    public function hasClientSecret(): bool
    {
        return $this->clientSecretSet ??= $this->readClientSecret() !== null;
    }

    /**
     * Reads the stored client secret for building the provider's live configuration.
     *
     * The one read that hands the value out, and its only reader is the resolver that
     * builds the configuration the token exchange runs on - the provider cannot be
     * talked to without it. Nothing on the way to a browser calls this. An empty
     * stored string reads as no secret, exactly as a NULL does.
     *
     * @return ?string Stored secret, or null when none is stored or the row is unpersisted
     * @throws DatabaseException When the secret lookup query fails
     */
    public function readClientSecret(): ?string
    {
        if ($this->entity->id === null) {
            return null;
        }

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($this->entity->id));
        $resultSet = Database::sql(
            'SELECT `' . EntityOAuthProvider::client_secret . '` FROM `' . EntityOAuthProvider::_table
                . '` WHERE `' . EntityOAuthProvider::id . '` = ?',
            $params,
        )->first();
        if ($resultSet === null) {
            return null;
        }

        $row = $resultSet->first();
        if ($row === null) {
            return null;
        }
        $secret = $row[EntityOAuthProvider::client_secret] ?? null;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /**
     * Replaces the stored client secret, or erases it with null (HIL-286).
     *
     * Written with a targeted UPDATE, so the secret stays out of the ORM columns, the
     * object/view surface, and the cross-worker sync payload values. The write is announced
     * through both ORM sync paths (db_sync_updated signal and source bus) with an empty diff,
     * so open screens redraw across processes without exposing the secret. A no-op for an
     * unpersisted row.
     *
     * @param ?string $secret New secret, or null to erase the stored one
     * @throws DatabaseException When the secret update query fails
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws SourceChangeSubscriberException Whatever a subscriber to the announcement raises
     * @throws ObjectGetIdStringNotImplementedException If getIdString() is not implemented or primary key is null
     */
    public function writeClientSecret(?string $secret): void
    {
        if ($this->entity->id === null) {
            return;
        }

        $idString = (string)$this->entity->id;
        DbWriteGuard::guardItemWrite(static::getCollectionKey(), $idString, TruthSourceOperation::Update);

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::auto($secret));
        $params->add(SqlParam::int($this->entity->id));
        Database::sql(
            'UPDATE `' . EntityOAuthProvider::_table . '` SET `' . EntityOAuthProvider::client_secret
                . '` = ? WHERE `' . EntityOAuthProvider::id . '` = ?',
            $params,
        );
        $this->clientSecretSet = $secret !== null && $secret !== '';

        $this->announceUnmappedUpdate();
    }

    /**
     * Forgets whether a secret is set, after another process wrote it.
     *
     * The secret's write travels as an empty diff, so this is the only news of it a process
     * that did not write gets; the next {@see hasClientSecret()} reads the row again.
     */
    public function applyDbSyncUnmappedUpdate(): void
    {
        $this->clientSecretSet = null;
    }

    /**
     * Converts the provider row to associative array (never includes the secret).
     *
     * @return array<string, mixed> Provider data (id, providerKey, clientId, scope)
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::providerKey => $this->entity->provider_key,
            self::clientId => $this->entity->client_id,
            self::scope => $this->entity->scope,
        ];
    }
}
