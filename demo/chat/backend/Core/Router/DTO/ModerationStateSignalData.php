<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Exception\NotImplementedException;

/**
 * ModerationStateSignalData - per-user moderation progress state.
 */
class ModerationStateSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * Creates moderation state signal data.
     *
     * @param int $userId User ID
     * @param bool $isModerating Whether moderation is in progress
     * @param ?string $message Current message text or null
     */
    public function __construct(
        public readonly int $userId,
        public readonly bool $isModerating,
        public readonly ?string $message,
    ) {
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, int|bool|string|null> DTO data as array
     */
    public function toArray(): array
    {
        return [
            'userId' => $this->userId,
            'isModerating' => $this->isModerating,
            'message' => $this->message,
        ];
    }

    /**
     * Creates DTO from array (not implemented).
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws NotImplementedException Always - not implemented
     */
    public static function fromArray(array $data): static
    {
        throw new NotImplementedException('ModerationStateSignalData::fromArray() is not implemented');
    }
}
