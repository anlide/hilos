<?php

declare(strict_types=1);

namespace Demo\Chat\Core\Router\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

final class FileUploadReadySignalData extends BaseDTO implements SignalDataInterface
{
    public function __construct(
        public readonly string $uploadId,
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly ?string $clientUploadId = null,
    ) {
    }

    public function toArray(): array
    {
        $a = [
            'uploadId' => $this->uploadId,
            'filename' => $this->filename,
            'mimeType' => $this->mimeType,
            'size' => $this->size,
        ];
        if ($this->clientUploadId !== null) {
            $a['clientUploadId'] = $this->clientUploadId;
        }

        return $a;
    }

    public static function fromArray(array $data): static
    {
        $cid = $data['clientUploadId'] ?? null;

        return new static(
            uploadId: (string)($data['uploadId'] ?? ''),
            filename: (string)($data['filename'] ?? ''),
            mimeType: (string)($data['mimeType'] ?? ''),
            size: (int)($data['size'] ?? 0),
            clientUploadId: is_string($cid) ? $cid : null,
        );
    }
}
