<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Item\SessionActions;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\Session as EntitySession;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;

/**
 * Session - Object wrapper for the hilos_session entity.
 *
 * @extends Object_<EntitySession>
 *
 * @property-read ?int $id
 * @property string $token
 * @property ?int $userId
 * @property ?int $impersonatorUserId
 * @property string $createdAt
 * @property string $lastSeenAt
 * @property ?string $expiresAt
 * @property ?string $pendingRegistrationIdentifier
 * @property ?string $pendingRegistrationSince
 * @property ?string $pendingAck
 * @property ?int $pendingSecondFactorUserId
 * @property ?string $pendingSecondFactorMode
 * @property ?string $pendingSecondFactorUntil
 * @property int $pendingSecondFactorAttempts
 * @property ?string $pendingSecondFactorAck
 * @property ?string $deviceName
 * @property ?int $blockedUserId
 */
final class Session extends Object_
{
    public const string ENTITY_CLASS = EntitySession::class;

    public const string id = 'id';
    public const string token = 'token';
    public const string userId = 'userId';
    public const string impersonatorUserId = 'impersonatorUserId';
    public const string createdAt = 'createdAt';
    public const string lastSeenAt = 'lastSeenAt';
    public const string expiresAt = 'expiresAt';
    public const string pendingRegistrationIdentifier = 'pendingRegistrationIdentifier';
    public const string pendingRegistrationSince = 'pendingRegistrationSince';
    public const string pendingAck = 'pendingAck';
    public const string pendingSecondFactorUserId = 'pendingSecondFactorUserId';
    public const string pendingSecondFactorMode = 'pendingSecondFactorMode';
    public const string pendingSecondFactorUntil = 'pendingSecondFactorUntil';
    public const string pendingSecondFactorAttempts = 'pendingSecondFactorAttempts';
    public const string pendingSecondFactorAck = 'pendingSecondFactorAck';
    public const string deviceName = 'deviceName';
    public const string blockedUserId = 'blockedUserId';

    /**
     * Returns the database collection key for this object type.
     *
     * @return string Collection key (HilosDbContext::sessions)
     */
    protected static function getCollectionKey(): string
    {
        return HilosDbContext::sessions;
    }

    /**
     * Returns the value of a session object property by name.
     *
     * @param string $property Property name (id, token, userId, impersonatorUserId, createdAt,
     *     lastSeenAt, expiresAt, pendingRegistrationIdentifier, pendingRegistrationSince, pendingAck,
     *     pendingSecondFactorUserId, pendingSecondFactorMode, pendingSecondFactorUntil,
     *     pendingSecondFactorAttempts, pendingSecondFactorAck, deviceName, blockedUserId)
     * @return mixed Property value or parent method result
     * @throws DatabaseException If entity access fails
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::token => $this->entity->token,
            self::userId => $this->entity->user_id,
            self::impersonatorUserId => $this->entity->impersonator_user_id,
            self::createdAt => $this->entity->created_at,
            self::lastSeenAt => $this->entity->last_seen_at,
            self::expiresAt => $this->entity->expires_at,
            self::pendingRegistrationIdentifier => $this->entity->pending_registration_identifier,
            self::pendingRegistrationSince => $this->entity->pending_registration_since,
            self::pendingAck => $this->entity->pending_ack,
            self::pendingSecondFactorUserId => $this->entity->pending_second_factor_user_id,
            self::pendingSecondFactorMode => $this->entity->pending_second_factor_mode,
            self::pendingSecondFactorUntil => $this->entity->pending_second_factor_until,
            self::pendingSecondFactorAttempts => $this->entity->pending_second_factor_attempts,
            self::pendingSecondFactorAck => $this->entity->pending_second_factor_ack,
            self::deviceName => $this->entity->device_name,
            self::blockedUserId => $this->entity->blocked_user_id,
            default => parent::__get($property),
        };
    }

    /**
     * Sets the value of a session object property.
     *
     * @param string $property Property name to set
     * @param mixed $value New value (cast to appropriate type)
     * @throws DatabaseException If entity sync fails
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::token => $this->entity->token = (string)$value,
            self::userId => $this->entity->user_id = $value === null ? null : (int)$value,
            self::impersonatorUserId => $this->entity->impersonator_user_id = $value === null ? null : (int)$value,
            self::createdAt => $this->entity->created_at = (string)$value,
            self::lastSeenAt => $this->entity->last_seen_at = (string)$value,
            self::expiresAt => $this->entity->expires_at = is_scalar($value) ? (string)$value : null,
            self::pendingRegistrationIdentifier => $this->entity->pending_registration_identifier
                = is_scalar($value) ? (string)$value : null,
            self::pendingRegistrationSince => $this->entity->pending_registration_since
                = is_scalar($value) ? (string)$value : null,
            self::pendingAck => $this->entity->pending_ack = is_scalar($value) ? (string)$value : null,
            self::pendingSecondFactorUserId => $this->entity->pending_second_factor_user_id
                = $value === null ? null : (int)$value,
            self::pendingSecondFactorMode => $this->entity->pending_second_factor_mode
                = is_scalar($value) ? (string)$value : null,
            self::pendingSecondFactorUntil => $this->entity->pending_second_factor_until
                = is_scalar($value) ? (string)$value : null,
            self::pendingSecondFactorAttempts => $this->entity->pending_second_factor_attempts = (int)$value,
            self::pendingSecondFactorAck => $this->entity->pending_second_factor_ack
                = is_scalar($value) ? (string)$value : null,
            self::deviceName => $this->entity->device_name = is_scalar($value) ? (string)$value : null,
            self::blockedUserId => $this->entity->blocked_user_id = $value === null ? null : (int)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Converts the session object to an associative array with its non-marker fields.
     *
     * `impersonatorUserId`, the `pendingRegistration*` pair, `pendingAck`, the
     * `pendingSecondFactor*` group and `blockedUserId` are intentionally excluded: all five are read-legal server-side (guards
     * and the session host read them via {@see __get}) but are kept off the browser-sync projection — the
     * impersonating state, the unfinished registration, the announcement the session
     * still owes, the second-factor step it waits on and the blocked account it lost are surfaced to the frontend through the
     * session state frame and the handshake response, not this row.
     *
     * @return array<string, mixed> Key => value array
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::token => $this->entity->token,
            self::userId => $this->entity->user_id,
            self::createdAt => $this->entity->created_at,
            self::lastSeenAt => $this->entity->last_seen_at,
            self::expiresAt => $this->entity->expires_at,
            self::deviceName => $this->entity->device_name,
        ];
    }

    /**
     * Counts one wrong second-factor code against this session's wait and answers the count (HIL-494).
     *
     * The count is kept by the ROW: the increment is one statement carrying the condition that
     * a wait is still there, so of two wrong codes sent at once from two tabs both are counted
     * and neither is lost to a read-modify-write. The mirror is re-read afterwards, because the
     * holder judges the ceiling by it. A session that no longer waits answers zero - there is no
     * wait to count against - and an unpersisted one does too.
     *
     * @return int Wrong codes the wait has taken after this one, or 0 when the session no longer waits
     * @throws DatabaseException When the update, the row count or the read-back fails
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     */
    public function countSecondFactorMiss(): int
    {
        if ($this->entity->id === null) {
            return 0;
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
            'UPDATE `' . EntitySession::_table . '` SET `' . EntitySession::pending_second_factor_attempts
                . '` = `' . EntitySession::pending_second_factor_attempts . '` + 1 WHERE `' . EntitySession::id . '` = ?'
                . ' AND `' . EntitySession::pending_second_factor_user_id . '` IS NOT NULL',
            $params,
        );
        if (Database::affectedRows() !== 1) {
            return 0;
        }

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($this->entity->id));
        $row = Database::sql(
            'SELECT `' . EntitySession::pending_second_factor_attempts . '` FROM `' . EntitySession::_table
                . '` WHERE `' . EntitySession::id . '` = ?',
            $params,
        )->firstRow();
        if ($row === null) {
            return 0;
        }

        $this->entity->pending_second_factor_attempts = (int)$row[EntitySession::pending_second_factor_attempts];
        $this->entitySync->pending_second_factor_attempts = $this->entity->pending_second_factor_attempts;

        return $this->entity->pending_second_factor_attempts;
    }

    /**
     * Returns the person at the keyboard of this session, or null when nobody is.
     *
     * The administrator behind an impersonation is the human being at the keyboard; the
     * impersonated account is what is being looked at, not who is looking. A session with
     * no person in it answers nobody whatever its marker says: a row left with a marker and
     * no user must not name its administrator, because the administrator is not there. That
     * branch is a belt over a door already shut - {@see SessionActions::unbindUser()} lowers
     * the marker together with the person since HIL-1061, and stopping an impersonation
     * wears the same belt - so it covers only rows written by the code before that.
     *
     * @return ?int User id of whoever is at the keyboard, or null when the session is anonymous
     * @throws DatabaseException If entity access fails
     */
    public function userAtKeyboard(): ?int
    {
        if ($this->userId === null) {
            return null;
        }

        return $this->impersonatorUserId ?? $this->userId;
    }
}
