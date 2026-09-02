<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Object\Collection\AuthBlocks as ObjectAuthBlocks;
use Hilos\Database\Object\Item\AuthBlock as ObjectAuthBlock;
use Hilos\Database\View\Item\AuthBlock;

/**
 * AuthBlocks Db collection.
 *
 * Read-facing API for the framework-owned hilos_auth_block table (HIL-420). The
 * throttle service reads the durable block through {@see findByKey()} and
 * persists / clears it through {@see recordBlock()} / {@see clearBlock()},
 * delegating to the object collection's primitives.
 *
 * @extends DbCollection<AuthBlock, ObjectAuthBlocks>
 */
final class AuthBlocks extends DbCollection
{
    public const string DB_ITEM_CLASS = AuthBlock::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectAuthBlocks::class;

    /**
     * Finds the durable block for a (scope, identity, action) triple.
     *
     * @param string $scope Throttle scope (see ThrottleScope)
     * @param string $identity Throttle identity (IP or session-token hash)
     * @param string $action Throttled action name
     * @return ?AuthBlock Block Db item or null when none recorded
     * @throws DatabaseException On database error while resolving the block
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     */
    public function findByKey(string $scope, string $identity, string $action): ?AuthBlock
    {
        $objectAuthBlock = $this->objectCollection->findByKey($scope, $identity, $action);

        if ($objectAuthBlock?->id === null) {
            return null;
        }

        /** @var ?AuthBlock $block */
        $block = $this->getItemForKey($objectAuthBlock->id);
        return $block;
    }

    /**
     * Records (upserts) the durable block for a (scope, identity, action) triple.
     *
     * Escalation write path: delegates to the object collection's
     * {@see ObjectAuthBlocks::recordBlock()} primitive and returns the
     * read-facing view item for the persisted row.
     *
     * @param string $scope Throttle scope (see ThrottleScope)
     * @param string $identity Throttle identity (IP or session-token hash)
     * @param string $action Throttled action name
     * @param int $level Ladder step reached
     * @param ?string $blockedUntil Datetime the block lifts, or null when only the level is retained
     * @return AuthBlock The persisted block's read-facing Db item
     * @throws EmptyValueException When scope, identity or action is empty
     * @throws DatabaseException On database error while recording the block
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function recordBlock(string $scope, string $identity, string $action, int $level, ?string $blockedUntil): AuthBlock
    {
        $objectAuthBlock = $this->objectCollection->recordBlock($scope, $identity, $action, $level, $blockedUntil);

        $id = $objectAuthBlock->id;
        if ($id === null) {
            throw new DatabaseException('Auth block insert did not assign an id');
        }

        /** @var ?AuthBlock $block */
        $block = $this->getItemForKey($id);
        if ($block === null) {
            throw new DatabaseException('Recorded auth block is not available on the read-facing collection');
        }

        return $block;
    }

    /**
     * Finds every block that has not yet lifted.
     *
     * @param string $now Datetime a block must outlast to still be in force
     * @return list<AuthBlock> Blocks still in force, in no particular order
     * @throws DatabaseException On database error while reading the blocks
     * @throws LogicException When collection class constants are not configured
     * @throws InvalidArgumentException When object type does not match the collection
     */
    public function findActive(string $now): array
    {
        $blocks = [];
        foreach ($this->objectCollection->findActive($now) as $objectAuthBlock) {
            $id = $objectAuthBlock->id;
            /** @var ?AuthBlock $block */
            $block = $id === null ? null : $this->getItemForKey($id);
            if ($block !== null) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * Deletes every stored block, reporting how many rows went.
     *
     * @return int Number of blocks deleted
     * @throws DatabaseException On database error while deleting the blocks
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function clearAll(): int
    {
        return $this->objectCollection->clearAll();
    }

    /**
     * Deletes the blocks that lifted before a given moment, reporting how many rows went.
     *
     * @param string $before Datetime a block must have lifted before to be deleted
     * @return int Number of blocks deleted
     * @throws DatabaseException On database error while deleting the blocks
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function clearServed(string $before): int
    {
        return $this->objectCollection->clearServed($before);
    }

    /**
     * Deletes every stored block of one key, on every action, reporting how many rows went.
     *
     * @param string $scope Throttle scope (see ThrottleScope)
     * @param string $identity Throttle identity (IP or session-token hash)
     * @return int Number of blocks deleted
     * @throws DatabaseException On database error while deleting the blocks
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function clearIdentity(string $scope, string $identity): int
    {
        return $this->objectCollection->clearIdentity($scope, $identity);
    }

    /**
     * Clears the durable block for a (scope, identity, action) triple.
     *
     * Reset write path: delegates to the object collection's
     * {@see ObjectAuthBlocks::clearBlock()} primitive. A missing row is an
     * idempotent no-op.
     *
     * @param string $scope Throttle scope (see ThrottleScope)
     * @param string $identity Throttle identity (IP or session-token hash)
     * @param string $action Throttled action name
     * @throws DatabaseException On database error while clearing the block
     * @throws SourceChangeSubscriberException Whatever a subscriber to the store announcement raises
     */
    public function clearBlock(string $scope, string $identity, string $action): void
    {
        $this->objectCollection->clearBlock($scope, $identity, $action);
    }
}
