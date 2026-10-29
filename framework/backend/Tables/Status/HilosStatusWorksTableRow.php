<?php

declare(strict_types=1);

namespace Hilos\Tables\Status;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Database\View\Item\StatusWork;

/** Exact public administration row; internal actors and mail audiences never enter this projection. */
final class HilosStatusWorksTableRow extends AbstractTableRow
{
    public const string id = 'id';
    public const string publicId = 'publicId';
    public const string title = 'title';
    public const string description = 'description';
    public const string componentKeys = 'componentKeys';
    public const string startsAt = 'startsAt';
    public const string endsAt = 'endsAt';
    public const string version = 'version';
    public const string canceledAt = 'canceledAt';
    public const string consumedAt = 'consumedAt';
    public const string expiredAt = 'expiredAt';
    public const string lastMailQueuedAt = 'lastMailQueuedAt';

    /**
     * @param int $id Persisted primary id
     * @param string $publicId Immutable public identity
     * @param string $title Public announcement title
     * @param ?string $description Optional public details
     * @param list<string> $componentKeys Selected catalog keys
     * @param int $startsAt Inclusive scheduled start, UTC epoch milliseconds
     * @param int $endsAt Exclusive scheduled end, UTC epoch milliseconds
     * @param int $version Domain revision for optimistic concurrency
     * @param ?int $canceledAt Cancellation moment, UTC epoch milliseconds
     * @param ?int $consumedAt Matching maintenance start, UTC epoch milliseconds
     * @param ?int $expiredAt Expiry observation, UTC epoch milliseconds
     * @param ?int $lastMailQueuedAt Last mail handoff, UTC epoch milliseconds
     */
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $title,
        public readonly ?string $description,
        public readonly array $componentKeys,
        public readonly int $startsAt,
        public readonly int $endsAt,
        public readonly int $version,
        public readonly ?int $canceledAt,
        public readonly ?int $consumedAt,
        public readonly ?int $expiredAt,
        public readonly ?int $lastMailQueuedAt,
    ) {
    }

    /**
     * @param StatusWork $work Persisted work and its direct component bridge
     * @return self Complete work slot
     */
    public static function fromWork(StatusWork $work): self
    {
        $keys = [];
        foreach ($work->components as $component) {
            $keys[] = $component->componentKey;
        }
        return new self(
            $work->id,
            $work->publicId,
            $work->title,
            $work->description,
            $keys,
            $work->startsAt,
            $work->endsAt,
            $work->version,
            $work->canceledAt,
            $work->consumedAt,
            $work->expiredAt,
            $work->lastMailQueuedAt,
        );
    }

    /** @return int Persisted primary id */
    public function getRowKey(): int
    {
        return $this->id;
    }

    /** @return string Row key field */
    public static function keyField(): string
    {
        return self::id;
    }

    /**
     * @param array<string, mixed> $data Exact work slot
     * @return static Typed public row
     * @throws InvalidFormatException When a required field is absent or malformed
     */
    public static function fromArray(array $data): static
    {
        foreach ([self::description, self::canceledAt, self::consumedAt, self::expiredAt, self::lastMailQueuedAt] as $field) {
            if (!array_key_exists($field, $data)) {
                throw new InvalidFormatException('Work row is missing field ' . $field);
            }
        }
        return new static(
            self::requireInt($data, self::id),
            self::requireString($data, self::publicId),
            self::requireString($data, self::title),
            self::optionalString($data, self::description),
            self::optionalStringList($data, self::componentKeys) ?? throw new InvalidFormatException('Work component keys are required'),
            self::requireInt($data, self::startsAt),
            self::requireInt($data, self::endsAt),
            self::requireInt($data, self::version),
            self::optionalInt($data, self::canceledAt),
            self::optionalInt($data, self::consumedAt),
            self::optionalInt($data, self::expiredAt),
            self::optionalInt($data, self::lastMailQueuedAt),
        );
    }

    /** @return array<string, mixed> Exactly the approved work fields */
    public function toArray(): array
    {
        return [
            self::id => $this->id,
            self::publicId => $this->publicId,
            self::title => $this->title,
            self::description => $this->description,
            self::componentKeys => $this->componentKeys,
            self::startsAt => $this->startsAt,
            self::endsAt => $this->endsAt,
            self::version => $this->version,
            self::canceledAt => $this->canceledAt,
            self::consumedAt => $this->consumedAt,
            self::expiredAt => $this->expiredAt,
            self::lastMailQueuedAt => $this->lastMailQueuedAt,
        ];
    }
}
