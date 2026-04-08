<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * ChatAgent → ModeratorAgent: moderate a completed quarantine file.
 */
final class ModerationFileRequestSignalData extends BaseDTO implements SignalDataInterface
{
    public function __construct(
        public readonly string $acceptKey,
        public readonly int $userId,
        public readonly string $quarantineBasename,
        public readonly string $originalFilename,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly string $syntheticMessage,
    ) {
    }

    public function toArray(): array
    {
        return [
            'acceptKey' => $this->acceptKey,
            'userId' => $this->userId,
            'quarantineBasename' => $this->quarantineBasename,
            'originalFilename' => $this->originalFilename,
            'mimeType' => $this->mimeType,
            'size' => $this->size,
            'syntheticMessage' => $this->syntheticMessage,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new static(
            acceptKey: (string)($data['acceptKey'] ?? ''),
            userId: (int)($data['userId'] ?? 0),
            quarantineBasename: (string)($data['quarantineBasename'] ?? ''),
            originalFilename: (string)($data['originalFilename'] ?? ''),
            mimeType: (string)($data['mimeType'] ?? ''),
            size: (int)($data['size'] ?? 0),
            syntheticMessage: (string)($data['syntheticMessage'] ?? ''),
        );
    }
}
