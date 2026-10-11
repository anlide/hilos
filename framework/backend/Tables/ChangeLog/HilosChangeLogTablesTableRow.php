<?php

declare(strict_types=1);

namespace Hilos\Tables\ChangeLog;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\ChangeLog\Section\ChangeLogTableSummary;
use Hilos\Database\Schema\JournalColumnMode;

/** One live table's journal declaration and activity, keyed by its name. */
final class HilosChangeLogTablesTableRow extends AbstractTableRow
{
    public const string name = 'name';
    public const string owner = 'owner';
    public const string journaled = 'journaled';
    public const string columns = 'columns';
    public const string changesLast24h = 'changesLast24h';
    public const string lastChangeAt = 'lastChangeAt';
    public const string changesSort = 'changesSort';
    public const string lastChangeSort = 'lastChangeSort';

    public const string columnsValue = JournalColumnMode::VALUE->value;
    public const string columnsPersonal = JournalColumnMode::PERSONAL->value;
    public const string columnsBinary = JournalColumnMode::BINARY->value;
    public const string columnsSecret = JournalColumnMode::SECRET->value;
    public const string columnsNoise = JournalColumnMode::NOISE->value;

    /** Lexical lower bound of the timestamp ordering; the display value stays nullable. */
    private const string LAST_CHANGE_SORT_MIN = '';

    /**
     * @param string $name Live table name and row key
     * @param string $owner Framework or project declaration
     * @param bool $journaled Whether the table is journaled
     * @param ?array{value: int, personal: int, binary: int, secret: int, noise: int} $columns Counts excluding the record key
     * @param ?int $changesLast24h Recent writes, or null without a journal declaration
     * @param ?string $lastChangeAt Last write in UTC ISO format, or null without a write
     * @param int $changesSort Numeric activity ordering key
     * @param string $lastChangeSort Lexical timestamp ordering key
     */
    public function __construct(
        public string $name,
        public string $owner,
        public bool $journaled,
        public ?array $columns,
        public ?int $changesLast24h,
        public ?string $lastChangeAt,
        public int $changesSort,
        public string $lastChangeSort,
    ) {
    }

    /**
     * @param ChangeLogTableSummary $summary Live table's declaration and activity
     * @return self Browser row with non-null ordering keys
     */
    public static function fromSummary(ChangeLogTableSummary $summary): self
    {
        $columns = null;
        if ($summary->journaled) {
            $columns = [
                self::columnsValue => $summary->modeCounts[self::columnsValue] ?? 0,
                self::columnsPersonal => $summary->modeCounts[self::columnsPersonal] ?? 0,
                self::columnsBinary => $summary->modeCounts[self::columnsBinary] ?? 0,
                self::columnsSecret => $summary->modeCounts[self::columnsSecret] ?? 0,
                self::columnsNoise => $summary->modeCounts[self::columnsNoise] ?? 0,
            ];
        }
        $changesLast24h = $summary->journaled ? $summary->changesLast24h : null;
        $lastChangeAt = $summary->journaled && $summary->lastChangeAt !== null
            ? HilosChangeLogTableParts::displayTime($summary->lastChangeAt) : null;

        return new self(
            $summary->name,
            $summary->owner->value,
            $summary->journaled,
            $columns,
            $changesLast24h,
            $lastChangeAt,
            $changesLast24h ?? -1,
            $lastChangeAt ?? self::LAST_CHANGE_SORT_MIN,
        );
    }

    /** @return string Live table name */
    public function getRowKey(): string
    {
        return $this->name;
    }

    /** @return string Payload field carrying the row key */
    public static function keyField(): string
    {
        return self::name;
    }

    /** @return array<string, mixed> Inline table slot without an entity id */
    public function toArray(): array
    {
        return [
            self::name => $this->name,
            self::owner => $this->owner,
            self::journaled => $this->journaled,
            self::columns => $this->columns,
            self::changesLast24h => $this->changesLast24h,
            self::lastChangeAt => $this->lastChangeAt,
            self::changesSort => $this->changesSort,
            self::lastChangeSort => $this->lastChangeSort,
        ];
    }

    /**
     * @param array<string, mixed> $data Table slot payload
     * @return static Reconstructed table row
     * @throws InvalidFormatException When a field or a column count is malformed
     */
    public static function fromArray(array $data): static
    {
        $columns = self::optionalArray($data, self::columns);
        if ($columns !== null) {
            $columns = [
                self::columnsValue => self::requireInt($columns, self::columnsValue),
                self::columnsPersonal => self::requireInt($columns, self::columnsPersonal),
                self::columnsBinary => self::requireInt($columns, self::columnsBinary),
                self::columnsSecret => self::requireInt($columns, self::columnsSecret),
                self::columnsNoise => self::requireInt($columns, self::columnsNoise),
            ];
        }

        return new static(
            self::requireString($data, self::name),
            self::requireString($data, self::owner),
            self::requireBool($data, self::journaled),
            $columns,
            self::optionalInt($data, self::changesLast24h),
            self::optionalString($data, self::lastChangeAt),
            self::requireInt($data, self::changesSort),
            self::requireString($data, self::lastChangeSort),
        );
    }
}
