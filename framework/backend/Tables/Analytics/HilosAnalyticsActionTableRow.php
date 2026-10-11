<?php

declare(strict_types=1);

namespace Hilos\Tables\Analytics;

use Hilos\Core\Analytics\AnalyticsSectionAction;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/** One numbered analytics action with its section fields. */
final class HilosAnalyticsActionTableRow extends AbstractTableRow
{
    public const string rowKey = 'rowKey';
    public const string createdTs = 'createdTs';
    public const string pageName = 'pageName';
    public const string actionName = 'actionName';

    /**
     * @param int $rowKey Analytics action number
     * @param int $createdTs Source moment in milliseconds
     * @param ?string $pageName Recorded page name, or null without a page session
     * @param string $actionName Recorded action name
     */
    public function __construct(
        public int $rowKey,
        public int $createdTs,
        public ?string $pageName,
        public string $actionName,
    ) {
    }

    /**
     * @param AnalyticsSectionAction $action Typed reader result
     * @return self Table row without internal session or account data
     */
    public static function fromAction(AnalyticsSectionAction $action): self
    {
        return new self(
            $action->id,
            $action->createdTs,
            $action->pageName,
            $action->actionName,
        );
    }

    /** @return int Analytics row number */
    public function getRowKey(): int
    {
        return $this->rowKey;
    }

    /** @return string Field carrying the row number */
    public static function keyField(): string
    {
        return self::rowKey;
    }

    /** @return array<string, mixed> Analytics slot payload */
    public function toArray(): array
    {
        return [
            self::rowKey => $this->rowKey,
            self::createdTs => $this->createdTs,
            self::pageName => $this->pageName,
            self::actionName => $this->actionName,
        ];
    }

    /**
     * @param array<string, mixed> $data Analytics slot payload
     * @return static Reconstructed table row
     * @throws InvalidFormatException When a required field is missing or a value has the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireInt($data, self::rowKey),
            self::requireInt($data, self::createdTs),
            self::optionalString($data, self::pageName),
            self::requireString($data, self::actionName),
        );
    }
}
