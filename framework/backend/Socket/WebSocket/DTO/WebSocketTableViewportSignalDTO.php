<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\TableConstants;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\WebSocket\Exception\InvalidFrameException;

/**
 * WebSocketTableViewportSignalDTO - DTO for a client's table viewport request.
 *
 * Sent from the client when a table mounts or its window changes (filter, sort,
 * paginate) and on cold load / reconnect. It declares the window the connection
 * wants for one table on its current page; the server answers with a table
 * window snapshot and remembers the delivered row-ids for live deltas.
 *
 * The sort rides the wire as a nested `{field, direction}` object and is held here as one
 * {@see TableSortDTO}, null when the window asked for no ordering.
 */
class WebSocketTableViewportSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    // Field name constants
    public const string ACCEPT_KEY = 'acceptKey';
    public const string PAGE = 'page';
    public const string TABLE_KEY = 'tableKey';
    public const string FILTER = 'filter';
    public const string SORT = 'sort';
    public const string OFFSET = 'offset';
    public const string LIMIT = 'limit';

    /**
     * Creates a table viewport signal DTO.
     *
     * @param string $acceptKey WebSocket accept key
     * @param ?string $page Page the table belongs to, null when the signal name carries it
     * @param string $tableKey Table key the viewport scopes
     * @param array<string, mixed> $filter Open filter map resolved by the concrete table
     * @param ?TableSortDTO $sort Requested ordering, or null for backend arrival order
     * @param int $offset Zero-based window offset
     * @param int $limit Window size (TableConstants::NO_LIMIT = all rows)
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly ?string $page = null,
        public readonly string $tableKey = '',
        public readonly array $filter = [],
        public readonly ?TableSortDTO $sort = null,
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
            self::TABLE_KEY => $this->tableKey,
            self::OFFSET => $this->offset,
            self::LIMIT => $this->limit,
        ];

        if ($this->page !== null) {
            $result[self::PAGE] = $this->page;
        }

        if ($this->filter !== []) {
            $result[self::FILTER] = $this->filter;
        }

        if ($this->sort !== null) {
            $result[self::SORT] = $this->sort->toArray();
        }

        return $result;
    }

    /**
     * Creates DTO from array.
     *
     * The window is what the frame is for and every part of it is required; the
     * filter and the sort are not, because the SDK leaves an empty filter and an
     * unset ordering out of the frame entirely.
     *
     * This is the one DTO of its family built straight from a client frame, and
     * that seam closes the connection on {@see InvalidFrameException} rather than
     * on the refusal itself. The translation is written where the frame is read
     * ({@see WebSocketClient::onFrame()}), beside every other check of the same
     * frame's shape: an override may not widen the contract of
     * {@see BaseDTO::fromArray()}, and it should not - the same class is also
     * restored from its own toArray() on the master-worker hop, where the plain
     * refusal is what the envelope expects.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the frame carries no accept key, table key, offset or limit
     */
    public static function fromArray(array $data): static
    {
        $page = self::optionalString($data, self::PAGE);

        return new static(
            acceptKey: self::requireString($data, self::ACCEPT_KEY),
            page: $page === '' ? null : $page,
            tableKey: self::requireString($data, self::TABLE_KEY),
            filter: self::optionalArray($data, self::FILTER) ?? [],
            sort: TableSortDTO::fromWire($data[self::SORT] ?? null),
            offset: self::requireInt($data, self::OFFSET),
            limit: self::requireInt($data, self::LIMIT),
        );
    }
}
