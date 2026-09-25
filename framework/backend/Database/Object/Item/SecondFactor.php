<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Item;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\SecondFactor as EntitySecondFactor;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * SecondFactor object - wraps the SecondFactor entity (HIL-494).
 *
 * One authenticator app of a person. {@see acceptStep()} is the replay guard: it takes a
 * code's 30-second step only when it is above the last one this app was accepted for,
 * and the condition rides on the write, so the database decides which of two workers
 * got the code first. The shared secret is DB-only and moves only through
 * {@see readSecret()} and {@see writeSecret()}.
 *
 * @extends Object_<EntitySecondFactor>
 *
 * @property-read ?int $id
 * @property int $userId
 * @property string $label
 * @property-read ?int $lastUsedStep
 * @property ?string $confirmedAt
 * @property-read ?string $lastUsedAt
 * @property string $createdAt
 */
final class SecondFactor extends Object_
{
    public const string ENTITY_CLASS = EntitySecondFactor::class;
    public const string id = 'id';
    public const string userId = 'userId';
    public const string label = 'label';
    public const string lastUsedStep = 'lastUsedStep';
    public const string confirmedAt = 'confirmedAt';
    public const string lastUsedAt = 'lastUsedAt';
    public const string createdAt = 'createdAt';

    /**
     * Returns the database collection key.
     *
     * @return string Collection key (HilosDbContext::secondFactors)
     */
    protected static function getCollectionKey(): string
    {
        return HilosDbContext::secondFactors;
    }

    /**
     * Magic getter for entity properties.
     *
     * @param string $property Property name (see class @property list)
     * @return mixed Property value
     * @throws DatabaseException When the property is not a known SecondFactor field
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::label => $this->entity->label,
            self::lastUsedStep => $this->entity->last_used_step,
            self::confirmedAt => $this->entity->confirmed_at,
            self::lastUsedAt => $this->entity->last_used_at,
            self::createdAt => $this->entity->created_at,
            default => parent::__get($property),
        };
    }

    /**
     * Magic setter for entity properties.
     *
     * `lastUsedStep` and `lastUsedAt` have no setter here; they move only through
     * {@see acceptStep()}, whose write carries the replay condition.
     *
     * @param string $property Name of a settable property (see the class @property list)
     * @param mixed $value Value to set
     * @throws DatabaseException When the property cannot be set on a SecondFactor
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::userId => $this->entity->user_id = (int)$value,
            self::label => $this->entity->label = (string)$value,
            self::confirmedAt => $this->entity->confirmed_at = $value === null ? null : (string)$value,
            self::createdAt => $this->entity->created_at = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Reads the stored shared secret for computing or checking a code.
     *
     * The one read that hands the value out. Its readers are the code check and the answer
     * that starts an enrolment, which has to show the secret once; nothing on the way to a
     * browser calls it otherwise.
     *
     * @return ?string Stored base32 secret, or null when none is stored or the row is unpersisted
     * @throws DatabaseException When the secret lookup query fails
     */
    public function readSecret(): ?string
    {
        if ($this->entity->id === null) {
            return null;
        }

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($this->entity->id));
        $row = Database::sql(
            'SELECT `' . EntitySecondFactor::secret . '` FROM `' . EntitySecondFactor::_table
                . '` WHERE `' . EntitySecondFactor::id . '` = ?',
            $params,
        )->firstRow();
        $secret = $row[EntitySecondFactor::secret] ?? null;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /**
     * Stores the shared secret of this authenticator.
     *
     * Written with a targeted UPDATE right after the row is inserted, so the secret stays
     * out of the ORM columns and the cross-worker sync payload. A no-op for an unpersisted row.
     *
     * @param string $secret Base32 shared secret
     * @throws DatabaseException When the secret update query fails
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     */
    public function writeSecret(string $secret): void
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
        $params->add(SqlParam::string($secret));
        $params->add(SqlParam::int($this->entity->id));
        Database::sql(
            'UPDATE `' . EntitySecondFactor::_table . '` SET `' . EntitySecondFactor::secret
                . '` = ? WHERE `' . EntitySecondFactor::id . '` = ?',
            $params,
        );
    }

    /**
     * Takes a code's time step for this authenticator, if no later or equal step was taken before.
     *
     * The replay guard of the second factor: a code seen once must not open the door twice,
     * even inside its own 30 seconds. The UPDATE carries `last_used_step < ?`, so of two
     * workers accepting the same code exactly one changes the row and gets true; false means
     * the step (or a later one) was already taken and never that the write failed - a failing
     * write throws. The winner's row is re-announced through {@see sync()}, so every reader of
     * the collection sees the new stamps. An unpersisted authenticator answers false.
     *
     * @param int $step Time step the code matched (Unix seconds divided by the period)
     * @return bool True when this call took the step, false when it was taken already
     * @throws DatabaseException When the update, the row count or the re-announcement fails
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws CreateNotAllowedException Never for a persisted row; declared by the re-announcing sync
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws ObjectGetIdStringNotImplementedException If the primary key is null during the re-announcement
     */
    public function acceptStep(int $step): bool
    {
        if ($this->entity->id === null) {
            return false;
        }

        DbWriteGuard::guardItemWrite(
            static::getCollectionKey(),
            (string)$this->entity->id,
            $this->touchedSetKeys(...),
            TruthSourceOperation::Update,
        );

        $now = TimeHelper::getSqlDateTime();
        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($step));
        $params->add(SqlParam::string($now));
        $params->add(SqlParam::int($this->entity->id));
        $params->add(SqlParam::int($step));
        Database::sql(
            'UPDATE `' . EntitySecondFactor::_table . '` SET `' . EntitySecondFactor::last_used_step . '` = ?, `'
                . EntitySecondFactor::last_used_at . '` = ? WHERE `' . EntitySecondFactor::id . '` = ?'
                . ' AND (`' . EntitySecondFactor::last_used_step . '` IS NULL OR `'
                . EntitySecondFactor::last_used_step . '` < ?)',
            $params,
        );
        if (Database::affectedRows() !== 1) {
            return false;
        }

        $this->entity->last_used_step = $step;
        $this->entity->last_used_at = $now;
        $this->sync();

        return true;
    }

    /**
     * Converts the authenticator to an associative array.
     *
     * The secret is DB-only and so never here.
     *
     * @return array<string, mixed> Authenticator data (id, userId, label, lastUsedStep, confirmedAt, lastUsedAt, createdAt)
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::label => $this->entity->label,
            self::lastUsedStep => $this->entity->last_used_step,
            self::confirmedAt => $this->entity->confirmed_at,
            self::lastUsedAt => $this->entity->last_used_at,
            self::createdAt => $this->entity->created_at,
        ];
    }
}
