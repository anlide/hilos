<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Table\TableActionConstants;
use Hilos\Core\Table\TableType;

/**
 * Data for a single table (one key): rows + meta for frontend.
 */
class TableDataDTO extends BaseDTO
{
    /**
     * @param string $key Table key (optional when payload is under tables[key]; omit to avoid duplication)
     * @param TableType $type Entity|Sql|Other
     * @param array<int, array<string, mixed>> $rows Rows (full or one page)
     * @param bool $supportsSnapshot Whether snapshot semantics apply
     * @param int $totalCount Total row count (-1 if unknown)
     * @param bool $isPage True if rows are a page (loadPage); false if full
     * @param int $offset Offset used when isPage (0 for full)
     * @param int $limit Limit used when isPage (0 for full)
     */
    public function __construct(
        public readonly string $key = '',
        public readonly TableType $type = TableType::Entity,
        public readonly array $rows = [],
        public readonly bool $supportsSnapshot = false,
        public readonly int $totalCount = -1,
        public readonly bool $isPage = false,
        public readonly int $offset = 0,
        public readonly int $limit = 0,
    ) {
    }

    public function toArray(): array
    {
        $data = [
            TableActionConstants::PAYLOAD_KEY_TYPE => $this->type->value,
            TableActionConstants::PAYLOAD_KEY_ROWS => $this->rows,
            TableActionConstants::PAYLOAD_KEY_SUPPORTS_SNAPSHOT => $this->supportsSnapshot,
            TableActionConstants::PAYLOAD_KEY_TOTAL_COUNT => $this->totalCount,
            TableActionConstants::PAYLOAD_KEY_IS_PAGE => $this->isPage,
            TableActionConstants::PAYLOAD_KEY_OFFSET => $this->offset,
            TableActionConstants::PAYLOAD_KEY_LIMIT => $this->limit,
        ];
        if ($this->key !== '') {
            $data[TableActionConstants::PAYLOAD_KEY_RESPONSE_TABLE_KEY] = $this->key;
        }
        return $data;
    }

    public static function fromArray(array $data): static
    {
        return new static(
            key: $data[TableActionConstants::PAYLOAD_KEY_RESPONSE_TABLE_KEY] ?? '',
            type: TableType::from($data[TableActionConstants::PAYLOAD_KEY_TYPE] ?? TableActionConstants::DEFAULT_TYPE),
            rows: $data[TableActionConstants::PAYLOAD_KEY_ROWS] ?? [],
            supportsSnapshot: (bool) ($data[TableActionConstants::PAYLOAD_KEY_SUPPORTS_SNAPSHOT] ?? false),
            totalCount: (int) ($data[TableActionConstants::PAYLOAD_KEY_TOTAL_COUNT] ?? -1),
            isPage: (bool) ($data[TableActionConstants::PAYLOAD_KEY_IS_PAGE] ?? false),
            offset: (int) ($data[TableActionConstants::PAYLOAD_KEY_OFFSET] ?? 0),
            limit: (int) ($data[TableActionConstants::PAYLOAD_KEY_LIMIT] ?? 0),
        );
    }
}
