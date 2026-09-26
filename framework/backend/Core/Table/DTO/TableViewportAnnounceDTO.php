<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableRowPlacement;

/**
 * TableViewportAnnounceDTO - Server-to-client word of a row one window has not shown, created or edited into it.
 *
 * A row that belongs above the window or between the rows it is showing cannot arrive on its
 * own: putting it in would shift everything below it. Staying silent is no better - the window
 * would drift away from the set with only a reload telling the truth - so the row is announced
 * instead. The frame carries the row key and not the row: the key is there so the same row
 * announced twice is counted once, and the strip a person reads is a number rather than a list.
 *
 * The counts travel with it, because an announcement is also a count. A create carries the
 * arithmetic the append carries, legitimate for the same reason - a window that is announced to
 * either has no filter map or has been told by the row source that the row is in its set, so a
 * create is one more row in its set. An edit carries the total unchanged: the row moved within
 * the set and added none to it. Addressed per accept key.
 *
 * The page count travels only while the total is exact, as it does for
 * {@see TableViewportCountDTO}: past {@see TableConstants::COUNT_CEILING} the total is the
 * ceiling and no page count follows from it, so the key is absent rather than zero.
 */
final class TableViewportAnnounceDTO extends BaseDTO implements SignalDataInterface
{
    public const string page = 'page';
    public const string tableKey = 'tableKey';
    public const string rowKey = 'rowKey';
    public const string placement = 'placement';
    public const string totalCount = 'totalCount';
    public const string totalExact = 'totalExact';
    public const string pageCount = 'pageCount';

    /**
     * Creates a table viewport announce payload.
     *
     * @param string $page Page the table belongs to
     * @param string $tableKey Table key the announcement is for
     * @param string $rowKey Key of the row the window has not shown: created, or brought into it by an edit
     * @param TableRowPlacement $placement Where the row falls against the window, above it or inside it
     * @param int $totalCount Total rows matching the filter
     * @param bool $totalExact Whether that total is the size of the set rather than the ceiling the count stopped at
     * @param ?int $pageCount Page count under the window size, or null when the total is not exact
     */
    public function __construct(
        public readonly string $page,
        public readonly string $tableKey,
        public readonly string $rowKey,
        public readonly TableRowPlacement $placement,
        public readonly int $totalCount,
        public readonly bool $totalExact,
        public readonly ?int $pageCount,
    ) {
    }

    /**
     * Converts the payload to its wire array.
     *
     * @return array<string, mixed> DTO payload in the table-viewport-announce wire form
     */
    public function toArray(): array
    {
        $payload = [
            self::page => $this->page,
            self::tableKey => $this->tableKey,
            self::rowKey => $this->rowKey,
            self::placement => $this->placement->value,
            self::totalCount => $this->totalCount,
            self::totalExact => $this->totalExact,
        ];
        if ($this->pageCount !== null) {
            $payload[self::pageCount] = $this->pageCount;
        }

        return $payload;
    }

    /**
     * Restores the payload from its wire array.
     *
     * A placement outside above and inside is refused rather than carried. This frame exists for
     * the two places a window cannot show, and a tail or a below in it would be a claim that the
     * window is missing a row it either already has or never showed.
     *
     * @param array<string, mixed> $data Source data in the table-viewport-announce wire form
     * @return static Restored DTO instance
     * @throws InvalidFormatException When the payload misses the addressed table, the row, the counts, or names a placement the window can show
     */
    public static function fromArray(array $data): static
    {
        $placement = TableRowPlacement::tryFrom(self::requireString($data, self::placement));
        if ($placement !== TableRowPlacement::Above && $placement !== TableRowPlacement::Inside) {
            throw new InvalidFormatException(
                'Payload names no announceable placement under key ' . self::placement,
            );
        }

        return new static(
            page: self::requireString($data, self::page),
            tableKey: self::requireString($data, self::tableKey),
            rowKey: self::requireString($data, self::rowKey),
            placement: $placement,
            totalCount: self::requireInt($data, self::totalCount),
            totalExact: self::requireBool($data, self::totalExact),
            pageCount: self::optionalInt($data, self::pageCount),
        );
    }
}
