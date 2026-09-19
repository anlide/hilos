<?php

declare(strict_types=1);

namespace Demo\Polls\Database\Actions\Collection;

use Demo\Polls\Database\Entity\Item\UserRename as EntityUserRename;
use Demo\Polls\Database\Object\Collection\UserRenames as ObjectUserRenames;
use Demo\Polls\Database\Object\Item\UserRename as ObjectUserRename;
use Demo\Polls\Database\PollsDbContext;
use Demo\Polls\Database\View\Collection\UserRenames as DbCollectionUserRenames;
use Demo\Polls\Database\View\Item\UserRename as DbUserRename;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\HilosException;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * UserRenamesActions - write operations for the user-rename audit.
 *
 * @extends DbActions<DbUserRename, ObjectUserRenames>
 * @property-read DbCollectionUserRenames $collection
 * @property-read ObjectUserRenames $objectCollection
 */
final class UserRenamesActions extends DbActions
{
    /**
     * Get table name for the UserRenames collection.
     *
     * @return string Table name
     */
    protected function getTableName(): string
    {
        return EntityUserRename::_table;
    }

    /**
     * Records one admin user-rename audit row.
     *
     * @param int $targetUserId Renamed user id
     * @param string $oldName Previous display name
     * @param string $newName New display name
     * @return DbUserRename Created audit row
     * @throws HilosException On database or truth-source failure
     */
    public function add(int $targetUserId, string $oldName, string $newName): DbUserRename
    {
        TruthSourceRegistry::checkCanCreate(PollsDbContext::userRenames);
        $this->ensureCanWrite(TruthSourceOperation::Add);

        $audit = ObjectUserRename::create();
        $audit->targetUserId = $targetUserId;
        $audit->oldName = $oldName;
        $audit->newName = $newName;
        $audit->timestamp = TimeHelper::getSqlDateTime();
        $audit->sync();

        $this->addObjectToCollection($audit);

        return $this->createDbItemFromObject($audit);
    }
}
