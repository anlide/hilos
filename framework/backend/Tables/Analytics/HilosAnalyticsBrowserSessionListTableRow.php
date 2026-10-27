<?php

declare(strict_types=1);

namespace Hilos\Tables\Analytics;

use Hilos\Core\Analytics\AnalyticsSectionBrowserSession;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;

/** Scalar session projection; the session number belongs only to the browser envelope. */
final class HilosAnalyticsBrowserSessionListTableRow extends AbstractTableRow
{
    public const string rowKey = 'rowKey';
    public const string lastSignedInUserId = 'lastSignedInUserId';
    public const string lastSignedInUserName = 'lastSignedInUserName';
    public const string browser = 'browser';
    public const string firstSeenTs = 'firstSeenTs';
    public const string lastSeenTs = 'lastSeenTs';
    public const string actionCount = 'actionCount';

    /**
     * @param int $rowKey Analytics browser-session number
     * @param ?int $lastSignedInUserId Last signed-in account, absent for a guest
     * @param ?string $lastSignedInUserName Current mounted account name, absent for a guest or deleted account
     * @param ?string $browser Short current browser and operating-system label
     * @param int $firstSeenTs First source moment in milliseconds
     * @param int $lastSeenTs Last source moment in milliseconds
     * @param int $actionCount Actions on connections attached to this session
     */
    public function __construct(
        public int $rowKey,
        public ?int $lastSignedInUserId,
        public ?string $lastSignedInUserName,
        public ?string $browser,
        public int $firstSeenTs,
        public int $lastSeenTs,
        public int $actionCount,
    ) {
    }

    /**
     * @param AnalyticsSectionBrowserSession $session Typed reader result
     * @param ?string $lastSignedInUserName Raw mounted account name, never a deleted-account label
     * @return self Scalar table row with separate nullable identity and name
     */
    public static function fromSession(AnalyticsSectionBrowserSession $session, ?string $lastSignedInUserName): self
    {
        return new self(
            $session->id,
            $session->lastSignedInUserId,
            $lastSignedInUserName,
            $session->browser,
            $session->firstSeenTs,
            $session->lastSeenTs,
            $session->actionCount,
        );
    }

    /** @return int Analytics browser-session number */
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
            self::lastSignedInUserId => $this->lastSignedInUserId,
            self::lastSignedInUserName => $this->lastSignedInUserName,
            self::browser => $this->browser,
            self::firstSeenTs => $this->firstSeenTs,
            self::lastSeenTs => $this->lastSeenTs,
            self::actionCount => $this->actionCount,
        ];
    }

    /**
     * @param array<string, mixed> $data Internal scalar row
     * @return static Reconstructed row
     * @throws InvalidFormatException When a required field is missing or a value has the wrong type
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireInt($data, self::rowKey),
            self::optionalInt($data, self::lastSignedInUserId),
            self::optionalString($data, self::lastSignedInUserName),
            self::optionalString($data, self::browser),
            self::requireInt($data, self::firstSeenTs),
            self::requireInt($data, self::lastSeenTs),
            self::requireInt($data, self::actionCount),
        );
    }
}
