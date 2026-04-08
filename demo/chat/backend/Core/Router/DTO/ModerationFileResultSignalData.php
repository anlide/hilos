<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * ModeratorAgent → ChatAgent: file moderation outcome.
 */
final class ModerationFileResultSignalData extends BaseDTO implements SignalDataInterface
{
    public function __construct(
        public readonly string $acceptKey,
        public readonly int $userId,
        public readonly bool $allow,
        public readonly string $reason,
        public readonly string $quarantineBasename,
        public readonly string $originalFilename,
        public readonly string $mimeType,
        public readonly int $size,
    ) {
    }

    public function toArray(): array
    {
        return [
            'acceptKey' => $this->acceptKey,
            'userId' => $this->userId,
            'allow' => $this->allow,
            'reason' => $this->reason,
            'quarantineBasename' => $this->quarantineBasename,
            'originalFilename' => $this->originalFilename,
            'mimeType' => $this->mimeType,
            'size' => $this->size,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new static(
            acceptKey: (string)($data['acceptKey'] ?? ''),
            userId: (int)($data['userId'] ?? 0),
            allow: (bool)($data['allow'] ?? false),
            reason: (string)($data['reason'] ?? ''),
            quarantineBasename: (string)($data['quarantineBasename'] ?? ''),
            originalFilename: (string)($data['originalFilename'] ?? ''),
            mimeType: (string)($data['mimeType'] ?? ''),
            size: (int)($data['size'] ?? 0),
        );
    }
}
