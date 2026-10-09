<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
use Hilos\Database\Object\Collection\Identities as ObjectIdentities;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;

/**
 * Identity object - wraps Identity entity.
 *
 * Exposes the identity's non-secret fields and the {@see verifyPassword()}
 * primitive. The `secret` hash is never exposed as a property, in toArray(),
 * or over the DB sync bus.
 *
 * @extends Object_<EntityIdentity>
 *
 * @property-read ?int $id
 * @property ?int $userId
 * @property string $type
 * @property string $identifier
 * @property ?string $provider
 * @property bool $verified
 */
class Identity extends Object_
{
    public const string ENTITY_CLASS = EntityIdentity::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectIdentities::class;
    public const string id = 'id';
    public const string userId = 'userId';
    public const string type = 'type';
    public const string identifier = 'identifier';
    public const string provider = 'provider';
    public const string verified = 'verified';

    /**
     * Fixed bcrypt hash used only to equalize login response time on an unknown
     * identifier (see {@see verifyDummyPassword()}). Not a real credential.
     */
    private const string DUMMY_PASSWORD_HASH = '$2y$12$Dl.YAAr3YO3hR7hVxV56Gewg9CzLLWQqLQfTP0TdJj.o9lg9lhwiy';

    /**
     * Magic getter for entity properties.
     *
     * @param string $property Property name (id, userId, type, identifier, provider, verified)
     * @return mixed Property value
     * @throws DatabaseException When the property is not a known Identity field
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::type => $this->entity->type,
            self::identifier => $this->entity->identifier,
            self::provider => $this->entity->provider,
            self::verified => $this->entity->verified,
            default => parent::__get($property),
        };
    }

    /**
     * Magic setter for entity properties.
     *
     * The `secret` hash has no setter here; it is written only through the
     * identity layer's write paths - the creation, {@see setPasswordHash()} and
     * {@see clearPassword()}.
     *
     * @param string $property Property name (userId, type, identifier, provider, verified)
     * @param mixed $value Value to set
     * @throws DatabaseException When the property cannot be set on an Identity
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::userId => $this->entity->user_id = (int)$value,
            self::type => $this->entity->type = (string)$value,
            self::identifier => $this->entity->identifier = (string)$value,
            self::provider => $this->entity->provider = is_scalar($value) ? (string)$value : null,
            self::verified => $this->entity->verified = (bool)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Verifies a plaintext secret against this identity's stored hash.
     *
     * Verify primitive of the identity layer: the hash is read with a targeted
     * query (it is not ORM-mapped) and compared in place, so only the boolean
     * result leaves the identity layer. Returns false for identities with no
     * secret (external methods) and for an unpersisted identity.
     *
     * @param string $plainPassword Plaintext secret to check
     * @return bool True when the secret matches the stored hash
     * @throws DatabaseException When the secret lookup query fails
     */
    public function verifyPassword(string $plainPassword): bool
    {
        if ($plainPassword === '') {
            return false;
        }

        $secret = $this->storedSecret();

        return $secret !== null && password_verify($plainPassword, $secret);
    }

    /**
     * Tells whether the stored hash was written under parameters that are no longer current.
     *
     * The read half of rehash-on-login (HIL-162): after the password has verified, the sign-in
     * asks this, and only a yes sends the fresh hash to the agent of the person, who writes it
     * through {@see setPasswordHash()} (HIL-1405). The hash is read with the targeted query
     * {@see verifyPassword()} uses and never leaves the layer. False for an unpersisted identity
     * and for one with no secret.
     *
     * @return bool True when {@see password_needs_rehash()} reports an algorithm or cost drift
     * @throws DatabaseException When the secret lookup query fails
     */
    public function passwordNeedsRehash(): bool
    {
        $secret = $this->storedSecret();

        return $secret !== null && password_needs_rehash($secret, PASSWORD_DEFAULT);
    }

    /**
     * Stores a password hash minted elsewhere as this identity's secret (HIL-1405).
     *
     * Secret-update write path of the identity layer, the write the person's agent runs for a
     * rehash on sign-in, a recovery and a change in the profile. The hash is minted by
     * {@see hashPassword()} where the password arrived, so the password itself never travels to
     * the writer; the hash is written with a targeted UPDATE and so stays out of the ORM columns,
     * the object/view surface and the cross-worker sync bus. A no-op for an unpersisted identity
     * or an empty hash.
     *
     * @param string $passwordHash `password_hash()` value to store as the secret
     * @throws DatabaseException When the secret update query fails
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     */
    public function setPasswordHash(string $passwordHash): void
    {
        if ($this->entity->id === null || $passwordHash === '') {
            return;
        }

        DbWriteGuard::guardItemWrite(
            static::getCollectionKey(),
            (string)$this->entity->id,
            $this->touchedSetKeys(...),
            TruthSourceOperation::Update,
        );

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::string($passwordHash));
        $params->add(SqlParam::int($this->entity->id));
        Database::sql(
            'UPDATE `' . EntityIdentity::_table . '` SET `' . EntityIdentity::secret . '` = ? WHERE `' . EntityIdentity::id . '` = ?',
            $params,
        );
    }

    /**
     * Erases this identity's secret, leaving the row and its address in place (HIL-692).
     *
     * The demotion half of an account merge: a password that did not survive stops being
     * a credential, while the address it carries stays the person's. Deleting the row
     * instead would take away their way in through that address altogether. Written with
     * the same targeted UPDATE {@see setPasswordHash()} uses, so the secret column stays out
     * of the ORM columns, the object/view surface, and the cross-worker sync bus - here
     * the split matters in the other direction, since the erase must reach the column
     * that no sync carries. A no-op for an unpersisted identity.
     *
     * @throws DatabaseException When the secret update query fails
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     */
    public function clearPassword(): void
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
        $params->add(SqlParam::int($this->entity->id));
        Database::sql(
            'UPDATE `' . EntityIdentity::_table . '` SET `' . EntityIdentity::secret . '` = NULL WHERE `' . EntityIdentity::id . '` = ?',
            $params,
        );
    }

    /**
     * Marks this identity verified (idempotent), flipping the `verified` flag.
     *
     * Verify-flip write path of the identity layer, opened by the register-confirm
     * leaf (HIL-365). The flip goes through {@see sync()} like any other column of the
     * row, so it is announced to every reader: registration creates the row unproven
     * and flips it a moment later, and a flip written past the announcement left the
     * other workers holding the created row - unproven - for good (HIL-299). A no-op
     * for an unpersisted identity; flipping a row already verified writes nothing.
     *
     * @throws DatabaseException When the verified update query fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws CreateNotAllowedException When the sync would add the row rather than update it, and nothing here may
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     */
    public function markVerified(): void
    {
        if ($this->entity->id === null) {
            return;
        }

        $this->entity->verified = true;
        $this->sync();
    }

    /**
     * Runs a throwaway hash verification to equalize login response time.
     *
     * Anti-enumeration companion of {@see verifyPassword()}: on an unknown
     * identifier the login handler has no stored hash to check, so it spends the
     * same bcrypt cost against a fixed dummy hash. The boolean result is
     * intentionally discarded — only the elapsed time matters.
     *
     * @param string $plainPassword Submitted plaintext to verify against the dummy hash
     */
    public static function verifyDummyPassword(string $plainPassword): void
    {
        password_verify($plainPassword, self::DUMMY_PASSWORD_HASH);
    }

    /**
     * Hashes a password for storage, with the parameters the layer stores every password under.
     *
     * The one place a password becomes its hash (HIL-1405): the creation paths and the process
     * that received a new password from a browser call it, and only the hash travels on - to the
     * insert, or in a frame to the person's agent, which writes it with {@see setPasswordHash()}.
     *
     * @param string $plainPassword Plaintext password
     * @return string `password_hash()` value of the password
     * @throws EmptyValueException When the password is empty
     */
    public static function hashPassword(string $plainPassword): string
    {
        if ($plainPassword === '') {
            throw new EmptyValueException('Password is required');
        }

        return password_hash($plainPassword, PASSWORD_DEFAULT);
    }

    /**
     * Converts identity to associative array (never includes the secret).
     *
     * @return array<string, mixed> Identity data (id, userId, type, identifier, provider, verified)
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::type => $this->entity->type,
            self::identifier => $this->entity->identifier,
            self::provider => $this->entity->provider,
            self::verified => $this->entity->verified,
        ];
    }

    /**
     * Reads the stored hash with a targeted query; it is not ORM-mapped.
     *
     * @return ?string The hash, or null for an unpersisted identity or one with no secret
     * @throws DatabaseException When the secret lookup query fails
     */
    private function storedSecret(): ?string
    {
        if ($this->entity->id === null) {
            return null;
        }

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($this->entity->id));
        $secret = Database::sql(
            'SELECT `' . EntityIdentity::secret . '` FROM `' . EntityIdentity::_table . '` WHERE `' . EntityIdentity::id . '` = ?',
            $params,
        )->first()?->first()[EntityIdentity::secret] ?? null;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
