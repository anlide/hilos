<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Mutation;

use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\Row\GenericTableRow;
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
     * @param string|int $rowKey Affected row key
     * @param ?AbstractTableRow $row Optional row data for create/update
     */
    public function __construct(
        public TableMutationType $type,
        public string|int $rowKey,
        public ?AbstractTableRow $row = null,
    ) {
    }

    /**
     * Converts to array for WebSocket serialization.
     *
     * @return array<string, mixed> Payload with type, rowKey, optional row
     */
    public function toArray(): array
    {
        $data = [
            TableConstants::MUTATION_KEY_TYPE => $this->type->value,
            TableConstants::MUTATION_KEY_ROW_KEY => $this->rowKey,
        ];
        if ($this->row !== null) {
            $data[TableConstants::MUTATION_KEY_ROW] = $this->row->toArray();
        }
        return $data;
    }

    /**
     * Rebuilds a mutation entry from its serialized form.
     *
     * When the concrete table row class is unknown in the current context, the
     * row is restored as {@see GenericTableRow}.
     *
     * @param array<string, mixed> $data Serialized mutation payload
     */
    public static function fromArray(array $data): self
    {
        $row = null;
        if (isset($data[TableConstants::MUTATION_KEY_ROW]) && is_array($data[TableConstants::MUTATION_KEY_ROW])) {
            $row = GenericTableRow::fromArray($data[TableConstants::MUTATION_KEY_ROW]);
        }

        return new self(
            type: TableMutationType::from((string) ($data[TableConstants::MUTATION_KEY_TYPE] ?? '')),
            rowKey: $data[TableConstants::MUTATION_KEY_ROW_KEY] ?? 0,
            row: $row,
        );
    }
}
