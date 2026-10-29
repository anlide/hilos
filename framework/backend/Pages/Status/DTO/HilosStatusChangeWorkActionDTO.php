<?php

declare(strict_types=1);

namespace Hilos\Pages\Status\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Exception\InvalidFormatException;

/** Change a work announcement from its ADMIN page. */
final class HilosStatusChangeWorkActionDTO extends ActionPayloadDTO
{
    public const string workId = 'workId';
    public const string publicId = 'publicId';
    public const string expectedVersion = 'expectedVersion';
    public const string title = 'title';
    public const string description = 'description';
    public const string componentKeys = 'componentKeys';
    public const string startsAt = 'startsAt';
    public const string endsAt = 'endsAt';

    public const array SECRET_FIELDS = [];

    /**
     * @param int $workId Captured work primary id
     * @param string $publicId Immutable public work identity
     * @param int $expectedVersion Captured domain version
     * @param string $title Public announcement title
     * @param ?string $description Optional public description
     * @param list<string> $componentKeys Selected catalog-key values
     * @param int $startsAt Inclusive start in UTC epoch milliseconds
     * @param int $endsAt Exclusive end in UTC epoch milliseconds
     * @throws InvalidFormatException When target, list or handover fields have an invalid shape
     */
    public function __construct(
        public readonly int $workId,
        public readonly string $publicId,
        public readonly int $expectedVersion,
        public readonly string $title,
        public readonly ?string $description,
        public readonly array $componentKeys,
        public readonly int $startsAt,
        public readonly int $endsAt,
    ) {
        if ($workId <= 0 || $expectedVersion <= 0 || preg_match('/^[a-f0-9]{32}$/D', $publicId) !== 1) {
            throw new InvalidFormatException('Choose the current work announcement and its version.');
        }
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
        return HilosSignalConstants::HILOS_STATUS_WORK_CHANGE;
    }

    /**
     * @param array<string, mixed> $data Business payload, optionally wrapped in action.data
     * @return static Strictly typed change request
     * @throws InvalidFormatException When a required field is missing or mistyped
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            throw new InvalidFormatException('The work action needs an object payload.');
        }

        return new static(
            workId: self::requireInt($inner, self::workId),
            publicId: self::requireString($inner, self::publicId),
            expectedVersion: self::requireInt($inner, self::expectedVersion),
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
            self::workId => $this->workId,
            self::publicId => $this->publicId,
            self::expectedVersion => $this->expectedVersion,
            self::title => $this->title,
            self::description => $this->description,
            self::componentKeys => $this->componentKeys,
            self::startsAt => $this->startsAt,
            self::endsAt => $this->endsAt,
        ];
    }
}
