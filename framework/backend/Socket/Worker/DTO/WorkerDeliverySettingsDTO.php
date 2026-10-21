<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\WorkerDTO;

/** Effective WebSocket delivery window read by a worker and reported to its master. */
final class WorkerDeliverySettingsDTO extends WorkerDTO
{
    public const string TYPE = 'type';
    public const string WINDOW_MS = 'windowMs';
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_DELIVERY_SETTINGS;

    /**
     * @param int $windowMs Positive delivery window in milliseconds
     * @throws InvalidFormatException When the window is not positive
     */
    public function __construct(public readonly int $windowMs)
    {
        if ($windowMs <= 0) {
            throw new InvalidFormatException('WebSocket delivery window must be positive');
        }
    }

    /**
     * @return string Worker message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * @return array<string, mixed> Worker wire payload
     */
    public function toArray(): array
    {
        return [self::TYPE => self::MESSAGE_TYPE, self::WINDOW_MS => $this->windowMs];
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Decoded settings report
     * @throws InvalidFormatException When the window is missing or is not a positive integer
     */
    public static function fromArray(array $data): static
    {
        return new static(self::requireInt($data, self::WINDOW_MS));
    }
}
