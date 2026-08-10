<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\UserVerification as EntityUserVerification;
use Hilos\Database\Object\Collection\UserVerifications;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * UserVerification object - wraps UserVerification entity.
 *
 * Exposes the challenge's non-secret fields and the verify/increment/consume
 * primitives. The `code_hash` is never exposed as a property, in toArray(), or
 * over the DB sync bus; it is minted by
 * {@see UserVerifications::createChallenge()}
 * and read only by {@see verifyCode()}.
 *
 * @extends Object_<EntityUserVerification>
 *
 * @property-read ?int $id
 * @property ?int $userId
 * @property string $type
 * @property string $identifier
 * @property-read int $attempts
 * @property string $expiresAt
 * @property-read ?string $consumedAt
 */
final class UserVerification extends Object_
{
    public const string ENTITY_CLASS = EntityUserVerification::class;
    public const string id = 'id';
    public const string userId = 'userId';
    public const string type = 'type';
    public const string identifier = 'identifier';
    public const string attempts = 'attempts';
    public const string expiresAt = 'expiresAt';
    public const string consumedAt = 'consumedAt';

    /**
     * Returns the database collection key.
     *
     * @return string Collection key (HilosDbContext::verifications)
     */
    protected static function getCollectionKey(): string
    {
        return HilosDbContext::verifications;
    }

    /**
     * Magic getter for entity properties.
     *
     * @param string $property Property name (id, userId, type, identifier, attempts, expiresAt, consumedAt)
     * @return mixed Property value
     * @throws DatabaseException When the property is not a known UserVerification field
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::type => $this->entity->type,
            self::identifier => $this->entity->identifier,
            self::attempts => $this->entity->attempts,
            self::expiresAt => $this->entity->expires_at,
            self::consumedAt => $this->entity->consumed_at,
            default => parent::__get($property),
        };
    }

    /**
     * Magic setter for entity properties.
     *
     * The `code_hash` has no setter here; it is written only through the
     * verification layer's mint path ({@see UserVerifications::createChallenge()}).
     *
     * @param string $property Property name (userId, type, identifier, expiresAt)
     * @param mixed $value Value to set
     * @throws DatabaseException When the property cannot be set on a UserVerification
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::userId => $this->entity->user_id = $value === null ? null : (int)$value,
            self::type => $this->entity->type = (string)$value,
            self::identifier => $this->entity->identifier = (string)$value,
            self::expiresAt => $this->entity->expires_at = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Whether this challenge is still usable at the given moment.
     *
     * Active = not consumed, not past its expiry, and under the attempt ceiling.
     * Datetime comparison is lexicographic on the SQL 'Y-m-d H:i:s' format, which
     * orders the same as chronological order.
     *
     * @param string $nowSql Current time as an SQL datetime string
     * @param int $maxAttempts Attempt ceiling (challenge is inactive once reached)
     * @return bool True when the challenge can still be verified
     */
    public function isActive(string $nowSql, int $maxAttempts): bool
    {
        return $this->entity->consumed_at === null
            && $this->entity->expires_at > $nowSql
            && $this->entity->attempts < $maxAttempts;
    }

    /**
     * Verifies a plaintext code against this challenge's stored hash.
     *
     * Verify primitive of the verification layer, symmetric with the identity
     * layer's {@see Identity::verifyPassword()}: the
     * hash is read with a targeted query (it is not ORM-mapped) and compared in
     * place, so only the boolean result leaves the layer. Returns false for an
     * unpersisted challenge.
     *
     * @param string $plainCode Plaintext code to check
     * @return bool True when the code matches the stored hash
     * @throws DatabaseException When the code hash lookup query fails
     */
    public function verifyCode(string $plainCode): bool
    {
        if ($this->entity->id === null || $plainCode === '') {
            return false;
        }

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($this->entity->id));
        $resultSet = Database::sql(
            'SELECT `' . EntityUserVerification::code_hash . '` FROM `' . EntityUserVerification::_table
                . '` WHERE `' . EntityUserVerification::id . '` = ?',
            $params,
        )->first();
        if ($resultSet === null) {
            return false;
        }

        $row = $resultSet->first();
        if ($row === null) {
            return false;
        }
        $codeHash = $row[EntityUserVerification::code_hash] ?? null;

        return is_string($codeHash) && $codeHash !== '' && password_verify($plainCode, $codeHash);
    }

    /**
     * Records one verification attempt against this challenge.
     *
     * Attempt-throttle primitive: increments the counter with a targeted UPDATE
     * and mirrors it on the loaded entity, so a caller can read the new count to
     * decide whether the ceiling has been reached. A no-op for an unpersisted
     * challenge.
     *
     * @throws DatabaseException When the attempt update query fails
     */
    public function incrementAttempts(): void
    {
        if ($this->entity->id === null) {
            return;
        }

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($this->entity->id));
        Database::sql(
            'UPDATE `' . EntityUserVerification::_table
                . '` SET `' . EntityUserVerification::attempts . '` = `' . EntityUserVerification::attempts
                . '` + 1 WHERE `' . EntityUserVerification::id . '` = ?',
            $params,
        );

        $this->entity->attempts++;
    }

    /**
     * Marks this challenge consumed (single-use), stamping the consume time.
     *
     * Single-use primitive: a consumed challenge is excluded from
     * {@see UserVerifications::findActive()}, so it can never be verified or
     * re-issued against again. A no-op for an unpersisted challenge.
     *
     * @throws DatabaseException When the consume update query fails
     */
    public function consume(): void
    {
        if ($this->entity->id === null) {
            return;
        }

        $now = TimeHelper::getSqlDateTime();

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::string($now));
        $params->add(SqlParam::int($this->entity->id));
        Database::sql(
            'UPDATE `' . EntityUserVerification::_table . '` SET `' . EntityUserVerification::consumed_at
                . '` = ? WHERE `' . EntityUserVerification::id . '` = ?',
            $params,
        );

        $this->entity->consumed_at = $now;
    }

    /**
     * Ages this challenge into the past so {@see isActive()} reads it as expired.
     *
     * Test-only expiry primitive backing the `verification:test:expire` CLI
     * (HIL-317): rewrites `expires_at` to just before now with a targeted UPDATE
     * and mirrors it on the loaded entity, so an e2e can drive the "invalid or
     * expired code" verify path without waiting out the TTL. Symmetric with
     * {@see consume()} — targeted write plus entity mirror, no sync and no
     * TruthSourceRegistry. A no-op for an unpersisted challenge.
     *
     * @throws DatabaseException When the expiry update query fails
     */
    public function expire(): void
    {
        if ($this->entity->id === null) {
            return;
        }

        $pastExpiry = date('Y-m-d H:i:s', time() - 1);

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::string($pastExpiry));
        $params->add(SqlParam::int($this->entity->id));
        Database::sql(
            'UPDATE `' . EntityUserVerification::_table . '` SET `' . EntityUserVerification::expires_at
                . '` = ? WHERE `' . EntityUserVerification::id . '` = ?',
            $params,
        );

        $this->entity->expires_at = $pastExpiry;
    }

    /**
     * Converts the challenge to an associative array (never includes the code hash).
     *
     * @return array<string, mixed> Verification data (id, userId, type, identifier, attempts, expiresAt, consumedAt)
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::type => $this->entity->type,
            self::identifier => $this->entity->identifier,
            self::attempts => $this->entity->attempts,
            self::expiresAt => $this->entity->expires_at,
            self::consumedAt => $this->entity->consumed_at,
        ];
    }
}
