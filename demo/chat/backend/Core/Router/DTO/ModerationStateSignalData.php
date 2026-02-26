<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;
use RuntimeException;

/**
 * ModerationStateSignalData - per-user moderation progress state.
 */
class ModerationStateSignalData extends BaseDTO implements SignalDataInterface
{
    public function __construct(
        public readonly int $userId,
        public readonly bool $isModerating,
        public readonly ?string $message,
    ) {
    }

    public function toArray(): array
    {
        return [
            'userId' => $this->userId,
            'isModerating' => $this->isModerating,
            'message' => $this->message,
        ];
    }

    public static function fromArray(array $data): static
    {
        throw new RuntimeException('ModerationStateSignalData::fromArray() is not implemented');
    }
}
