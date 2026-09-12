<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\TableConstants;

/**
 * TableBulkUntouchedDTO - one row a bulk run reached and did not change, and why.
 *
 * The whole point of the report is this line: a silent partial success is forbidden, so every
 * row the run left alone is named, and named with the reason it was left. "39 of 40 deleted"
 * is a message after which the reader has to go looking.
 *
 * The reason is text and not a code, for the reason every other refusal on this wire is text:
 * it is written where both facts are known - the row and what stopped it - and the surface
 * shows it rather than recomposing it out of flags. The framework's own two reasons are
 * {@see TableConstants::BULK_REASON_ROW_GONE} and
 * {@see TableConstants::BULK_REASON_NO_VERDICT}; any other reason is the page's own words
 * about a row it judged.
 */
final class TableBulkUntouchedDTO extends BaseDTO
{
    /** Wire key for the row that was left untouched. */
    public const string rowKey = 'rowKey';

    /** Wire key for why the row was left untouched. */
    public const string reason = 'reason';

    /**
     * @param string $rowKey Row the run reached and did not change
     * @param string $reason Why it was left, in words addressed to the person who asked
     */
    public function __construct(
        public readonly string $rowKey,
        public readonly string $reason,
    ) {
    }

    /**
     * Converts the line to its wire array.
     *
     * @return array<string, mixed> Line payload in the bulk-report wire form
     */
    public function toArray(): array
    {
        return [
            self::rowKey => $this->rowKey,
            self::reason => $this->reason,
        ];
    }

    /**
     * Restores the line from its wire array.
     *
     * @param array<string, mixed> $data Source data in the bulk-report wire form
     * @return static Restored DTO instance
     * @throws InvalidFormatException When the line names no row or gives no reason
     */
    public static function fromArray(array $data): static
    {
        return new static(
            rowKey: self::requireString($data, self::rowKey),
            reason: self::requireString($data, self::reason),
        );
    }
}
