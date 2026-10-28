<?php

declare(strict_types=1);

namespace Hilos\Pages\Status\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Exact subscription target for an ADMIN page removal. */
final class HilosStatusRemoveSubscriberActionDTO extends ActionPayloadDTO
{
    public const string subscriberId = 'subscriberId';

    public const array SECRET_FIELDS = [];

    /**
     * @param int $subscriberId Persisted subscriber primary id
     * @throws InvalidFormatException When the target is not positive
     */
    public function __construct(public readonly int $subscriberId)
    {
        if ($subscriberId <= 0) {
            throw new InvalidFormatException('Choose an subscriber.');
        }
    }

    /** @return string ADMIN subscriber removal action */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_STATUS_SUBSCRIBER_REMOVE;
    }

    /**
     * @param array<string, mixed> $data Raw action payload, optionally wrapped
     * @return static Validated subscriber target
     * @throws InvalidFormatException When the data section or target is malformed
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            throw new InvalidFormatException('The subscriber action needs an object payload.');
        }

        return new static(self::requireInt($inner, self::subscriberId));
    }

    /** @return array<string, mixed> The target without envelope metadata */
    public function toArray(): array
    {
        return [self::subscriberId => $this->subscriberId];
    }
}
