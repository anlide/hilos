<?php

declare(strict_types=1);

namespace Hilos\Tables\Users;

use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Runtime\View\Collection\HilosPresenceSource;
use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;
use Throwable;

/**
 * Base definition for the Hilos users table: the presence-merge engine.
 *
 * The framework owns the source-change dispatch — a DB user create/update/delete
 * projects to a row mutation, and a connection lifecycle event refreshes the
 * user's presence as an update. A project binds the concrete pieces: its DB user
 * source and RT presence source, how a user id resolves to a row, and how a
 * presence change resolves to the affected user id. This keeps the framework
 * free of any project user/connection type while reusing the merge logic.
 */
abstract class AbstractHilosUsersTable extends TableDefinition
{
    /**
     * The source key of the project DB user collection this table projects.
     */
    abstract protected function usersSourceKey(): string;

    /**
     * The source key of the project RT presence collection this table merges.
     */
    abstract protected function presenceSourceKey(): string;

    /**
     * The project presence source bound to this table (its RT connections).
     */
    abstract protected function presenceSource(): HilosPresenceSource;

    /**
     * Builds the current row for a user id, or null when the user is gone.
     *
     * @param int $userId User id to project into a row
     * @return ?AbstractHilosUserTableRow Current row, or null when the user no longer exists
     */
    abstract protected function rowForUserId(int $userId): ?AbstractHilosUserTableRow;

    /**
     * Resolves the user id affected by a presence (connection) source change.
     *
     * @param SourceChange $change Presence source change
     * @return int Affected user id, or 0 when it cannot be resolved
     */
    abstract protected function resolveUserIdForPresence(SourceChange $change): int;

    /**
     * Dispatches a DB user or presence source change to a users-table mutation.
     *
     * @param SourceChange $change Source change that may affect this table
     * @return ?TableRowMutationDTO Row mutation to fan out, or null when unaffected
     * @throws Throwable Propagated from the project row builder or presence resolution
     */
    final public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey === $this->usersSourceKey()) {
            return $this->mutationForUser($change);
        }
        if ($change->sourceKey === $this->presenceSourceKey()) {
            return $this->mutationForPresence($change);
        }

        return null;
    }

    /**
     * Builds a row mutation for a DB user create, update, or delete.
     *
     * @param SourceChange $change DB user source change
     * @return ?TableRowMutationDTO Row mutation, or null for an invalid id or missing user
     * @throws Throwable Propagated from {@see self::rowForUserId()}
     */
    private function mutationForUser(SourceChange $change): ?TableRowMutationDTO
    {
        $userId = (int) $change->sourceId;
        if ($userId <= 0) {
            return null;
        }

        if ($change->mutationType === TableMutationType::Delete) {
            return $this->mutation(TableMutationType::Delete, $userId);
        }

        $row = $this->rowForUserId($userId);

        return $row === null ? null : $this->mutation($change->mutationType, $userId, $row);
    }

    /**
     * A presence change never removes a row; it refreshes the user's row as an
     * update with the recomputed online/presence aggregates.
     *
     * @param SourceChange $change Presence source change
     * @return ?TableRowMutationDTO Row update, or null when no user can be resolved
     * @throws Throwable Propagated from {@see self::rowForUserId()} or presence resolution
     */
    private function mutationForPresence(SourceChange $change): ?TableRowMutationDTO
    {
        $userId = $this->resolveUserIdForPresence($change);
        if ($userId <= 0) {
            return null;
        }

        $row = $this->rowForUserId($userId);

        return $row === null ? null : $this->mutation(TableMutationType::Update, $userId, $row);
    }

    /**
     * Summarizes a user's runtime presence through the bound presence source.
     *
     * @param int $userId User id to summarize
     * @return HilosUserPresenceSummary Presence and active session count
     */
    protected function presenceForUser(int $userId): HilosUserPresenceSummary
    {
        return $this->presenceSource()->summaryForUser($userId);
    }
}
