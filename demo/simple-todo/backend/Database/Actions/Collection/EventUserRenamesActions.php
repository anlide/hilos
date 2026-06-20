<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Database\Actions\Collection;

use Demo\SimpleTodo\Database\Entity\Item\EventUserRename as EntityEventUserRename;
use Demo\SimpleTodo\Database\Object\Collection\EventUserRenames as ObjectEventUserRenames;
use Demo\SimpleTodo\Database\Object\Item\EventUserRename as ObjectEventUserRename;
use Demo\SimpleTodo\Database\TodoDbContext;
use Demo\SimpleTodo\Database\View\Collection\EventUserRenames as DbCollectionEventUserRenames;
use Demo\SimpleTodo\Database\View\Item\EventUserRename as DbEventUserRename;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * EventUserRenamesActions - write operations for the user-rename audit.
 *
 * @extends DbActions<DbEventUserRename, ObjectEventUserRenames>
 * @property-read DbCollectionEventUserRenames $collection
 * @property-read ObjectEventUserRenames $objectCollection
 */
final class EventUserRenamesActions extends DbActions
{
    /**
     * Get table name for the EventUserRenames collection.
     *
     * @return string Table name
     */
    protected function getTableName(): string
    {
        return EntityEventUserRename::_table;
    }

    /**
     * Records one admin user-rename audit row.
     *
     * @param int $targetUserId Renamed user id
     * @param string $oldName Previous display name
     * @param string $newName New display name
     * @return DbEventUserRename Created audit row
     * @throws HilosException On database or truth-source failure
     */
    public function add(int $targetUserId, string $oldName, string $newName): DbEventUserRename
    {
        TruthSourceRegistry::checkCanCreate(TodoDbContext::eventUserRenames);
        $this->ensureCanWrite();

        $audit = ObjectEventUserRename::create();
        $audit->targetUserId = $targetUserId;
        $audit->oldName = $oldName;
        $audit->newName = $newName;
        $audit->timestamp = TimeHelper::getSqlDateTime();
        $audit->sync();

        $this->addObjectToCollection($audit);

        return $this->createDbItemFromObject($audit);
    }
}
