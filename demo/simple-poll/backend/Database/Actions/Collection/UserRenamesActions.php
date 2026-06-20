<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Database\Actions\Collection;

use Demo\SimplePoll\Database\Entity\Item\UserRename as EntityUserRename;
use Demo\SimplePoll\Database\Object\Collection\UserRenames as ObjectUserRenames;
use Demo\SimplePoll\Database\Object\Item\UserRename as ObjectUserRename;
use Demo\SimplePoll\Database\PollDbContext;
use Demo\SimplePoll\Database\View\Collection\UserRenames as DbCollectionUserRenames;
use Demo\SimplePoll\Database\View\Item\UserRename as DbUserRename;
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
        TruthSourceRegistry::checkCanCreate(PollDbContext::userRenames);
        $this->ensureCanWrite();

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
