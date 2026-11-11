<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Collection;

use Demo\Chat\Database\Object\Collection\UserRenames as ObjectUserRenames;
use Demo\Chat\Database\Object\Item\UserRename as ObjectUserRename;
use Hilos\Database\Database;
use Demo\Chat\Database\View\Collection\UserRenames as DbCollectionUserRenames;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Collection\UserRenamesActions as FrameworkUserRenamesActions;
use Hilos\HilosException;

/**
 * UserRenamesActions - the framework's writes and the one with which the person's agent takes
 * its rows off a feed being cleared (HIL-1897).
 *
 * @property-read DbCollectionUserRenames $collection
 * @property-read ObjectUserRenames $objectCollection
 */
final class UserRenamesActions extends FrameworkUserRenamesActions
{
    /**
     * The person's agent takes its rows off the feed at ChatAgent's request; the rows stay (HIL-1897).
     * The same pass repairs memory where the database has already cleared the link.
     *
     * @param int $userId Person whose journal is unlinked
     * @return int Number of links cleared in memory
     * @throws HilosException On database or truth-source failure
     */
    public function unlinkEventsOf(int $userId): int
    {
        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Update);

        $unlinked = 0;
        Database::transactionStart();
        try {
            foreach ($this->objectCollection->loadByColumn(ObjectUserRename::userId, $userId) as $id) {
                $rename = $this->objectCollection[$id];
                if ($rename->eventId !== null) {
                    $rename->eventId = null;
                    $rename->sync();
                    $unlinked++;
                }
            }
            Database::transactionCommit();
        } catch (HilosException $f) {
            Database::transactionRollbackSilent();
            throw $f;
        }

        return $unlinked;
    }
}
