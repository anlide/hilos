<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionReplyDTO;

/**
 * TableBulkAcceptedReplyDTO - the answer a bulk action gives at once: the run was taken.
 *
 * It is not the outcome of the run and never becomes one. A mass operation over a condition
 * outlives the client's action timeout trivially - every row goes to its owner and waits for
 * the answer - so holding the action open for the outcome would fail work that is progressing
 * fine. The outcome arrives later, addressed to the connection that asked, as a
 * {@see TableBulkReportSignalData} frame.
 *
 * `progressKey` is what ties the three together: the reply, the progress bar the run sends
 * while it works, and the report it ends with all name the same run, so a view connects its own
 * press to what appears in the panel without guessing.
 *
 * `total` is the number of rows the run will judge, and it is null when that number is not
 * honestly known - a condition whose count stopped at the ceiling has no exact size, and a bar
 * with no estimate is a real state rather than a missing field. "500 of 500+" would be a lie of
 * the same kind the count ceiling exists to prevent.
 */
final class TableBulkAcceptedReplyDTO extends ActionReplyDTO
{
    /** Wire key for the key of the run the reply accepts. */
    public const string progressKey = 'progressKey';

    /** Wire key for the number of rows the run will judge. */
    public const string total = 'total';

    /**
     * @param string $progressKey Key naming the accepted run, shared by its bar and its report
     * @param ?int $total Rows the run will judge, or null when the set has no honest count
     */
    public function __construct(
        public readonly string $progressKey,
        public readonly ?int $total,
    ) {
    }

    /**
     * Converts the reply to its wire array.
     *
     * An unknown total is absent rather than null, the way {@see TableProgressDTO::toArray()}
     * leaves out the estimate the work does not have.
     *
     * @return array<string, mixed> Reply payload in the bulk-accepted wire form
     */
    public function toArray(): array
    {
        $payload = [self::progressKey => $this->progressKey];
        if ($this->total !== null) {
            $payload[self::total] = $this->total;
        }

        return $payload;
    }

    /**
     * Restores the reply from its wire array.
     *
     * @param array<string, mixed> $data Source data in the bulk-accepted wire form
     * @return static Restored DTO instance
     * @throws InvalidFormatException When the run is unnamed or the total is present and not an integer
     */
    public static function fromArray(array $data): static
    {
        return new static(
            progressKey: self::requireString($data, self::progressKey),
            total: self::optionalInt($data, self::total),
        );
    }
}
