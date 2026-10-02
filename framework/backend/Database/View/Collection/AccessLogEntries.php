<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\AccessLogEntries as ObjectAccessLogEntries;
use Hilos\Database\View\Item\AccessLogEntry;

/**
 * AccessLogEntries Db collection - the account access log (HIL-1174).
 *
 * @extends DbCollection<AccessLogEntry, ObjectAccessLogEntries>
 */
class AccessLogEntries extends DbCollection
{
    public const string DB_ITEM_CLASS = AccessLogEntry::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectAccessLogEntries::class;

    /**
     * Lists every access log row of a person in the order they occurred.
     *
     * @param int $userId Person whose rows to list
     * @return list<AccessLogEntry> Row Db items, oldest first, empty when none
     * @throws LogicException When the collection class constants are not configured
     * @throws InvalidArgumentException When a loaded object or order direction is invalid
     * @throws DatabaseException When the lookup fails
     */
    public function ofUser(int $userId): array
    {
        $result = [];
        foreach ($this->objectCollection->ofUser($userId) as $entry) {
            $item = $this->getItemForKey($entry->id);
            if ($item === null) {
                continue;
            }
            $result[] = $item;
        }

        return $result;
    }
}
