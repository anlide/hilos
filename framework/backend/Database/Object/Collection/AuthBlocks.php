<?php

declare(strict_types=1);

namespace Hilos\Database\Object\Collection;

use Hilos\Constants\CliCommands;
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
     * Finds every block that has not yet lifted.
     *
     * The throttle agent's start reads this once and replays it into the runtime counters:
     * the counters are transient and a restart empties them, so without this replay a
     * process restart would be a way to have a block forgotten - the whole reason the block
     * is durable while the counter is not.
     *
     * @param string $now Datetime a block must outlast to still be in force
     * @return list<ObjectAuthBlock> Blocks still in force, in no particular order
     * @throws DatabaseException If the database query fails
     */
    public function findActive(string $now): array
    {
        $blocks = [];
        $rawWhere = '`' . EntityAuthBlock::blocked_until . '` > ?';
        foreach (EntityAuthBlock::get($rawWhere, [$now]) as $entityAuthBlock) {
            if ($entityAuthBlock->id === null) {
                continue;
            }

            if (!isset($this->objects[$entityAuthBlock->id])) {
                $this->objects[$entityAuthBlock->id] = ObjectAuthBlock::fromEntity($entityAuthBlock);
            }

            $blocks[] = $this->objects[$entityAuthBlock->id];
        }

        return $blocks;
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
     * Deletes the blocks that lifted before a given moment, reporting how many rows went.
     *
     * A served block is read by nothing: {@see findActive()} passes over it, and the counter
     * it belonged to has long been swept. Keeping it would mean a table that only ever grows,
     * one row per key ever blocked.
     *
     * @param string $before Datetime a block must have lifted before to be deleted
     * @return int Number of blocks deleted
     * @throws DatabaseException If the lookup or delete query fails
     */
    public function clearServed(string $before): int
    {
        $deleted = 0;
        $column = '`' . EntityAuthBlock::blocked_until . '`';
        // The null half is garbage by the same rule: a row that names no moment is never replayed either.
        $rawWhere = "({$column} IS NULL OR {$column} < ?)";
        foreach (EntityAuthBlock::get($rawWhere, [$before]) as $entityAuthBlock) {
            $this->forget($entityAuthBlock);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Deletes every stored block of one key, on every action, reporting how many rows went.
     *
     * The durable half of what a successful authentication forgives a session. Without it a
     * block the session collected on some other action would sit in the table unmatched by
     * any counter, and the next agent start would replay it back into a session that has
     * since proved itself.
     *
     * @param string $scope Throttle scope (see ThrottleScope)
     * @param string $identity Throttle identity (IP or session-token hash)
     * @return int Number of blocks deleted
     * @throws DatabaseException If the lookup or delete query fails
     */
    public function clearIdentity(string $scope, string $identity): int
    {
        if ($scope === '' || $identity === '') {
            return 0;
        }

        $deleted = 0;
        $blocks = EntityAuthBlock::get([
            EntityAuthBlock::scope => $scope,
            EntityAuthBlock::identity => $identity,
        ]);
        foreach ($blocks as $entityAuthBlock) {
            $this->forget($entityAuthBlock);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Deletes every stored block, reporting how many rows went.
     *
     * The durable half of the test-only reset ({@see CliCommands::THROTTLE_TEST_RESET}),
     * driven through the throttle agent like every other write here. A block outliving the
     * test that provoked it would refuse whatever runs next from the same address, which is
     * a failure the next test cannot explain from anything it did itself.
     *
     * @return int Number of blocks deleted
     * @throws DatabaseException If the lookup or delete query fails
     */
    public function clearAll(): int
    {
        $deleted = 0;
        foreach (EntityAuthBlock::getAll() as $entityAuthBlock) {
            $this->forget($entityAuthBlock);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Deletes one stored row and drops whatever this collection held for it.
     *
     * @param EntityAuthBlock $entityAuthBlock Row to delete
     * @throws DatabaseException If the delete query fails
     */
    private function forget(EntityAuthBlock $entityAuthBlock): void
    {
        $id = $entityAuthBlock->id;
        $block = $id !== null && isset($this->objects[$id])
            ? $this->objects[$id]
            : ObjectAuthBlock::fromEntity($entityAuthBlock);

        $block->delete();
        if ($id !== null) {
            unset($this->objects[$id]);
        }
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
