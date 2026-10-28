<?php

declare(strict_types=1);

namespace Hilos\Tables\ProtectedMode;

use Hilos\AdminViewMode\WireField;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Definition\SelfSnapshotTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Exception\TableSearchFieldUnknownException;
use Hilos\Core\Table\Exception\TableSearchNotSupportedException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Hilos;
use Hilos\ProtectedMode\ProtectedModeStubConstants;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;

/**
 * Maintenance words table: lists stubs for protected-mode operations (HIL-1677).
 *
 * A self-snapshot table over the protected-mode stub registry, not a DB source.
 * Projects activate it by binding it to the Words page in PAGE_TABLES.
 */
class HilosMaintenanceWordsTable extends TableDefinition implements SelfSnapshotTable
{
    /** Canonical table key under which a project registers this table in its TableContext. */
    public const string TABLE = 'hilosMaintenanceWords';

    /** Wire slot the row payload rides under; must match the frontend stub slot. */
    private const string ROW_SLOT = 'stub';

    /**
     * Builds a row mutation from a source change; this table has no DB/RT sources.
     *
     * @param SourceChange $change Source change
     * @return ?TableRowMutationDTO Always null
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return null;
    }

    /**
     * Serializes one words row into its internal browser-row envelope.
     *
     * @param AbstractTableRow $row Words table row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [
                self::ROW_SLOT => $row->toArray(),
            ],
        ];
    }

    /**
     * Declares where each field of a maintenance words row comes from for the admin view mode (HIL-1250).
     *
     * Every field originates from project constants and is not personal.
     *
     * @return array<string, WireField>
     */
    public function wireFields(): array
    {
        return [
            HilosMaintenanceWordsTableRow::operation => WireField::notPersonal(),
            HilosMaintenanceWordsTableRow::title => WireField::notPersonal(),
            HilosMaintenanceWordsTableRow::message => WireField::notPersonal(),
            HilosMaintenanceWordsTableRow::bannerMessage => WireField::notPersonal(),
            HilosMaintenanceWordsTableRow::usesDefault => WireField::notPersonal(),
        ];
    }

    /**
     * Queries the words rows in the declared order: default, manual_maintenance, restore,
     * followed by remaining registry entries in their order, unique.
     *
     * @param TableQueryDTO $query Table query parameters
     * @return TableSnapshotDTO Words table snapshot
     * @throws TableSearchNotSupportedException When a term arrives and this table declares no searchable fields
     * @throws TableSearchFieldUnknownException When a declared field is carried by no row of the set
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $registry = Hilos::protectedModeStubRegistry();
        $keys = [
            ProtectedModeStubConstants::DEFAULT_OPERATION,
            ProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE,
            BackupAgent::RESTORE_OPERATION,
        ];

        foreach (array_keys($registry) as $operation) {
            if (!in_array($operation, $keys, true)) {
                $keys[] = $operation;
            }
        }

        $rows = [];
        foreach ($keys as $operation) {
            if ($operation === BackupAgent::RESTORE_OPERATION && !isset($registry[$operation])) {
                $rows[] = (new HilosMaintenanceWordsTableRow(
                    operation: $operation,
                    title: null,
                    message: null,
                    bannerMessage: null,
                    usesDefault: true,
                ))->toArray();
                continue;
            }

            $entry = $registry[$operation] ?? null;
            $rows[] = (new HilosMaintenanceWordsTableRow(
                operation: $operation,
                title: $entry[ProtectedModeStubConstants::TITLE] ?? null,
                message: $entry[ProtectedModeStubConstants::MESSAGE] ?? null,
                bannerMessage: $entry[ProtectedModeStubConstants::BANNER_MESSAGE] ?? null,
                usesDefault: false,
            ))->toArray();
        }

        return $this->filterInMemory($rows, $query);
    }

    /**
     * Configures the row class used by the maintenance words table.
     */
    protected function init(): void
    {
        $this->setRowClass(HilosMaintenanceWordsTableRow::class);
    }
}
