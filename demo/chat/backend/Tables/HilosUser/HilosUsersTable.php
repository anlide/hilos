<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\HilosUser;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\HilosUser\Actions\HilosUserItemActions;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSourceEventDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\DatabaseException;

/**
 * Table definition for the Hilos users page.
 */
final class HilosUsersTable extends TableDefinition
{
    /**
     * Builds a Hilos users row mutation from a user source event.
     *
     * @param TableSourceEventDTO $event User source event to project into the Hilos users table
     * @return ?TableRowMutationDTO Hilos users row mutation, or null when the event does not affect this table
     * @throws DatabaseException If source user lookup fails
     */
    public function buildMutationForSourceEvent(TableSourceEventDTO $event): ?TableRowMutationDTO
    {
        if ($event->sourceKey !== DbChatContext::users) {
            return null;
        }

        $userId = (int) $event->sourceRowKey;
        if ($userId <= 0) {
            return null;
        }

        if ($event->mutationType === TableMutationType::Delete) {
            return $this->mutation(TableMutationType::Delete, $userId);
        }

        $dbUser = Hilos::$db->users[$userId] ?? null;
        if ($dbUser === null) {
            return null;
        }

        return $this->mutation(
            $event->mutationType,
            $userId,
            HilosUserTableRow::fromDbUser($dbUser),
        );
    }

    /**
     * Queries chat users for the Hilos users table.
     *
     * @param TableQueryDTO $query Table query parameters
     * @return TableSnapshotDTO Hilos users table snapshot
     * @throws DatabaseException If user query execution fails
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $snapshot = $this->queryDbCollection(Hilos::$db->users, $query);

        return new TableSnapshotDTO(
            rows: array_map(
                fn(AbstractTableRow|array $row): HilosUserTableRow => $this->makeHilosUserTableRow($row),
                $snapshot->rows,
            ),
            totalCount: $snapshot->totalCount,
            offset: $snapshot->offset,
            limit: $snapshot->limit,
        );
    }

    /**
     * Converts a DB user row payload into the runtime-enriched Hilos users table row.
     *
     * @param AbstractTableRow|array<string, mixed> $row Source row returned by the DB collection table query
     */
    private function makeHilosUserTableRow(AbstractTableRow|array $row): HilosUserTableRow
    {
        $rowPayload = $row instanceof AbstractTableRow ? $row->toArray() : $row;
        $userId = (int) ($rowPayload[HilosUserTableRow::id] ?? 0);
        $dbUser = $userId > 0 ? Hilos::$db->users[$userId] ?? null : null;

        return $dbUser === null ? HilosUserTableRow::fromArray($rowPayload) : HilosUserTableRow::fromDbUser($dbUser);
    }

    /**
     * Configures the row shape and item actions used by the Hilos users table.
     */
    protected function init(): void
    {
        $this->setRowClass(HilosUserTableRow::class);
        $this->setItemActionsClass(HilosUserItemActions::class);
    }
}
