<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\DbWriteGuard;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\Notifications as EntityNotifications;
use Hilos\Database\Entity\Item\Notification as EntityNotification;
use Hilos\Database\Exception\TableNotActivatedException;
use Hilos\Database\Object\Item\Notification as ObjectNotification;
use Hilos\Database\Object\Objects;
use Hilos\Database\Schema\Schema;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\Database\SqlSortDirection;
use Hilos\Notification\HilosNotifier;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * Notifications object collection.
 *
 * Persistence primitives of the durable notification model (HIL-102): create a
 * notification for a recipient, list a recipient's notifications, count the
 * recipient's unread, and mark all of a recipient's notifications read. The
 * per-item mark-read primitive lives on {@see ObjectNotification} via its item
 * actions; the emit orchestration and the live signal fan live in
 * {@see HilosNotifier}.
 *
 * @extends Objects<ObjectNotification>
 * @method ObjectNotification|null current()
 * @method ObjectNotification|null first()
 * @method ObjectNotification|null last()
 * @method ObjectNotification|null get(int|string $key)
 * @method ObjectNotification|null offsetGet(mixed $offset)
 */
final class Notifications extends Objects
{
    public const string OBJECT_CLASS = ObjectNotification::class;
    public const string ENTITY_COLLECTION_CLASS = EntityNotifications::class;
    public const string COLLECTION_KEY = HilosDbContext::notifications;

    /** Whether the activation of the notification table has already been confirmed. */
    private bool $tableActivationConfirmed = false;

    /**
     * Persists a new notification for a recipient.
     *
     * The durable write behind {@see HilosNotifier::emit()}:
     * the row is inserted through the ORM (which assigns the id and stamps
     * `created_at`), so a caller can read the id back to correlate the live signal.
     *
     * @param int $userId Recipient user id
     * @param string $type Machine notification type (e.g. backup.completed)
     * @param string $severity Severity level (see NotificationSeverity)
     * @param string $title Rendered title (default locale at emit)
     * @param ?string $body Rendered body, or null
     * @param ?string $data Structured context as a JSON string, or null
     * @return ObjectNotification The created notification object
     * @throws EmptyValueException When type or title is empty
     * @throws TableNotActivatedException When the project has not activated the notification table
     * @throws DatabaseException If the insert query fails
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function createFor(
        int $userId,
        string $type,
        string $severity,
        string $title,
        ?string $body,
        ?string $data,
    ): ObjectNotification {
        $this->requireActivatedTable();

        if ($type === '' || $title === '') {
            throw new EmptyValueException('Notification type and title are required');
        }

        $notification = ObjectNotification::create();
        $notification->userId = $userId;
        $notification->type = $type;
        $notification->severity = $severity;
        $notification->title = $title;
        $notification->body = $body;
        $notification->data = $data;
        $notification->createdAt = TimeHelper::getSqlDateTime();
        $notification->sync();

        $id = $notification->id;
        if ($id === null) {
            throw new DatabaseException('Notification insert did not assign an id');
        }

        $this[$id] = $notification;

        return $notification;
    }

    /**
     * Lists a recipient's notifications, newest first.
     *
     * @param int $userId Recipient user id
     * @param int $limit Maximum rows to return (defensive upper bound on the page)
     * @return list<ObjectNotification> Notifications newest-first (empty when none)
     * @throws TableNotActivatedException When the project has not activated the notification table
     * @throws DatabaseException If the database query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function listForUser(int $userId, int $limit = 100): array
    {
        $this->requireActivatedTable();

        $entities = EntityNotification::get(
            [EntityNotification::user_id => $userId],
            [],
            [EntityNotification::id => SqlSortDirection::DESC],
        );

        $result = [];
        foreach ($entities as $entity) {
            if ($entity->id === null) {
                continue;
            }
            if (!isset($this->objects[$entity->id])) {
                $this->hydrate($entity->id, ObjectNotification::fromEntity($entity));
            }
            $result[] = $this->objects[$entity->id];
            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * Counts a recipient's unread notifications.
     *
     * The unread badge is a COUNT rather than runtime state: it is read on
     * subscribe and then kept in sync on the client by the created/read signals,
     * so no RT collection tracks it.
     *
     * @param int $userId Recipient user id
     * @return int Number of unread notifications for the user
     * @throws TableNotActivatedException When the project has not activated the notification table
     * @throws DatabaseException If the count query fails
     */
    public function countUnreadForUser(int $userId): int
    {
        $this->requireActivatedTable();

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($userId));
        $resultSet = Database::sql(
            'SELECT COUNT(*) AS `cnt` FROM `' . EntityNotification::_table . '`'
            . ' WHERE `' . EntityNotification::user_id . '` = ?'
            . ' AND `' . EntityNotification::read_at . '` IS NULL',
            $params,
        )->first();
        if ($resultSet === null) {
            return 0;
        }

        $row = $resultSet->first();

        return $row === null ? 0 : (int)($row['cnt'] ?? 0);
    }

    /**
     * Marks every unread notification of a recipient read, stamping the read time.
     *
     * Bulk single-statement write (mirrors the verification layer's targeted
     * updates): only the recipient's own rows are touched, so a caller can never
     * mark another user's notifications read.
     *
     * @param int $userId Recipient user id
     * @return int Number of rows marked read
     * @throws TableNotActivatedException When the project has not activated the notification table
     * @throws DatabaseException If the update query fails
     * @throws WriteNotAllowedException When no truth source in this process covers the whole collection
     */
    public function markAllReadForUser(int $userId): int
    {
        $this->requireActivatedTable();

        $now = TimeHelper::getSqlDateTime();

        DbWriteGuard::guardCollectionWrite(static::COLLECTION_KEY);

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::string($now));
        $params->add(SqlParam::int($userId));
        Database::sql(
            'UPDATE `' . EntityNotification::_table . '`'
            . ' SET `' . EntityNotification::read_at . '` = ?'
            . ' WHERE `' . EntityNotification::user_id . '` = ?'
            . ' AND `' . EntityNotification::read_at . '` IS NULL',
            $params,
        );

        $this->invalidateLoadedForUser($userId, $now);

        return Database::affectedRows();
    }

    /**
     * Asserts once per collection that the project activated the notification table.
     *
     * The schema is loaded once per process, so a confirmed activation cannot become
     * false again and the check is remembered instead of repeated on every read and
     * write. Only success is remembered: a project that activates the table later in
     * the same process (a migration run followed by a fresh schema load) is picked up
     * by the next call.
     *
     * @throws TableNotActivatedException When the project has not activated the table
     */
    private function requireActivatedTable(): void
    {
        if ($this->tableActivationConfirmed) {
            return;
        }

        Schema::requireTable(EntityNotification::_table);
        $this->tableActivationConfirmed = true;
    }

    /**
     * Mirrors a bulk mark-all-read onto any already-hydrated objects for the user.
     *
     * Keeps the in-memory objects consistent with the targeted UPDATE so a later
     * read in the same request does not see stale unread rows.
     *
     * @param int $userId Recipient user id whose loaded rows were marked read
     * @param string $readAt Read timestamp stamped by the bulk update
     */
    private function invalidateLoadedForUser(int $userId, string $readAt): void
    {
        foreach ($this->objects as $notification) {
            if ($notification->userId === $userId && $notification->isUnread()) {
                $notification->readAt = $readAt;
            }
        }
    }
}
