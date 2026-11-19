<?php

declare(strict_types=1);

namespace Hilos\Users\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\ActionPayloadDTO;

/** Cancels preparation of an existing merge from the administrator's user card. */
final class AccountMergeCancelActionDTO extends ActionPayloadDTO
{
    public const string operationId = 'operationId';
    public const array SECRET_FIELDS = [];

    /** @param int $operationId Existing merge operation */
    public function __construct(public readonly int $operationId)
    {
    }

    /** @return string Browser action name */
    public function getAction(): string
    {
        return HilosSignalConstants::HILOS_USER_MERGE_CANCEL;
    }

    /**
     * @param array<string, mixed> $data Raw payload or action-data envelope
     * @return static Cancellation payload
     * @throws InvalidFormatException When the operation id is absent, non-integer or not positive
     */
    public static function fromArray(array $data): static
    {
        $inner = $data[SignalPayloadConstants::FIELD_DATA] ?? $data;
        if (!is_array($inner)) {
            throw new InvalidFormatException('Merge cancellation payload must be an object');
        }
        $operationId = self::requireInt($inner, self::operationId);
        if ($operationId <= 0) {
            throw new InvalidFormatException('Merge operation id must be positive');
        }

        return new static($operationId);
    }

    /** @return array<string, int> Existing operation address */
    public function toArray(): array
    {
        return [self::operationId => $this->operationId];
    }
}
