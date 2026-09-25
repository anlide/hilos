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
use Hilos\Database\Entity\Item\SecondFactorReset as EntitySecondFactorReset;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * SecondFactorReset object - wraps the SecondFactorReset entity (HIL-494).
 *
 * A delayed removal of a person's second factor. It ends one of two ways, and only one:
 * {@see cancel()} and {@see complete()} both carry the condition that neither has
 * happened on their write, so a cancel link clicked in the minute the removal runs
 * decides the race in the database.
 *
 * @extends Object_<EntitySecondFactorReset>
 *
 * @property-read ?int $id
 * @property int $userId
 * @property string $requestedAt
 * @property string $effectiveAt
 * @property string $cancelTokenHash
 * @property string $notifiedAt
 * @property-read ?string $canceledAt
 * @property-read ?string $completedAt
 */
final class SecondFactorReset extends Object_
{
    public const string ENTITY_CLASS = EntitySecondFactorReset::class;
    public const string id = 'id';
    public const string userId = 'userId';
    public const string requestedAt = 'requestedAt';
    public const string effectiveAt = 'effectiveAt';
    public const string cancelTokenHash = 'cancelTokenHash';
    public const string notifiedAt = 'notifiedAt';
    public const string canceledAt = 'canceledAt';
    public const string completedAt = 'completedAt';

    /**
     * Returns the database collection key.
     *
     * @return string Collection key (HilosDbContext::secondFactorResets)
     */
    protected static function getCollectionKey(): string
    {
        return HilosDbContext::secondFactorResets;
    }

    /**
     * Magic getter for entity properties.
     *
     * @param string $property Property name (see class @property list)
     * @return mixed Property value
     * @throws DatabaseException When the property is not a known SecondFactorReset field
     */
    public function __get(string $property): mixed
    {
        return match ($property) {
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::requestedAt => $this->entity->requested_at,
            self::effectiveAt => $this->entity->effective_at,
            self::cancelTokenHash => $this->entity->cancel_token_hash,
            self::notifiedAt => $this->entity->notified_at,
            self::canceledAt => $this->entity->canceled_at,
            self::completedAt => $this->entity->completed_at,
            default => parent::__get($property),
        };
    }

    /**
     * Magic setter for entity properties.
     *
     * `canceledAt` and `completedAt` have no setter here; they move only through
     * {@see cancel()} and {@see complete()}, whose writes carry the race condition.
     *
     * @param string $property Name of a settable property (see the class @property list)
     * @param mixed $value Value to set
     * @throws DatabaseException When the property cannot be set on a SecondFactorReset
     */
    public function __set(string $property, mixed $value): void
    {
        match ($property) {
            self::userId => $this->entity->user_id = (int)$value,
            self::requestedAt => $this->entity->requested_at = (string)$value,
            self::effectiveAt => $this->entity->effective_at = (string)$value,
            self::cancelTokenHash => $this->entity->cancel_token_hash = (string)$value,
            self::notifiedAt => $this->entity->notified_at = (string)$value,
            default => parent::__set($property, $value),
        };
    }

    /**
     * Whether the request is still waiting - neither canceled nor carried out.
     *
     * @return bool True while the request stands
     */
    public function isLive(): bool
    {
        return $this->entity->canceled_at === null && $this->entity->completed_at === null;
    }

    /**
     * Cancels the request, if it still stands.
     *
     * @return bool True when this call canceled it, false when it was canceled or carried out already
     * @throws DatabaseException When the update, the row count or the re-announcement fails
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws CreateNotAllowedException Never for a persisted row; declared by the re-announcing sync
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws ObjectGetIdStringNotImplementedException If the primary key is null during the re-announcement
     */
    public function cancel(): bool
    {
        return $this->end(EntitySecondFactorReset::canceled_at);
    }

    /**
     * Marks the request carried out, if it still stands.
     *
     * Called by the sweep that removes the factor; it removes the factor only when this answers
     * true, so a cancel that won the race leaves the factor where it was.
     *
     * @return bool True when this call ended it, false when it was canceled or carried out already
     * @throws DatabaseException When the update, the row count or the re-announcement fails
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws CreateNotAllowedException Never for a persisted row; declared by the re-announcing sync
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws ObjectGetIdStringNotImplementedException If the primary key is null during the re-announcement
     */
    public function complete(): bool
    {
        return $this->end(EntitySecondFactorReset::completed_at);
    }

    /**
     * Stamps one of the two ending columns under the condition that neither is stamped yet.
     *
     * Of two workers ending the same request exactly one changes the row; the winner's row is
     * re-announced through {@see sync()}. An unpersisted request answers false.
     *
     * @param string $column Ending column to stamp (canceled_at or completed_at)
     * @return bool True when this call ended the request
     * @throws DatabaseException When the update, the row count or the re-announcement fails
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws CreateNotAllowedException Never for a persisted row; declared by the re-announcing sync
     * @throws SourceChangeSubscriberException Whatever a subscriber to the update announcement raises
     * @throws InvalidArgumentException When the queued DB-sync signal cannot be named
     * @throws ObjectGetIdStringNotImplementedException If the primary key is null during the re-announcement
     */
    private function end(string $column): bool
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
        $params->add(SqlParam::string($now));
        $params->add(SqlParam::int($this->entity->id));
        Database::sql(
            'UPDATE `' . EntitySecondFactorReset::_table . '` SET `' . $column . '` = ? WHERE `'
                . EntitySecondFactorReset::id . '` = ?'
                . ' AND `' . EntitySecondFactorReset::canceled_at . '` IS NULL'
                . ' AND `' . EntitySecondFactorReset::completed_at . '` IS NULL',
            $params,
        );
        if (Database::affectedRows() !== 1) {
            return false;
        }

        if ($column === EntitySecondFactorReset::canceled_at) {
            $this->entity->canceled_at = $now;
        } else {
            $this->entity->completed_at = $now;
        }
        $this->sync();

        return true;
    }

    /**
     * Converts the request to an associative array (never includes the token hash).
     *
     * @return array<string, mixed> Request data (id, userId, requestedAt, effectiveAt, notifiedAt, canceledAt, completedAt)
     */
    public function toArray(): array
    {
        return [
            self::id => $this->entity->id,
            self::userId => $this->entity->user_id,
            self::requestedAt => $this->entity->requested_at,
            self::effectiveAt => $this->entity->effective_at,
            self::notifiedAt => $this->entity->notified_at,
            self::canceledAt => $this->entity->canceled_at,
            self::completedAt => $this->entity->completed_at,
        ];
    }
}
