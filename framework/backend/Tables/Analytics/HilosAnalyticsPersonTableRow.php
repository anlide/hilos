<?php

declare(strict_types=1);

namespace Hilos\Tables\Analytics;

use Hilos\Core\Analytics\AnalyticsSectionPerson;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/** Scalar person projection with its envelope address kept for the table lifecycle. */
final class HilosAnalyticsPersonTableRow extends AbstractTableRow
{
    public const string rowKey = 'rowKey';
    public const string name = 'name';
    public const string sessionCount = 'sessionCount';
    public const string lastSeenTs = 'lastSeenTs';

    /**
     * @param int $rowKey Positive numeric account id retained by analytics
     * @param ?string $name Current mounted account name, absent for a deleted account
     * @param int $sessionCount Browser sessions matching the selected period
     * @param int $lastSeenTs Latest source moment among those sessions, in milliseconds
     */
    public function __construct(
        public int $rowKey,
        public ?string $name,
        public int $sessionCount,
        public int $lastSeenTs,
    ) {
    }

    /**
     * @param AnalyticsSectionPerson $person Grouped reader result
     * @param ?string $name Current mounted account name
     * @return self Scalar table row
     */
    public static function fromPerson(AnalyticsSectionPerson $person, ?string $name): self
    {
        return new self($person->userId, $name, $person->sessionCount, $person->lastSeenTs);
    }

    /** @return int Numeric account id */
    public function getRowKey(): int
    {
        return $this->rowKey;
    }

    /** @return string Field carrying the row address */
    public static function keyField(): string
    {
        return self::rowKey;
    }

    /** @return array<string, mixed> Internal scalar row, including its lifecycle key */
    public function toArray(): array
    {
        return [
            self::rowKey => $this->rowKey,
            self::name => $this->name,
            self::sessionCount => $this->sessionCount,
            self::lastSeenTs => $this->lastSeenTs,
        ];
    }

    /**
     * @param array<string, mixed> $data Internal scalar row
     * @return static Reconstructed row
     * @throws InvalidFormatException When a required field is missing or mistyped
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireInt($data, self::rowKey),
            self::optionalString($data, self::name),
            self::requireInt($data, self::sessionCount),
            self::requireInt($data, self::lastSeenTs),
        );
    }
}
