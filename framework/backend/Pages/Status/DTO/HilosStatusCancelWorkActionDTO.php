<?php

declare(strict_types=1);

namespace Hilos\Pages\Status\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Exception\InvalidFormatException;

/** Cancel a work announcement from its ADMIN page. */
final class HilosStatusCancelWorkActionDTO extends ActionPayloadDTO
{
    public const string workId = 'workId';
    public const string publicId = 'publicId';
    public const string expectedVersion = 'expectedVersion';

    public const array SECRET_FIELDS = [];

    /**
     * @param int $workId Captured work primary id
     * @param string $publicId Immutable public work identity
     * @param int $expectedVersion Captured domain version
     * @throws InvalidFormatException When target, list or handover fields have an invalid shape
     */
    public function __construct(
        public readonly int $workId,
        public readonly string $publicId,
        public readonly int $expectedVersion,
    ) {
        if ($workId <= 0 || $expectedVersion <= 0 || preg_match('/^[a-f0-9]{32}$/D', $publicId) !== 1) {
            throw new InvalidFormatException('Choose the current work announcement and its version.');
        }
    }

    /** @return string Owning ADMIN page action */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_STATUS_WORK_CANCEL;
    }

    /**
     * @param array<string, mixed> $data Business payload, optionally wrapped in action.data
     * @return static Strictly typed cancel request
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
        );
    }

    /** @return array<string, mixed> Business fields without request envelope */
    public function toArray(): array
    {
        return [
            self::workId => $this->workId,
            self::publicId => $this->publicId,
            self::expectedVersion => $this->expectedVersion,
        ];
    }
}
