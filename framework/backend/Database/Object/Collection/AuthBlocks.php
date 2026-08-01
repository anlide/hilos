<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Core\Exception\EmptyValueException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\AuthBlocks as EntityAuthBlocks;
use Hilos\Database\Entity\Item\AuthBlock as EntityAuthBlock;
use Hilos\Database\Object\Item\AuthBlock as ObjectAuthBlock;
use Hilos\Database\Object\Objects;

/**
 * AuthBlocks object collection.
 *
 * Persistence primitives for the framework anti-abuse durable block table
 * (HIL-420). The throttle service owns the ladder/window decision; this layer
 * only reads and writes the consummated block per (scope, identity, action).
 *
 * @extends Objects<ObjectAuthBlock>
 * @method ObjectAuthBlock|null current()
 * @method ObjectAuthBlock|null first()
 * @method ObjectAuthBlock|null last()
 * @method ObjectAuthBlock|null get(int|string $key)
 * @method ObjectAuthBlock|null offsetGet(mixed $offset)
 */
final class AuthBlocks extends Objects
{
    public const string OBJECT_CLASS = ObjectAuthBlock::class;
    public const string ENTITY_COLLECTION_CLASS = EntityAuthBlocks::class;
    public const string COLLECTION_KEY = HilosDbContext::authBlocks;

    /**
     * Finds the durable block for a (scope, identity, action) triple.
     *
     * The triple is unique, so this returns at most one block. The returned
     * block may be cooled down (its `blockedUntil` in the past or null); the
     * throttle service decides whether it is currently active.
     *
     * @param string $scope Throttle scope (see ThrottleScope)
     * @param string $identity Throttle identity (IP or session-token hash)
     * @param string $action Throttled action name
     * @return ?ObjectAuthBlock Block object or null when none recorded
     * @throws DatabaseException If the database query fails
     */
    public function findByKey(string $scope, string $identity, string $action): ?ObjectAuthBlock
    {
        if ($scope === '' || $identity === '' || $action === '') {
            return null;
        }

        $entityAuthBlock = EntityAuthBlock::get([
            EntityAuthBlock::scope => $scope,
            EntityAuthBlock::identity => $identity,
            EntityAuthBlock::action => $action,
        ])->first();

        if ($entityAuthBlock === null || $entityAuthBlock->id === null) {
            return null;
        }

        if (!isset($this->objects[$entityAuthBlock->id])) {
            $this->objects[$entityAuthBlock->id] = ObjectAuthBlock::fromEntity($entityAuthBlock);
        }

        return $this->objects[$entityAuthBlock->id];
    }

    /**
     * Records (upserts) the durable block for a (scope, identity, action) triple.
     *
     * Escalation write path: the throttle service computes the ladder `level`
     * and the `blockedUntil` moment and persists them here so the block survives
     * a restart and reaches every worker. An existing row for the triple is
     * updated in place (uniqueness is per triple); otherwise a new row is
     * inserted.
     *
     * @param string $scope Throttle scope (see ThrottleScope)
     * @param string $identity Throttle identity (IP or session-token hash)
     * @param string $action Throttled action name
     * @param int $level Ladder step reached
     * @param ?string $blockedUntil Datetime the block lifts, or null when only the level is retained
     * @return ObjectAuthBlock The persisted block object
     * @throws EmptyValueException When scope, identity or action is empty
     * @throws DatabaseException If the insert or update query fails
     */
    public function recordBlock(string $scope, string $identity, string $action, int $level, ?string $blockedUntil): ObjectAuthBlock
    {
        if ($scope === '' || $identity === '' || $action === '') {
            throw new EmptyValueException('Auth block scope, identity and action are required');
        }

        $block = $this->findByKey($scope, $identity, $action);
        if ($block === null) {
            $block = ObjectAuthBlock::create();
            $block->scope = $scope;
            $block->identity = $identity;
            $block->action = $action;
        }

        $block->level = $level;
        $block->blockedUntil = $blockedUntil;
        $block->sync();

        $id = $block->id;
        if ($id === null) {
            throw new DatabaseException('Auth block insert did not assign an id');
        }

        $this->objects[$id] = $block;

        return $block;
    }

    /**
     * Clears the durable block for a (scope, identity, action) triple.
     *
     * Reset write path: a successful auth drops the `session`-scope block so a
     * proven user is not left throttled. A missing row is an idempotent no-op.
     *
     * @param string $scope Throttle scope (see ThrottleScope)
     * @param string $identity Throttle identity (IP or session-token hash)
     * @param string $action Throttled action name
     * @throws DatabaseException If the lookup or delete query fails
     */
    public function clearBlock(string $scope, string $identity, string $action): void
    {
        $block = $this->findByKey($scope, $identity, $action);
        if ($block === null) {
            return;
        }

        $id = $block->id;
        $block->delete();
        if ($id !== null) {
            unset($this->objects[$id]);
        }
    }
}
