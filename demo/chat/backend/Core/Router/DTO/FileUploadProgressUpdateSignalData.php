<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * Server → client: upload progress for the current user's in-flight binary upload (null = none).
 */
final class FileUploadProgressUpdateSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * @param ?array<string, mixed> $progress filename, uploadedBytes, totalBytes
     */
    public function __construct(
        public readonly ?array $progress,
    ) {
    }

    public function toArray(): array
    {
        return ['fileUploadProgress' => $this->progress];
    }

    public static function fromArray(array $data): static
    {
        $p = $data['fileUploadProgress'] ?? null;

        return new static(progress: is_array($p) ? $p : null);
    }
}
