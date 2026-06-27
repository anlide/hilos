<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\TableConstants;

/**
 * WebSocketTableViewportSignalDTO - DTO for a client's table viewport request.
 *
 * Sent from the client when a table mounts or its window changes (filter, sort,
 * paginate) and on cold load / reconnect. It declares the window the connection
 * wants for one table on its current page; the server answers with a table
 * window snapshot and remembers the delivered row-ids for live deltas.
 *
 * The sort rides the wire as a nested `{field, direction}` object and is held
 * here as the flat sortField / sortDirection pair.
 */
class WebSocketTableViewportSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    // Field name constants
    public const string ACCEPT_KEY = 'acceptKey';
    public const string PAGE = 'page';
    public const string TABLE_KEY = 'tableKey';
    public const string FILTER = 'filter';
    public const string SORT = 'sort';
    public const string SORT_FIELD = 'field';
    public const string SORT_DIRECTION = 'direction';
    public const string OFFSET = 'offset';
    public const string LIMIT = 'limit';

    /**
     * Creates a table viewport signal DTO.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $page Page the table belongs to
     * @param string $tableKey Table key the viewport scopes
     * @param array<string, mixed> $filter Open filter map resolved by the concrete table
     * @param string $sortField Sort field, or '' for backend arrival order
     * @param string $sortDirection TableConstants::ORDER_ASC or TableConstants::ORDER_DESC
     * @param int $offset Zero-based window offset
     * @param int $limit Window size (TableConstants::NO_LIMIT = all rows)
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly string $page = '',
        public readonly string $tableKey = '',
        public readonly array $filter = [],
        public readonly string $sortField = '',
        public readonly string $sortDirection = TableConstants::ORDER_ASC,
        public readonly int $offset = 0,
        public readonly int $limit = TableConstants::NO_LIMIT,
    ) {
    }

    public function getAcceptKey(): string
    {
        return $this->acceptKey;
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        $result = [
            self::ACCEPT_KEY => $this->acceptKey,
            self::PAGE => $this->page,
            self::TABLE_KEY => $this->tableKey,
            self::OFFSET => $this->offset,
            self::LIMIT => $this->limit,
        ];

        if ($this->filter !== []) {
            $result[self::FILTER] = $this->filter;
        }

        if ($this->sortField !== '') {
            $result[self::SORT] = [
                self::SORT_FIELD => $this->sortField,
                self::SORT_DIRECTION => $this->sortDirection,
            ];
        }

        return $result;
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        $filter = $data[self::FILTER] ?? [];
        $sort = $data[self::SORT] ?? [];
        if (!is_array($sort)) {
            $sort = [];
        }

        return new static(
            acceptKey: (string) ($data[self::ACCEPT_KEY] ?? ''),
            page: (string) ($data[self::PAGE] ?? ''),
            tableKey: (string) ($data[self::TABLE_KEY] ?? ''),
            filter: is_array($filter) ? $filter : [],
            sortField: is_string($sort[self::SORT_FIELD] ?? null) ? $sort[self::SORT_FIELD] : '',
            sortDirection: is_string($sort[self::SORT_DIRECTION] ?? null) ? $sort[self::SORT_DIRECTION] : TableConstants::ORDER_ASC,
            offset: (int) ($data[self::OFFSET] ?? 0),
            limit: (int) ($data[self::LIMIT] ?? TableConstants::NO_LIMIT),
        );
    }
}
