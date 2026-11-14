<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Collection;

use Demo\Chat\Database\Object\Collection\UserRenames as ObjectUserRenames;
use Demo\Chat\Database\Object\Item\UserRename as ObjectUserRename;
use Hilos\Database\Database;
use Demo\Chat\Database\View\Collection\UserRenames as DbCollectionUserRenames;
use Demo\Chat\Hilos;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Collection\UserRenamesActions as FrameworkUserRenamesActions;
use Hilos\Database\DatabaseException;
use Hilos\HilosException;

/**
 * UserRenamesActions - the framework's writes and the two with which the person's agent takes
 * its rows off the feed: one being cleared (HIL-1897), and the erasure's capture (HIL-1940).
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

        Database::transactionStart();
        try {
            $unlinked = $this->unlinkLoadedRowsOf($userId);
            Database::transactionCommit();
        } catch (HilosException $f) {
            Database::transactionRollbackSilent();
            throw $f;
        }

        return $unlinked;
    }

    /**
     * The person's agent takes its rows off the feed inside the erasure capture's transaction and
     * keeps the events they named, for ChatAgent to delete after the person is gone (HIL-1940).
     * The rows and the events stay; a write failure rolls the whole capture back.
     *
     * SCAFFOLD: no production caller until the erasure switch (HIL-1952).
     *
     * @param int $userId Person whose journal is unlinked
     * @return list<int> Feed events the person's rows named before the unlink, in id order
     * @throws DatabaseException When no transaction is open: the capture's own must carry the unlink
     * @throws HilosException On database or truth-source failure
     */
    public function unlinkForErasure(int $userId): array
    {
        if (!Database::hasOpenTransactions()) {
            throw new DatabaseException('A rename feed unlink for an erasure must run inside its participant transaction');
        }
        $this->ensureCanWriteSet((string)$userId, TruthSourceOperation::Update);
        $eventIds = Hilos::$db->userRenames->eventIdsByUser($userId);
        $this->unlinkLoadedRowsOf($userId);

        return $eventIds;
    }

    /**
     * Clears the feed link of every row of the person inside the caller's transaction, repairing
     * memory where the database has already cleared it.
     *
     * @param int $userId Person whose journal is unlinked
     * @return int Number of links cleared in memory
     * @throws HilosException On database failure
     */
    private function unlinkLoadedRowsOf(int $userId): int
    {
        $unlinked = 0;
        foreach ($this->objectCollection->loadByColumn(ObjectUserRename::userId, $userId) as $id) {
            $rename = $this->objectCollection[$id];
            if ($rename->eventId !== null) {
                $rename->eventId = null;
                $rename->sync();
                $unlinked++;
            }
        }

        return $unlinked;
    }
}
