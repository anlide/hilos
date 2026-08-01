<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Core\Exception\EmptyValueException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
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
     */
    public function clearBlock(string $scope, string $identity, string $action): void
    {
        $this->objectCollection->clearBlock($scope, $identity, $action);
    }
}
