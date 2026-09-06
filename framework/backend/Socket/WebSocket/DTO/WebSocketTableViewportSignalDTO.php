<?php

declare(strict_types=1);

namespace Hilos\Socket\WebSocket\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDataDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\TableAnchorDirection;
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
 * The order rides the wire as a list of nested `{field, direction}` objects, in the sequence
 * they apply, and is held here as one {@see TableSortOrderDTO}, null when the window asked for
 * no ordering. An empty list is no ordering too: the frame is what an SDK sends when it has
 * none to report, so it is not a malformed frame.
 *
 * The window is addressed one of two ways and the frame carries one of them: an anchor with the
 * side it is taken from, or the index of a page to jump to. A frame carrying both is refused
 * rather than resolved, because either reading of it would be a window the client did not ask
 * for. A frame carrying neither asks for the first window of the set.
 */
class WebSocketTableViewportSignalDTO extends BaseDTO implements SignalDataDTO, SignalDataInterface, WebSocketAcceptKeySignalDTO
{
    // Field name constants
    public const string ACCEPT_KEY = 'acceptKey';
    public const string PAGE = 'page';
    public const string TABLE_KEY = 'tableKey';
    public const string FILTER = 'filter';
    public const string SORT = 'sort';
    public const string LIMIT = 'limit';
    public const string ANCHOR = 'anchor';
    public const string ANCHOR_DIRECTION = 'anchorDirection';
    public const string PAGE_INDEX = 'pageIndex';

    /**
     * Creates a table viewport signal DTO.
     *
     * @param string $acceptKey WebSocket accept key
     * @param ?string $page Page the table belongs to, null when the signal name carries it
     * @param string $tableKey Table key the viewport scopes
     * @param array<string, mixed> $filter Open filter map resolved by the concrete table
     * @param ?TableSortOrderDTO $sort Requested order, or null for backend arrival order
     * @param int $limit Window size (TableConstants::NO_LIMIT = all rows)
     * @param ?TableAnchorDTO $anchor Place the window is taken from, or null for the edge of the set
     * @param TableAnchorDirection $anchorDirection Side of the anchor, and which edge a null anchor means
     * @param ?int $pageIndex Zero-based page to jump to, or null when the window is paged by anchor
     */
    public function __construct(
        public readonly string $acceptKey,
        public readonly ?string $page = null,
        public readonly string $tableKey = '',
        public readonly array $filter = [],
        public readonly ?TableSortOrderDTO $sort = null,
        public readonly int $limit = TableConstants::NO_LIMIT,
        public readonly ?TableAnchorDTO $anchor = null,
        public readonly TableAnchorDirection $anchorDirection = TableAnchorDirection::After,
        public readonly ?int $pageIndex = null,
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
            self::LIMIT => $this->limit,
        ];

        if ($this->pageIndex !== null) {
            $result[self::PAGE_INDEX] = $this->pageIndex;
        } else {
            $result[self::ANCHOR] = $this->anchor?->toArray();
            $result[self::ANCHOR_DIRECTION] = $this->anchorDirection->value;
        }

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
     * The size of the window is what the frame is for and is required; the filter and the
     * sort are not, because the SDK leaves an empty filter and an unset ordering out of the
     * frame entirely. Neither is the address, whose absence names the first window of the set.
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
     * @throws InvalidFormatException When the frame carries no accept key, table key or limit,
     *     addresses the window both ways at once, or names a side the anchor has no meaning on
     */
    public static function fromArray(array $data): static
    {
        $page = self::optionalString($data, self::PAGE);
        $pageIndex = self::optionalInt($data, self::PAGE_INDEX);
        $anchor = TableAnchorDTO::fromWire($data[self::ANCHOR] ?? null);
        $direction = self::optionalString($data, self::ANCHOR_DIRECTION);
        if ($pageIndex !== null && ($anchor !== null || $direction !== null)) {
            throw new InvalidFormatException('Table viewport frame addresses its window both by anchor and by page index');
        }

        return new static(
            acceptKey: self::requireString($data, self::ACCEPT_KEY),
            page: $page === '' ? null : $page,
            tableKey: self::requireString($data, self::TABLE_KEY),
            filter: self::optionalArray($data, self::FILTER) ?? [],
            sort: TableSortOrderDTO::fromWire($data[self::SORT] ?? null),
            limit: self::requireInt($data, self::LIMIT),
            anchor: $anchor,
            anchorDirection: $direction === null
                ? TableAnchorDirection::After
                : TableAnchorDirection::tryFrom($direction)
                    ?? throw new InvalidFormatException('Table viewport frame carries an unknown anchor direction'),
            pageIndex: $pageIndex,
        );
    }
}
