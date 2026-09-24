<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Auth\SecondFactor\BackupCodeEntry;
use Hilos\Auth\SecondFactor\BackupCodeGenerator;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\Exception\CreateNotAllowedException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\SecondFactorBackupCodes as EntitySecondFactorBackupCodes;
use Hilos\Database\Entity\Item\SecondFactorBackupCode as EntitySecondFactorBackupCode;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\Object\Item\SecondFactorBackupCode as ObjectSecondFactorBackupCode;
use Hilos\Database\Object\Objects;
use Hilos\Database\SqlParam;
use Hilos\Database\SqlParamCollection;
use Hilos\Database\SqlSortDirection;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * SecondFactorBackupCodes object collection - the backup codes of every person (HIL-494).
 *
 * A person holds one set at a time: {@see issueSet()} replaces the old set whole, and
 * {@see deleteForUser()} takes it out with the rest of the second factor. The code column
 * is DB-only, so the two reads that need the codes themselves - finding the row a typed
 * code names, and listing the set for the "Show" screen - are targeted queries.
 *
 * @extends Objects<ObjectSecondFactorBackupCode>
 * @method ObjectSecondFactorBackupCode|null current()
 * @method ObjectSecondFactorBackupCode|null first()
 * @method ObjectSecondFactorBackupCode|null last()
 * @method ObjectSecondFactorBackupCode|null get(int|string $key)
 * @method ObjectSecondFactorBackupCode|null offsetGet(mixed $offset)
 */
final class SecondFactorBackupCodes extends Objects
{
    public const string OBJECT_CLASS = ObjectSecondFactorBackupCode::class;
    public const string ENTITY_COLLECTION_CLASS = EntitySecondFactorBackupCodes::class;
    public const string COLLECTION_KEY = HilosDbContext::secondFactorBackupCodes;

    /**
     * Replaces a person's backup codes with a new set.
     *
     * The old set dies before the new one is written: an interruption in between leaves the
     * person with fewer codes, never with two live sets.
     *
     * @param int $userId Person the set belongs to
     * @param list<string> $codes Normalized codes of the new set
     * @throws DatabaseException When a lookup, a delete, an insert or a code write fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws CreateNotAllowedException When no truth source in this process may add a row here
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     * @throws ObjectGetIdStringNotImplementedException If an inserted row has no primary key
     */
    public function issueSet(int $userId, array $codes): void
    {
        $this->deleteForUser($userId);

        $now = TimeHelper::getSqlDateTime();
        foreach ($codes as $code) {
            $row = ObjectSecondFactorBackupCode::create();
            $row->userId = $userId;
            $row->createdAt = $now;
            $row->sync();

            $id = $row->id;
            if ($id === null) {
                throw new DatabaseException('Backup code insert did not assign an id');
            }
            $row->writeCode($code);
            $this[$id] = $row;
        }
    }

    /**
     * Finds the unused row a typed code names, if any.
     *
     * @param int $userId Person whose set to look in
     * @param string $code Normalized code as typed
     * @return ?ObjectSecondFactorBackupCode The unused row holding the code, or null when none does
     * @throws DatabaseException When the lookup query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     * @throws LogicException When the collection class constants are not configured
     */
    public function findUnused(int $userId, string $code): ?ObjectSecondFactorBackupCode
    {
        if ($code === '') {
            return null;
        }

        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($userId));
        $params->add(SqlParam::string($code));
        $row = Database::sql(
            'SELECT `' . EntitySecondFactorBackupCode::id . '` FROM `' . EntitySecondFactorBackupCode::_table
                . '` WHERE `' . EntitySecondFactorBackupCode::user_id . '` = ? AND `'
                . EntitySecondFactorBackupCode::code . '` = ? AND `'
                . EntitySecondFactorBackupCode::used_at . '` IS NULL LIMIT 1',
            $params,
        )->firstRow();
        if ($row === null) {
            return null;
        }

        return $this->offsetGet((int)$row[EntitySecondFactorBackupCode::id]);
    }

    /**
     * Lists a person's set with the codes themselves, for the "Show" screen.
     *
     * @param int $userId Person whose set to list
     * @return list<BackupCodeEntry> Codes in issue order, in their display form (empty when none)
     * @throws DatabaseException When the lookup query fails
     */
    public function entriesOf(int $userId): array
    {
        $params = SqlParamCollection::empty();
        $params->add(SqlParam::int($userId));
        $resultSet = Database::sql(
            'SELECT `' . EntitySecondFactorBackupCode::code . '`, `' . EntitySecondFactorBackupCode::used_at
                . '` FROM `' . EntitySecondFactorBackupCode::_table . '` WHERE `'
                . EntitySecondFactorBackupCode::user_id . '` = ? AND `' . EntitySecondFactorBackupCode::code
                . '` IS NOT NULL ORDER BY `' . EntitySecondFactorBackupCode::id . '` ' . SqlSortDirection::ASC,
            $params,
        )->first();

        $entries = [];
        foreach ($resultSet ?? [] as $row) {
            $entries[] = new BackupCodeEntry(
                BackupCodeGenerator::display((string)$row[EntitySecondFactorBackupCode::code]),
                $row[EntitySecondFactorBackupCode::used_at] !== null,
            );
        }

        return $entries;
    }

    /**
     * Lists every row of a person's set.
     *
     * @param int $userId Person whose set to list
     * @return list<ObjectSecondFactorBackupCode> Rows in issue order (empty when none)
     * @throws DatabaseException When the lookup query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction
     */
    public function listByUser(int $userId): array
    {
        $entities = EntitySecondFactorBackupCode::get(
            [EntitySecondFactorBackupCode::user_id => $userId],
            orderBy: [EntitySecondFactorBackupCode::id => SqlSortDirection::ASC],
        );

        $result = [];
        foreach ($entities as $entity) {
            if ($entity->id === null) {
                continue;
            }
            if (!isset($this->objects[$entity->id])) {
                $this->hydrate($entity->id, ObjectSecondFactorBackupCode::fromEntity($entity));
            }
            $result[] = $this->objects[$entity->id];
        }

        return $result;
    }

    /**
     * Deletes a person's whole set.
     *
     * @param int $userId Person whose set to delete
     * @throws DatabaseException When a lookup or a delete fails
     * @throws InvalidArgumentException When the entity query or the queued DB-sync signal is invalid
     * @throws WriteNotAllowedException When no truth source in this process may write that row
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function deleteForUser(int $userId): void
    {
        foreach ($this->listByUser($userId) as $row) {
            $id = $row->id;
            $row->delete();
            if ($id !== null) {
                unset($this[$id]);
            }
        }
    }
}
