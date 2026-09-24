<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Auth\SecondFactor\BackupCodeEntry;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\SecondFactorBackupCodes as ObjectSecondFactorBackupCodes;
use Hilos\Database\View\Item\SecondFactorBackupCode;

/**
 * SecondFactorBackupCodes Db collection - the backup codes of every person (HIL-494).
 *
 * Read-facing representation of the framework-owned hilos_second_factor_backup_code table.
 * The code column is DB-only, so the reads that need a code - which row a typed code names,
 * and the set as the "Show" screen lists it - are answered here rather than off the items.
 *
 * @extends DbCollection<SecondFactorBackupCode, ObjectSecondFactorBackupCodes>
 */
final class SecondFactorBackupCodes extends DbCollection
{
    public const string DB_ITEM_CLASS = SecondFactorBackupCode::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectSecondFactorBackupCodes::class;

    /**
     * Finds the unused row a typed code names, if any.
     *
     * @param int $userId Person whose set to look in
     * @param string $code Normalized code as typed
     * @return ?SecondFactorBackupCode The unused row, or null when the code names none
     * @throws DatabaseException When the lookup query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction or an object type does not match
     * @throws LogicException When collection class constants are not configured
     */
    public function findUnused(int $userId, string $code): ?SecondFactorBackupCode
    {
        return $this->getItemForKey($this->objectCollection->findUnused($userId, $code)?->id);
    }

    /**
     * Lists a person's set with the codes themselves, for the "Show" screen.
     *
     * @param int $userId Person
     * @return list<BackupCodeEntry> Codes in issue order, in their display form (empty when none)
     * @throws DatabaseException When the lookup query fails
     */
    public function entriesOf(int $userId): array
    {
        return $this->objectCollection->entriesOf($userId);
    }

    /**
     * Lists every row of a person's set.
     *
     * @param int $userId Person
     * @return list<SecondFactorBackupCode> Rows in issue order (empty when none)
     * @throws DatabaseException When the lookup query fails
     * @throws InvalidArgumentException When the entity query is given an invalid order direction or an object type does not match
     * @throws LogicException When collection class constants are not configured
     */
    public function listByUser(int $userId): array
    {
        $result = [];
        foreach ($this->objectCollection->listByUser($userId) as $object) {
            $id = $object->id;
            if ($id !== null) {
                $result[] = $this->getOrCreateItemForLoadedObject($id, $object);
            }
        }

        return $result;
    }
}
