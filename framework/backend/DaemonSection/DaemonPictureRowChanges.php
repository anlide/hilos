<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\Row\AbstractTableRow;

/** Stateless comparison of two complete subject projections; no row bodies survive the call. */
final class DaemonPictureRowChanges
{
    /**
     * @param list<AbstractTableRow> $before Previous subject rows, without pagination or search filtering
     * @param list<AbstractTableRow> $after Current subject rows, without pagination or search filtering
     * @return list<TableRowMutationDTO> Current creates/updates followed by departed subject keys
     * @throws TableRowKeyMissingException When a projected row is a placeholder
     */
    public static function between(array $before, array $after): array
    {
        $previous = [];
        foreach ($before as $row) {
            $previous[$row->requireRowKey()] = $row;
        }

        $mutations = [];
        foreach ($after as $row) {
            $key = $row->requireRowKey();
            if (!isset($previous[$key])) {
                $mutations[] = new TableRowMutationDTO(TableMutationType::Create, $key, $row);
            } elseif ($previous[$key]->toArray() !== $row->toArray()) {
                $mutations[] = new TableRowMutationDTO(TableMutationType::Update, $key, $row);
            }
            unset($previous[$key]);
        }
        foreach ($previous as $row) {
            $mutations[] = new TableRowMutationDTO(TableMutationType::Delete, $row->requireRowKey());
        }

        return $mutations;
    }
}
