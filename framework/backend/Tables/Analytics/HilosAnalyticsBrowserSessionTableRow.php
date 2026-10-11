<?php

declare(strict_types=1);

namespace Hilos\Tables\Analytics;

use Hilos\Core\Analytics\AnalyticsSectionBrowserSession;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/** One numbered analytics session with its section fields. */
final class HilosAnalyticsBrowserSessionTableRow extends AbstractTableRow
{
    public const string rowKey = 'rowKey';
    public const string firstSeenTs = 'firstSeenTs';
    public const string lastSeenTs = 'lastSeenTs';
    public const string browser = 'browser';
    public const string actionCount = 'actionCount';

    /**
     * @param int $rowKey Analytics browser-session number
     * @param int $firstSeenTs First source moment in milliseconds
     * @param int $lastSeenTs Last source moment in milliseconds
     * @param ?string $browser Short current browser and operating-system label
     * @param int $actionCount Actions on connections attached to this session
     */
    public function __construct(
        public int $rowKey,
        public int $firstSeenTs,
        public int $lastSeenTs,
        public ?string $browser,
        public int $actionCount,
    ) {
    }

    /**
     * @param AnalyticsSectionBrowserSession $session Typed reader result
     * @return self Table row without internal session or account data
     */
    public static function fromSession(AnalyticsSectionBrowserSession $session): self
    {
        return new self(
            $session->id,
            $session->firstSeenTs,
            $session->lastSeenTs,
            $session->browser,
            $session->actionCount,
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
            self::firstSeenTs => $this->firstSeenTs,
            self::lastSeenTs => $this->lastSeenTs,
            self::browser => $this->browser,
            self::actionCount => $this->actionCount,
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
            self::requireInt($data, self::firstSeenTs),
            self::requireInt($data, self::lastSeenTs),
            self::optionalString($data, self::browser),
            self::requireInt($data, self::actionCount),
        );
    }
}
