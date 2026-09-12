<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Table\TableConstants;

/**
 * TableBulkReportSignalData - Server-to-client word of how a bulk run judged the rows it reached.
 *
 * This is the outcome of a mass operation, and it is a frame rather than the action's reply. The
 * reply answered acceptance the moment the run was taken, because a run over a condition outlives
 * the client's action timeout and holding the action open would fail work that is going fine
 * (docs/agents/frontend/wire-protocol.md, "When the work outlives the reply"). The shape of the
 * outcome is the one the table doctrine fixed - touched, and the untouched by name - and only its
 * carrier changed.
 *
 * `touched` is a NUMBER and not a list of names. Rows that were changed have already reached
 * every tab looking at them as ordinary live deltas, and repeating their names here would give
 * the reader a second copy of what is already on the screen. The untouched are the opposite: no
 * delta ever mentions them, and without this list a partial success is silent.
 *
 * `untouchedOmitted` counts the names that did not fit under
 * {@see TableConstants::BULK_UNTOUCHED_NAME_CEILING}, and it is null - and absent from the wire -
 * when every name fit. Null rather than zero because a report that named everyone has no such
 * fact to state, the way {@see TableProgressDTO} carries no total for work with no estimate.
 *
 * Addressed to the connection that started the run and to no one else: the selection panel
 * belongs to whoever was marking rows, and a tab that was merely watching has nowhere to draw a
 * report and no press of its own to connect it to.
 */
final class TableBulkReportSignalData extends BaseDTO implements SignalDataInterface
{
    public const string page = 'page';
    public const string tableKey = 'tableKey';
    public const string progressKey = 'progressKey';
    public const string touched = 'touched';
    public const string untouched = 'untouched';
    public const string untouchedOmitted = 'untouchedOmitted';

    /**
     * Creates the report one bulk run ends with.
     *
     * @param string $page Page the table belongs to
     * @param string $tableKey Table the run was over
     * @param string $progressKey Key naming the run, the same one its reply and its bar carried
     * @param int $touched How many rows the run changed
     * @param list<TableBulkUntouchedDTO> $untouched Rows it reached and left alone, each with its reason
     * @param ?int $untouchedOmitted Untouched names that did not fit under the ceiling, or null when every name fit
     */
    public function __construct(
        public readonly string $page,
        public readonly string $tableKey,
        public readonly string $progressKey,
        public readonly int $touched,
        public readonly array $untouched,
        public readonly ?int $untouchedOmitted = null,
    ) {
    }

    /**
     * Converts the report to its wire array.
     *
     * @return array<string, mixed> DTO payload in the table-bulk-report wire form
     */
    public function toArray(): array
    {
        $payload = [
            self::page => $this->page,
            self::tableKey => $this->tableKey,
            self::progressKey => $this->progressKey,
            self::touched => $this->touched,
            self::untouched => array_map(
                static fn(TableBulkUntouchedDTO $line): array => $line->toArray(),
                $this->untouched,
            ),
        ];
        if ($this->untouchedOmitted !== null) {
            $payload[self::untouchedOmitted] = $this->untouchedOmitted;
        }

        return $payload;
    }

    /**
     * Restores the report from its wire array.
     *
     * @param array<string, mixed> $data Source data in the table-bulk-report wire form
     * @return static Restored DTO instance
     * @throws InvalidFormatException When the payload misses the addressed table, the run, the count, or a line of the list
     */
    public static function fromArray(array $data): static
    {
        $untouched = [];
        foreach (self::requireArray($data, self::untouched) as $line) {
            if (!is_array($line)) {
                throw new InvalidFormatException(
                    'Payload carries a non-array line under key ' . self::untouched,
                );
            }
            $untouched[] = TableBulkUntouchedDTO::fromArray($line);
        }

        return new static(
            page: self::requireString($data, self::page),
            tableKey: self::requireString($data, self::tableKey),
            progressKey: self::requireString($data, self::progressKey),
            touched: self::requireInt($data, self::touched),
            untouched: $untouched,
            untouchedOmitted: self::optionalInt($data, self::untouchedOmitted),
        );
    }
}
