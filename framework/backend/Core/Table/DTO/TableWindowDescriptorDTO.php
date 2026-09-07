<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use Hilos\Socket\WebSocket\DTO\WebSocketTableViewportSignalDTO;

/**
 * One window a tab is already holding, told to the server on a page subscription (HIL-642).
 *
 * It is the body of a {@see WebSocketTableViewportSignalDTO} without the page and the table key
 * — those are the frame's own address and the key this descriptor hangs under — and it means the
 * same thing there and here: the window this connection wants for that table. A tab sends one
 * per live window so that a subscription made again after a broken socket comes back as the
 * window that was on the screen, filter, order and page in place. The server keeps none of them:
 * the window it remembered died with the accept key, so the tab is the only side that still
 * knows, and it says so in the one frame the reconnect already sends.
 *
 * A page subscription carrying no descriptor for a table is not a lesser case: it is the cold
 * entry, where the tab has nothing to remember yet and the table's own declaration answers for
 * the first window.
 *
 * The window is addressed one of two ways and a descriptor carries one of them, refusing to
 * carry both for the same reason the viewport frame refuses it — either reading would be a
 * window nobody asked for.
 */
final class TableWindowDescriptorDTO extends BaseDTO
{
    /** Wire key: the open filter map the concrete table resolves. */
    public const string FILTER = 'filter';

    /** Wire key: the order the window runs in, as a list of `{field, direction}`. */
    public const string SORT = 'sort';

    /** Wire key: how many rows the window carries. */
    public const string LIMIT = 'limit';

    /** Wire key: the place the window is taken from. */
    public const string ANCHOR = 'anchor';

    /** Wire key: the side of the anchor the window is taken from. */
    public const string ANCHOR_DIRECTION = 'anchorDirection';

    /** Wire key: the zero-based page the window jumped to. */
    public const string PAGE_INDEX = 'pageIndex';

    /**
     * Creates one window descriptor.
     *
     * @param array<string, mixed> $filter Open filter map resolved by the concrete table
     * @param ?TableSortOrderDTO $sort Order the window runs in, or null for the source's own order
     * @param int $limit Window size (TableConstants::NO_LIMIT = all rows)
     * @param ?TableAnchorDTO $anchor Place the window is taken from, or null for the edge of the set
     * @param TableAnchorDirection $anchorDirection Side of the anchor, and which edge a null anchor means
     * @param ?int $pageIndex Zero-based page the window jumped to, or null when it is paged by anchor
     */
    public function __construct(
        public readonly array $filter = [],
        public readonly ?TableSortOrderDTO $sort = null,
        public readonly int $limit = TableConstants::NO_LIMIT,
        public readonly ?TableAnchorDTO $anchor = null,
        public readonly TableAnchorDirection $anchorDirection = TableAnchorDirection::After,
        public readonly ?int $pageIndex = null,
    ) {
    }

    /**
     * Converts the descriptor to its wire array.
     *
     * @return array<string, mixed> Descriptor in the page-subscribe wire form
     */
    public function toArray(): array
    {
        $result = [
            self::LIMIT => $this->limit,
        ];

        if ($this->pageIndex !== null) {
            $result[self::PAGE_INDEX] = $this->pageIndex;
        } else {
            $result[self::ANCHOR] = $this->anchor?->toArray();
            $result[self::ANCHOR_DIRECTION] = $this->anchorDirection->value;
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
     * Reads one window descriptor out of a page-subscribe frame.
     *
     * The size of the window is the one thing a descriptor is not a descriptor without: a tab
     * reports a window it is holding, and a window it is holding has a size. Everything else is
     * omitted where there is nothing to say — an empty filter, no ordering, the edge of the set.
     *
     * @param array<string, mixed> $data Source data
     * @return static Descriptor of the window the tab holds
     * @throws InvalidFormatException When the descriptor carries no limit, addresses the window
     *     both ways at once, or names a side the anchor has no meaning on
     */
    public static function fromArray(array $data): static
    {
        $pageIndex = self::optionalInt($data, self::PAGE_INDEX);
        $anchor = TableAnchorDTO::fromWire($data[self::ANCHOR] ?? null);
        $direction = self::optionalString($data, self::ANCHOR_DIRECTION);
        if ($pageIndex !== null && ($anchor !== null || $direction !== null)) {
            throw new InvalidFormatException('Table window descriptor addresses its window both by anchor and by page index');
        }

        return new static(
            filter: self::optionalArray($data, self::FILTER) ?? [],
            sort: TableSortOrderDTO::fromWire($data[self::SORT] ?? null),
            limit: self::requireInt($data, self::LIMIT),
            anchor: $anchor,
            anchorDirection: $direction === null
                ? TableAnchorDirection::After
                : TableAnchorDirection::tryFrom($direction)
                    ?? throw new InvalidFormatException('Table window descriptor carries an unknown anchor direction'),
            pageIndex: $pageIndex,
        );
    }
}
