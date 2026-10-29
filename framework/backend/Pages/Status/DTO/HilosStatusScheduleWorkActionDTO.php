<?php

declare(strict_types=1);

namespace Hilos\Pages\Status\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Exception\InvalidFormatException;

/** Schedule a work announcement from its ADMIN page. */
final class HilosStatusScheduleWorkActionDTO extends ActionPayloadDTO
{
    public const string title = 'title';
    public const string description = 'description';
    public const string componentKeys = 'componentKeys';
    public const string startsAt = 'startsAt';
    public const string endsAt = 'endsAt';

    public const array SECRET_FIELDS = [];

    /**
     * @param string $title Public announcement title
     * @param ?string $description Optional public description
     * @param list<string> $componentKeys Selected catalog-key values
     * @param int $startsAt Inclusive start in UTC epoch milliseconds
     * @param int $endsAt Exclusive end in UTC epoch milliseconds
     * @throws InvalidFormatException When target, list or handover fields have an invalid shape
     */
    public function __construct(
        public readonly string $title,
        public readonly ?string $description,
        public readonly array $componentKeys,
        public readonly int $startsAt,
        public readonly int $endsAt,
    ) {
        if (!array_is_list($componentKeys)) {
            throw new InvalidFormatException('Choose a list of status component keys.');
        }
        foreach ($componentKeys as $key) {
            if (!is_string($key)) {
                throw new InvalidFormatException('Every status component key must be a string.');
            }
        }
    }

    /** @return string Owning ADMIN page action */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_STATUS_WORK_SCHEDULE;
    }

    /**
     * @param array<string, mixed> $data Business payload, optionally wrapped in action.data
     * @return static Strictly typed schedule request
     * @throws InvalidFormatException When a required field is missing or mistyped
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            throw new InvalidFormatException('The work action needs an object payload.');
        }

        return new static(
            title: self::requireString($inner, self::title),
            description: self::optionalString($inner, self::description),
            componentKeys: self::requireArray($inner, self::componentKeys),
            startsAt: self::requireInt($inner, self::startsAt),
            endsAt: self::requireInt($inner, self::endsAt),
        );
    }

    /** @return array<string, mixed> Business fields without request envelope */
    public function toArray(): array
    {
        return [
            self::title => $this->title,
            self::description => $this->description,
            self::componentKeys => $this->componentKeys,
            self::startsAt => $this->startsAt,
            self::endsAt => $this->endsAt,
        ];
    }
}
