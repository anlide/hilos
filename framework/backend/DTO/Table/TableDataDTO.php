<?php

declare(strict_types=1);

namespace Hilos\DTO\Table;

use Hilos\Core\Table\TableType;
use Hilos\DTO\BaseDTO;

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
            'type' => $this->type->value,
            'rows' => $this->rows,
            'supportsSnapshot' => $this->supportsSnapshot,
            'totalCount' => $this->totalCount,
            'isPage' => $this->isPage,
            'offset' => $this->offset,
            'limit' => $this->limit,
        ];
        if ($this->key !== '') {
            $data['key'] = $this->key;
        }
        return $data;
    }

    public static function fromArray(array $data): static
    {
        return new static(
            key: $data['key'] ?? '',
            type: TableType::from($data['type'] ?? 'entity'),
            rows: $data['rows'] ?? [],
            supportsSnapshot: (bool) ($data['supportsSnapshot'] ?? false),
            totalCount: (int) ($data['totalCount'] ?? -1),
            isPage: (bool) ($data['isPage'] ?? false),
            offset: (int) ($data['offset'] ?? 0),
            limit: (int) ($data['limit'] ?? 0),
        );
    }
}
