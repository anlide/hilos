<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Server → client: file moderation UI for the current user (null = none).
 * Phases: moderating | rejected only — upload progress uses {@see FileUploadProgressUpdateSignalData}.
 */
final class FileModerationStateUpdateSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param ?array<string, mixed> $state phase, filename, uploadedBytes, totalBytes, reason
     */
    public function __construct(
        public readonly ?array $state,
    ) {
    }

    public function toArray(): array
    {
        return ['fileModerationState' => $this->state];
    }

    public static function fromArray(array $data): static
    {
        $s = $data['fileModerationState'] ?? null;

        return new static(state: is_array($s) ? $s : null);
    }
}
