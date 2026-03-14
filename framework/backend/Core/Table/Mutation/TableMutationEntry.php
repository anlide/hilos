<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Mutation;

use Hilos\Core\Table\TableConstants;

/**
 * Single table mutation event (create/update/delete).
 *
 * Broadcast to all connected users so the frontend can show pending-change indicators.
 */
readonly class TableMutationEntry
{
    /**
     * Creates table mutation entry.
     *
     * @param TableMutationType $type Mutation type (created, updated, deleted)
     * @param string|int $rowId Affected row ID
     * @param ?array<string, mixed> $row Optional row data for create/update
     */
    public function __construct(
        public TableMutationType $type,
        public string|int $rowId,
        public ?array $row = null,
    ) {
    }

    /**
     * Converts to array for WebSocket serialization.
     *
     * @return array<string, mixed> Payload with type, rowId, optional row
     */
    public function toArray(): array
    {
        $data = [
            TableConstants::MUTATION_KEY_TYPE => $this->type->value,
            TableConstants::MUTATION_KEY_ROW_ID => $this->rowId,
        ];
        if ($this->row !== null) {
            $data[TableConstants::MUTATION_KEY_ROW] = $this->row;
        }
        return $data;
    }
}
