<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Identity as EntityIdentity;
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
 * @property-read ?int $userId
 * @property string $type
 * @property string $identifier
 * @property ?string $provider
 * @property bool $verified
 */
final class Identity extends Object_
{
    public const string ENTITY_CLASS = EntityIdentity::class;
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
     * Returns the database collection key.
     *
     * @return string Collection key (HilosDbContext::identities)
     */
    protected static function getCollectionKey(): string
    {
        return HilosDbContext::identities;
    }

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
     * identity layer's write path in the consuming leaves (register / rehash).
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
        if ($this->entity->id === null || $plainPassword === '') {
            return false;
        }

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($this->entity->id));
        $resultSet = Database::sql(
            'SELECT `' . EntityIdentity::secret . '` FROM `' . EntityIdentity::_table . '` WHERE `' . EntityIdentity::id . '` = ?',
            $params,
        )->first();
        if ($resultSet === null) {
            return false;
        }

        $row = $resultSet->first();
        if ($row === null) {
            return false;
        }
        $secret = $row[EntityIdentity::secret] ?? null;

        return is_string($secret) && $secret !== '' && password_verify($plainPassword, $secret);
    }

    /**
     * Re-hashes the stored password when the current hash is outdated.
     *
     * Rehash-on-login primitive of the identity layer (HIL-162): after the
     * plaintext has verified, the hash is read and, when
     * {@see password_needs_rehash()} reports an algorithm/cost drift, rewritten
     * with a targeted query so the hash never leaves the layer. A no-op for an
     * unpersisted identity, one with no secret, or a hash already at the current
     * parameters.
     *
     * @param string $plainPassword Plaintext secret that just verified against the stored hash
     * @throws DatabaseException When the secret lookup or update query fails
     */
    public function rehashPasswordIfNeeded(string $plainPassword): void
    {
        if ($this->entity->id === null || $plainPassword === '') {
            return;
        }

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($this->entity->id));
        $resultSet = Database::sql(
            'SELECT `' . EntityIdentity::secret . '` FROM `' . EntityIdentity::_table . '` WHERE `' . EntityIdentity::id . '` = ?',
            $params,
        )->first();
        if ($resultSet === null) {
            return;
        }

        $row = $resultSet->first();
        if ($row === null) {
            return;
        }
        $secret = $row[EntityIdentity::secret] ?? null;
        if (!is_string($secret) || $secret === '' || !password_needs_rehash($secret, PASSWORD_DEFAULT)) {
            return;
        }

        $updateParams = SqlParamCollection::empty();
        $updateParams->add(SqlParam::string(password_hash($plainPassword, PASSWORD_DEFAULT)));
        $updateParams->add(SqlParam::int($this->entity->id));
        Database::sql(
            'UPDATE `' . EntityIdentity::_table . '` SET `' . EntityIdentity::secret . '` = ? WHERE `' . EntityIdentity::id . '` = ?',
            $updateParams,
        );
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
}
