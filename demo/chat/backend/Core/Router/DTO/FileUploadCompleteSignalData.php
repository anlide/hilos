<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

final class FileUploadCompleteSignalData extends BaseDTO implements SignalDataInterface
{
    public function __construct(
        public readonly string $uploadId,
        public readonly string $filename,
    ) {
    }

    public function toArray(): array
    {
        return [
            'uploadId' => $this->uploadId,
            'filename' => $this->filename,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new static(
            uploadId: (string)($data['uploadId'] ?? ''),
            filename: (string)($data['filename'] ?? ''),
        );
    }
}
