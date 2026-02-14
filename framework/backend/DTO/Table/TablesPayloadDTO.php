<?php

declare(strict_types=1);

namespace Hilos\DTO\Table;

use Hilos\Core\Table\TableActionConstants;
use Hilos\DTO\BaseDTO;

/**
 * Payload for multiple tables (e.g. subscription response: one page, several tables).
 *
 * Maps table key -> TableDataDTO. Frontend receives this and fills each table by key.
 */
class TablesPayloadDTO extends BaseDTO
{
    /**
     * @param array<string, TableDataDTO> $tables Table key -> table data
     */
    public function __construct(
        public readonly array $tables,
    ) {
    }

    /**
     * Returns flat map tableKey => tableData (for signal payload: payload.tables.users etc.)
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->tables as $key => $dto) {
            $out[$key] = $dto->toArray();
        }
        return $out;
    }

    public static function fromArray(array $data): static
    {
        $tables = [];
        foreach ($data as $key => $row) {
            if (is_array($row)) {
                $tables[$key] = TableDataDTO::fromArray([TableActionConstants::PAYLOAD_KEY_RESPONSE_TABLE_KEY => $key] + $row);
            }
        }
        return new static(tables: $tables);
    }
}
